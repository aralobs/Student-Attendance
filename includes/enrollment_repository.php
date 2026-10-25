<?php
declare(strict_types=1);
require_once __DIR__ . '/school_years.php';

/** Fixed internal select: historical ownership never comes from students.section_id. */
function enrollmentContextSelect(): string
{
    return "SELECT e.student_id,e.id AS enrollment_id,a.id AS assignment_id,
      y.id AS school_year_id,y.label AS school_year,y.state AS year_state,e.state AS enrollment_state,
      a.section_id,a.grade_level,GREATEST(y.starts_on,e.starts_on,a.starts_on) AS starts_on,
      LEAST(y.ends_on,e.ends_on,a.ends_on) AS ends_on,a.version,
      sec.school_year_id AS section_year_id,sec.grade_level AS section_grade,
      s.is_active AS profile_active,sec.is_active AS section_active
      FROM student_enrollments e JOIN school_years y ON y.id=e.school_year_id
      JOIN enrollment_sections a ON a.enrollment_id=e.id JOIN sections sec ON sec.id=a.section_id
      JOIN students s ON s.id=e.student_id";
}

function normalizedEnrollmentContext(array $row): array
{
    if ((int)$row['section_year_id'] !== (int)$row['school_year_id'] || $row['section_grade'] !== $row['grade_level']
        || $row['starts_on'] > $row['ends_on']) throw new DomainException('Invalid enrollment assignment ownership.');
    foreach (['student_id','enrollment_id','assignment_id','school_year_id','section_id','version'] as $key) $row[$key] = (int)$row[$key];
    unset($row['section_year_id'],$row['section_grade'],$row['profile_active'],$row['section_active']);
    return $row;
}

/** Annual model only, even in legacy mode. No fallback to current profile placement.
 * With lock=true the caller owns a transaction. Lock shared year/section, then profile,
 * enrollment and assignments; never acquire the structural control lock here.
 */
function getEnrollmentContext(PDO $db, int $studentId, string $date, string $purpose = 'read', bool $lock = false): ?array
{
    validatedSchoolYearDate($date);
    if (!in_array($purpose,['read','write','scan'],true)) throw new InvalidArgumentException('Invalid enrollment purpose.');
    if ($lock && !$db->inTransaction()) throw new LogicException('Enrollment locks require a caller-owned transaction.');
    $resolve = static function () use ($db,$studentId,$date): array {
        $q=$db->prepare(enrollmentContextSelect() . " WHERE e.student_id=? AND e.state<>'cancelled'
          AND ? BETWEEN y.starts_on AND y.ends_on AND ? BETWEEN e.starts_on AND e.ends_on
          AND ? BETWEEN a.starts_on AND a.ends_on ORDER BY e.id,a.id");
        $q->execute([$studentId,$date,$date,$date]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1) throw new DomainException('Multiple enrollment assignments match this date.');
        return $rows;
    };
    $rows=$resolve();
    if (!$rows) return null;
    $row=$rows[0];
    normalizedEnrollmentContext($row); // Reject malformed ownership before operational filtering.
    if ($lock) {
        $locked=[];
        foreach ([['school_years',$row['school_year_id'],' LOCK IN SHARE MODE'],['sections',$row['section_id'],' LOCK IN SHARE MODE'],
            ['students',$studentId,' FOR UPDATE'],['student_enrollments',$row['enrollment_id'],' FOR UPDATE']] as [$table,$id,$suffix]) {
            $q=$db->prepare("SELECT * FROM $table WHERE id=?$suffix"); $q->execute([$id]); $locked[$table]=$q->fetch(PDO::FETCH_ASSOC);
            if (!$locked[$table]) throw new DomainException('Enrollment ownership changed; retry the operation.');
        }
        $q=$db->prepare('SELECT * FROM enrollment_sections WHERE enrollment_id=? ORDER BY id FOR UPDATE');
        $q->execute([$row['enrollment_id']]);
        $current=array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC),static fn($a)=>$a['starts_on']<=$date && $a['ends_on']>=$date));
        // Rebuild from current locking reads, not an earlier repeatable-read snapshot.
        // Never acquire a newly discovered structural lock after downstream row locks.
        $y=$locked['school_years']; $e=$locked['student_enrollments']; $sec=$locked['sections'];
        if (count($current)>1) throw new DomainException('Multiple enrollment assignments match this date.');
        if (!$current || $current[0]['section_id']!=$row['section_id'] || $current[0]['id']!=$row['assignment_id']
            || $e['school_year_id']!=$y['id'] || $e['student_id']!=$studentId) {
            throw new DomainException('Enrollment ownership changed; retry the operation.');
        }
        $a=$current[0];
        $row=['student_id'=>$studentId,'enrollment_id'=>$e['id'],'assignment_id'=>$a['id'],'school_year_id'=>$y['id'],
          'school_year'=>$y['label'],'year_state'=>$y['state'],'enrollment_state'=>$e['state'],'section_id'=>$sec['id'],
          'grade_level'=>$a['grade_level'],'starts_on'=>max($y['starts_on'],$e['starts_on'],$a['starts_on']),
          'ends_on'=>min($y['ends_on'],$e['ends_on'],$a['ends_on']),'version'=>$a['version'],
          'section_year_id'=>$sec['school_year_id'],'section_grade'=>$sec['grade_level'],
          'profile_active'=>$locked['students']['is_active'],'section_active'=>$sec['is_active']];
        if ($e['state']==='cancelled' || $date<$row['starts_on'] || $date>$row['ends_on']) return null;
    }
    $ctx=normalizedEnrollmentContext($row);
    if ($purpose!=='read' && ($row['year_state']!=='active' || $row['enrollment_state']!=='active'
        || !(int)$row['profile_active'] || !(int)$row['section_active'])) return null;
    // Prepared annual records are upcoming, not an eligible historical attendance context.
    if ($purpose==='read' && !in_array($row['enrollment_state'],['active','completed'],true)) return null;
    return $ctx;
}

