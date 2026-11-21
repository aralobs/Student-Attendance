<?php
declare(strict_types=1);
require_once __DIR__.'/enrollment_access.php';

/** Available viewing contexts; this never grants write access to an enrollment. */
function viewingSchoolYears(PDO $db, array $actor): array
{
    if (!in_array($actor['role'] ?? '', ['admin','teacher'], true) || empty($actor['id'])) {
        throw new DomainException('School-year access denied.');
    }
    if ($actor['role'] === 'admin') return $db->query('SELECT * FROM school_years ORDER BY starts_on DESC')->fetchAll();
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $q=$db->prepare("SELECT DISTINCT y.* FROM school_years y JOIN sections sec ON sec.school_year_id=y.id WHERE ($scope) ORDER BY y.starts_on DESC");
    $q->execute($params);
    return $q->fetchAll();
}

/** Only the shared selector changes the preference. Explicit legacy links stay local. */
function viewingSchoolYear(PDO $db, array $actor, ?array $query=null): array
{
    $query=$query ?? $_GET;
    $years=viewingSchoolYears($db,$actor);
    $active=getActiveSchoolYear($db);
    $allowed=[]; foreach ($years as $year) $allowed[(int)$year['id']]=$year;
    // A teacher with no assigned sections may still view an empty current roster.
    if ($active) $allowed[(int)$active['id']]=$active;
    $fallback=$active ?? ($years[0] ?? null);
    if (!$fallback) throw new DomainException('No school year is available.');
    $owner=(int)$actor['id'];
    $saved=$_SESSION['viewing_school_year'] ?? [];
    if (($saved['actor_id'] ?? null) !== $owner) $saved=[];
    if (array_key_exists('view_school_year',$query)) {
        $value=$query['view_school_year'];
        if (!is_string($value) || ($value!=='current' && !preg_match('/\A[1-9][0-9]*\z/',$value))) throw new InvalidArgumentException('Invalid viewing school year.');
        $id=$value==='current' ? (int)$fallback['id'] : (int)$value;
        if (!isset($allowed[$id])) throw new DomainException('School-year access denied.');
        $_SESSION['viewing_school_year']=['actor_id'=>$owner,'year_id'=>$id];
        return $allowed[$id];
    }
    if (!empty($query['school_year_id'])) {
        $value=$query['school_year_id'];
        if (!is_scalar($value) || !preg_match('/\A[1-9][0-9]*\z/',(string)$value)) throw new InvalidArgumentException('Invalid school year.');
        if (!isset($allowed[(int)$value])) throw new DomainException('School-year access denied.');
        return $allowed[(int)$value];
    }
    if (!empty($query['sy'])) {
        if (!is_string($query['sy'])) throw new InvalidArgumentException('Invalid school year.');
        foreach ($allowed as $year) if ($year['label']===$query['sy']) return $year;
        throw new DomainException('School-year access denied.');
    }
    $year=$allowed[(int)($saved['year_id'] ?? 0)] ?? $fallback;
    if ($saved) $_SESSION['viewing_school_year']=['actor_id'=>$owner,'year_id'=>(int)$year['id']];
    return $year;
}

function requireViewingSchoolYear(PDO $db, array $actor, ?array $query=null): array
{
    try { return viewingSchoolYear($db,$actor,$query); }
    catch (DomainException|InvalidArgumentException $e) { http_response_code(400); exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }
}

function viewingSchoolYearDate(array $year): string
{
    return min(max(date('Y-m-d'),$year['starts_on']),$year['ends_on']);
}

function renderViewingSchoolYearControl(PDO $db, array $actor, array $year): void
{
    $years=viewingSchoolYears($db,$actor);
    if (!in_array((int)$year['id'],array_map('intval',array_column($years,'id')),true)) $years[]=$year;
    echo '<form method="get" class="school-year-toolbar ms-auto no-print" aria-label="Viewing school year"><div class="school-year-control"><label for="reportSchoolYear"><i class="bi bi-calendar3" aria-hidden="true"></i><span>Viewing school year</span></label><select id="reportSchoolYear" class="form-select form-select-sm" name="view_school_year" onchange="this.form.submit()">';
    foreach ($years as $option) echo '<option value="'.(int)$option['id'].'"'.((int)$option['id']===(int)$year['id']?' selected':'').'>'.htmlspecialchars($option['label']).'</option>';
    echo '</select>';
    // A year change clears dependent sections, dates and record IDs from the old year.
    foreach (['search','view','type'] as $key) {
        if (isset($_GET[$key]) && is_scalar($_GET[$key])) echo '<input type="hidden" name="'.$key.'" value="'.htmlspecialchars((string)$_GET[$key]).'">';
    }
    echo '<button type="submit" class="btn btn-sm btn-outline-primary" aria-label="Apply school year" title="Apply school year"><i class="bi bi-check2" aria-hidden="true"></i></button></div></form>';
}

function renderViewingSchoolYearNotice(PDO $db, array $year): void
{
    $active=getActiveSchoolYear($db);
    if ($active && (int)$active['id']===(int)$year['id']) return;
    $label=$year['state']==='closed' || $year['ends_on']<date('Y-m-d') ? 'Historical view' : 'Viewing school year';
    echo '<div class="viewing-year-notice no-print" role="status"><span>'.htmlspecialchars($label.' · '.$year['label']).'</span><a href="?view_school_year=current">Return to current year</a></div>';
}
