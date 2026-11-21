<?php
declare(strict_types=1);
require_once __DIR__.'/delivery_modes.php';

function manualAbsenceNoticeSchemaSQL():string {
    return "CREATE TABLE IF NOT EXISTS attendance_absence_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,enrollment_id INT NOT NULL,learning_date DATE NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',attempted_at DATETIME NULL,completed_at DATETIME NULL,
        UNIQUE KEY daily_absence_notice(enrollment_id,learning_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}

/** Existing daily absence SMS, kept separate from intervention episodes. Provider I/O follows commit. */
function dispatchManualAbsenceNotification(PDO $db,array $actor,int $noticeId,callable $sender):string {
    if($db->inTransaction())throw new LogicException('Daily absence notification owns its transaction.');
    $q=$db->prepare('SELECT n.*,e.student_id FROM attendance_absence_notifications n JOIN student_enrollments e ON e.id=n.enrollment_id WHERE n.id=?');$q->execute([$noticeId]);$candidate=$q->fetch(PDO::FETCH_ASSOC);
    if(!$candidate)return 'cancelled';
    $db->beginTransaction();
    try{
        $lock=lockAttendanceEnrollment($db,(int)$candidate['student_id'],$candidate['learning_date'],$actor);$ctx=$lock['context'];
        if($ctx['enrollment_id']!==(int)$candidate['enrollment_id'])throw new DomainException('Attendance notification ownership changed.');
        $q=$db->prepare('SELECT * FROM attendance_absence_notifications WHERE id=? FOR UPDATE');$q->execute([$noticeId]);$notice=$q->fetch(PDO::FETCH_ASSOC);
        if($notice['status']!=='pending'){$db->commit();return $notice['status'];}
        $q=$db->prepare('SELECT decision FROM attendance_decisions WHERE enrollment_id=? AND learning_date=? FOR UPDATE');$q->execute([$ctx['enrollment_id'],$candidate['learning_date']]);$decision=$q->fetchColumn();
        if($decision!=='absent_unexcused'){
            $db->prepare("UPDATE attendance_absence_notifications SET status='cancelled',completed_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'),$noticeId]);$db->commit();return 'cancelled';
        }
        $delivery=resolveDeliveryDay($db,$ctx['school_year_id'],$ctx['section_id'],$candidate['learning_date']);
        if(!$delivery['eligible'])throw new DomainException('Attendance day is no longer eligible.');
        $q=$db->prepare('SELECT id,first_name,last_name,parent_contact FROM students WHERE id=?');$q->execute([$ctx['student_id']]);$student=$q->fetch(PDO::FETCH_ASSOC);
        $db->prepare("UPDATE attendance_absence_notifications SET status='sending',attempted_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'),$noticeId]);$db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $phone=preg_replace('/[^0-9+]/','',(string)$student['parent_contact']);$status='failed';
    if(preg_match('/^(?:09\d{9}|\+?639\d{9})$/D',$phone)){
        try{$status=$sender($phone,$student,$candidate['learning_date']);if(!in_array($status,['sent','failed','unknown'],true))$status='unknown';}catch(Throwable $e){$status='unknown';}
    }
    $db->prepare('UPDATE attendance_absence_notifications SET status=?,completed_at=? WHERE id=?')->execute([$status,date('Y-m-d H:i:s'),$noticeId]);return $status;
}
