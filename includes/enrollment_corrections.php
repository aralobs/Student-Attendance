<?php
declare(strict_types=1);
require_once __DIR__.'/enrollment_commands.php';
require_once __DIR__.'/moving_up.php';

/** Correction commands own transactions. No schema, notification, or lifecycle work at include time. */
function enrollmentCorrectionCommand(array $input): array
{
    $kind=$input['kind'] ?? null; $reason=$input['reason'] ?? null;
    if (!in_array($kind,['reassign','cancel','historical_assignment','sf2_remark'],true)
        || !is_string($reason) || trim($reason)==='' || strlen($reason)>4000 || !mb_check_encoding($reason,'UTF-8') || str_contains($reason,"\0")) {
        throw new DomainException('Choose a correction and enter a reason of at most 4,000 bytes.');
    }
    $command=['kind'=>$kind,'reason'=>trim($reason)];
    if ($kind==='cancel') return $command;
    $command['section_id']=movingUpId($input['section_id'] ?? null);
    if ($kind==='sf2_remark') {
        foreach (['report_month'=>[1,12],'report_year'=>[1900,2100],'expected_remark_version'=>[0,PHP_INT_MAX]] as $key=>$bounds) {
            $value=filter_var($input[$key] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>$bounds[0],'max_range'=>$bounds[1]]]);
            if ($value===false || is_bool($input[$key] ?? null)) throw new DomainException('Invalid report coordinates or remark version.');
            $command[$key]=$value;
        }
        $remark=$input['remark'] ?? null;
        if (!is_string($remark) || strlen($remark)>4000 || !mb_check_encoding($remark,'UTF-8') || mb_strlen($remark,'UTF-8')>1000 || str_contains($remark,"\0")) throw new DomainException('Remarks must contain at most 1,000 characters.');
        $command['remark']=$remark;
    } else {
        $command['assignment_id']=movingUpId($input['assignment_id'] ?? null);
        foreach (['starts_on','ends_on'] as $key) {
            if (!is_string($input[$key] ?? null)) throw new DomainException('Enter explicit effective dates.');
            $command[$key]=validatedSchoolYearDate($input[$key]);
        }
        if ($command['starts_on']>$command['ends_on']) throw new DomainException('Effective dates must be ordered.');
    }
    return $command;
}

/** Structural lock prefix precedes all consistent reads. All downstream rows of the
 * selected student are locked, so scans that finish while the year lock waits are visible.
 * Sections/years are conservatively locked in full, matching lifecycle commands.
 */
