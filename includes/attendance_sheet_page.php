<?php
// Included only after the entry point has authenticated staff and verified schema readiness.
require_once __DIR__.'/attendance_decisions.php';
$panelManual=$panelManual??false; $pageTitle=$panelManual?'Manual Attendance':'Attendance'; $error=null; $rows=[]; $sections=[];
require_once __DIR__.'/viewing_school_year.php';
$viewingYear=requireViewingSchoolYear($db,currentUser(),$_SERVER['REQUEST_METHOD']==='POST' ? ['school_year_id'=>$_POST['school_year_id']??0] : $_GET);
$date=validatedSchoolYearDate($_GET['date']??$_POST['date']??viewingSchoolYearDate($viewingYear));
$yearId=(int)($_GET['school_year_id']??$_POST['school_year_id']??0);
if (!$yearId) $yearId=(int)$viewingYear['id'];
ob_start(); renderViewingSchoolYearControl($db,currentUser(),$viewingYear); $pageSchoolYearControl=ob_get_clean();
$sectionId=(int)($_GET['section']??$_POST['section_id']??0);
try {
    $sections=enrollmentReportSections($db,currentUser(),$yearId);
    if ($panelManual && !$sectionId && $sections) $sectionId=(int)$sections[0]['id'];
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireCsrfPost();
        $saved=saveAttendanceDecision($db,currentUser(),[
            'student_id'=>$_POST['student_id']??0,'section_id'=>$sectionId,'date'=>$date,'decision'=>$_POST['decision']??'',
            'reason'=>$_POST['reason']??'','participation_method'=>$_POST['participation_method']??null,
            'expected_revision'=>$_POST['expected_revision']??null,'scan_revision'=>$_POST['scan_revision']??''
        ]);
        $noticeText='';
        if(!empty($saved['absence_notice_id'])){
            try{
                $outcome=dispatchManualAbsenceNotification($db,currentUser(),$saved['absence_notice_id'],static function($phone,$student,$learningDate):string{
                    $status=null;sendSMS($phone,renderNotificationText(notificationTemplate('sms_absence_template'),$student,$learningDate),(int)$student['id'],'absence',$status);
                    return $status==='sent'?'sent':($status==='failed'?'failed':'unknown');
                });$noticeText=' Parent absence SMS: '.$outcome.'.';
            }catch(Throwable $e){$noticeText=' Parent absence notice needs follow-up.';}
        }
        setFlash('success','Attendance decision saved. Original scans were preserved.'.$noticeText);
        header('Location: manual.php?'.http_build_query(['section'=>$sectionId,'date'=>$date,'school_year_id'=>$yearId])); exit;
    }
    if ($panelManual) {
        if ($sectionId) $rows=loadAttendanceSheet($db,currentUser(),$yearId,$sectionId,$date);
    } else {
        require __DIR__.'/attendance_overview_data.php';
    }
} catch (DomainException|InvalidArgumentException $e) { http_response_code(400); $error=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $error='Unable to load or save attendance. Please try again.'; }
[$yearScope,$yearParams]=enrollmentSectionScope('sec',currentUser(),true);
$yearRead=$db->prepare("SELECT DISTINCT y.id,y.label,y.starts_on FROM school_years y JOIN sections sec ON sec.school_year_id=y.id WHERE ($yearScope) ORDER BY y.starts_on DESC");
$yearRead->execute($yearParams); $years=$yearRead->fetchAll();
$base=['section'=>$sectionId,'date'=>$date,'school_year_id'=>$yearId];
$teacherDateError=null;
if (isTeacher()) { try { requireTeacherAttendanceDate($db,currentUser(),$date); } catch (DomainException|InvalidArgumentException $e) { $teacherDateError=$e->getMessage(); } }
include __DIR__.'/header.php'; include __DIR__.'/sidebar.php';
include __DIR__.'/intervention_alert.php';
?>
<?php showFlash(); ?>
<?php if (!$panelManual) {
    include __DIR__.'/attendance_overview.php';
    include __DIR__.'/footer.php';
    return;
} ?>
<div class="students-page workflow-page">
<div class="card students-card workflow-heading mb-4"><div><h1 class="page-title"><?= $pageTitle ?></h1><p class="page-subtitle">Full eligible roster. Decisions preserve original scan evidence.</p></div>
<a class="btn btn-primary" href="<?= $panelManual?'attendance.php':'manual.php' ?>?<?= sanitize(http_build_query($base)) ?>"><?= $panelManual?'Attendance Sheet':'Manual Entry' ?></a></div>
<?php if($teacherDateError): ?><div class="alert alert-info"><?= sanitize($teacherDateError) ?> <a href="../students/change_requests.php">Change Requests</a></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-warning"><?= sanitize($error) ?></div><?php endif; ?>
<div class="card students-card mb-4"><div class="card-header"><i class="bi bi-funnel me-2 text-primary" aria-hidden="true"></i>Choose Attendance Roster</div><div class="card-body"><form method="get" class="row g-2"><input type="hidden" name="school_year_id" value="<?= $yearId ?>">
<div class="col-md-3"><label class="form-label">Date</label><input type="date" class="form-control" name="date" value="<?= sanitize($date) ?>" required></div><div class="col-md-4"><label class="form-label">Section</label><select class="form-select" name="section"><option value="0">Choose section for selected year</option><?php foreach($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$sectionId?'selected':'' ?>><?= sanitize($s['grade_level'].' — '.$s['section_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2 align-self-end"><button class="btn btn-outline-primary">Apply</button></div></form></div></div>
<p><?= count($rows) ?> eligible learners. <a href="../reports/sf2.php?<?= sanitize(http_build_query(['section'=>$sectionId,'month'=>(int)substr($date,5,2),'year'=>(int)substr($date,0,4),'school_year_id'=>$yearId])) ?>">Monthly SF2</a></p>
<?php if($rows): ?><div class="alert alert-info">Delivery: <?= sanitize(deliveryLabels()[$rows[0]['delivery']['mode']]??'No class') ?>. <?php if($rows[0]['delivery']['deadline_at']): ?>Deadline: <?= sanitize($rows[0]['delivery']['deadline_at']) ?> (Manila).<?php endif; ?> Pending and review-needed participation do not count as absence.</div><?php endif; ?>
<div class="card students-card workflow-list"><div class="table-responsive"><table data-list-search="Search student name, LRN or attendance status..." data-list-title="Attendance List" class="table align-middle students-table"><thead><tr><th>Learner</th><th>Status</th><th>Scan evidence</th><th><?= $panelManual?'Record / correct':'Details' ?></th></tr></thead><tbody>
<?php foreach($rows as $row): $status=$row['classification']['status']; $decision=$row['decision']; ?>
<tr><td><?= sanitize($row['last_name'].', '.$row['first_name']) ?><small class="d-block text-muted"><?= sanitize($row['lrn']) ?></small></td><td><?= sanitize(attendanceDecisionLabels()[$status]) ?></td>
<td><?php foreach(['am_in'=>'AM in','am_out'=>'AM out','pm_in'=>'PM in','pm_out'=>'PM out'] as $key=>$label): if(!empty($row['scan'][$key])): ?><div><?= $label ?>: <?= sanitize($row['scan'][$key]) ?></div><?php endif; endforeach; ?><?php if(!$row['scan']): ?><span class="text-muted">No scans</span><?php endif; ?></td>
<td><?php if($panelManual && !$teacherDateError && ($viewingYear['state']??'')==='active' && !in_array($status,['future','not_eligible'],true)): ?>
<details><summary>Record / correct status</summary><form method="post" class="mt-2"><?= csrfField() ?>
<?php foreach(array_merge($base,['section_id'=>$sectionId,'student_id'=>$row['student_id'],'expected_revision'=>$decision['revision']??0,'scan_revision'=>$row['scan_revision']]) as $key=>$value): ?><input type="hidden" name="<?= sanitize($key) ?>" value="<?= sanitize((string)$value) ?>"><?php endforeach; ?>
<label class="form-label">Attendance decision</label><select name="decision" class="form-select mb-2"><?php foreach(array_slice(attendanceDecisionLabels(),0,7,true) as $key=>$label): ?><option value="<?= $key ?>" <?= ($decision['decision']??'')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
<label class="form-label">Participation method</label><select name="participation_method" class="form-select mb-2"><option value="">Not applicable (face-to-face / absence)</option><?php foreach(['live_class'=>'Live class','submitted_work'=>'Submitted work','module_submission'=>'Module submission','parent_contact'=>'Parent contact','other_activity'=>'Other accepted activity'] as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select>
<label class="form-label">Reason (required for Excused or correction)</label><textarea name="reason" class="form-control mb-2" maxlength="2000"></textarea><button class="btn btn-sm btn-primary">Save Decision</button></form></details>
<?php elseif(!$panelManual): ?><a href="manual.php?<?= sanitize(http_build_query($base)) ?>">Manual Entry</a><?php endif; ?>
<?php if($decision): ?><small class="d-block text-muted">Revision <?= (int)$decision['revision'] ?> · Actor #<?= (int)$decision['recorded_by'] ?> · <?= sanitize($decision['confirmed_at']) ?></small><?php if(!empty($decision['reason_ciphertext'])): ?><details><summary>Reason</summary><?= sanitize(decryptPII($decision['reason_ciphertext'],'attendance-decision:'.$row['enrollment_id'].':'.$date)) ?></details><?php endif; ?><?php endif; ?>
<?php if($row['absence_notification_status']): ?><small class="d-block">Parent absence SMS: <?= sanitize($row['absence_notification_status']) ?><?= in_array($row['absence_notification_status'],['failed','unknown','sending','pending'],true)?' — follow up if unconfirmed':'' ?></small><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="4">No eligible roster for these filters.</td></tr><?php endif; ?></tbody></table></div></div>
<?php echo '</div><!-- /.workflow-page -->';
include __DIR__.'/footer.php'; ?>
