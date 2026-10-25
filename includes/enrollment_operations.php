<?php
declare(strict_types=1);
require_once __DIR__ . '/enrollment_access.php';

/** Transaction-internal. Resolve before any attendance row lock; scanner access
 * is deliberately separate from the staff/report authorization contract. */
function lockAttendanceEnrollment(PDO $db, int $studentId, string $date, ?array $actor = null, ?int $sectionId = null): array
{
    if (!$db->inTransaction()) throw new LogicException('Attendance ownership requires a transaction.');
    validatedSchoolYearDate($date);
    if ($date > date('Y-m-d')) throw new DomainException('Future attendance cannot be recorded.');
    $ctx = $actor === null ? getEnrollmentContext($db,$studentId,$date,'write',true)
        : requireEnrollmentWrite($db,$actor,$studentId,$date);
    if (!$ctx || ($sectionId !== null && $ctx['section_id'] !== $sectionId)) throw new DomainException('No eligible enrollment in the selected section.');
    $q=$db->prepare('SELECT * FROM sections WHERE id=? LOCK IN SHARE MODE');
    $q->execute([$ctx['section_id']]);
    return ['context'=>$ctx,'section'=>$q->fetch(PDO::FETCH_ASSOC)];
}

/** Transaction-internal structural prefix for a multi-student attendance batch.
 * All years/sections precede all profiles/enrollments/assignments and attendance.
 * Re-resolution after these locks rejects revoked or moved ownership. */
function lockAttendanceEnrollmentBatch(PDO $db, array $studentIds, string $date, array $actor, ?int $sectionId = null): array
{
    if (!$db->inTransaction()) throw new LogicException('Attendance batch requires a transaction.');
    $ids=array_values(array_unique(array_map('intval',$studentIds))); sort($ids,SORT_NUMERIC);
    $sets=['school_years'=>[],'sections'=>[],'students'=>$ids,'student_enrollments'=>[],'enrollment_sections'=>[]];
    foreach ($ids as $id) {
        $ctx=getEnrollmentContext($db,$id,$date,'write');
        if (!$ctx) throw new DomainException('No eligible enrollment for this date.');
        $sets['school_years'][]=$ctx['school_year_id']; $sets['sections'][]=$ctx['section_id'];
        $sets['student_enrollments'][]=$ctx['enrollment_id'];
    }
    foreach ($sets as $table=>$keys) {
        if ($table==='enrollment_sections') {
            $keys=$sets['student_enrollments'];
            if (!$keys) continue;
            $q=$db->prepare('SELECT id FROM enrollment_sections WHERE enrollment_id IN ('.implode(',',array_fill(0,count($keys),'?')).') ORDER BY id FOR UPDATE');
        } else {
            $keys=array_values(array_unique($keys)); sort($keys,SORT_NUMERIC);
            if (!$keys) continue;
            $q=$db->prepare("SELECT id FROM $table WHERE id IN (".implode(',',array_fill(0,count($keys),'?')).') ORDER BY id'.(in_array($table,['school_years','sections'],true)?' LOCK IN SHARE MODE':' FOR UPDATE'));
        }
        $q->execute($keys); $q->fetchAll();
    }
    $result=[];
    foreach ($ids as $id) $result[$id]=lockAttendanceEnrollment($db,$id,$date,$actor,$sectionId);
    return $result;
}

/** Never silently map an existing unowned/mismatched record in converted mode. */
function verifyAttendanceEnrollment(array $row, array $ctx): void
{
    if ((int)($row['enrollment_id']??0)!==$ctx['enrollment_id'] || (int)($row['assignment_id']??0)!==$ctx['assignment_id']) {
        throw new DomainException('Attendance ownership requires reviewed correction.');
    }
}

/** Read-only operational roster, annual ownership only. Returns profile and
 * schedule fields using the dated assignment, never the placement projection. */
