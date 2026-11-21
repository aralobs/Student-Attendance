<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once dirname(__DIR__).'/includes/attendance_decisions.php';
checkEnrollment(function_exists('dispatchManualAbsenceNotification'),'Manual absence notification adapter must exist');
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);$db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1');UPDATE students SET parent_contact='09123456789' WHERE id=101");
 $command=['student_id'=>101,'section_id'=>10,'date'=>'2026-10-20','decision'=>'absent_unexcused','reason'=>'Synthetic absence','expected_revision'=>0];
 $saved=saveAttendanceDecision($db,$f['teacher'],$command);checkEnrollment(!empty($saved['absence_notice_id']),'New confirmed absence reserves existing daily notice');
 $sends=0;$sender=function($phone,$student,$date)use(&$sends):string{$sends++;checkEnrollment($date==='2026-10-20','Message uses learning date');return 'sent';};
 dispatchManualAbsenceNotification($db,$f['teacher'],$saved['absence_notice_id'],$sender);dispatchManualAbsenceNotification($db,$f['teacher'],$saved['absence_notice_id'],$sender);checkEnrollment($sends===1,'Repeated dispatch makes one attempt');
 $command['expected_revision']=1;$saved=saveAttendanceDecision($db,$f['teacher'],$command);checkEnrollment(empty($saved['absence_notice_id']),'Unchanged absence saves do not reserve/send again');
 $command['date']='2026-10-19';$command['expected_revision']=0;$command['decision']='absent_excused';$saved=saveAttendanceDecision($db,$f['teacher'],$command);checkEnrollment(empty($saved['absence_notice_id']),'Excused creates no daily notice');
 $command['date']='2026-10-22';$command['decision']='absent_unexcused';$saved=saveAttendanceDecision($db,$f['teacher'],$command);
 $command['expected_revision']=1;$command['decision']='present';saveAttendanceDecision($db,$f['teacher'],$command);
 $outcome=dispatchManualAbsenceNotification($db,$f['teacher'],$saved['absence_notice_id'],$sender);checkEnrollment($outcome==='cancelled' && $sends===1,'Correction before claim cancels obsolete daily notice');
});echo "Manual daily absence notification passed (one reserved synthetic send).\n";
