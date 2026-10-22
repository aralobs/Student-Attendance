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
if (!$targetYearId && $sourceYear) foreach($years as $year) if($year['state']==='planned' && $year['starts_on']>$sourceYear['ends_on']) { $targetYearId=(int)$year['id']; break; }
$targetYear=null; foreach($years as $year) if((int)$year['id']===$targetYearId) $targetYear=$year;
$sourceSectionId=(int)($input['source_section_id'] ?? 0);
$sections=$db->query('SELECT id,section_name,grade_level,school_year_id,is_active FROM sections ORDER BY grade_level,section_name,id')->fetchAll();
$roster=$sourceSectionId>0 ? listMovingUpSourceRoster($db,currentUser(),$sourceYearId,$sourceSectionId) : [];
$preview=null; $command=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (($input['select_all'] ?? '')==='1') $input['student_ids']=array_column($roster,'student_id');
        $command=movingUpCommand($input); $preview=previewMovingUp($db,currentUser(),$command);
    } catch(DomainException|InvalidArgumentException $e) { $error=$e->getMessage(); }
}
$pageTitle='Moving Up';
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="page-header"><div><h1 class="page-title">Moving Up</h1><p class="page-subtitle">Prepare a future school-year batch. Current placement and attendance stay unchanged until year activation.</p></div></div>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<form method="GET" class="card card-body mb-3">
<label class="form-label" for="source-year">Source school year</label><select class="form-select mb-2" name="source_year_id" id="source-year">
<?php foreach($years as $year): if(!in_array($year['state'],['active','closed'],true)) continue; ?><option value="<?= (int)$year['id'] ?>" <?= (int)$year['id']===$sourceYearId?'selected':'' ?>><?= sanitize($year['label']) ?></option><?php endforeach; ?></select>
<label class="form-label" for="source-section">Source section</label><select class="form-select mb-2" name="source_section_id" id="source-section"><option value="">Choose a source section</option>
<?php foreach($sections as $section): ?><option value="<?= (int)$section['id'] ?>" data-year="<?= (int)$section['school_year_id'] ?>" <?= (int)$section['id']===$sourceSectionId?'selected':'' ?> <?= (int)$section['school_year_id']!==$sourceYearId?'hidden disabled':'' ?>><?= sanitize($section['grade_level'].' — '.$section['section_name']) ?></option><?php endforeach; ?></select>
<label class="form-label" for="target-year">Target school year</label><select class="form-select mb-2" name="target_year_id" id="target-year"><option value="">Choose a planned year</option>
<?php foreach($years as $year): if($year['state']!=='planned') continue; ?><option value="<?= (int)$year['id'] ?>" <?= (int)$year['id']===$targetYearId?'selected':'' ?>><?= sanitize($year['label'].' ('.$year['starts_on'].' through '.$year['ends_on'].')') ?></option><?php endforeach; ?></select>
<button class="btn btn-outline-primary" type="submit">Load source roster</button>
</form>
<?php if(!$targetYearId): ?><div class="alert alert-info">Create and verify the next school year before preparing a batch. <a href="../settings/school_years.php">Create school year</a></div><?php endif; ?>
<?php if($sourceSectionId && $targetYearId): ?>
<form method="POST" class="card card-body mb-3">
<?= csrfField() ?><input type="hidden" name="source_year_id" value="<?= $sourceYearId ?>"><input type="hidden" name="source_section_id" value="<?= $sourceSectionId ?>"><input type="hidden" name="target_year_id" value="<?= $targetYearId ?>">
<p>Roster uses each student's final annual assignment. Prepare separate batches for different outcomes or destinations.</p>
<label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="select_all" value="1" id="select-all-roster"> Select all <?= count($roster) ?> students in this source-section roster</label>
<div class="table-responsive"><table class="table"><thead><tr><th>Select</th><th>Student</th><th>Source grade</th></tr></thead><tbody>
<?php foreach($roster as $row): ?><tr><td><input class="form-check-input roster-student" type="checkbox" name="student_ids[]" value="<?= $row['student_id'] ?>" <?= in_array($row['student_id'],$command['student_ids'] ?? [],true)?'checked':'' ?>></td><td><?= sanitize($row['last_name'].', '.$row['first_name']) ?></td><td><?= sanitize($row['grade_level']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<label for="outcome" class="form-label">Outcome</label><select class="form-select mb-2" name="outcome" id="outcome"><?php foreach(['promoted'=>'Promoted','retained'=>'Retained','graduated'=>'Graduated (Grade 6 only)'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($input['outcome'] ?? 'promoted')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
<label for="destination" class="form-label">Destination section (none for graduation)</label><select class="form-select mb-2" name="destination_section_id" id="destination"><option value="">No destination</option>
<?php foreach($sections as $section): if((int)$section['school_year_id']!==$targetYearId || !(int)$section['is_active']) continue; ?><option value="<?= (int)$section['id'] ?>" <?= (int)($input['destination_section_id'] ?? 0)===(int)$section['id']?'selected':'' ?>><?= sanitize($section['grade_level'].' — '.$section['section_name']) ?></option><?php endforeach; ?></select>
<button type="submit" class="btn btn-primary" <?= !$roster?'disabled':'' ?>>Preview batch</button>
</form>
<?php endif; ?>
<?php if($preview): ?>
<?php $destinationSection=null; foreach($sections as $section) if((int)$section['id']===($command['destination_section_id'] ?? 0)) $destinationSection=$section; ?>
<div class="card card-body mb-3"><h2 class="h5">Confirm prepared batch</h2><p><?= count($preview['rows']) ?> students: <?= sanitize($command['outcome']) ?>. Source year <?= sanitize($sourceYear['label'] ?? '') ?>; target year <?= sanitize($targetYear['label'] ?? '') ?> (<?= sanitize($targetYear['starts_on'] ?? '') ?> through <?= sanitize($targetYear['ends_on'] ?? '') ?>).</p>
<p>Destination: <?= $destinationSection ? sanitize($destinationSection['grade_level'].' — '.$destinationSection['section_name']) : 'Graduation decision only; no destination enrollment' ?>.</p>
<ul><?php $names=array_column($roster,null,'student_id'); foreach($preview['rows'] as $row): ?><li><?= sanitize(($names[$row['student_id']]['last_name'] ?? '').', '.($names[$row['student_id']]['first_name'] ?? '')) ?> — <?= sanitize($row['grade_level'].' → '.$row['outcome']) ?></li><?php endforeach; ?></ul>
<form method="POST" action="save_moving_up.php"><?= csrfField() ?>
<?php foreach($command as $key=>$value): if($key==='student_ids') { foreach($value as $id) echo '<input type="hidden" name="student_ids[]" value="'.$id.'">'; } else { ?><input type="hidden" name="<?= sanitize($key) ?>" value="<?= sanitize((string)$value) ?>"><?php } endforeach; ?>
<?php if(($input['select_all'] ?? '')==='1'): ?><input type="hidden" name="select_all" value="1"><?php endif; ?>
<input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><input type="hidden" name="expected_fingerprint" value="<?= sanitize($preview['fingerprint']) ?>">
<button class="btn btn-success" type="submit">Confirm and prepare <?= count($preview['rows']) ?> students</button></form></div>
<?php endif; ?>
<script>
const sourceYear=document.getElementById('source-year'), sourceSection=document.getElementById('source-section');
sourceYear.addEventListener('change',()=>{for(const option of sourceSection.options){if(!option.dataset.year)continue;option.hidden=option.disabled=option.dataset.year!==sourceYear.value;}sourceSection.value='';});
const all=document.getElementById('select-all-roster');
if(all){all.addEventListener('change',()=>document.querySelectorAll('.roster-student').forEach(box=>box.checked=all.checked));document.querySelectorAll('.roster-student').forEach(box=>box.addEventListener('change',()=>{all.checked=[...document.querySelectorAll('.roster-student')].every(item=>item.checked);}));}
const outcome=document.getElementById('outcome'), destination=document.getElementById('destination');
if(outcome){const update=()=>{destination.disabled=outcome.value==='graduated';if(destination.disabled)destination.value='';};outcome.addEventListener('change',update);update();}
</script>
<?php include '../includes/footer.php'; ?>
