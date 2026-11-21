<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/section_preparation.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Section preparation requires an administrator.'); }
$db=getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
requireSchoolYearActivationAdmin($db,currentUser());
guardCsrfPage();
$input=$_SERVER['REQUEST_METHOD']==='POST' ? $_POST : $_GET;
$returnTo='sections.php'; $returnLabel='Back to Sections';
$returnParts=is_string($input['return_to'] ?? null) ? parse_url($input['return_to']) : false;
if (is_array($returnParts) && !isset($returnParts['scheme']) && !isset($returnParts['host']) && !isset($returnParts['fragment']) && in_array($returnParts['path'] ?? '',['sections.php','../students/moving_up.php'],true)) {
    parse_str($returnParts['query'] ?? '',$returnQuery); $safeQuery=[];
    $movingReturn=$returnParts['path']==='../students/moving_up.php';
    foreach($movingReturn ? ['source_year_id','source_section_id','target_year_id'] : ['school_year_id'] as $key) {
        $value=filter_var($returnQuery[$key] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($value!==false) $safeQuery[$key]=$value;
    }
    if (!$movingReturn && in_array($returnQuery['view'] ?? '',['active','archived'],true)) $safeQuery['view']=$returnQuery['view'];
    $returnTo=$returnParts['path'].($safeQuery ? '?'.http_build_query($safeQuery) : '');
    if ($movingReturn) $returnLabel='Back to Moving Up';
}
$years=$db->query('SELECT id,label,starts_on,ends_on,state FROM school_years ORDER BY starts_on')->fetchAll();
$sourceYearId=(int)($input['source_year_id'] ?? 0);
if (!$sourceYearId) foreach($years as $year) if($year['state']==='active') $sourceYearId=(int)$year['id'];
$source=null; foreach($years as $year) if((int)$year['id']===$sourceYearId && in_array($year['state'],['active','closed'],true)) $source=$year;
$targets=$source ? array_values(array_filter($years,fn($y)=>$y['state']==='planned' && $y['starts_on']>$source['ends_on'])) : [];
$targetYearId=(int)($input['target_year_id'] ?? 0);
if (!$targetYearId && $source) foreach($targets as $year) if($year['label']===nextSchoolYearLabel($source['label'])) $targetYearId=(int)$year['id'];
$target=null; foreach($targets as $year) if((int)$year['id']===$targetYearId) $target=$year;
$sections=[];
if ($source) {
    $q=$db->prepare('SELECT * FROM sections WHERE school_year_id=? AND is_active=1 ORDER BY '.gradeLevelOrderSQL('grade_level').',section_name,id');
    $q->execute([$sourceYearId]); $sections=$q->fetchAll();
}
$selected=[]; $preview=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $command=sectionPreparationCommand($input); $selected=$command['section_ids'];
        if (($input['action'] ?? '')==='confirm') {
            $result=prepareSections($db,currentUser(),$command,is_string($input['expected_fingerprint'] ?? null) ? $input['expected_fingerprint'] : '');
            setFlash('success',$result['created'].' sections prepared; '.$result['skipped'].' existing sections skipped. Advisers are unassigned for newly prepared sections.');
            header('Location: prepare.php?'.http_build_query(['source_year_id'=>$sourceYearId,'target_year_id'=>$targetYearId,'return_to'=>$returnTo])); exit;
        }
        if (($input['action'] ?? '')!=='preview') throw new DomainException('Choose preview or confirm.');
        $preview=previewSectionPreparation($db,currentUser(),$command);
    } catch (DomainException|InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('Section preparation failed: '.$e->getMessage()); $error='Unable to prepare sections. No sections were copied. Please try again.'; }
}
$pageTitle='Prepare Next-Year Sections';
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<div class="students-page workflow-page">
<?php showFlash(); ?>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<div class="card students-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="page-title mb-0"><i class="bi bi-diagram-3 me-2 text-primary" aria-hidden="true"></i>Prepare Next-Year Sections</h1>
        <div class="d-flex flex-wrap gap-2">
        <a href="<?= sanitize($returnTo) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?= $returnLabel ?></a>
        <?php if($returnLabel!=='Back to Moving Up'): ?><a href="../students/moving_up.php?<?= sanitize(http_build_query(['source_year_id'=>$sourceYearId,'target_year_id'=>$targetYearId])) ?>" class="btn btn-outline-primary">Student Moving Up</a><?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <p class="small text-muted">Copy section names, current grades, and schedules into a planned year. Students stay in their current placements. New sections start with unassigned advisers.</p>
        <div class="section-divider"><i class="bi bi-funnel" aria-hidden="true"></i>Choose School Years</div>
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="return_to" value="<?= sanitize($returnTo) ?>">
            <div class="col-md-5"><label for="section-source-year" class="form-label">Source school year</label>
                <select id="section-source-year" name="source_year_id" class="form-select">
                <?php foreach($years as $year): if(!in_array($year['state'],['active','closed'],true)) continue; ?>
                    <option value="<?= (int)$year['id'] ?>" <?= (int)$year['id']===$sourceYearId?'selected':'' ?>><?= sanitize($year['label']) ?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5"><label for="section-target-year" class="form-label">Target school year</label>
                <select id="section-target-year" name="target_year_id" class="form-select">
                    <option value="">Choose a planned year</option>
                <?php foreach($years as $year): if($year['state']!=='planned') continue; ?>
                    <option value="<?= (int)$year['id'] ?>" data-start="<?= sanitize($year['starts_on']) ?>" <?= (int)$year['id']===$targetYearId?'selected':'' ?> <?= $source && $year['starts_on']>$source['ends_on']?'':'hidden disabled' ?>><?= sanitize($year['label']) ?></option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Load sections</button></div>
        </form>
        <?php if($target): ?><div class="mt-3 small"><a href="sections.php?school_year_id=<?= $targetYearId ?>">View sections for <?= sanitize($target['label']) ?></a></div><?php endif; ?>
    </div>
