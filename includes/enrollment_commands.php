<?php
declare(strict_types=1);
require_once __DIR__ . '/enrollment_access.php';

/** Transaction-internal structural lock prefix. Public commands never nest. */
function lockAnnualEnrollmentDestination(PDO $db, array $actor, int $yearId, int $sectionId, string $start, string $end): array
{
    if (!$db->inTransaction()) throw new LogicException('Enrollment locks require a transaction.');
    if (!in_array($actor['role'] ?? '', ['admin','teacher'], true) || (int)($actor['id'] ?? 0) <= 0) throw new DomainException('Enrollment creation access denied.');
    if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) throw new DomainException('School-year control is not initialized.');
    if (!enrollmentModelEnabled($db)) throw new DomainException('Annual enrollment is not enabled.');
    $years = $db->query('SELECT * FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $year = null; $activeCount = 0;
    foreach ($years as $row) { if ((int)$row['id'] === $yearId) $year = $row; if ($row['state'] === 'active') $activeCount++; }
    if ($activeCount !== 1 || !$year || !in_array($year['state'], ['planned','active'], true)) throw new DomainException('Choose an open managed school year.');
    if ($start > $end || $start < $year['starts_on'] || $end > $year['ends_on']) throw new DomainException('Enrollment dates must be ordered within the selected school year.');
    $q = $db->prepare('SELECT * FROM sections WHERE id=? FOR UPDATE'); $q->execute([$sectionId]); $section = $q->fetch(PDO::FETCH_ASSOC);
    if (!$section || !(int)$section['is_active'] || (int)$section['school_year_id'] !== $yearId) throw new DomainException('Choose a live section in the selected school year.');
    // This locking read is current, even if a prior page read saw a former adviser.
    if ($actor['role'] === 'teacher' && (int)$section['adviser_id'] !== (int)$actor['id']) throw new DomainException('Section access has changed. Choose an assigned section.');
    securityAuditContext((int)$actor['id']);
    return [$year,$section];
}

/** Caller owns locks on control, years, section and profile, in that order. */
function insertAnnualEnrollmentLocked(PDO $db, int $studentId, array $year, array $section, string $start, string $end): int
{
    if (!$db->inTransaction()) throw new LogicException('Enrollment insert requires a transaction.');
    $q = $db->prepare('SELECT id FROM student_enrollments WHERE student_id=? ORDER BY id FOR UPDATE'); $q->execute([$studentId]); $q->fetchAll();
    $q = $db->prepare('SELECT id FROM student_enrollments WHERE student_id=? AND school_year_id=? FOR UPDATE'); $q->execute([$studentId,$year['id']]);
    if ($q->fetchColumn()) throw new DomainException('This student already has an enrollment in that school year. Use the enrollment correction flow for changes.');
    $state = $year['state'] === 'active' ? 'active' : 'prepared';
    $db->prepare('INSERT INTO student_enrollments(student_id,school_year_id,state,starts_on,ends_on) VALUES (?,?,?,?,?)')->execute([$studentId,$year['id'],$state,$start,$end]);
    $id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (?,?,?,?,?)')->execute([$id,$section['id'],$section['grade_level'],$start,$end]);
    $assignmentId = (int)$db->lastInsertId();
    updateCurrentEnrollmentProjection($db,$studentId);
    securityAuditChange($db,'enrollment.create','enrollment',$id,null,['student_id'=>$studentId,'school_year_id'=>(int)$year['id'],'section_id'=>(int)$section['id'],'assignment_id'=>$assignmentId,'state'=>$state,'starts_on'=>$start,'ends_on'=>$end]);
    return $id;
}

