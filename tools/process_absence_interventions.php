<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/functions.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';
$db=getDB(true); requirePanelFeatures($db);
$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
$db->prepare("UPDATE attendance_absence_notifications SET status='unknown',completed_at=? WHERE status='sending' AND attempted_at<?")->execute([$now->format('Y-m-d H:i:s'),$now->modify('-15 minutes')->format('Y-m-d H:i:s')]);
// Recompute all active enrollments so scanner/finalizer writes are included even without a browser visit.
foreach($db->query("SELECT id FROM student_enrollments WHERE state='active' OR id IN(SELECT enrollment_id FROM attendance_recompute_queue)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    recomputeEnrollmentInterventions($db,(int)$id,$now->format('Y-m-d'),$now);
}
$result=in_array('--dry-run',$argv,true)?['dry_run'=>true]:processAbsenceInterventions($db,$now,static function($phone,$student,$episode):string {
    $status=null;
    $template=notificationTemplate('sms_intervention_template');
    sendSMS($phone,renderNotificationText($template,$student,$episode['threshold_date']),(int)$student['student_id'],'intervention',$status);
    return $status==='sent'?'sent':($status==='failed'?'failed':'unknown');
});
echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
