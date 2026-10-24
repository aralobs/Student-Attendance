<?php
declare(strict_types=1);
require_once __DIR__.'/enrollment_access.php';

function movingUpId($value): int
{
    $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id===false || is_bool($value)) throw new DomainException('Choose valid moving-up records.');
    return $id;
}

/** Canonical payload: request replay is independent of checkbox ordering. */
function movingUpCommand(array $input): array
{
    if (!is_array($input['student_ids'] ?? null) || !$input['student_ids']) throw new DomainException('Select at least one student.');
    $ids=array_map('movingUpId',$input['student_ids']); sort($ids,SORT_NUMERIC);
    if (count(array_unique($ids))!==count($ids)) throw new DomainException('Duplicate student selection.');
    $outcome=$input['outcome'] ?? '';
    if (!in_array($outcome,['promoted','retained','graduated'],true)) throw new DomainException('Choose a valid outcome.');
    $destination=$input['destination_section_id'] ?? null;
    if ($destination==='' || $destination===0 || $destination==='0') $destination=null;
    if ($outcome==='graduated' && $destination!==null) throw new DomainException('Graduation has no destination section.');
    if ($outcome!=='graduated') $destination=movingUpId($destination);
    return ['source_year_id'=>movingUpId($input['source_year_id'] ?? null),'source_section_id'=>movingUpId($input['source_section_id'] ?? null),'student_ids'=>$ids,'target_year_id'=>movingUpId($input['target_year_id'] ?? null),'outcome'=>$outcome,'destination_section_id'=>$destination];
}

function requireMovingUpAdmin(PDO $db, array $actor, bool $lock=false): void
{
    if (($actor['role'] ?? '')!=='admin' || (int)($actor['id'] ?? 0)<=0 || !enrollmentModelEnabled($db)) throw new DomainException('Moving up requires an administrator and annual enrollment mode.');
    $q=$db->prepare('SELECT role,is_active,session_token FROM users WHERE id=?'.($lock ? ' LOCK IN SHARE MODE' : ''));
    $q->execute([(int)$actor['id']]); $user=$q->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['role']!=='admin' || !(int)$user['is_active']) throw new DomainException('Administrator access has changed.');
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id']===(int)$actor['id'] && (!is_string($_SESSION['session_token'] ?? null) || !is_string($user['session_token']) || !hash_equals($user['session_token'],$_SESSION['session_token']))) throw new DomainException('Your session has changed. Sign in again.');
}

