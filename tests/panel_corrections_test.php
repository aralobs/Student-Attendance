<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once dirname(__DIR__).'/includes/attendance_decisions.php';require_once dirname(__DIR__).'/includes/enrollment_corrections.php';
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);
 $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
 $saved=saveAttendanceDecision($db,$f['teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-20','decision'=>'absent_excused','reason'=>'Synthetic','expected_revision'=>0]);
 $command=['kind'=>'historical_assignment','assignment_id'=>2001,'section_id'=>11,'starts_on'=>'2026-10-20','ends_on'=>'2026-10-20','reason'=>'Synthetic ownership correction'];
 $preview=previewEnrollmentCorrection($db,$f['admin'],1001,$command);
 checkEnrollment(($preview['affected_decision_ids']??[])===[(int)$saved['id']],'Preview must include exact manual decision IDs');
 applyEnrollmentCorrection($db,$f['admin'],$preview['correction_id'],$preview['fingerprint']);
 $sheet=loadAttendanceSheet($db,$f['otherTeacher'],1,11,'2026-10-20');
 checkEnrollment(count($sheet)===1 && $sheet[0]['classification']['status']==='absent_excused','Destination retains corrected manual attendance');
 checkEnrollment((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn()===1,'No invented scan row');
});echo "Manual decision enrollment correction passed.\n";