</div>
<?php if(!$targets): ?><div class="alert alert-info">Create a planned school year with verified dates after the source year before preparing sections. <a href="../settings/school_years.php">Manage school years</a></div>
<?php elseif(!$target): ?><div class="alert alert-info">Choose a later planned target school year and load sections.</div><?php endif; ?>
<?php if($source && $target): ?>
<form method="post" class="card students-card students-list workflow-list mb-4" id="section-preparation-form">
    <?= csrfField() ?>
    <input type="hidden" name="return_to" value="<?= sanitize($returnTo) ?>">
    <input type="hidden" name="source_year_id" value="<?= $sourceYearId ?>">
    <input type="hidden" name="target_year_id" value="<?= $targetYearId ?>">
    <input type="hidden" name="action" value="preview">
    <div class="card-header d-flex justify-content-center align-items-center text-center"><h2 class="h6 mb-0"><i class="bi bi-table me-1" aria-hidden="true"></i>Source Section List <span class="badge bg-primary ms-1"><?= count($sections) ?></span></h2></div>
    <div class="card-body pb-2">
        <p class="small text-muted">Grades stay the same: a Grade 3 section becomes a Grade 3 section in <?= sanitize($target['label']) ?>. Matching target sections are skipped, including archived sections.</p>
        <label class="form-check"><input type="checkbox" class="form-check-input" id="select-all-sections" <?= !$sections?'disabled':'' ?>>Select all <?= count($sections) ?> sections</label>
    </div>
    <div class="table-responsive"><table class="table table-hover students-table mb-0">
        <thead><tr><th scope="col">Select</th><th scope="col">Section</th><th scope="col">Current grade</th><th scope="col">Schedule</th><th scope="col">Time-In</th><th scope="col">Time-Out</th></tr></thead>
        <tbody>
        <?php if(!$sections): ?><tr><td colspan="6" class="text-center text-muted py-5">No active sections found in this source school year.</td></tr><?php endif; ?>
        <?php foreach($sections as $section): $inPrefix=$section['schedule_type']==='pm_only'?'pm':'am'; $outPrefix=$section['schedule_type']==='am_only'?'am':'pm'; ?>
            <tr><td class="text-center"><input type="checkbox" class="form-check-input section-copy-check" name="section_ids[]" value="<?= (int)$section['id'] ?>" aria-label="Select <?= sanitize($section['grade_level'].' '.$section['section_name']) ?>" <?= in_array((int)$section['id'],$selected,true)?'checked':'' ?>></td>
                <td class="fw-semibold"><?= sanitize($section['section_name']) ?></td><td class="text-center"><span class="badge bg-secondary"><?= sanitize($section['grade_level']) ?></span></td>
                <td><?= sanitize(ucfirst(str_replace('_',' ',$section['schedule_type']))) ?></td>
                <td class="small text-nowrap"><?= sanitize(substr($section[$inPrefix.'_in_start'],0,5).' – '.substr($section[$inPrefix.'_in_end'],0,5)) ?></td>
                <td class="small text-nowrap"><?= sanitize(substr($section[$outPrefix.'_out_start'],0,5).' – '.substr($section[$outPrefix.'_out_end'],0,5)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="card-footer py-3"><button type="submit" class="btn btn-primary" <?= !$sections?'disabled':'' ?>><i class="bi bi-eye me-1" aria-hidden="true"></i>Preview sections</button></div>
</form>
<?php endif; ?>
<?php if($preview): $createCount=count(array_filter($preview['rows'],fn($row)=>$row['status']==='create')); ?>
<div class="card students-card students-list workflow-list mb-4" id="section-copy-preview">
    <div class="card-header d-flex justify-content-center align-items-center text-center"><h2 class="h6 mb-0"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Confirm Section Preparation</h2></div>
    <div class="card-body"><p class="small mb-0"><?= $createCount ?> to create and <?= count($preview['rows'])-$createCount ?> to skip in <?= sanitize($preview['target']['label']) ?>. Names, grades, and schedules stay unchanged. Advisers: Unassigned.</p></div>
    <div class="table-responsive"><table class="table table-hover students-table mb-0"><thead><tr><th scope="col">Section</th><th scope="col">Grade</th><th scope="col">Schedule</th><th scope="col">Result</th></tr></thead><tbody>
    <?php foreach($preview['rows'] as $row): ?>
        <tr><td class="fw-semibold"><?= sanitize($row['section']['section_name']) ?></td><td><?= sanitize($row['section']['grade_level']) ?></td><td><?= sanitize(ucfirst(str_replace('_',' ',$row['section']['schedule_type']))) ?></td><td><?= ['create'=>'Create new','existing'=>'Skip existing','archived'=>'Skip archived — restore in Sections'][$row['status']] ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="card-footer py-3">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="return_to" value="<?= sanitize($returnTo) ?>">
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="source_year_id" value="<?= $sourceYearId ?>">
            <input type="hidden" name="target_year_id" value="<?= $targetYearId ?>">
            <input type="hidden" name="expected_fingerprint" value="<?= sanitize($preview['fingerprint']) ?>">
            <?php foreach($selected as $id): ?><input type="hidden" name="section_ids[]" value="<?= $id ?>"><?php endforeach; ?>
            <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Confirm and prepare sections</button>
        </form>
        <p class="small text-muted mt-2 mb-0">Existing sections will not be overwritten. <a href="sections.php?view=archived&amp;school_year_id=<?= $targetYearId ?>">Review archived target sections</a>.</p>
    </div>
</div>
<?php endif; ?>
</div>
<script src="<?= BASE_URL ?>assets/js/section_preparation.js"></script>
<?php include '../includes/footer.php'; ?>