function lockEnrollmentCorrectionRows(PDO $db,array $actor,int $enrollmentId): array
{
    if (!$db->inTransaction()) throw new LogicException('Correction locks require a transaction.');
    if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) throw new DomainException('School-year control is not initialized.');
    $years=$db->query('SELECT id,label,starts_on,ends_on,state,version FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $sections=$db->query('SELECT id,school_year_id,grade_level,is_active,adviser_id FROM sections ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    // Resolve under locked structural rows; recheck the candidate after the profile lock.
    $q=$db->prepare('SELECT student_id FROM student_enrollments WHERE id=?'); $q->execute([$enrollmentId]); $studentId=(int)$q->fetchColumn();
    if (!$studentId) throw new DomainException('Enrollment is unavailable.');
    $q=$db->prepare('SELECT id,is_active FROM students WHERE id=? FOR UPDATE'); $q->execute([$studentId]); $student=$q->fetch(PDO::FETCH_ASSOC);
    $q=$db->prepare('SELECT id,student_id,school_year_id,state,starts_on,ends_on,version FROM student_enrollments WHERE student_id=? ORDER BY id FOR UPDATE'); $q->execute([$studentId]); $enrollments=$q->fetchAll(PDO::FETCH_ASSOC);
    $ids=array_column($enrollments,'id'); $marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare("SELECT id,enrollment_id,section_id,grade_level,starts_on,ends_on,version FROM enrollment_sections WHERE enrollment_id IN ($marks) ORDER BY id FOR UPDATE"); $q->execute($ids); $assignments=$q->fetchAll(PDO::FETCH_ASSOC);
    // Nullable composite keys permit assignment-only references, including rows
    // belonging to another student. Include every reference before declaring unused.
    $attendanceWhere="student_id=? OR enrollment_id IN ($marks)"; $attendanceParams=array_merge([$studentId],$ids);
    if ($assignments) {
        $assignmentIds=array_column($assignments,'id'); $assignmentMarks=implode(',',array_fill(0,count($assignmentIds),'?'));
        $attendanceWhere.=" OR assignment_id IN ($assignmentMarks)"; $attendanceParams=array_merge($attendanceParams,$assignmentIds);
    }
    $q=$db->prepare("SELECT id,student_id,enrollment_id,assignment_id,date,am_in,am_out,am_status,pm_in,pm_out,pm_status,attendance_type,remarks,recorded_by FROM attendance WHERE $attendanceWhere ORDER BY student_id,date,id FOR UPDATE"); $q->execute($attendanceParams); $attendance=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$db->prepare("SELECT id,batch_id,source_enrollment_id,destination_enrollment_id,target_year_id,outcome,state,version FROM moving_up_decisions WHERE source_enrollment_id IN ($marks) OR destination_enrollment_id IN ($marks) ORDER BY id FOR UPDATE"); $q->execute(array_merge($ids,$ids)); $decisions=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$db->prepare('SELECT student_id,section_id,report_month,report_year,remark_ciphertext,version FROM sf2_remarks WHERE student_id=? ORDER BY section_id,report_year,report_month FOR UPDATE'); $q->execute([$studentId]); $remarks=$q->fetchAll(PDO::FETCH_ASSOC);
    requireMovingUpAdmin($db,$actor,true);
    if (count(array_filter($years,fn($year)=>$year['state']==='active'))!==1) throw new DomainException('One active managed year is required.');
    $annualById=array_column($enrollments,null,'id'); $assignmentById=array_column($assignments,null,'id'); $yearById=array_column($years,null,'id');
    foreach ($attendance as $scan) {
        if ((int)$scan['student_id']!==$studentId) throw new DomainException('Attendance references another student\'s enrollment or assignment. Resolve ownership before correction.');
        $annual=$scan['enrollment_id']===null ? null : ($annualById[$scan['enrollment_id']] ?? null);
        $assignment=$scan['assignment_id']===null ? null : ($assignmentById[$scan['assignment_id']] ?? null);
        if (($scan['enrollment_id']!==null && !$annual) || ($scan['assignment_id']!==null && !$assignment)) throw new DomainException('Attendance references inconsistent enrollment ownership. Resolve ownership before correction.');
        if ($assignment) {
            $assignmentAnnual=$annualById[$assignment['enrollment_id']] ?? null;
            if (!$assignmentAnnual || ($annual && (int)$annual['id']!==(int)$assignmentAnnual['id']) || $scan['date']<$assignment['starts_on'] || $scan['date']>$assignment['ends_on']) throw new DomainException('Attendance does not match its referenced assignment or dates. Resolve ownership before correction.');
            $annual=$assignmentAnnual;
        }
        if ($annual) {
            $annualYear=$yearById[$annual['school_year_id']] ?? null;
            if (!$annualYear || (int)$annual['student_id']!==$studentId || $scan['date']<max($annual['starts_on'],$annualYear['starts_on']) || $scan['date']>min($annual['ends_on'],$annualYear['ends_on'])) throw new DomainException('Attendance does not match its referenced enrollment or year dates. Resolve ownership before correction.');
        }
        // Coherent missing ownership remains eligible only for an explicit reviewed
        // exact-ID repair. This validation never infers or writes missing IDs.
    }
    securityAuditContext((int)$actor['id']);
    return compact('years','sections','student','enrollments','assignments','attendance','decisions','remarks');
}

/** Build the exact reviewed transformation without writing. The first segment keeps
 * its assignment identity; right-hand segments get new IDs only on confirmation.
 */