function enrollmentOperationalRows(PDO $db, string $date, ?int $sectionId = null, ?array $actor = null): array
{
    validatedSchoolYearDate($date);
    $where=''; $params=[$date,$date,$date];
    if ($sectionId!==null) { $where.=' AND sec.id=?'; $params[]=$sectionId; }
    if ($actor!==null) {
        [$scope,$scopeParams]=enrollmentSectionScope('sec',$actor);
        $where.=" AND ($scope)"; $params=array_merge($params,$scopeParams);
    }
    $q=$db->prepare("SELECT s.*,s.id AS student_id,sec.section_name,sec.id AS section_id,
        sec.grade_level,sec.school_year,sec.schedule_type,sec.am_in_start,sec.am_in_end,sec.am_late_threshold,
        sec.am_out_start,sec.am_out_end,sec.pm_in_start,sec.pm_in_end,sec.pm_late_threshold,sec.pm_out_start,sec.pm_out_end,
        s.created_at AS student_created_at,sec.created_at AS section_created_at,
        e.id AS enrollment_id,es.id AS assignment_id
        FROM student_enrollments e JOIN school_years y ON y.id=e.school_year_id
        JOIN enrollment_sections es ON es.enrollment_id=e.id JOIN sections sec ON sec.id=es.section_id
        JOIN students s ON s.id=e.student_id
        WHERE y.state='active' AND e.state='active' AND s.is_active=1 AND sec.is_active=1
        AND sec.school_year_id=y.id AND sec.grade_level=es.grade_level
        AND ? BETWEEN y.starts_on AND y.ends_on AND ? BETWEEN e.starts_on AND e.ends_on
        AND ? BETWEEN es.starts_on AND es.ends_on$where ORDER BY sec.section_name,s.last_name,s.first_name");
    $q->execute($params); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $seen=[];
    foreach ($rows as $row) {
        if (isset($seen[$row['student_id']])) throw new DomainException('Ambiguous operational enrollment.');
        $seen[$row['student_id']]=true;
    }
    return $rows;
}

/** Current card/scan profile with dated section fields; caller authenticates role. */
function enrollmentOperationalStudent(PDO $db, int $id, string $date, ?array $actor = null): ?array
{
    $ctx=getEnrollmentContext($db,$id,$date,'scan');
    if (!$ctx) return null;
    if ($actor!==null) {
        [$scope,$params]=enrollmentSectionScope('sec',$actor);
        $q=$db->prepare("SELECT sec.id FROM sections sec WHERE sec.id=? AND ($scope)");
        $q->execute(array_merge([$ctx['section_id']],$params));
        if (!$q->fetchColumn()) return null;
    }
    $q=$db->prepare('SELECT s.*,sec.section_name,sec.id AS section_id,sec.grade_level,sec.school_year,sec.schedule_type,
        sec.am_in_start,sec.am_in_end,sec.am_late_threshold,sec.am_out_start,sec.am_out_end,
        sec.pm_in_start,sec.pm_in_end,sec.pm_late_threshold,sec.pm_out_start,sec.pm_out_end
        FROM students s JOIN sections sec ON sec.id=? WHERE s.id=?');
    $q->execute([$ctx['section_id'],$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Explicit historical enrollment is required; selected student ID alone never
 * authorizes a report photo. Historical membership follows current advisers. */
function enrollmentHistoricalPhoto(PDO $db, int $studentId, int $enrollmentId, array $actor): ?array
{
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $q=$db->prepare("SELECT s.first_name,s.last_name,s.photo FROM students s JOIN student_enrollments e ON e.student_id=s.id
        JOIN school_years y ON y.id=e.school_year_id WHERE s.id=? AND e.id=? AND e.state IN ('active','completed')
        AND EXISTS(SELECT 1 FROM enrollment_sections es JOIN sections sec ON sec.id=es.section_id
            WHERE es.enrollment_id=e.id AND sec.school_year_id=y.id AND sec.grade_level=es.grade_level
            AND GREATEST(es.starts_on,e.starts_on,y.starts_on)<=LEAST(es.ends_on,e.ends_on,y.ends_on) AND ($scope))");
    $q->execute(array_merge([$studentId,$enrollmentId],$params));
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Public structural command; owns its transaction. Only profile/section
 * visibility changes. Restore never changes annual states, dates or assignments. */
function setEnrollmentEntityActive(PDO $db, array $actor, string $entity, int $id, bool $active): bool
{
    if (!in_array($entity,['student','section'],true) || $id<1) throw new InvalidArgumentException('Invalid archive target.');
    if (($actor['role']??'')!=='admin' || (int)($actor['id']??0)<1) throw new DomainException('Administrator access required.');
    if ($db->inTransaction()) throw new LogicException('Archive command owns its transaction.');
    $db->beginTransaction();
    try {
        $db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchAll();
        $db->query('SELECT id FROM school_years ORDER BY id FOR UPDATE')->fetchAll();
        $db->query('SELECT id FROM sections ORDER BY id FOR UPDATE')->fetchAll();
        $table=$entity==='student'?'students':'sections';
        $q=$db->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE"); $q->execute([$id]); $before=$q->fetch(PDO::FETCH_ASSOC);
        if (!$before) { $db->rollBack(); return false; }
        $authority=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1 LOCK IN SHARE MODE");
        $authority->execute([(int)$actor['id']]);
        if (!$authority->fetchColumn()) throw new DomainException('Administrator access required.');
        if ($entity==='section' && !$active) {
            $q=$db->prepare("SELECT COUNT(*) FROM student_enrollments e JOIN enrollment_sections es ON es.enrollment_id=e.id
                WHERE es.section_id=? AND e.state IN ('active','prepared') AND es.ends_on>=CURDATE()");
            $q->execute([$id]);
            if ((int)$q->fetchColumn()>0) throw new DomainException('This section still has active or prepared enrollments.');
        }
        $db->prepare("UPDATE $table SET is_active=? WHERE id=?")->execute([$active?1:0,$id]);
        securityAuditContext((int)$actor['id']);
        $after=$before; $after['is_active']=$active?1:0;
        securityAuditChange($db,$entity.'.'.($active?'restore':'archive'),$entity==='student'?'student':'route',$id,
            ['is_active'=>(int)$before['is_active']],['is_active'=>$active?1:0]);
        $db->commit(); return true;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