/** Caller owns transaction. Lock complete structural prefix before metadata, never cache placement. */
function lockMovingUpRows(PDO $db, array $actor, array $command): array
{
    if (!$db->inTransaction()) throw new LogicException('Moving-up locks require a transaction.');
    if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) throw new DomainException('School-year control is not initialized.');
    if (!enrollmentModelEnabled($db)) throw new DomainException('Annual enrollment is not enabled.');
    // Fingerprints use domain dates/versions, never connection-local TIMESTAMP renderings.
    $years=$db->query('SELECT id,label,starts_on,ends_on,state,version FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    // Other source assignments can reveal moved/overlapping placements. Lock sections first.
    $sections=$db->query('SELECT id,school_year_id,grade_level,is_active,adviser_id,school_year FROM sections ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $marks=implode(',',array_fill(0,count($command['student_ids']),'?'));
    $q=$db->prepare("SELECT id,is_active FROM students WHERE id IN ($marks) ORDER BY id FOR UPDATE"); $q->execute($command['student_ids']); $students=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$db->prepare("SELECT id,student_id,school_year_id,state,starts_on,ends_on,version FROM student_enrollments WHERE student_id IN ($marks) ORDER BY id FOR UPDATE"); $q->execute($command['student_ids']); $enrollments=$q->fetchAll(PDO::FETCH_ASSOC);
    $assignments=[]; $attendance=[]; $decisions=[];
    if ($enrollments) {
        $ids=array_column($enrollments,'id'); $em=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare("SELECT id,enrollment_id,section_id,grade_level,starts_on,ends_on,version FROM enrollment_sections WHERE enrollment_id IN ($em) ORDER BY id FOR UPDATE"); $q->execute($ids); $assignments=$q->fetchAll(PDO::FETCH_ASSOC);
        // A cancelled destination can be replaced by graduation only while unused.
        // Lock scan validity before decision metadata, including malformed references.
        $cancelledTargets=array_filter($enrollments,fn($e)=>$command['outcome']==='graduated' && (int)$e['school_year_id']===$command['target_year_id'] && $e['state']==='cancelled');
        if ($cancelledTargets) {
            $targetIds=array_map('intval',array_column($cancelledTargets,'id')); $tm=implode(',',array_fill(0,count($targetIds),'?'));
            $targetAssignments=array_values(array_filter($assignments,fn($a)=>in_array((int)$a['enrollment_id'],$targetIds,true)));
            $where="student_id IN ($marks) OR enrollment_id IN ($tm)"; $params=array_merge($command['student_ids'],$targetIds);
            if ($targetAssignments) {
                $assignmentIds=array_column($targetAssignments,'id'); $am=implode(',',array_fill(0,count($assignmentIds),'?'));
                $where.=" OR assignment_id IN ($am)"; $params=array_merge($params,$assignmentIds);
            }
            $q=$db->prepare("SELECT id,student_id,date,enrollment_id,assignment_id FROM attendance WHERE $where ORDER BY student_id,date,id FOR UPDATE"); $q->execute($params); $attendance=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        $q=$db->prepare("SELECT * FROM moving_up_decisions WHERE source_enrollment_id IN ($em) ORDER BY id FOR UPDATE"); $q->execute($ids); $decisions=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    requireMovingUpAdmin($db,$actor,true);
    return compact('years','sections','students','enrollments','assignments','attendance','decisions');
}

/** Final placement is the unique assignment covering min(enrollment end, source-year end). */
function movingUpSnapshot(array $locked, array $command): array
{
    $years=array_column($locked['years'],null,'id'); $sections=array_column($locked['sections'],null,'id');
    $source=$years[$command['source_year_id']] ?? null; $target=$years[$command['target_year_id']] ?? null;
    if (count(array_filter($years,fn($y)=>$y['state']==='active'))!==1 || !$source || !$target || !in_array($source['state'],['active','closed'],true) || $target['state']!=='planned' || $target['starts_on']<=$source['ends_on']) throw new DomainException('Choose a later planned school year with verified dates.');
    $section=$sections[$command['source_section_id']] ?? null; $destination=$sections[$command['destination_section_id']] ?? null;
    if (!$section || (int)$section['school_year_id']!==$command['source_year_id']) throw new DomainException('Source section does not belong to the selected school year.');
    $grades=['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'];
    $grade=array_search($section['grade_level'],$grades,true);
    if ($command['outcome']==='graduated') {
        if ($grade!==6) throw new DomainException('Only Grade 6 can graduate.');
    } else {
        $wanted=$command['outcome']==='retained' ? $grade : $grade+1;
        if ($grade===false || !isset($grades[$wanted]) || !$destination || !(int)$destination['is_active'] || (int)$destination['school_year_id']!==$command['target_year_id'] || $destination['grade_level']!==$grades[$wanted]) throw new DomainException('Choose a same-year destination with the correct outcome grade.');
    }
    $students=array_column($locked['students'],null,'id'); $enrollmentsById=array_column($locked['enrollments'],null,'id'); $rows=[];
    foreach ($command['student_ids'] as $studentId) {
        if (!isset($students[$studentId]) || !(int)$students[$studentId]['is_active']) throw new DomainException('Selected student is unavailable.');
        $enrollment=null; $cancelledTarget=null; $cancelledAssignments=[];
        foreach ($locked['enrollments'] as $e) {
            if ((int)$e['student_id']!==$studentId) continue;
            if ((int)$e['school_year_id']===$command['target_year_id']) {
                if ($command['outcome']!=='graduated' || $e['state']!=='cancelled') throw new DomainException('A target-year enrollment already exists. Use the correction flow.');
                $cancelledTarget=$e;
                $cancelledAssignments=array_values(array_filter($locked['assignments'],fn($a)=>(int)$a['enrollment_id']===(int)$e['id']));
                $assignmentIds=array_map('intval',array_column($cancelledAssignments,'id'));
                foreach ($locked['attendance'] as $scan) {
                    if ((int)$scan['enrollment_id']===(int)$e['id'] || in_array((int)$scan['assignment_id'],$assignmentIds,true) || ((int)$scan['student_id']===$studentId && $scan['date']>=$target['starts_on'] && $scan['date']<=$target['ends_on'])) throw new DomainException('The cancelled destination has attendance. Review enrollment corrections.');
                }
            }
            if ((int)$e['school_year_id']===$command['source_year_id']) $enrollment=$e;
        }
        if (!$enrollment || !in_array($enrollment['state'],['active','completed'],true)) throw new DomainException('Student has no eligible source enrollment.');
        $start=max($source['starts_on'],$enrollment['starts_on']); $end=min($source['ends_on'],$enrollment['ends_on']);
        if ($start>$end) throw new DomainException('Source enrollment has no eligible dates.');
        $previous=null; $final=[]; $history=[];
        $assignments=array_values(array_filter($locked['assignments'],fn($a)=>(int)$a['enrollment_id']===(int)$enrollment['id']));
        usort($assignments,fn($a,$b)=>strcmp($a['starts_on'],$b['starts_on']) ?: ((int)$a['id']<=>(int)$b['id']));
        foreach ($assignments as $assignment) {
            $assigned=$sections[$assignment['section_id']] ?? null;
            if (!$assigned || (int)$assigned['school_year_id']!==$command['source_year_id'] || $assigned['grade_level']!==$assignment['grade_level'] || $assignment['starts_on']>$assignment['ends_on'] || ($previous!==null && $assignment['starts_on']<=$previous)) throw new DomainException('Source placement history is ambiguous. Review enrollment corrections.');
            $previous=$assignment['ends_on']; $history[]=$assignment;
            if ($assignment['starts_on']<=$end && $assignment['ends_on']>=$end) $final[]=$assignment;
        }
        if (count($final)!==1 || (int)$final[0]['section_id']!==$command['source_section_id']) throw new DomainException('Student is outside the selected final-placement roster.');
        foreach ($locked['decisions'] as $decision) {
            $decisionSource=$enrollmentsById[$decision['source_enrollment_id']] ?? null;
            if ((int)($decisionSource['student_id'] ?? 0)===$studentId && (int)$decision['target_year_id']===$command['target_year_id'] && $decision['state']!=='cancelled') throw new DomainException('An outcome already exists for this student and target year.');
        }
        $rows[]=['student_id'=>$studentId,'enrollment_id'=>(int)$enrollment['id'],'assignment_id'=>(int)$final[0]['id'],'section_id'=>$command['source_section_id'],'grade_level'=>$final[0]['grade_level'],'outcome'=>$command['outcome'],'destination_section_id'=>$command['destination_section_id'],'final_on'=>$end,'enrollment'=>$enrollment,'assignments'=>$history,'cancelled_target'=>$cancelledTarget,'cancelled_assignments'=>$cancelledAssignments];
    }
    $fingerprint=hash('sha256',json_encode([$command,$source,$target,$section,$destination,$rows],JSON_THROW_ON_ERROR));
    // No profile/contact/QR fields are exposed by this service.
    $publicRows=array_map(fn($row)=>array_diff_key($row,array_flip(['enrollment','assignments','cancelled_target','cancelled_assignments'])),$rows);
    return ['rows'=>$publicRows,'fingerprint'=>$fingerprint,'target'=>$target];
}

function previewMovingUp(PDO $db, array $actor, array $command): array
{
    if ($db->inTransaction()) throw new LogicException('Moving-up preview owns its transaction.');
    requireMovingUpAdmin($db,$actor); $command=movingUpCommand($command); $db->beginTransaction();
    try { $result=movingUpSnapshot(lockMovingUpRows($db,$actor,$command),$command); unset($result['target']); $db->commit(); return $result; }
    catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
}

function movingUpBatchResult(PDO $db, int $batchId, bool $replayed): array
{
    $q=$db->prepare('SELECT id,destination_enrollment_id FROM moving_up_decisions WHERE batch_id=? ORDER BY id'); $q->execute([$batchId]);
    $decisionIds=[]; $destinationIds=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) { $decisionIds[]=(int)$row['id']; if($row['destination_enrollment_id']!==null) $destinationIds[]=(int)$row['destination_enrollment_id']; }
    return ['batch_id'=>$batchId,'decision_ids'=>$decisionIds,'destination_enrollment_ids'=>$destinationIds,'replayed'=>$replayed];
}

function prepareMovingUp(PDO $db, array $actor, array $command, string $requestKey, string $expectedFingerprint): array
{
    if ($db->inTransaction()) throw new LogicException('Moving-up preparation owns its transaction.');
    requireMovingUpAdmin($db,$actor); $command=movingUpCommand($command);
    if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/',$requestKey)) throw new DomainException('Invalid moving-up request key.');
    $payloadHash=hash('sha256',json_encode([(int)$actor['id'],$command],JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        $locked=lockMovingUpRows($db,$actor,$command);
        $q=$db->prepare('SELECT id,payload_hash FROM moving_up_batches WHERE request_key=? FOR UPDATE'); $q->execute([$requestKey]); $existing=$q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (!hash_equals($existing['payload_hash'],$payloadHash)) throw new DomainException('Request key already belongs to a different moving-up command.');
            $result=movingUpBatchResult($db,(int)$existing['id'],true); $db->commit(); return $result;
        }
        $snapshot=movingUpSnapshot($locked,$command);
        if (!hash_equals($snapshot['fingerprint'],$expectedFingerprint)) throw new DomainException('The preview is stale. Preview the current roster again.');
        securityAuditContext((int)$actor['id']);
        $db->prepare('INSERT INTO moving_up_batches(request_key,payload_hash,source_year_id,target_year_id,outcome,destination_section_id,actor_id) VALUES (?,?,?,?,?,?,?)')->execute([$requestKey,$payloadHash,$command['source_year_id'],$command['target_year_id'],$command['outcome'],$command['destination_section_id'],(int)$actor['id']]);
        $batchId=(int)$db->lastInsertId(); $target=$snapshot['target'];
        foreach ($snapshot['rows'] as $row) {
            $destinationId=null;
            if ($command['outcome']!=='graduated') {
                $destination=array_column($locked['sections'],null,'id')[$command['destination_section_id']];
                // Focused locked inserts deliberately do not call the projection-writing enrollment command.
                $db->prepare("INSERT INTO student_enrollments(student_id,school_year_id,state,starts_on,ends_on) VALUES (?,?,'prepared',?,?)")->execute([$row['student_id'],$command['target_year_id'],$target['starts_on'],$target['ends_on']]);
                $destinationId=(int)$db->lastInsertId();
                $db->prepare('INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (?,?,?,?,?)')->execute([$destinationId,$command['destination_section_id'],$destination['grade_level'],$target['starts_on'],$target['ends_on']]);
            }
            $db->prepare("INSERT INTO moving_up_decisions(batch_id,source_enrollment_id,destination_enrollment_id,target_year_id,outcome,state) VALUES (?,?,?,?,?,'prepared')")->execute([$batchId,$row['enrollment_id'],$destinationId,$command['target_year_id'],$command['outcome']]);
            securityAuditChange($db,'moving_up.prepare','enrollment',$row['enrollment_id'],null,['batch_id'=>$batchId,'decision_id'=>(int)$db->lastInsertId(),'destination_enrollment_id'=>$destinationId,'outcome'=>$command['outcome'],'state'=>'prepared']);
        }
        $result=movingUpBatchResult($db,$batchId,false); $db->commit(); return $result;
    } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** Complete source-section filter; never paginated or derived from profile projection. Preview revalidates every selection. */
function listMovingUpSourceRoster(PDO $db, array $actor, int $sourceYearId, int $sourceSectionId): array
{
    requireMovingUpAdmin($db,$actor);
    $q=$db->prepare("SELECT DISTINCT s.id AS student_id,s.first_name,s.last_name,e.id AS enrollment_id,es.grade_level
      FROM school_years y JOIN student_enrollments e ON e.school_year_id=y.id
      JOIN enrollment_sections es ON es.enrollment_id=e.id JOIN sections sec ON sec.id=es.section_id JOIN students s ON s.id=e.student_id
      WHERE y.id=? AND es.section_id=? AND sec.school_year_id=y.id AND sec.grade_level=es.grade_level AND y.state IN ('active','closed') AND e.state IN ('active','completed') AND s.is_active=1
        AND GREATEST(e.starts_on,y.starts_on)<=LEAST(e.ends_on,y.ends_on)
        AND es.starts_on<=LEAST(e.ends_on,y.ends_on) AND es.ends_on>=LEAST(e.ends_on,y.ends_on)
        AND NOT EXISTS(SELECT 1 FROM enrollment_sections other WHERE other.enrollment_id=e.id AND other.id<>es.id AND other.starts_on<=LEAST(e.ends_on,y.ends_on) AND other.ends_on>=LEAST(e.ends_on,y.ends_on))
      ORDER BY s.last_name,s.first_name,s.id");
    $q->execute([$sourceYearId,$sourceSectionId]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row) { $row['student_id']=(int)$row['student_id']; $row['enrollment_id']=(int)$row['enrollment_id']; } unset($row);
    return $rows;
}
