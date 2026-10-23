<?php
declare(strict_types=1);

require_once __DIR__ . '/security_audit.php';

/** Labels identify consecutive academic years; dates must be supplied separately. */
function schoolYearLabelStart(string $label): int
{
    if (!preg_match('/\A([1-9][0-9]{3})-([1-9][0-9]{3})\z/', $label, $parts)
        || (int)$parts[2] !== (int)$parts[1] + 1) {
        throw new InvalidArgumentException('School-year label must contain consecutive YYYY-YYYY years.');
    }
    return (int)$parts[1];
}

function nextSchoolYearLabel(string $label): string
{
    $start = schoolYearLabelStart($label) + 1;
    if ($start >= 9999) throw new InvalidArgumentException('Next school-year label exceeds four digits.');
    return $start . '-' . ($start + 1);
}

/** A schema installation alone never switches operational consumers to the model. */
function enrollmentModelEnabled(PDO $db): bool
{
    $read = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');
    $read->execute(['enrollment_model_version']);
    return $read->fetchColumn() === '1';
}

/** Caller owns the transaction when locking. Structural writers lock control row 1 first. */
function getActiveSchoolYear(PDO $db, bool $lock = false): ?array
{
    if ($lock && !$db->inTransaction()) throw new LogicException('Locking a school year requires a transaction.');
    $years = $db->query("SELECT * FROM school_years WHERE state='active' ORDER BY id" . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
    if (count($years) > 1 || (count($years) === 0 && enrollmentModelEnabled($db))) {
        throw new DomainException('Converted enrollment mode requires exactly one active school year.');
    }
    if (!$years) return null;
    $year = $years[0];
    $year['id'] = (int)$year['id'];
    $year['version'] = (int)$year['version'];
    $year['active_slot'] = (int)$year['active_slot'];
    return $year;
}

function validatedSchoolYearDate($value): string
{
    if (!is_string($value) || !preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new InvalidArgumentException('School-year boundaries require real ISO dates.');
    }
    return $value;
}

/** Public command owns its transaction; never call inside another command's transaction. */
function savePlannedSchoolYear(PDO $db, array $actor, array $input): int
{
    $actorId = filter_var($actor['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (($actor['role'] ?? null) !== 'admin' || $actorId === false) {
        throw new DomainException('Only an administrator can manage school years.');
    }
    if ($db->inTransaction()) throw new LogicException('School-year save owns its transaction.');
    $label = $input['label'] ?? null;
    if (!is_string($label)) throw new InvalidArgumentException('School-year label is required.');
    schoolYearLabelStart($label);
    $start = validatedSchoolYearDate($input['starts_on'] ?? null);
    $end = validatedSchoolYearDate($input['ends_on'] ?? null);
    if ($start > $end) throw new InvalidArgumentException('School-year start must not follow its end.');
    $id = $input['id'] ?? null;
    $version = $input['expected_version'] ?? null;
    if ($id !== null) {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $version = filter_var($version, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || $version === false) throw new InvalidArgumentException('Year edits require a valid ID and expected version.');
    } elseif ($version !== null) {
        throw new InvalidArgumentException('New school years do not have an expected version.');
    }

    $db->beginTransaction();
    try {
        // Serialize competing inserts/edits even when the year table is empty.
        if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) {
            throw new DomainException('School-year control is not initialized.');
        }
        $years = $db->query('SELECT id,label,starts_on,ends_on,state,version FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $activeCount = 0;
        $before = null;
        foreach ($years as $year) {
            $year['id'] = (int)$year['id'];
            $year['version'] = (int)$year['version'];
            if ($year['state'] === 'active') $activeCount++;
            if ($id === $year['id']) {
                $before = $year;
                continue;
            }
            if ($year['label'] === $label) throw new DomainException('School-year label already exists.');
            if ($start <= $year['ends_on'] && $end >= $year['starts_on']) {
                throw new DomainException('School-year dates overlap an existing year.');
            }
        }
        if ($activeCount > 1 || ($activeCount !== 1 && enrollmentModelEnabled($db))) {
            throw new DomainException('Converted enrollment mode requires exactly one active school year.');
        }
        if ($id !== null && (!$before || $before['state'] !== 'planned' || $before['version'] !== $version)) {
            throw new DomainException('School year is missing, no longer planned, or changed since it was opened.');
        }
        if ($before !== null) {
            // The control/year locks serialize structural changes. Lock dependent
            // entities in the shared section/profile/enrollment/assignment order
            // before validating their current boundaries or performing any DML.
            $sectionsRead = $db->prepare('SELECT id FROM sections WHERE school_year_id=? ORDER BY id FOR UPDATE');
            $sectionsRead->execute([$id]);
            $sectionIds = $sectionsRead->fetchAll(PDO::FETCH_COLUMN);
            $profilesRead = $db->prepare('SELECT s.id FROM students s WHERE EXISTS (SELECT 1 FROM student_enrollments e WHERE e.student_id=s.id AND e.school_year_id=?) ORDER BY s.id FOR UPDATE');
            $profilesRead->execute([$id]);
            $profilesRead->fetchAll(PDO::FETCH_COLUMN);
            $enrollmentsRead = $db->prepare('SELECT id,starts_on,ends_on FROM student_enrollments WHERE school_year_id=? ORDER BY id FOR UPDATE');
            $enrollmentsRead->execute([$id]);
            $enrollments = $enrollmentsRead->fetchAll(PDO::FETCH_ASSOC);
            $assignmentsRead = $db->prepare('SELECT a.id,a.starts_on,a.ends_on FROM enrollment_sections a WHERE a.enrollment_id IN (SELECT e.id FROM student_enrollments e WHERE e.school_year_id=?) ORDER BY a.id FOR UPDATE');
            $assignmentsRead->execute([$id]);
            $assignments = $assignmentsRead->fetchAll(PDO::FETCH_ASSOC);
            if ($before['label'] !== $label && ($sectionIds || $enrollments)) {
                throw new DomainException('A referenced school-year label cannot change. Keep the existing label.');
            }
            foreach ([$enrollments,$assignments] as $records) foreach ($records as $record) {
                if ($record['starts_on'] < $start || $record['ends_on'] > $end) {
                    throw new DomainException('School-year dates must contain every existing enrollment and section assignment.');
                }
            }
        }
        if ($id === null) {
            $db->prepare("INSERT INTO school_years (label,starts_on,ends_on,state) VALUES (?,?,?,'planned')")->execute([$label,$start,$end]);
            $id = (int)$db->lastInsertId();
            $nextVersion = 1;
        } else {
            $db->prepare('UPDATE school_years SET label=?,starts_on=?,ends_on=?,version=version+1 WHERE id=?')->execute([$label,$start,$end,$id]);
            $nextVersion = $version + 1;
        }
        $after = ['id'=>$id,'label'=>$label,'starts_on'=>$start,'ends_on'=>$end,'state'=>'planned','version'=>$nextVersion];
        securityAuditContext((int)$actorId);
        securityAuditChange($db, $before === null ? 'school_year.create' : 'school_year.update', 'school_year', $id, $before, $after);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function requireSchoolYearActivationAdmin(PDO $db, array $actor, bool $lock=false): void
{
    if (($actor['role'] ?? '')!=='admin' || (int)($actor['id'] ?? 0)<=0) throw new DomainException('School-year activation requires an administrator.');
    $q=$db->prepare('SELECT role,is_active,session_token FROM users WHERE id=?'.($lock?' LOCK IN SHARE MODE':''));
    $q->execute([(int)$actor['id']]); $user=$q->fetch(PDO::FETCH_ASSOC);
    if (!$user || $user['role']!=='admin' || !(int)$user['is_active']) throw new DomainException('Administrator access has changed.');
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id']===(int)$actor['id'] && (!is_string($_SESSION['session_token'] ?? null) || !is_string($user['session_token']) || !hash_equals($user['session_token'],$_SESSION['session_token']))) throw new DomainException('Your session has changed. Sign in again.');
}

/** Caller owns transaction. Conservative complete structural prefix avoids lock inversions
 * with scans (shared years/sections), backfill, corrections and moving-up preparation.
 * No consistent reads until these locks are held: a scan finishing while we wait on
 * its shared year lock must be included in readiness and its legacy fingerprint.
 */
function lockSchoolYearActivationRows(PDO $db, array $actor): array
{
    if (!$db->inTransaction()) throw new LogicException('Activation locks require a transaction.');
    if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) throw new DomainException('School-year control is not initialized.');
    $years=$db->query('SELECT id,label,starts_on,ends_on,state,version FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $sections=$db->query('SELECT id,school_year_id,school_year,grade_level,is_active,adviser_id FROM sections ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $students=$db->query('SELECT id,is_active,enrollment_status,section_id,grade_level,school_year FROM students ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $enrollments=$db->query('SELECT id,student_id,school_year_id,state,starts_on,ends_on,version FROM student_enrollments ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $assignments=$db->query('SELECT id,enrollment_id,section_id,grade_level,starts_on,ends_on,version FROM enrollment_sections ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $attendance=$db->query('SELECT id,student_id,date,enrollment_id,assignment_id FROM attendance ORDER BY student_id,date,id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $decisions=$db->query('SELECT id,batch_id,source_enrollment_id,destination_enrollment_id,target_year_id,outcome,state,version FROM moving_up_decisions ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $users=$db->query('SELECT id,role,is_active FROM users ORDER BY id LOCK IN SHARE MODE')->fetchAll(PDO::FETCH_ASSOC);
    $settings=$db->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ('enrollment_model_version','school_year') ORDER BY setting_key FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
    if ((array_column($settings,'setting_value','setting_key')['enrollment_model_version'] ?? null)!=='1') throw new DomainException('Annual enrollment must already be enabled. Initial cutover is a separate operation.');
    requireSchoolYearActivationAdmin($db,$actor,true);
    return compact('years','sections','students','enrollments','assignments','attendance','decisions','users','settings');
}

/** Private locked snapshot: output contains IDs and reason codes, never identity/contact data. */
function schoolYearActivationSnapshot(PDO $db, array $locked, int $targetYearId): array
{
    require_once __DIR__.'/enrollment_migration.php';
    $years=array_column($locked['years'],null,'id'); $target=$years[$targetYearId] ?? null;
    $active=array_values(array_filter($years,fn($y)=>$y['state']==='active'));
    if (count($active)!==1 || !$target || $target['state']!=='planned' || $target['starts_on']<=$active[0]['ends_on']) throw new DomainException('Choose a later planned school year with verified dates.');
    $source=$active[0]; $blocking=[]; $historical=[]; $followUp=[];
    $add=function(string $type,int $id,string $code) use (&$blocking): void { $blocking[$type.':'.$id.':'.$code]=['record_type'=>$type,'record_id'=>$id,'code'=>$code]; };
    $students=array_column($locked['students'],null,'id'); $sections=array_column($locked['sections'],null,'id'); $enrollments=array_column($locked['enrollments'],null,'id');
    $futureOnly=[];
    foreach($locked['enrollments'] as $e) if ((int)$e['school_year_id']===$targetYearId && $e['state']==='prepared') {
        $student=$students[$e['student_id']] ?? null;
        // Task5 clears current placement for explicit future-only entrants. A live
        // outgoing projection/annual row/scan is still operational mapping evidence.
        if ($student && $student['section_id']===null && $student['grade_level']===null && $student['school_year']===null) $futureOnly[(int)$e['student_id']]=true;
    }
    foreach($locked['enrollments'] as $e) if ((int)$e['school_year_id']===(int)$source['id']) unset($futureOnly[(int)$e['student_id']]);
    foreach($locked['attendance'] as $a) if ($a['date']>=$source['starts_on'] && $a['date']<=$source['ends_on']) unset($futureOnly[(int)$a['student_id']]);
    $readiness=[];
    foreach ([(int)$source['id'],$targetYearId] as $yearId) {
        $ready=enrollmentMigrationReadiness($db,$yearId);
        $readiness[$yearId]=['fingerprint'=>$ready['fingerprint'],'blocking_issues'=>$ready['blocking_issues']];
        foreach($ready['blocking_issues'] as $issue) {
            if ($yearId===(int)$source['id'] && $issue['record_type']==='student' && $issue['code']==='missing_current_assignment' && isset($futureOnly[(int)$issue['record_id']])) continue;
            $add($issue['record_type'],(int)$issue['record_id'],$issue['code']);
        }
        foreach($ready['historical_issues'] as $issue) $historical[$issue['record_type'].':'.$issue['record_id'].':'.$issue['code']]=$issue;
    }
    $counts=['prepared'=>0,'promoted'=>0,'retained'=>0,'graduated'=>0,'unassigned'=>0,'cancelled'=>0,'outgoing'=>0]; $destinations=[]; $decided=[];
    foreach($locked['enrollments'] as $e) {
        if ((int)$e['school_year_id']===(int)$source['id'] && $e['state']!=='cancelled') $counts['outgoing']++;
        if ((int)$e['school_year_id']!==$targetYearId) continue;
        if ($e['state']==='cancelled') { $counts['cancelled']++; continue; }
        if ($e['state']!=='prepared') { $add('enrollment',(int)$e['id'],'target_enrollment_not_prepared'); continue; }
        $counts['prepared']++; $destinations[(int)$e['student_id']]=true;
        if (!(int)($students[$e['student_id']]['is_active'] ?? 0)) $add('enrollment',(int)$e['id'],'prepared_profile_unavailable');
        if ($e['starts_on']<$target['starts_on'] || $e['ends_on']>$target['ends_on'] || $e['starts_on']>$e['ends_on']) $add('enrollment',(int)$e['id'],'invalid_prepared_dates');
        $rows=array_values(array_filter($locked['assignments'],fn($a)=>(int)$a['enrollment_id']===(int)$e['id']));
        usort($rows,fn($a,$b)=>strcmp($a['starts_on'],$b['starts_on'])); $previous=null;
        if (!$rows) $add('enrollment',(int)$e['id'],'missing_prepared_assignment');
        foreach($rows as $a) {
            $section=$sections[$a['section_id']] ?? null;
            if (!$section || !(int)$section['is_active'] || (int)$section['school_year_id']!==$targetYearId || $section['grade_level']!==$a['grade_level'] || $a['starts_on']<$e['starts_on'] || $a['ends_on']>$e['ends_on'] || $a['starts_on']>$a['ends_on']) $add('assignment',(int)$a['id'],'invalid_prepared_assignment');
            if ($previous!==null && $a['starts_on']<=$previous) $add('enrollment',(int)$e['id'],'overlapping_prepared_assignments');
            $previous=max($previous ?? $a['ends_on'],$a['ends_on']);
        }
    }
    foreach($locked['decisions'] as $d) {
        if ((int)$d['target_year_id']!==$targetYearId || $d['state']==='cancelled') continue;
        $e=$enrollments[$d['source_enrollment_id']] ?? null; $destination=$enrollments[$d['destination_enrollment_id']] ?? null;
        $sourceYear=$e ? ($years[$e['school_year_id']] ?? null) : null;
        if ($d['state']!=='prepared' || !$e || !in_array($e['state'],['active','completed'],true) || !$sourceYear || !in_array($sourceYear['state'],['active','closed'],true) || $sourceYear['ends_on']>=$target['starts_on']) $add('decision',(int)$d['id'],'invalid_prepared_decision');
        $final=[];
        if ($e && $sourceYear) {
            if (!(int)($students[$e['student_id']]['is_active'] ?? 0)) $add('decision',(int)$d['id'],'decision_profile_unavailable');
            $end=min($e['ends_on'],$sourceYear['ends_on']);
            $history=array_values(array_filter($locked['assignments'],fn($a)=>(int)$a['enrollment_id']===(int)$e['id']));
            usort($history,fn($a,$b)=>strcmp($a['starts_on'],$b['starts_on'])); $previous=null;
            foreach($history as $a) {
                $section=$sections[$a['section_id']] ?? null;
                if (!$section || (int)$section['school_year_id']!==(int)$sourceYear['id'] || $section['grade_level']!==$a['grade_level'] || $a['starts_on']>$a['ends_on'] || ($previous!==null && $a['starts_on']<=$previous)) $add('decision',(int)$d['id'],'invalid_decision_source_history');
                $previous=max($previous ?? $a['ends_on'],$a['ends_on']);
                if ($a['starts_on']<=$end && $a['ends_on']>=$end) $final[]=$a;
            }
            if (count($final)!==1 || max($e['starts_on'],$sourceYear['starts_on'])>$end) $add('decision',(int)$d['id'],'invalid_decision_final_placement');
        }
        if ($d['outcome']==='graduated') {
            if ($d['destination_enrollment_id']!==null || ($e && isset($destinations[(int)$e['student_id']]))) $add('decision',(int)$d['id'],'graduation_has_destination');
            if (count($final)!==1 || $final[0]['grade_level']!=='Grade 6') $add('decision',(int)$d['id'],'graduation_requires_grade_6');
        } elseif (!$destination || !$e || (int)$destination['student_id']!==(int)$e['student_id'] || (int)$destination['school_year_id']!==$targetYearId || $destination['state']!=='prepared') $add('decision',(int)$d['id'],'decision_destination_unavailable');
        elseif (count($final)===1) {
            $grades=['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'];
            $grade=array_search($final[0]['grade_level'],$grades,true); $wanted=$grade===false?null:($grades[$grade+($d['outcome']==='promoted'?1:0)] ?? null);
            foreach($locked['assignments'] as $a) if ((int)$a['enrollment_id']===(int)$destination['id'] && $a['grade_level']!==$wanted) $add('decision',(int)$d['id'],'decision_destination_grade_changed');
        }
        $counts[$d['outcome']]++; $decided[(int)$d['source_enrollment_id']]=true;
    }
    foreach($locked['enrollments'] as $e) {
        if ((int)$e['school_year_id']===(int)$source['id'] && $e['state']!=='cancelled' && !isset($destinations[(int)$e['student_id']]) && !isset($decided[(int)$e['id']])) {
            $followUp[]=['student_id'=>(int)$e['student_id'],'enrollment_id'=>(int)$e['id'],'code'=>'no_prepared_destination_or_outcome'];
        }
    }
    $counts['unassigned']=count($followUp); ksort($blocking,SORT_STRING); ksort($historical,SORT_STRING);
    // Explicit domain fields and integer versions; no connection-local TIMESTAMP strings.
    $fingerprint=hash('sha256',json_encode([$targetYearId,date('Y-m-d'),$locked,$readiness,$blocking,$followUp],JSON_THROW_ON_ERROR));
    return ['ready'=>!$blocking,'blocking_issues'=>array_values($blocking),'follow_up'=>$followUp,'counts'=>$counts,'fingerprint'=>$fingerprint,'source_year'=>$source,'target_year'=>$target,'historical_issues'=>array_values($historical)];
}

function schoolYearActivationPreview(PDO $db, array $actor, int $targetYearId): array
{
    if ($db->inTransaction()) throw new LogicException('Activation preview owns its transaction.');
    requireSchoolYearActivationAdmin($db,$actor);
    $db->beginTransaction();
    try { $snapshot=schoolYearActivationSnapshot($db,lockSchoolYearActivationRows($db,$actor),$targetYearId); $db->commit(); return $snapshot; }
    catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
}

function activateSchoolYear(PDO $db, array $actor, int $targetYearId, string $expectedFingerprint): void
{
    require_once __DIR__.'/enrollment_commands.php';
    if ($db->inTransaction()) throw new LogicException('School-year activation owns its transaction.');
    requireSchoolYearActivationAdmin($db,$actor);
    $db->beginTransaction();
    try {
        $locked=lockSchoolYearActivationRows($db,$actor); $snapshot=schoolYearActivationSnapshot($db,$locked,$targetYearId);
        if (!hash_equals($snapshot['fingerprint'],$expectedFingerprint)) throw new DomainException('The activation preview is stale. Review readiness again.');
        if (!$snapshot['ready']) throw new DomainException('Resolve blocking enrollment mappings before activation.');
        securityAuditContext((int)$actor['id']);
        foreach([$snapshot['source_year'],$snapshot['target_year']] as $year) {
            $state=(int)$year['id']===$targetYearId?'active':'closed';
            $db->prepare('UPDATE school_years SET state=?,version=version+1 WHERE id=?')->execute([$state,$year['id']]);
            $after=$year; $after['state']=$state; $after['version']=(int)$year['version']+1;
            securityAuditChange($db,'school_year.'.$state,'school_year',(int)$year['id'],$year,$after);
        }
        foreach($locked['enrollments'] as $e) {
            $state=null;
            if ((int)$e['school_year_id']===(int)$snapshot['source_year']['id'] && in_array($e['state'],['active','prepared'],true)) $state='completed';
            if ((int)$e['school_year_id']===$targetYearId && $e['state']==='prepared') $state='active';
            if ($state===null) continue;
            $db->prepare('UPDATE student_enrollments SET state=?,version=version+1 WHERE id=?')->execute([$state,$e['id']]);
            $after=$e; $after['state']=$state; $after['version']=(int)$e['version']+1;
            securityAuditChange($db,'enrollment.'.$state,'enrollment',(int)$e['id'],$e,$after);
        }
        foreach($locked['decisions'] as $d) {
            if ((int)$d['target_year_id']!==$targetYearId || $d['state']!=='prepared') continue;
            $db->prepare("UPDATE moving_up_decisions SET state='applied',version=version+1 WHERE id=?")->execute([$d['id']]);
            $after=$d; $after['state']='applied'; $after['version']=(int)$d['version']+1;
            securityAuditChange($db,'moving_up.apply','enrollment',(int)$d['source_enrollment_id'],$d,$after);
        }
        // Clear obsolete caches as well as projecting eligible destinations. Profiles and
        // placements remain the same records; graduation lives in applied decision metadata.
        foreach($locked['students'] as $student) {
            updateCurrentEnrollmentProjection($db,(int)$student['id']);
            $q=$db->prepare('SELECT id,is_active,enrollment_status,section_id,grade_level,school_year FROM students WHERE id=?'); $q->execute([$student['id']]); $after=$q->fetch(PDO::FETCH_ASSOC);
            if ($student!==$after) securityAuditChange($db,'enrollment.project','student',(int)$student['id'],$student,$after);
        }
        $before=array_column($locked['settings'],'setting_value','setting_key')['school_year'] ?? null;
        $db->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('school_year',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$snapshot['target_year']['label']]);
        securityAuditChange($db,'school_year.activate','school_year',$targetYearId,['school_year'=>$before],['school_year'=>$snapshot['target_year']['label'],'counts'=>$snapshot['counts'],'follow_up'=>$snapshot['follow_up']]);
        $db->commit();
    } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
}
