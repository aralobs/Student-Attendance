<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/attendance_decisions.php';
withEnrollmentFixture(function(array $f):void {
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);
 $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
 saveAttendanceDecision($db,$f['teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-20','decision'=>'absent_excused','reason'=>'Synthetic','expected_revision'=>0]);
 $report=loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31');
 checkEnrollment(function_exists('overlayAttendanceDecisions'),'Report decision overlay must exist');
 $report=overlayAttendanceDecisions($db,$report);
 checkEnrollment(count($report['attendance'])===2,'Official report includes decision with no scan');
 $source=enrollmentReportSources($db,$report)['attendance'];
 checkEnrollment((int)$db->query("SELECT COUNT(*) FROM $source a WHERE a.attendance_type='absent'")->fetchColumn()===1,'Excused remains in absence totals');
});
echo "Report decisions passed.\n";