function enrollmentCorrectionSnapshot(array $locked,int $enrollmentId,array $command,bool $direct=false): array
{
    $enrollments=array_column($locked['enrollments'],null,'id'); $e=$enrollments[$enrollmentId] ?? null;
    $years=array_column($locked['years'],null,'id'); $sections=array_column($locked['sections'],null,'id');
    $year=$e ? ($years[$e['school_year_id']] ?? null) : null;
    if (!$e || !$year || !$locked['student']) throw new DomainException('Enrollment is unavailable.');
    if ($e['starts_on']<$year['starts_on'] || $e['ends_on']>$year['ends_on'] || $e['starts_on']>$e['ends_on']) throw new DomainException('Annual dates must be valid within the verified year.');
    $assignments=array_values(array_filter($locked['assignments'],fn($a)=>(int)$a['enrollment_id']===$enrollmentId));
    usort($assignments,fn($a,$b)=>strcmp($a['starts_on'],$b['starts_on']));
    $previous=null;
    foreach ($assignments as $a) {
        $section=$sections[$a['section_id']] ?? null;
        if (!$section || (int)$section['school_year_id']!==(int)$e['school_year_id'] || $section['grade_level']!==$a['grade_level']
            || $a['starts_on']<$e['starts_on'] || $a['ends_on']>$e['ends_on'] || $a['starts_on']>$a['ends_on'] || ($previous!==null && $a['starts_on']<=$previous)) throw new DomainException('Existing assignments need an unambiguous bounded correction.');
        $previous=$a['ends_on'];
    }
    $assignmentIds=array_map('intval',array_column($assignments,'id'));
    $annualAttendance=array_values(array_filter($locked['attendance'],fn($a)=>(int)$a['enrollment_id']===$enrollmentId || in_array((int)$a['assignment_id'],$assignmentIds,true) || ($a['date']>=$e['starts_on'] && $a['date']<=$e['ends_on'])));
    if ($direct && ($year['state']!=='planned' || !in_array($e['state'],['prepared','cancelled'],true) || $annualAttendance || !in_array($command['kind'],['cancel','reassign'],true))) throw new DomainException('Direct revision requires a prepared destination without attendance. Use reviewed correction.');
    $segments=[]; $affected=[]; $assignment=null; $remark=null; $decisionChanges=[];
    if ($command['kind']==='cancel') {
        if ($year['state']!=='planned' || $e['state']!=='prepared' || $annualAttendance) throw new DomainException('Only a prepared destination without attendance can be cancelled. Use bounded historical correction for recorded attendance.');
    } elseif ($command['kind']==='sf2_remark') {
        if (!in_array($e['state'],['active','completed'],true)) throw new DomainException('A reportable annual enrollment is required.');
        $start=sprintf('%04d-%02d-01',$command['report_year'],$command['report_month']); $end=date('Y-m-t',strtotime($start));
        $membership=array_filter($assignments,fn($a)=>(int)$a['section_id']===$command['section_id'] && $a['starts_on']<=$end && $a['ends_on']>=$start);
        if (!$membership) throw new DomainException('The selected report coordinates have no annual membership.');
        foreach ($locked['remarks'] as $row) if ((int)$row['section_id']===$command['section_id'] && (int)$row['report_month']===$command['report_month'] && (int)$row['report_year']===$command['report_year']) $remark=$row;
        if ((int)($remark['version'] ?? 0)!==$command['expected_remark_version']) throw new DomainException('This remark changed. Reload and preview again.');
    } else {
        foreach ($assignments as $a) if ((int)$a['id']===$command['assignment_id']) $assignment=$a;
        $destination=$sections[$command['section_id']] ?? null;
        if (!$assignment || !$destination || (int)$destination['school_year_id']!==(int)$e['school_year_id'] || $command['starts_on']<$assignment['starts_on'] || $command['ends_on']>$assignment['ends_on']) throw new DomainException('Choose one assignment and bound the change within its verified dates and year.');
        if ($command['kind']==='reassign' && !(($year['state']==='planned' && in_array($e['state'],['prepared','cancelled'],true)) || ($year['state']==='active' && $e['state']==='active'))) throw new DomainException('Ordinary reassignment requires an active or prepared enrollment. Use reviewed historical correction for completed history.');
        if ($command['kind']==='reassign' && ($year['state']==='closed' || !(int)$destination['is_active'] || !(int)$locked['student']['is_active'] || ($e['state']==='active' && $command['starts_on']<=date('Y-m-d')))) throw new DomainException('Ordinary reassignment must use a live future placement; past or closed dates need historical correction.');
        if ($e['state']==='cancelled' && ($year['state']!=='planned' || $annualAttendance || $command['kind']!=='reassign')) throw new DomainException('Only an unused cancelled destination in a planned year can be reinstated.');
        if ($e['state']==='cancelled') foreach ($locked['decisions'] as $other) {
            $otherSource=$enrollments[$other['source_enrollment_id']] ?? null;
            if ((int)($otherSource['student_id'] ?? 0)===(int)$e['student_id'] && (int)$other['target_year_id']===(int)$e['school_year_id'] && $other['state']!=='cancelled') throw new DomainException('A replacement outcome exists; review it before reinstating this annual enrollment.');
        }
        if ($destination['grade_level']!==$assignment['grade_level'] && ($year['state']!=='planned' || $annualAttendance || count($assignments)!==1 || $command['starts_on']!==$e['starts_on'] || $command['ends_on']!==$e['ends_on'])) throw new DomainException('Grade changes require a complete unused prepared destination.');
        if ($command['kind']==='reassign' && $annualAttendance && $command['starts_on']<=date('Y-m-d')) throw new DomainException('Recorded history requires historical correction.');
        if ($assignment['starts_on']<$command['starts_on']) $segments[]=['section_id'=>(int)$assignment['section_id'],'grade_level'=>$assignment['grade_level'],'starts_on'=>$assignment['starts_on'],'ends_on'=>(new DateTimeImmutable($command['starts_on']))->modify('-1 day')->format('Y-m-d')];
        $segments[]=['section_id'=>$command['section_id'],'grade_level'=>$destination['grade_level'],'starts_on'=>$command['starts_on'],'ends_on'=>$command['ends_on']];
        if ($assignment['ends_on']>$command['ends_on']) $segments[]=['section_id'=>(int)$assignment['section_id'],'grade_level'=>$assignment['grade_level'],'starts_on'=>(new DateTimeImmutable($command['ends_on']))->modify('+1 day')->format('Y-m-d'),'ends_on'=>$assignment['ends_on']];
        foreach ($annualAttendance as $row) {
            if ($row['date']<$assignment['starts_on'] || $row['date']>$assignment['ends_on']) continue;
            if ($row['enrollment_id']!==null && (int)$row['enrollment_id']!==$enrollmentId) throw new DomainException('Attendance belongs to a different annual enrollment.');
            if ($row['assignment_id']!==null && (int)$row['assignment_id']!==(int)$assignment['id']) throw new DomainException('Attendance has inconsistent ownership; resolve the conflicting assignment first.');
            foreach ($segments as $index=>$segment) if ($row['date']>=$segment['starts_on'] && $row['date']<=$segment['ends_on']) {
                if ($index!==0 || $segment['section_id']!==(int)$assignment['section_id'] || $row['assignment_id']===null || $row['enrollment_id']===null) $affected[]=(int)$row['id'];
            }
        }
        // Future moves must not repair already recorded history implicitly.
        if ($command['kind']==='reassign' && $affected) throw new DomainException('Attendance in the changed interval requires historical correction.');
    }
    // Keep prepared decisions coherent. Applied history is immutable metadata; only
    // same-grade historical section corrections can coexist with applied outcomes.
    foreach ($locked['decisions'] as $decision) {
        if ((int)$decision['destination_enrollment_id']!==$enrollmentId) continue;
        if ($command['kind']==='sf2_remark') continue;
        if ($decision['state']==='applied') {
            if ($command['kind']==='cancel' || ($assignment && $segments[0]['grade_level']!==$assignment['grade_level'])) throw new DomainException('Applied moving-up outcome cannot be revised as prepared.');
            continue;
        }
        if ($command['kind']==='cancel') { if ($decision['state']==='prepared') $decisionChanges[]=['before'=>$decision,'state'=>'cancelled','outcome'=>$decision['outcome']]; continue; }
        if ($decision['state']==='cancelled' && $e['state']!=='cancelled') continue;
        $source=$enrollments[$decision['source_enrollment_id']] ?? null; $sourceYear=$source ? ($years[$source['school_year_id']] ?? null) : null;
        $final=$source ? array_values(array_filter($locked['assignments'],fn($a)=>(int)$a['enrollment_id']===(int)$source['id'] && $a['starts_on']<=$source['ends_on'] && $a['ends_on']>=$source['ends_on'])) : [];
        if (!$source || !$sourceYear || !in_array($source['state'],['active','completed'],true) || $year['state']!=='planned' || (int)$decision['target_year_id']!==(int)$year['id'] || $year['starts_on']<=$sourceYear['ends_on'] || count($final)!==1) throw new DomainException('Moving-up source and target must still be eligible.');
        $grades=['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'];
        $sourceGrade=array_search($final[0]['grade_level'],$grades,true);
        $targetGrade=array_search($segments[0]['grade_level'],$grades,true);
        foreach ($segments as $segment) if ($segment['grade_level']!==$segments[0]['grade_level']) throw new DomainException('Prepared outcome must have one destination grade.');
        if ($sourceGrade===false || $targetGrade===false || !in_array($targetGrade,[$sourceGrade,$sourceGrade+1],true)) throw new DomainException('Destination must retain or promote the source grade.');
        $decisionChanges[]=['before'=>$decision,'state'=>'prepared','outcome'=>$targetGrade===$sourceGrade ? 'retained' : 'promoted'];
    }
    sort($affected,SORT_NUMERIC);
    $fingerprint=hash('sha256',json_encode([$locked,$enrollmentId,$command,date('Y-m-d')],JSON_THROW_ON_ERROR));
    return ['enrollment'=>$e,'year'=>$year,'assignment'=>$assignment,'segments'=>$segments,'affected_attendance_ids'=>$affected,'remark'=>$remark,'decision_changes'=>$decisionChanges,'fingerprint'=>$fingerprint];
}

