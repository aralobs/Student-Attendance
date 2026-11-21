<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/moving_up.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Moving up requires an administrator.'); }
$db=getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
requireMovingUpAdmin($db,currentUser());
guardCsrfPage();
$input=$_SERVER['REQUEST_METHOD']==='POST' ? $_POST : $_GET;
$years=$db->query('SELECT id,label,starts_on,ends_on,state FROM school_years ORDER BY starts_on')->fetchAll();
$sourceYearId=(int)($input['source_year_id'] ?? 0);
if (!$sourceYearId) foreach($years as $year) if($year['state']==='active') $sourceYearId=(int)$year['id'];
$sourceYear=null; foreach($years as $year) if((int)$year['id']===$sourceYearId) $sourceYear=$year;
$targetYearId=(int)($input['target_year_id'] ?? 0);
if (!$targetYearId && $sourceYear) {
    $nextLabel=nextSchoolYearLabel($sourceYear['label']);
    foreach($years as $year) if($year['state']==='planned' && $year['label']===$nextLabel) { $targetYearId=(int)$year['id']; break; }
}
$targetYear=null; foreach($years as $year) if((int)$year['id']===$targetYearId) $targetYear=$year;
$sourceSectionId=(int)($input['source_section_id'] ?? 0);
$sections=$db->query('SELECT id,section_name,grade_level,school_year_id,is_active FROM sections ORDER BY grade_level,section_name,id')->fetchAll();
$destinationSections=array_filter($sections,fn($section)=>(int)$section['school_year_id']===$targetYearId && (int)$section['is_active']===1);
$roster=$sourceSectionId>0 ? listMovingUpSourceRoster($db,currentUser(),$sourceYearId,$sourceSectionId) : [];
$preview=null; $command=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (($input['select_all'] ?? '')==='1') $input['student_ids']=array_column($roster,'student_id');
        $command=movingUpCommand($input); $preview=previewMovingUp($db,currentUser(),$command);
    } catch(DomainException|InvalidArgumentException $e) { $error=$e->getMessage(); }
}
$pageTitle='Moving Up';
$sectionPreparationQuery=http_build_query(['source_year_id'=>$sourceYearId,'target_year_id'=>$targetYearId,'return_to'=>'../students/moving_up.php?'.http_build_query(['source_year_id'=>$sourceYearId,'source_section_id'=>$sourceSectionId,'target_year_id'=>$targetYearId])]);
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<div class="students-page workflow-page">
<?php showFlash(); ?>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<div class="card students-card mb-4">
<div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
<h1 class="page-title mb-0"><i class="bi bi-mortarboard me-2 text-primary" aria-hidden="true"></i>Moving Up</h1>
<div class="d-flex flex-wrap gap-2">
<a href="../sections/prepare.php?<?= sanitize($sectionPreparationQuery) ?>" class="btn btn-outline-success"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Prepare Next-Year Sections</a>
<a href="enrollment_corrections.php" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#enrollmentCorrectionsModal"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Enrollment Corrections</a>
</div>
</div>
<div class="card-body">
<p class="text-muted small mb-3">Prepare a future school-year batch. Current placement and attendance stay unchanged until year activation. Use Enrollment Corrections to correct or cancel a prepared enrollment or graduation decision.</p>
<div class="section-divider"><i class="bi bi-funnel" aria-hidden="true"></i>Choose Source and Destination</div>
<form method="GET" class="row g-2 align-items-end">
<div class="col-md-4 col-xl-3">
<label class="form-label" for="source-year">Source school year</label><select class="form-select" name="source_year_id" id="source-year">
<?php foreach($years as $year): if(!in_array($year['state'],['active','closed'],true)) continue; ?><option value="<?= (int)$year['id'] ?>" <?= (int)$year['id']===$sourceYearId?'selected':'' ?>><?= sanitize($year['label']) ?></option><?php endforeach; ?></select>
</div><div class="col-md-4 col-xl-3">
<label class="form-label" for="source-section">Source section</label><select class="form-select" name="source_section_id" id="source-section"><option value="">Choose a source section</option>
<?php foreach($sections as $section): ?><option value="<?= (int)$section['id'] ?>" data-year="<?= (int)$section['school_year_id'] ?>" <?= (int)$section['id']===$sourceSectionId?'selected':'' ?> <?= (int)$section['school_year_id']!==$sourceYearId?'hidden disabled':'' ?>><?= sanitize($section['grade_level'].' — '.$section['section_name']) ?></option><?php endforeach; ?></select>
</div><div class="col-md-4 col-xl-3">
<label class="form-label" for="target-year">Target school year</label><select class="form-select" name="target_year_id" id="target-year"><option value="">Choose a planned year</option>
<?php foreach($years as $year): if($year['state']!=='planned') continue; ?><option value="<?= (int)$year['id'] ?>" <?= (int)$year['id']===$targetYearId?'selected':'' ?>><?= sanitize($year['label'].' ('.$year['starts_on'].' through '.$year['ends_on'].')') ?></option><?php endforeach; ?></select>
</div><div class="col-md-12 col-xl-3">
<button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Load source roster</button>
</div>
</form>
</div></div>
<?php if(!$targetYearId): ?><div class="alert alert-info">Create and verify the next school year before preparing a batch. <a href="../settings/school_years.php">Create school year</a></div><?php endif; ?>
<?php if($sourceSectionId && $targetYearId): ?>
<form method="POST" class="card students-card students-list workflow-list mb-4">
<div class="card-header d-flex justify-content-center align-items-center text-center">
<h2 class="h6 mb-0"><i class="bi bi-table me-1" aria-hidden="true"></i>Prepare Student Roster <span class="badge bg-primary ms-1"><?= count($roster) ?></span></h2>
</div>
<?= csrfField() ?><input type="hidden" name="source_year_id" value="<?= $sourceYearId ?>"><input type="hidden" name="source_section_id" value="<?= $sourceSectionId ?>"><input type="hidden" name="target_year_id" value="<?= $targetYearId ?>">
<div class="card-body pb-2">
<p class="small text-muted mb-3">Roster uses each student's final annual assignment. Prepare separate batches for different outcomes or destinations.</p>
<label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="select_all" value="1" id="select-all-roster" <?= !$roster?'disabled':'' ?>> Select all <?= count($roster) ?> students in this source-section roster</label>
</div>
<div class="table-responsive"><table class="table table-hover students-table mb-0"><thead><tr><th scope="col">Select</th><th scope="col">Student</th><th scope="col">Source grade</th></tr></thead><tbody>
<?php if(!$roster): ?><tr><td colspan="3" class="text-center text-muted py-5"><i class="bi bi-people fs-2 d-block mb-2" aria-hidden="true"></i>No students found in this source-section roster.</td></tr><?php endif; ?>
<?php foreach($roster as $row): ?><tr><td class="text-center"><input class="form-check-input roster-student" type="checkbox" name="student_ids[]" value="<?= $row['student_id'] ?>" aria-label="Select <?= sanitize($row['last_name'].', '.$row['first_name']) ?>" <?= in_array($row['student_id'],$command['student_ids'] ?? [],true)?'checked':'' ?>></td><td class="fw-semibold"><?= sanitize($row['last_name'].', '.$row['first_name']) ?></td><td class="text-center"><span class="badge bg-secondary"><?= sanitize($row['grade_level']) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="card-body border-top">
<div class="section-divider"><i class="bi bi-arrow-up-right-circle" aria-hidden="true"></i>Batch Outcome</div>
<div class="row g-2 align-items-end">
<div class="col-md-4">
<label for="outcome" class="form-label">Outcome</label><select class="form-select" name="outcome" id="outcome"><?php foreach(['promoted'=>'Promoted','retained'=>'Retained','graduated'=>'Graduated (Grade 6 only)'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($input['outcome'] ?? 'promoted')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
</div><div class="col-md-5">
<label for="destination" class="form-label">Destination section (none for graduation)</label><select class="form-select" name="destination_section_id" id="destination"><option value="">No destination</option>
<?php foreach($destinationSections as $section): ?><option value="<?= (int)$section['id'] ?>" <?= (int)($input['destination_section_id'] ?? 0)===(int)$section['id']?'selected':'' ?>><?= sanitize($section['grade_level'].' — '.$section['section_name']) ?></option><?php endforeach; ?></select>
</div><div class="col-md-3">
<button type="submit" class="btn btn-primary" <?= !$roster?'disabled':'' ?>><i class="bi bi-eye me-1" aria-hidden="true"></i>Preview batch</button>
</div></div></div>
<?php if(!$destinationSections): ?>
<div class="card-footer">
<p class="small mb-2">No active destination sections exist for <?= sanitize($targetYear['label'] ?? '') ?>. Promoted and retained students need a section in the target school year. Graduation does not need a destination.</p>
<a class="btn btn-outline-primary" href="../sections/sections.php?school_year_id=<?= $targetYearId ?>"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Manage target-year sections</a>
<a class="btn btn-outline-success" href="../sections/prepare.php?<?= sanitize($sectionPreparationQuery) ?>">Copy sections from an existing year</a>
<p class="small text-muted mt-2 mb-0">Add a section with the required grade in that year, or restore an archived one. Then return here and load the source roster again.</p>
</div>
<?php endif; ?>
</form>
<?php endif; ?>
<?php if($preview): ?>
<?php $destinationSection=null; foreach($sections as $section) if((int)$section['id']===($command['destination_section_id'] ?? 0)) $destinationSection=$section; ?>
<div class="card students-card students-list workflow-list mb-4">
<div class="card-header d-flex justify-content-center align-items-center text-center"><h2 class="h6 mb-0"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Confirm Prepared Batch <span class="badge bg-primary ms-1"><?= count($preview['rows']) ?></span></h2></div>
<div class="card-body"><p class="small mb-2"><?= count($preview['rows']) ?> students: <?= sanitize($command['outcome']) ?>. Source year <?= sanitize($sourceYear['label'] ?? '') ?>; target year <?= sanitize($targetYear['label'] ?? '') ?> (<?= sanitize($targetYear['starts_on'] ?? '') ?> through <?= sanitize($targetYear['ends_on'] ?? '') ?>).</p>
<p class="small text-muted mb-0">Destination: <?= $destinationSection ? sanitize($destinationSection['grade_level'].' — '.$destinationSection['section_name']) : 'Graduation decision only; no destination enrollment' ?>.</p></div>
<div class="table-responsive"><table class="table table-hover students-table mb-0"><thead><tr><th scope="col">Student</th><th scope="col">Source grade</th><th scope="col">Outcome</th></tr></thead><tbody>
<?php $names=array_column($roster,null,'student_id'); foreach($preview['rows'] as $row): ?><tr><td class="fw-semibold"><?= sanitize(($names[$row['student_id']]['last_name'] ?? '').', '.($names[$row['student_id']]['first_name'] ?? '')) ?></td><td class="text-center"><span class="badge bg-secondary"><?= sanitize($row['grade_level']) ?></span></td><td class="text-center"><?= sanitize(ucfirst($row['outcome'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="card-footer py-3">
<form method="POST" action="save_moving_up.php"><?= csrfField() ?>
<?php foreach($command as $key=>$value): if($key==='student_ids') { foreach($value as $id) echo '<input type="hidden" name="student_ids[]" value="'.$id.'">'; } else { ?><input type="hidden" name="<?= sanitize($key) ?>" value="<?= sanitize((string)$value) ?>"><?php } endforeach; ?>
<?php if(($input['select_all'] ?? '')==='1'): ?><input type="hidden" name="select_all" value="1"><?php endif; ?>
<input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><input type="hidden" name="expected_fingerprint" value="<?= sanitize($preview['fingerprint']) ?>">
<button class="btn btn-success" type="submit"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Confirm and prepare <?= count($preview['rows']) ?> students</button></form></div></div>
<?php endif; ?>
<div class="modal fade" id="enrollmentCorrectionsModal" tabindex="-1" aria-labelledby="enrollmentCorrectionsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-6" id="enrollmentCorrectionsModalTitle"><i class="bi bi-pencil-square me-2 text-primary" aria-hidden="true"></i>Enrollment Corrections</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close enrollment corrections"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="enrollmentCorrectionsFrame" title="Enrollment corrections form" data-src="enrollment_corrections.php?modal=1" class="w-100 border-0 d-block" style="height:75dvh"></iframe>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('enrollmentCorrectionsModal').addEventListener('show.bs.modal',()=>{
    const frame=document.getElementById('enrollmentCorrectionsFrame');
    if(!frame.getAttribute('src')) frame.src=frame.dataset.src;
});
const sourceYear=document.getElementById('source-year'), sourceSection=document.getElementById('source-section');
sourceYear.addEventListener('change',()=>{for(const option of sourceSection.options){if(!option.dataset.year)continue;option.hidden=option.disabled=option.dataset.year!==sourceYear.value;}sourceSection.value='';});
const all=document.getElementById('select-all-roster');
if(all){all.addEventListener('change',()=>document.querySelectorAll('.roster-student').forEach(box=>box.checked=all.checked));document.querySelectorAll('.roster-student').forEach(box=>box.addEventListener('change',()=>{all.checked=[...document.querySelectorAll('.roster-student')].every(item=>item.checked);}));}
const outcome=document.getElementById('outcome'), destination=document.getElementById('destination');
if(outcome){const update=()=>{destination.disabled=outcome.value==='graduated';if(destination.disabled)destination.value='';};outcome.addEventListener('change',update);update();}
</script>
<?php echo '</div><!-- /.workflow-page -->';
include '../includes/footer.php'; ?>