/** Separate visible unresolved mapping count; resolution never invents ownership. */
function countEnrollmentMigrationIssues(PDO $db): int
{
    return (int)$db->query('SELECT COUNT(*) FROM enrollment_migration_issues WHERE resolved_at IS NULL')->fetchColumn();
}

/** One row per annual enrollment with only the requested section's authorized,
 * inclusive assignment ranges, clipped to year/enrollment/request boundaries.
 */
function listEnrollmentRoster(PDO $db, int $yearId, int $sectionId, string $from, string $to, array $actor, int $page = 1, int $perPage = 25): array
{
    require_once __DIR__ . '/enrollment_access.php';
    validatedSchoolYearDate($from); validatedSchoolYearDate($to);
    if ($from>$to || $page<1 || $perPage<1 || $perPage>250 || $yearId<1 || $sectionId<1) throw new InvalidArgumentException('Invalid roster interval or pagination.');
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $where=" WHERE y.id=? AND sec.id=? AND e.state IN ('active','completed') AND ($scope)
      AND GREATEST(y.starts_on,e.starts_on,a.starts_on)<=? AND LEAST(y.ends_on,e.ends_on,a.ends_on)>=?";
    $bindings=array_merge([$yearId,$sectionId],$params,[$to,$from]);
    $base=" FROM student_enrollments e JOIN school_years y ON y.id=e.school_year_id
      JOIN enrollment_sections a ON a.enrollment_id=e.id JOIN sections sec ON sec.id=a.section_id JOIN students s ON s.id=e.student_id";
    $q=$db->prepare('SELECT COUNT(DISTINCT e.id)'.$base.$where); $q->execute($bindings); $total=(int)$q->fetchColumn();
    $offset=($page-1)*$perPage;
    $q=$db->prepare('SELECT DISTINCT e.id'.$base.$where." ORDER BY e.id LIMIT $perPage OFFSET $offset"); $q->execute($bindings);
    $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) return ['rows'=>[],'total'=>$total];
    $q=$db->prepare(enrollmentContextSelect().$where.' AND e.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY e.id,a.starts_on,a.id');
    $q->execute(array_merge($bindings,$ids)); $grouped=[];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ctx=normalizedEnrollmentContext($row);
        $ctx['starts_on']=max($ctx['starts_on'],$from); $ctx['ends_on']=min($ctx['ends_on'],$to);
        // Check every assignment in the intersecting range, even a different section
        // the teacher cannot view. Reject corruption without returning those ranges.
        $overlap=$db->prepare('SELECT COUNT(*) FROM enrollment_sections WHERE enrollment_id=? AND id<>? AND starts_on<=? AND ends_on>=?');
        $overlap->execute([$ctx['enrollment_id'],$ctx['assignment_id'],$ctx['ends_on'],$ctx['starts_on']]);
        if ((int)$overlap->fetchColumn()>0) throw new DomainException('Overlapping roster assignment ranges.');
        $id=$ctx['enrollment_id'];
        if (!isset($grouped[$id])) {
            $grouped[$id]=$ctx;
            unset($grouped[$id]['assignment_id'],$grouped[$id]['version'],$grouped[$id]['starts_on'],$grouped[$id]['ends_on']);
            $grouped[$id]['assignments']=[];
        }
        foreach ($grouped[$id]['assignments'] as $previous) if ($ctx['starts_on']<=$previous['ends_on']) throw new DomainException('Overlapping roster assignment ranges.');
        $grouped[$id]['assignments'][]=$ctx;
    }
    return ['rows'=>array_values($grouped),'total'=>$total];
}
