<?php
declare(strict_types=1);
require_once __DIR__ . '/enrollment_access.php';

/** Correlated ownership predicate. Aliases are developer supplied, values are bound. */
function attendanceOwnershipScope(string $attendanceAlias, array $actor, int $yearId, ?int $sectionId = null): array
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $attendanceAlias)) throw new InvalidArgumentException('Invalid attendance alias.');
    [$scope,$params] = enrollmentSectionScope('report_sec',$actor,true);
    $a = $attendanceAlias;
    $sql = "EXISTS (SELECT 1 FROM student_enrollments report_e
        JOIN school_years report_y ON report_y.id=report_e.school_year_id
        JOIN enrollment_sections report_as ON report_as.enrollment_id=report_e.id
        JOIN sections report_sec ON report_sec.id=report_as.section_id
        WHERE report_e.id=$a.enrollment_id AND report_e.student_id=$a.student_id
        AND report_as.id=$a.assignment_id AND report_e.school_year_id=?
        AND report_e.state IN ('active','completed') AND report_sec.school_year_id=report_y.id
        AND report_sec.grade_level=report_as.grade_level
        AND $a.date BETWEEN report_y.starts_on AND report_y.ends_on
        AND $a.date BETWEEN report_e.starts_on AND report_e.ends_on
        AND $a.date BETWEEN report_as.starts_on AND report_as.ends_on AND ($scope)";
    $params = array_merge([$yearId],$params);
    if ($sectionId !== null) { $sql .= ' AND report_as.section_id=?'; $params[]=$sectionId; }
    return ['sql'=>$sql.')','params'=>$params];
}

/** Explicit years may clip a calendar interval. Unscoped ranges must not span years. */
function resolveEnrollmentReportYear(PDO $db, int $yearId, string $from, string $to): array
{
    validatedSchoolYearDate($from); validatedSchoolYearDate($to);
    if ($from > $to) throw new InvalidArgumentException('Invalid report date range.');
    if ($yearId > 0) {
        $q=$db->prepare('SELECT * FROM school_years WHERE id=?'); $q->execute([$yearId]); $year=$q->fetch();
        if (!$year) throw new DomainException('School year not found.');
        return $year;
    }
    $q=$db->prepare('SELECT * FROM school_years WHERE starts_on<=? AND ends_on>=? ORDER BY starts_on');
    $q->execute([$to,$from]); $years=$q->fetchAll();
    if (count($years)>1) throw new DomainException('Select a separate school year for this report range.');
    return $years[0] ?? getActiveSchoolYear($db) ?? throw new DomainException('No school year is available.');
}