/** Public command owns its transaction. Existing annual enrollments cannot be moved here. */
function createAnnualEnrollment(PDO $db, array $actor, int $studentId, int $yearId, int $sectionId, string $startsOn, string $endsOn): int
{
    if ($db->inTransaction()) throw new LogicException('Annual enrollment creation owns its transaction.');
    $start = validatedSchoolYearDate($startsOn); $end = validatedSchoolYearDate($endsOn);
    $db->beginTransaction();
    try {
        [$year,$section] = lockAnnualEnrollmentDestination($db,$actor,$yearId,$sectionId,$start,$end);
        $q = $db->prepare('SELECT id,is_active FROM students WHERE id=? FOR UPDATE'); $q->execute([$studentId]); $student = $q->fetch(PDO::FETCH_ASSOC);
        if (!$student || !(int)$student['is_active']) throw new DomainException('Student is unavailable for enrollment.');
        $id = insertAnnualEnrollmentLocked($db,$studentId,$year,$section,$start,$end);
        $db->commit(); return $id;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** A new identity and its first placement commit together. QR/photo values are supplied by the existing adapter. */
function createStudentWithEnrollment(PDO $db, array $actor, array $profile, int $yearId, int $sectionId, string $startsOn, string $endsOn): int
{
    if ($db->inTransaction()) throw new LogicException('Student creation owns its transaction.');
    $start = validatedSchoolYearDate($startsOn); $end = validatedSchoolYearDate($endsOn);
    foreach (['lrn','first_name','last_name','gender','qr_token'] as $key) if (!is_string($profile[$key] ?? null) || trim($profile[$key]) === '') throw new InvalidArgumentException('Required student information is missing.');
    if (!in_array($profile['gender'], ['Male','Female'], true)) throw new InvalidArgumentException('Invalid student gender.');
    $db->beginTransaction();
    try {
        [$year,$section] = lockAnnualEnrollmentDestination($db,$actor,$yearId,$sectionId,$start,$end);
        $q = $db->prepare('SELECT id FROM students WHERE lrn=? FOR UPDATE'); $q->execute([$profile['lrn']]);
        if ($q->fetchColumn()) throw new DomainException('This LRN already exists. Use Returning Student Enrollment; contact an administrator if the profile is unavailable.');
        $fields = ['lrn','first_name','middle_name','last_name','gender','birth_date','address','photo','qr_token','parent_name','parent_contact','parent_email'];
        $values = []; foreach ($fields as $key) $values[] = $profile[$key] ?? ($key === 'birth_date' ? null : ($key === 'photo' ? 'default.png' : ''));
        $db->prepare('INSERT INTO students (' . implode(',',$fields) . ') VALUES (' . implode(',',array_fill(0,count($fields),'?')) . ')')->execute($values);
        $id = (int)$db->lastInsertId();
        insertAnnualEnrollmentLocked($db,$id,$year,$section,$start,$end);
        $db->commit(); return $id;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** Transaction-internal projection only; caller owns the structural locks and all profile/enrollment changes. */
function updateCurrentEnrollmentProjection(PDO $db, int $studentId): void
{
    if (!$db->inTransaction()) throw new LogicException('Projection update requires a caller-owned transaction.');
    if (!enrollmentModelEnabled($db)) throw new DomainException('Annual enrollment is not enabled.');
    $q = $db->prepare("SELECT es.section_id,es.grade_level,y.label FROM school_years y
      JOIN student_enrollments e ON e.school_year_id=y.id AND e.state='active'
      JOIN enrollment_sections es ON es.enrollment_id=e.id
      WHERE y.state='active' AND e.student_id=? AND ? BETWEEN e.starts_on AND e.ends_on AND ? BETWEEN es.starts_on AND es.ends_on
      ORDER BY es.id");
    $today = date('Y-m-d'); $q->execute([$studentId,$today,$today]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) throw new DomainException('Overlapping enrollment projection.');
    $row = $rows[0] ?? null;
    $db->prepare('UPDATE students SET section_id=?,grade_level=?,school_year=? WHERE id=?')->execute([$row['section_id'] ?? null,$row['grade_level'] ?? null,$row['label'] ?? null,$studentId]);
}

/** Managed section writes share the structural lock order used by enrollment creation. */
function saveManagedEnrollmentSection(PDO $db, array $actor, array $input): int
{
    if ($db->inTransaction()) throw new LogicException('Section save owns its transaction.');
    if (!in_array($actor['role'] ?? '', ['admin','teacher'], true) || (int)($actor['id'] ?? 0) <= 0) throw new DomainException('Section editing access denied.');
    $db->beginTransaction();
    try {
        if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn() || !enrollmentModelEnabled($db)) throw new DomainException('Annual enrollment is not enabled.');
        $years = $db->query('SELECT * FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $id = (int)($input['id'] ?? 0); $before = null;
        if ($id > 0) {
            $q = $db->prepare('SELECT * FROM sections WHERE id=? FOR UPDATE'); $q->execute([$id]); $before = $q->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new DomainException('Section is unavailable.');
        }
        if ($actor['role'] === 'teacher') {
            if (!$before || !(int)$before['is_active'] || (int)$before['adviser_id'] !== (int)$actor['id']) throw new DomainException('Section access has changed.');
            foreach (['section_name','grade_level','adviser_id','school_year_id'] as $field) $input[$field] = $before[$field];
        }
        $year = null; foreach ($years as $row) if ((int)$row['id'] === (int)($input['school_year_id'] ?? 0)) $year = $row;
        if (!$year || $year['state'] === 'closed') throw new DomainException('Choose an open managed school year.');
        if ($before) {
            foreach ($years as $row) if ((int)$row['id'] === (int)$before['school_year_id'] && $row['state'] === 'closed') throw new DomainException('Closed-year sections are read-only.');
            $q = $db->prepare('SELECT id FROM enrollment_sections WHERE section_id=? LIMIT 1'); $q->execute([$id]);
            if ($q->fetchColumn() && ((int)$before['school_year_id'] !== (int)$year['id'] || $before['grade_level'] !== $input['grade_level'])) throw new DomainException('A section with enrollment history cannot change year or grade. Create a distinct section.');
        }
        $input['school_year'] = $year['label'];
        $fields = ['section_name','grade_level','schedule_type','adviser_id','school_year','school_year_id','am_in_start','am_in_end','am_late_threshold','am_out_start','am_out_end','pm_in_start','pm_in_end','pm_late_threshold','pm_out_start','pm_out_end'];
        $values = []; foreach ($fields as $field) $values[] = $input[$field] ?? null;
        if ($before) {
            $db->prepare('UPDATE sections SET ' . implode(',',array_map(fn($field)=>$field.'=?',$fields)) . ' WHERE id=?')->execute(array_merge($values,[$id]));
        } else {
            $db->prepare('INSERT INTO sections (' . implode(',',$fields) . ') VALUES (' . implode(',',array_fill(0,count($fields),'?')) . ')')->execute($values);
            $id = (int)$db->lastInsertId();
        }
        securityAuditContext((int)$actor['id']);
        securityAuditChange($db,$before ? 'section.update' : 'section.create','route',$id,$before,array_intersect_key($input,array_flip($fields)));
        $db->commit(); return $id;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** Atomic adviser-only batch. Structural locks prevent scans and historical authorization
 * from racing reassignment. Every section/adviser is validated before the first update.
 */
function saveEnrollmentSectionAdvisers(PDO $db, array $actor, array $assignments): void
{
    if ($db->inTransaction()) throw new LogicException('Adviser assignment owns its transaction.');
    if (($actor['role'] ?? '')!=='admin' || (int)($actor['id'] ?? 0)<1) throw new DomainException('Administrator access required.');
    $changes=[];
    foreach ($assignments as $sectionId=>$adviserId) {
        $id=filter_var($sectionId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $adviser=$adviserId===null || $adviserId==='' ? null : filter_var($adviserId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || $adviser===false) throw new InvalidArgumentException('Invalid adviser assignment.');
        $changes[$id]=$adviser;
    }
    ksort($changes,SORT_NUMERIC);
    $db->beginTransaction();
    try {
        if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn() || !enrollmentModelEnabled($db)) throw new DomainException('Annual enrollment is not enabled.');
        $years=[]; foreach ($db->query('SELECT id,state FROM school_years ORDER BY id FOR UPDATE')->fetchAll() as $row) $years[(int)$row['id']]=$row['state'];
        $sections=[];
        $select=$db->prepare('SELECT id,school_year_id,adviser_id FROM sections WHERE id=? FOR UPDATE');
        foreach ($changes as $id=>$adviser) {
            $select->execute([$id]); $section=$select->fetch();
            if (!$section || !isset($years[(int)$section['school_year_id']]) || $years[(int)$section['school_year_id']]==='closed') throw new DomainException('Closed-year sections are read-only.');
            $sections[$id]=$section;
        }
        $users=array_unique(array_merge([(int)$actor['id']],array_values(array_filter($changes,fn($id)=>$id!==null)))); sort($users,SORT_NUMERIC);
        $select=$db->prepare('SELECT role,is_active FROM users WHERE id=? LOCK IN SHARE MODE');
        foreach ($users as $id) {
            $select->execute([$id]); $user=$select->fetch();
            if (!$user || (int)$user['is_active']!==1 || !in_array($user['role'],['admin','teacher'],true)
                || $id===(int)$actor['id'] && $user['role']!=='admin') throw new DomainException('Choose an active authorized adviser.');
        }
        securityAuditContext((int)$actor['id']);
        $update=$db->prepare('UPDATE sections SET adviser_id=? WHERE id=?');
        foreach ($changes as $id=>$adviser) {
            $before=$sections[$id]['adviser_id']===null ? null : (int)$sections[$id]['adviser_id'];
            if ($before===$adviser) continue;
            $update->execute([$adviser,$id]);
            securityAuditChange($db,'section.adviser','route',$id,['adviser_id'=>$before],['adviser_id'=>$adviser,'school_year_id'=>(int)$sections[$id]['school_year_id']]);
        }
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