function revisePreparedEnrollment(PDO $db,array $actor,int $enrollmentId,array $change,int $expectedVersion): void
{
    if ($db->inTransaction()) throw new LogicException('Prepared revision owns its transaction.');
    requireMovingUpAdmin($db,$actor); $command=enrollmentCorrectionCommand($change); $db->beginTransaction();
    try {
        $locked=lockEnrollmentCorrectionRows($db,$actor,$enrollmentId); $snapshot=enrollmentCorrectionSnapshot($locked,$enrollmentId,$command,true);
        if ((int)$snapshot['enrollment']['version']!==$expectedVersion) throw new DomainException('Enrollment changed. Reload before revising.');
        applyEnrollmentCorrectionLocked($db,$actor,$locked,$snapshot,$command);
        $db->commit();
    } catch(Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function previewEnrollmentCorrection(PDO $db,array $actor,int $enrollmentId,array $change): array
{
    if ($db->inTransaction()) throw new LogicException('Correction preview owns its transaction.');
    requireMovingUpAdmin($db,$actor); $command=enrollmentCorrectionCommand($change); $db->beginTransaction();
    try {
        $locked=lockEnrollmentCorrectionRows($db,$actor,$enrollmentId); $snapshot=enrollmentCorrectionSnapshot($locked,$enrollmentId,$command);
        $db->prepare("INSERT INTO enrollment_corrections(enrollment_id,kind,command_ciphertext,preview_fingerprint,expected_version,actor_id) VALUES (?,?,'',?,?,?)")->execute([$enrollmentId,$command['kind'],$snapshot['fingerprint'],$snapshot['enrollment']['version'],(int)$actor['id']]);
        $id=(int)$db->lastInsertId();
        $db->prepare('UPDATE enrollment_corrections SET command_ciphertext=? WHERE id=?')->execute([encryptPII(json_encode($command,JSON_THROW_ON_ERROR),'enrollment-correction:'.$id),$id]);
        securityAuditChange($db,'enrollment.correction_preview','enroll_correct',$id,null,['enrollment_id'=>$enrollmentId,'command'=>$command,'affected_attendance_ids'=>$snapshot['affected_attendance_ids'],'fingerprint'=>$snapshot['fingerprint']]);
        $db->commit();
        return ['correction_id'=>$id,'affected_attendance_ids'=>$snapshot['affected_attendance_ids'],'fingerprint'=>$snapshot['fingerprint']];
    } catch(Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

function applyEnrollmentCorrection(PDO $db,array $actor,int $correctionId,string $expectedFingerprint): void
{
    if ($db->inTransaction()) throw new LogicException('Correction confirmation owns its transaction.');
    requireMovingUpAdmin($db,$actor);
    // Candidate lookup occurs before the transaction; the locked metadata is rechecked.
    $q=$db->prepare('SELECT enrollment_id FROM enrollment_corrections WHERE id=?'); $q->execute([$correctionId]); $enrollmentId=(int)$q->fetchColumn();
    if (!$enrollmentId) throw new DomainException('Preview this correction before confirmation.');
    $db->beginTransaction();
    try {
        $locked=lockEnrollmentCorrectionRows($db,$actor,$enrollmentId);
        $q=$db->prepare('SELECT * FROM enrollment_corrections WHERE id=? FOR UPDATE'); $q->execute([$correctionId]); $correction=$q->fetch(PDO::FETCH_ASSOC);
        if (!$correction || (int)$correction['enrollment_id']!==$enrollmentId || $correction['state']!=='pending' || (int)$correction['actor_id']!==(int)$actor['id'] || !hash_equals($correction['preview_fingerprint'],$expectedFingerprint)) throw new DomainException('Only the administrator who previewed this pending correction may confirm it.');
        $command=enrollmentCorrectionCommand(json_decode(decryptPII($correction['command_ciphertext'],'enrollment-correction:'.$correctionId),true,512,JSON_THROW_ON_ERROR));
        $snapshot=enrollmentCorrectionSnapshot($locked,$enrollmentId,$command);
        if ((int)$snapshot['enrollment']['version']!==(int)$correction['expected_version'] || !hash_equals($snapshot['fingerprint'],$expectedFingerprint)) throw new DomainException('Records changed since preview. Preview the correction again.');
        applyEnrollmentCorrectionLocked($db,$actor,$locked,$snapshot,$command);
        $db->prepare("UPDATE enrollment_corrections SET state='applied',applied_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$correctionId]);
        securityAuditChange($db,'enrollment.correction_apply','enroll_correct',$correctionId,['state'=>'pending'],['state'=>'applied','command'=>$command,'affected_attendance_ids'=>$snapshot['affected_attendance_ids']]);
        $db->commit();
    } catch(Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** Transaction internal. Caller owns all structural, attendance and metadata locks. */
function applyEnrollmentCorrectionLocked(PDO $db,array $actor,array $locked,array $snapshot,array $command): void
{
    if (!$db->inTransaction()) throw new LogicException('Correction mutation requires a transaction.');
    $e=$snapshot['enrollment']; $enrollmentId=(int)$e['id']; $studentId=(int)$e['student_id'];
    if ($command['kind']==='sf2_remark') {
        $coordinates=[$studentId,$command['section_id'],$command['report_month'],$command['report_year']];
        $context='sf2:'.implode(':',$coordinates); $previous=$snapshot['remark'];
        $before=$previous ? decryptPII($previous['remark_ciphertext'],$context) : ''; $version=(int)($previous['version'] ?? 0)+1;
        $db->prepare('INSERT INTO sf2_remarks(student_id,section_id,report_month,report_year,remark_ciphertext,version,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE remark_ciphertext=VALUES(remark_ciphertext),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP')->execute(array_merge($coordinates,[encryptPII($command['remark'],$context),$version,(int)$actor['id']]));
        $event=bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO sf2_remark_history(event_id,student_id,section_id,report_month,report_year,actor_id,version,change_ciphertext) VALUES (?,?,?,?,?,?,?,?)')->execute(array_merge([$event],$coordinates,[(int)$actor['id'],$version,encryptPII(json_encode(['before'=>$before,'after'=>$command['remark'],'reason'=>$command['reason']],JSON_THROW_ON_ERROR),'sf2-audit:'.$event)]));
        securityAuditChange($db,'enrollment.correct_remark','enrollment',$enrollmentId,['remark'=>$before,'version'=>$version-1],['remark'=>$command['remark'],'version'=>$version,'coordinates'=>$coordinates,'reason'=>$command['reason']]);
    } elseif ($command['kind']==='cancel') {
        $db->prepare("UPDATE student_enrollments SET state='cancelled',version=version+1 WHERE id=?")->execute([$enrollmentId]);
    } else {
        $assignment=$snapshot['assignment']; $segments=$snapshot['segments'];
        foreach ($segments as $index=>&$segment) {
            if ($index===0) {
                $segment['id']=(int)$assignment['id'];
                $db->prepare('UPDATE enrollment_sections SET section_id=?,grade_level=?,starts_on=?,ends_on=?,version=version+1 WHERE id=?')->execute([$segment['section_id'],$segment['grade_level'],$segment['starts_on'],$segment['ends_on'],$segment['id']]);
            } else {
                $db->prepare('INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (?,?,?,?,?)')->execute([$enrollmentId,$segment['section_id'],$segment['grade_level'],$segment['starts_on'],$segment['ends_on']]);
                $segment['id']=(int)$db->lastInsertId();
            }
        } unset($segment);
        foreach ($locked['attendance'] as $row) {
            if (!in_array((int)$row['id'],$snapshot['affected_attendance_ids'],true)) continue;
            foreach ($segments as $segment) if ($row['date']>=$segment['starts_on'] && $row['date']<=$segment['ends_on']) {
                $db->prepare('UPDATE attendance SET enrollment_id=?,assignment_id=?,updated_at=updated_at WHERE id=?')->execute([$enrollmentId,$segment['id'],$row['id']]);
                securityAuditChange($db,'enrollment.correct_ownership','attendance',(int)$row['id'],['enrollment_id'=>$row['enrollment_id'],'assignment_id'=>$row['assignment_id'],'section_id'=>(int)$assignment['section_id']],['enrollment_id'=>$enrollmentId,'assignment_id'=>$segment['id'],'section_id'=>$segment['section_id'],'reason'=>$command['reason']]);
            }
        }
        $db->prepare('UPDATE student_enrollments SET state=?,version=version+1 WHERE id=?')->execute([$e['state']==='cancelled' ? 'prepared' : $e['state'],$enrollmentId]);
        securityAuditChange($db,'enrollment.correct_assignments','enrollment',$enrollmentId,['assignment'=>$assignment],['segments'=>$segments,'reason'=>$command['reason']]);
    }
    foreach ($snapshot['decision_changes'] as $change) {
        $decision=$change['before'];
        $db->prepare('UPDATE moving_up_decisions SET state=?,outcome=?,version=version+1 WHERE id=?')->execute([$change['state'],$change['outcome'],$decision['id']]);
        securityAuditChange($db,'enrollment.correct_decision','enrollment',$enrollmentId,$decision,['decision_id'=>(int)$decision['id'],'state'=>$change['state'],'outcome'=>$change['outcome'],'reason'=>$command['reason']]);
    }
    if ($command['kind']!=='sf2_remark') {
        updateCurrentEnrollmentProjection($db,$studentId);
        $after=$e; $after['state']=$command['kind']==='cancel' ? 'cancelled' : ($e['state']==='cancelled' ? 'prepared' : $e['state']); $after['version']=(int)$e['version']+1; $after['reason']=$command['reason'];
        securityAuditChange($db,'enrollment.revise','enrollment',$enrollmentId,$e,$after);
    }
}

/** Prepared graduation has no destination annual row. Cancelling the decision must
 * never cancel, shorten, or re-project the active/completed source enrollment.
 */
function revisePreparedGraduationDecision(PDO $db,array $actor,int $decisionId,string $reason,int $expectedVersion): void
{
    if ($db->inTransaction()) throw new LogicException('Graduation revision owns its transaction.');
    requireMovingUpAdmin($db,$actor); $command=enrollmentCorrectionCommand(['kind'=>'cancel','reason'=>$reason]);
    $q=$db->prepare('SELECT source_enrollment_id FROM moving_up_decisions WHERE id=?'); $q->execute([$decisionId]); $enrollmentId=(int)$q->fetchColumn();
    if (!$enrollmentId) throw new DomainException('Prepared graduation decision is unavailable.');
    $db->beginTransaction();
    try {
        $locked=lockEnrollmentCorrectionRows($db,$actor,$enrollmentId); $decision=array_column($locked['decisions'],null,'id')[$decisionId] ?? null;
        $target=$decision ? (array_column($locked['years'],null,'id')[$decision['target_year_id']] ?? null) : null;
        if (!$decision || (int)$decision['source_enrollment_id']!==$enrollmentId || $decision['outcome']!=='graduated' || $decision['destination_enrollment_id']!==null || $decision['state']!=='prepared' || (int)$decision['version']!==$expectedVersion || !$target || $target['state']!=='planned') throw new DomainException('Only a current prepared graduation decision for a planned year can be cancelled.');
        $db->prepare("UPDATE moving_up_decisions SET state='cancelled',version=version+1 WHERE id=?")->execute([$decisionId]);
        securityAuditChange($db,'enrollment.cancel_graduation','enrollment',$enrollmentId,$decision,['decision_id'=>$decisionId,'state'=>'cancelled','version'=>$expectedVersion+1,'reason'=>$command['reason']]);
        $db->commit();
    } catch(Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

