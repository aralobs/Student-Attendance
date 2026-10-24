<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/enrollment_corrections.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Enrollment corrections require an administrator.'); }
$db=getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
requireMovingUpAdmin($db,currentUser());
guardCsrfPage();
$input=$_SERVER['REQUEST_METHOD']==='POST' ? $_POST : $_GET;
$enrollmentId=(int)($input['enrollment_id'] ?? 0); $studentId=(int)($input['student_id'] ?? 0);
$enrollments=$db->query('SELECT e.id,e.student_id,e.school_year_id,e.state,e.starts_on,e.ends_on,e.version,y.label,y.state AS year_state FROM student_enrollments e JOIN school_years y ON y.id=e.school_year_id ORDER BY y.starts_on DESC,e.student_id,e.id')->fetchAll();
if ($studentId) $enrollments=array_values(array_filter($enrollments,fn($e)=>(int)$e['student_id']===$studentId));
$enrollment=null; foreach ($enrollments as $row) if ((int)$row['id']===$enrollmentId) $enrollment=$row;
$assignments=[]; $sections=[]; $graduations=[];
if ($enrollment) {
    $q=$db->prepare('SELECT es.*,s.section_name FROM enrollment_sections es JOIN sections s ON s.id=es.section_id WHERE es.enrollment_id=? ORDER BY es.starts_on'); $q->execute([$enrollmentId]); $assignments=$q->fetchAll();
    $q=$db->prepare('SELECT id,section_name,grade_level,is_active FROM sections WHERE school_year_id=? ORDER BY grade_level,section_name'); $q->execute([$enrollment['school_year_id']]); $sections=$q->fetchAll();
    $q=$db->prepare("SELECT d.id,d.version,y.label FROM moving_up_decisions d JOIN school_years y ON y.id=d.target_year_id WHERE d.source_enrollment_id=? AND d.state='prepared' AND d.outcome='graduated' AND d.destination_enrollment_id IS NULL AND y.state='planned' ORDER BY d.id"); $q->execute([$enrollmentId]); $graduations=$q->fetchAll();
}
// Load report coordinates explicitly; an administrator never types an optimistic version.
$reportSectionId=(int)($input['report_section_id'] ?? ((($input['kind'] ?? '')==='sf2_remark') ? ($input['section_id'] ?? 0) : ($assignments[0]['section_id'] ?? 0)));
$reportMonth=(int)($input['report_month'] ?? date('n')); $reportYear=(int)($input['report_year'] ?? date('Y'));
$loadedRemark=null; $loadedRemarkText=''; $reportMembership=false;
if ($enrollment && $reportMonth>=1 && $reportMonth<=12 && $reportYear>=1900 && $reportYear<=2100) {
    $from=sprintf('%04d-%02d-01',$reportYear,$reportMonth); $to=date('Y-m-t',strtotime($from));
    foreach ($assignments as $a) if ((int)$a['section_id']===$reportSectionId && $a['starts_on']<=$to && $a['ends_on']>=$from) $reportMembership=true;
    if ($reportMembership) {
        $q=$db->prepare('SELECT remark_ciphertext,version FROM sf2_remarks WHERE student_id=? AND section_id=? AND report_month=? AND report_year=?');
        $q->execute([(int)$enrollment['student_id'],$reportSectionId,$reportMonth,$reportYear]); $loadedRemark=$q->fetch();
        if ($loadedRemark) $loadedRemarkText=decryptPII($loadedRemark['remark_ciphertext'],'sf2:'.$enrollment['student_id'].':'.$reportSectionId.':'.$reportMonth.':'.$reportYear);
    }
}
$preview=null; $command=null; $error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $command=enrollmentCorrectionCommand($input);
        $preview=previewEnrollmentCorrection($db,currentUser(),$enrollmentId,$command);
    } catch(DomainException|InvalidArgumentException $e) { http_response_code(409); $error=$e->getMessage(); }
    catch(Throwable $e) { http_response_code(500); $error='Unable to create the correction preview. Reload and try again.'; }
}
$pageTitle='Enrollment Corrections';
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<div class="main-content"><h1 class="h3">Enrollment Corrections</h1>
<p>Review the effective dates and exact attendance impact, then confirm with the same administrator account. Enter a reason for every correction.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php showFlash(); ?>
<form method="GET" class="card card-body mb-3">
<label for="enrollment" class="form-label">Annual enrollment</label>
<select id="enrollment" class="form-select mb-2" name="enrollment_id"><option value="">Choose an enrollment</option>
<?php foreach ($enrollments as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)$row['id']===$enrollmentId?'selected':'' ?>><?= sanitize('Student #'.$row['student_id'].' · '.$row['label'].' · enrollment #'.$row['id'].' · '.$row['state']) ?></option><?php endforeach; ?></select>
<button class="btn btn-outline-primary" type="submit">Load enrollment</button></form>
<?php if ($enrollment): ?>
<div class="card card-body mb-3"><h2 class="h5"><?= sanitize($enrollment['label']) ?>: <?= sanitize($enrollment['starts_on'].' through '.$enrollment['ends_on']) ?></h2>
<p>Student #<?= (int)$enrollment['student_id'] ?> · enrollment #<?= $enrollmentId ?> · <?= sanitize($enrollment['state']) ?> · year <?= sanitize($enrollment['year_state']) ?>.</p>
<?php if ($enrollment['year_state']==='closed'): ?><p class="alert alert-warning">This year remains closed. Only the explicitly reviewed historical assignment or SF2 remark correction may be applied.</p><?php endif; ?>
<ul><?php foreach ($assignments as $a): ?><li>Assignment #<?= (int)$a['id'] ?>: <?= sanitize($a['grade_level'].' — '.$a['section_name'].' ('.$a['starts_on'].' through '.$a['ends_on'].')') ?></li><?php endforeach; ?></ul>
</div>
<form method="POST" class="card card-body mb-3">
<?= csrfField() ?><input type="hidden" name="enrollment_id" value="<?= $enrollmentId ?>">
<label for="kind" class="form-label">Correction</label><select name="kind" id="kind" class="form-select mb-2">
<?php foreach (['reassign'=>'Future reassignment / reinstate unused prepared destination','cancel'=>'Cancel prepared destination without attendance','historical_assignment'=>'Reviewed historical assignment'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($input['kind'] ?? '')===$key?'selected':'' ?>><?= sanitize($label) ?></option><?php endforeach; ?></select>
<label for="assignment" class="form-label">Assignment to revise (placement corrections)</label><select name="assignment_id" id="assignment" class="form-select mb-2">
<?php foreach ($assignments as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)($input['assignment_id'] ?? 0)===(int)$a['id']?'selected':'' ?>><?= sanitize('#'.$a['id'].' '.$a['section_name'].' '.$a['starts_on'].' through '.$a['ends_on']) ?></option><?php endforeach; ?></select>
<label for="section" class="form-label">Destination section, or original SF2 report section</label><select name="section_id" id="section" class="form-select mb-2">
<?php foreach ($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($input['section_id'] ?? 0)===(int)$s['id']?'selected':'' ?>><?= sanitize($s['grade_level'].' — '.$s['section_name'].(!(int)$s['is_active']?' (archived)':'')) ?></option><?php endforeach; ?></select>
<label for="starts" class="form-label">Effective start (inclusive)</label><input id="starts" class="form-control mb-2" type="date" name="starts_on" value="<?= sanitize($input['starts_on'] ?? $enrollment['starts_on']) ?>">
<label for="ends" class="form-label">Effective end (inclusive)</label><input id="ends" class="form-control mb-2" type="date" name="ends_on" value="<?= sanitize($input['ends_on'] ?? $enrollment['ends_on']) ?>">

<label for="reason" class="form-label">Reason</label><textarea id="reason" class="form-control mb-3" name="reason" required maxlength="4000"><?= sanitize($input['reason'] ?? '') ?></textarea>
<button type="submit" class="btn btn-primary">Preview correction</button></form>
<form method="GET" class="card card-body mb-3">
<input type="hidden" name="enrollment_id" value="<?= $enrollmentId ?>"><h2 class="h5">Load SF2 remark</h2>
<label for="report-section" class="form-label">Original report section</label><select name="report_section_id" id="report-section" class="form-select mb-2">
<?php foreach ($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$reportSectionId?'selected':'' ?>><?= sanitize($s['grade_level'].' — '.$s['section_name']) ?></option><?php endforeach; ?></select>
<label for="month">Report month</label><input id="month" class="form-control mb-2" type="number" min="1" max="12" name="report_month" value="<?= $reportMonth ?>">
<label for="report-year">Report calendar year</label><input id="report-year" class="form-control mb-2" type="number" min="1900" max="2100" name="report_year" value="<?= $reportYear ?>">
<button class="btn btn-outline-primary" type="submit">Load existing remark</button></form>
<?php if ($reportMembership): ?>
<form method="POST" class="card card-body mb-3">
<?= csrfField() ?><input type="hidden" name="enrollment_id" value="<?= $enrollmentId ?>"><input type="hidden" name="kind" value="sf2_remark">
<input type="hidden" name="section_id" value="<?= $reportSectionId ?>"><input type="hidden" name="report_month" value="<?= $reportMonth ?>"><input type="hidden" name="report_year" value="<?= $reportYear ?>">
<input type="hidden" name="expected_remark_version" value="<?= (int)($loadedRemark['version'] ?? 0) ?>">
<h2 class="h5">Correct SF2 remark</h2><p>Section #<?= $reportSectionId ?> · <?= $reportMonth ?>/<?= $reportYear ?>.</p>
<p>Existing remark: <?= $loadedRemarkText!=='' ? sanitize($loadedRemarkText) : 'No remark saved.' ?></p>
<label for="remark" class="form-label">Corrected remark</label><textarea id="remark" class="form-control mb-2" name="remark" maxlength="1000"><?= sanitize(($input['kind'] ?? '')==='sf2_remark' ? ($input['remark'] ?? '') : $loadedRemarkText) ?></textarea>
<label for="remark-reason" class="form-label">Reason</label><textarea id="remark-reason" class="form-control mb-2" name="reason" required maxlength="4000"><?= sanitize(($input['kind'] ?? '')==='sf2_remark' ? ($input['reason'] ?? '') : '') ?></textarea>
<button class="btn btn-primary" type="submit">Preview remark correction</button></form>
<?php else: ?><p>No annual membership exists for the selected SF2 report coordinates.</p><?php endif; ?>
<?php foreach ($graduations as $decision): ?>
<form method="POST" action="save_enrollment_correction.php" class="card card-body mb-3">
<?= csrfField() ?><input type="hidden" name="action" value="cancel_graduation"><input type="hidden" name="decision_id" value="<?= (int)$decision['id'] ?>"><input type="hidden" name="expected_version" value="<?= (int)$decision['version'] ?>">
<h2 class="h5">Cancel prepared graduation</h2><p>Decision #<?= (int)$decision['id'] ?> for <?= sanitize($decision['label']) ?>. This cancels the pending graduation decision and preserves the source enrollment and attendance. Choose the replacement outcome through <a href="moving_up.php">Moving Up</a>.</p>
<label class="form-label" for="graduation-reason-<?= (int)$decision['id'] ?>">Reason</label><textarea class="form-control mb-2" id="graduation-reason-<?= (int)$decision['id'] ?>" name="reason" required maxlength="4000"></textarea><button type="submit" class="btn btn-outline-danger">Cancel prepared graduation</button></form>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($preview): ?>
<div class="card card-body mb-3"><h2 class="h5">Review correction #<?= $preview['correction_id'] ?></h2>
<p>Enrollment #<?= $enrollmentId ?> · <?= sanitize($command['kind']) ?>.</p>
<?php if (isset($command['starts_on'])): ?><p>Effective dates: <?= sanitize($command['starts_on'].' through '.$command['ends_on']) ?>. Destination section #<?= $command['section_id'] ?>.</p><?php endif; ?>
<?php if ($command['kind']==='sf2_remark'): ?><p>SF2 section #<?= $command['section_id'] ?>, <?= $command['report_month'] ?>/<?= $command['report_year'] ?>.</p><p>Corrected remark: <?= sanitize($command['remark']) ?></p><?php endif; ?>
<p>Reason: <?= sanitize($command['reason']) ?></p>
<p>Attendance ownership affected: <?= count($preview['affected_attendance_ids']) ?> record(s). Interval splits may also change assignment IDs in the later preserved portion.</p>
<?php if ($preview['affected_attendance_ids']): ?><ul><?php foreach ($preview['affected_attendance_ids'] as $attendanceId): ?><li>Attendance #<?= $attendanceId ?></li><?php endforeach; ?></ul><?php else: ?><p>No attendance records change.</p><?php endif; ?>
<p>Scan values, timestamps and notification history are preserved.</p>
<form method="POST" action="save_enrollment_correction.php"><?= csrfField() ?><input type="hidden" name="correction_id" value="<?= $preview['correction_id'] ?>"><input type="hidden" name="expected_fingerprint" value="<?= sanitize($preview['fingerprint']) ?>"><button class="btn btn-success" type="submit">Confirm correction</button></form></div>
<?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>


