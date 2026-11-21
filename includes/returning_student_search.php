<?php
declare(strict_types=1);
require_once __DIR__.'/enrollment_access.php';

/** Only minimal identity data may be exposed by this lookup. Historical teachers
 * retain access through sections they currently advise, not profile projections. */
function returningStudentIdentityScope(array $actor): array
{
    [$scope,$params]=enrollmentSectionScope('lookup_section',$actor,true);
    if ($scope==='1 = 0' || ($actor['role'] ?? '')==='admin') return [$scope,$params];
    return ["s.is_active=1 AND EXISTS (
        SELECT 1 FROM student_enrollments lookup_enrollment
        JOIN enrollment_sections lookup_assignment ON lookup_assignment.enrollment_id=lookup_enrollment.id
        JOIN sections lookup_section ON lookup_section.id=lookup_assignment.section_id
        WHERE lookup_enrollment.student_id=s.id AND lookup_enrollment.state IN ('active','completed')
        AND lookup_section.school_year_id=lookup_enrollment.school_year_id AND ($scope)
    )",$params];
}

function searchReturningStudents(PDO $db, array $actor, string $query): array
{
    $query=trim(preg_replace('/\s+/u',' ',mb_substr($query,0,100)) ?? '');
    if (mb_strlen($query)<2) return [];
    [$scope,$params]=returningStudentIdentityScope($actor);
    if ($scope==='1 = 0') return [];
    $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
    $q=$db->prepare("SELECT s.id,s.lrn,s.first_name,s.middle_name,s.last_name,s.is_active FROM students s
        WHERE ($scope) AND (s.lrn LIKE ? ESCAPE '!' OR
        CONCAT_WS(' ',s.first_name,s.last_name) LIKE ? ESCAPE '!' OR
        CONCAT_WS(' ',s.last_name,s.first_name) LIKE ? ESCAPE '!' OR
        CONCAT_WS(' ',s.first_name,NULLIF(s.middle_name,''),s.last_name) LIKE ? ESCAPE '!')
        ORDER BY s.last_name,s.first_name,s.id LIMIT 20");
    $q->execute(array_merge($params,[$like,$like,$like,$like]));
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function selectReturningStudent(PDO $db, array $actor, int $studentId): ?array
{
    if ($studentId<=0) return null;
    [$scope,$params]=returningStudentIdentityScope($actor);
    if ($scope==='1 = 0') return null;
    $q=$db->prepare("SELECT s.id,s.lrn,s.first_name,s.middle_name,s.last_name,s.is_active FROM students s
        WHERE s.id=? AND s.is_active=1 AND ($scope)");
    $q->execute(array_merge([$studentId],$params));
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