function enrollmentReportSections(PDO $db, array $actor, int $yearId): array
{
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $q=$db->prepare("SELECT sec.*,u.full_name AS adviser_name FROM sections sec LEFT JOIN users u ON u.id=sec.adviser_id
        WHERE sec.school_year_id=? AND ($scope) ORDER BY sec.grade_level,sec.section_name");
    $q->execute(array_merge([$yearId],$params)); return $q->fetchAll();
}

/** Task 3 is the only membership source. Paginate annual rows, then merge section ranges. */
function loadEnrollmentReport(PDO $db, array $actor, int $yearId, ?int $sectionId, string $from, string $to): array
{
    if (!in_array($actor['role'] ?? '',['admin','teacher'],true) || (int)($actor['id'] ?? 0)<1) throw new DomainException('Report access denied.');
    $year=resolveEnrollmentReportYear($db,$yearId,$from,$to); $yearId=(int)$year['id'];
    $sections=enrollmentReportSections($db,$actor,$yearId);
    if ($sectionId !== null) {
        $sections=array_values(array_filter($sections,fn($s)=>(int)$s['id']===$sectionId));
        if (!$sections) throw new DomainException('Section access denied.');
    }
    $roster=[]; $ranges=[];
    foreach ($sections as $section) {
        $page=1;
        do {
            $batch=listEnrollmentRoster($db,$yearId,(int)$section['id'],$from,$to,$actor,$page,250);
            foreach ($batch['rows'] as $row) {
                $id=$row['enrollment_id'];
                if (!isset($roster[$id])) $roster[$id]=$row;
                else $roster[$id]['assignments']=array_merge($roster[$id]['assignments'],$row['assignments']);
                foreach ($row['assignments'] as $assignment) $ranges[$assignment['assignment_id']]=$assignment;
            }
            $page++;
        } while (($page-1)*250 < $batch['total']);
    }
    // Profile identity is loaded only after annual membership and authorization are established.
    if ($roster) {
        $ids=array_unique(array_column($roster,'student_id')); $ph=implode(',',array_fill(0,count($ids),'?'));
        $q=$db->prepare("SELECT * FROM students WHERE id IN ($ph)"); $q->execute(array_values($ids));
        $profiles=[]; foreach ($q->fetchAll() as $profile) $profiles[(int)$profile['id']]=$profile;
        foreach ($roster as &$row) $row=array_merge($profiles[$row['student_id']],$row,['id'=>$row['student_id']]);
        unset($row);
    }
    $scope=attendanceOwnershipScope('a',$actor,$yearId,$sectionId);
    $q=$db->prepare("SELECT a.*,s.first_name,s.middle_name,s.last_name,s.lrn,s.gender,s.photo,
        own.section_id,own.grade_level,sec.section_name,sec.schedule_type
        FROM attendance a JOIN students s ON s.id=a.student_id
        JOIN enrollment_sections own ON own.id=a.assignment_id AND own.enrollment_id=a.enrollment_id
        JOIN sections sec ON sec.id=own.section_id WHERE a.date BETWEEN ? AND ? AND ({$scope['sql']}) ORDER BY a.date,a.student_id");
    $q->execute(array_merge([$from,$to],$scope['params'])); $attendance=[];
    foreach ($q->fetchAll() as $row) {
        $range=$ranges[(int)$row['assignment_id']] ?? null;
        if (!$range || $row['date']<$range['starts_on'] || $row['date']>$range['ends_on']) continue;
        foreach (['id','student_id','enrollment_id','assignment_id','section_id'] as $key) $row[$key]=(int)$row[$key];
        $attendance[]=$row;
    }
    return ['roster'=>array_values($roster),'attendance'=>$attendance,
        'unresolved_count'=>$actor['role']==='admin' ? countEnrollmentMigrationIssues($db) : 0,'year'=>$year,'sections'=>$sections];
}

/** Read-only SQL adapters preserve existing report aggregates. No temporary tables or writes.
 * The union contains only authorized annual/section IDs from Task 3, never PII.
 * Callers join attendance by student AND enrollment AND report_section_id.
 */
function enrollmentReportSources(PDO $db, array $report): array
{
    $members=[];
    foreach ($report['roster'] as $row) foreach ($row['assignments'] as $assignment) {
        $key=$row['enrollment_id'].':'.$assignment['section_id'];
        $members[$key]='SELECT '.(int)$row['student_id'].' AS student_id,'.(int)$row['enrollment_id'].' AS enrollment_id,'.(int)$assignment['section_id'].' AS section_id';
    }
    $membership=$members ? implode(' UNION ALL ',array_values($members)) : 'SELECT 0 AS student_id,0 AS enrollment_id,0 AS section_id WHERE 1=0';
    $columns=[];
    foreach ($db->query('SHOW COLUMNS FROM students')->fetchAll() as $column) {
        $name=$column['Field'];
        if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/',$name)) throw new LogicException('Invalid profile column.');
        $columns[]=match($name) {
            'section_id'=>'member.section_id', 'grade_level'=>'sec.grade_level', 'school_year'=>'y.label AS school_year',
            'is_active'=>'1 AS is_active', default=>'profile.`'.$name.'`'
        };
    }
    $students='(SELECT '.implode(',',$columns).',profile.is_active AS profile_active,member.enrollment_id FROM ('.$membership.') member
        JOIN students profile ON profile.id=member.student_id JOIN sections sec ON sec.id=member.section_id JOIN school_years y ON y.id=sec.school_year_id)';
    $ids=array_map('intval',array_column($report['attendance'],'id'));
    $attendance='(SELECT record.*,own.section_id AS report_section_id FROM attendance record JOIN enrollment_sections own ON own.id=record.assignment_id AND own.enrollment_id=record.enrollment_id WHERE record.id IN ('.implode(',',$ids ?: [0]).'))';
    return ['students'=>$students,'attendance'=>$attendance];
}

/** HTTP adapter, invoked only after staff guards. Legacy callers retain their exact sources. */
function prepareEnrollmentReport(PDO $db, string $from, string $to, ?int $sectionId=null, bool $operational=false): array
{
    if (!enrollmentModelEnabled($db)) return ['managed'=>false,'students'=>'students','attendance'=>'attendance','join'=>'','year'=>null,'sections'=>null,'data'=>null];
    try {
        $id=(int)($_GET['school_year_id'] ?? 0);
        if (!$id && !empty($_GET['sy'])) {
            $q=$db->prepare('SELECT id FROM school_years WHERE label=?'); $q->execute([$_GET['sy']]);
            $id=(int)$q->fetchColumn(); if (!$id) throw new DomainException('School year not found.');
        }
        if ($operational && !$id) $id=(int)(getActiveSchoolYear($db)['id'] ?? 0);
        if (!$id && $sectionId) { $q=$db->prepare('SELECT school_year_id FROM sections WHERE id=?'); $q->execute([$sectionId]); $id=(int)$q->fetchColumn(); }
        $year=resolveEnrollmentReportYear($db,$id,$from,$to);
        $report=loadEnrollmentReport($db,currentUser(),(int)$year['id'],$sectionId,$from,$to);
        return array_merge(enrollmentReportSources($db,$report),['managed'=>true,'join'=>' AND a.enrollment_id=s.enrollment_id AND a.report_section_id=s.section_id',
            'year'=>$year,'sections'=>enrollmentReportSections($db,currentUser(),(int)$year['id']),'data'=>$report]);
    } catch (InvalidArgumentException|DomainException $error) { http_response_code(400); exit(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8')); }
}

function enrollmentReportNotice(array $context): void
{
    if (!$context['managed']) return;
    echo '<div class="alert alert-info">School year: '.htmlspecialchars($context['year']['label']).'.';
    if ($context['data']['unresolved_count']) echo ' '.(int)$context['data']['unresolved_count'].' unresolved historical ownership exceptions; totals exclude records without verified ownership.';
    echo '</div>';
    $labels=enrollmentReportYearLabels(getDB(),currentUser());
    echo '<form method="get" class="no-print mb-3"><label>School year <select name="sy" onchange="this.form.submit()">';
    foreach ($labels as $label) echo '<option value="'.htmlspecialchars($label).'"'.($label===$context['year']['label'] ? ' selected' : '').'>'.htmlspecialchars($label).'</option>';
    echo '</select></label>';
    foreach ($_GET as $key=>$value) {
        if (in_array($key,['sy','school_year_id','section','enrollment_id','page'],true) || !is_scalar($value)) continue;
        echo '<input type="hidden" name="'.htmlspecialchars((string)$key).'" value="'.htmlspecialchars((string)$value).'">';
    }
    echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Select year</button></form>';
}

function enrollmentReportDay(array $context, string $date): bool
{
    return !$context['managed'] || ($date >= $context['year']['starts_on'] && $date <= $context['year']['ends_on']);
}

function enrollmentReportMemberDay(array $row, string $date): bool
{
    foreach ($row['assignments'] ?? [] as $range) if ($date >= $range['starts_on'] && $date <= $range['ends_on']) return true;
    return false;
}

/** Existing rate formulas use eligible student-days instead of full-month cached headcounts. */
function enrollmentReportPossible(array $context, ?int $sectionId=null, ?string $grade=null, ?int $studentId=null, ?string $gender=null): int
{
    $days=[];
    foreach ($context['data']['roster'] ?? [] as $row) {
        if ($studentId !== null && (int)$row['id']!==$studentId) continue;
        if ($gender !== null && $row['gender']!==$gender) continue;
        foreach ($row['assignments'] as $range) {
            if ($sectionId !== null && $range['section_id']!==$sectionId || $grade !== null && $range['grade_level']!==$grade) continue;
            for ($date=$range['starts_on'];$date<=$range['ends_on'];$date=date('Y-m-d',strtotime($date.' +1 day'))) {
                if ((int)date('N',strtotime($date))<=5 && !isHolidayOrNoClass($date)) $days[$row['enrollment_id'].':'.$date]=true;
            }
        }
    }
    return count($days);
}

/** Directories default to today's active enrollment; explicit historical selections use the year. */
function prepareEnrollmentDirectory(PDO $db, bool $wholeYear=false): array
{
    if (!enrollmentModelEnabled($db)) return prepareEnrollmentReport($db,date('Y-m-d'),date('Y-m-d'));
    $id=(int)($_GET['school_year_id'] ?? 0);
    if (!empty($_GET['enrollment_id'])) {
        $q=$db->prepare('SELECT school_year_id FROM student_enrollments WHERE id=?'); $q->execute([(int)$_GET['enrollment_id']]);
        $id=(int)$q->fetchColumn(); if (!$id) { http_response_code(404); exit('Enrollment not found.'); }
    }
    if (!$id && !empty($_GET['sy'])) {
        $q=$db->prepare('SELECT id FROM school_years WHERE label=?'); $q->execute([$_GET['sy']]); $id=(int)$q->fetchColumn();
        if (!$id) { http_response_code(404); exit('School year not found.'); }
    }
    $historical=$wholeYear || $id>0;
    if (!$id) $id=(int)(getActiveSchoolYear($db)['id'] ?? 0);
    $year=resolveEnrollmentReportYear($db,$id,date('Y-m-d'),date('Y-m-d'));
    $from=$historical ? $year['starts_on'] : date('Y-m-d'); $to=$historical ? $year['ends_on'] : $from;
    $report=loadEnrollmentReport($db,currentUser(),$id,null,$from,$to);
    return array_merge(enrollmentReportSources($db,$report),['managed'=>true,'join'=>' AND a.enrollment_id=s.enrollment_id AND a.report_section_id=s.section_id','year'=>$year,'sections'=>$report['sections'],'data'=>$report,'historical'=>$historical]);
}

/** One row per annual enrollment even after a return to a section. */
function enrollmentDirectoryRows(array $context, string $search='', ?int $sectionId=null, string $grade=''): array
{
    $sections=[]; foreach ($context['sections'] as $section) $sections[(int)$section['id']]=$section;
    $rows=[];
    foreach ($context['data']['roster'] as $row) {
        $matches=array_filter($row['assignments'],fn($a)=>($sectionId===null || $a['section_id']===$sectionId) && ($grade==='' || $a['grade_level']===$grade));
        if (!$matches) continue;
        if ($search!=='' && stripos($row['first_name'].' '.$row['last_name'].' '.$row['lrn'],$search)===false) continue;
        $labels=[]; $grades=[];
        foreach ($matches as $range) { $labels[$range['section_id']]=$sections[$range['section_id']]['section_name']; $grades[$range['grade_level']]=true; }
        $row['section_name']=implode(' / ',$labels); $row['grade_level']=implode(' / ',array_keys($grades));
        $row['section_id']=(int)array_key_first($labels);
        $row['schedule_type']=$sections[$row['section_id']]['schedule_type'];
        $row['school_year']=$context['year']['label']; $row['today_status']=null;
        foreach ($context['data']['attendance'] as $record) if ($record['student_id']===$row['id'] && $record['date']===date('Y-m-d')) $row['today_status']=$record['attendance_type'];
        $rows[]=$row;
    }
    usort($rows,fn($a,$b)=>[$a['grade_level'],$a['last_name'],$a['first_name']] <=> [$b['grade_level'],$b['last_name'],$b['first_name']]);
    return $rows;
}

function enrollmentReportYearLabels(PDO $db, array $actor): array
{
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $q=$db->prepare("SELECT DISTINCT y.label FROM school_years y JOIN sections sec ON sec.school_year_id=y.id WHERE ($scope) ORDER BY y.starts_on DESC");
    $q->execute($params); return $q->fetchAll(PDO::FETCH_COLUMN);
}
