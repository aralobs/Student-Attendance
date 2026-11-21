<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/attendance_decisions.php';
require_once dirname(__DIR__).'/includes/attendance_finalizer.php';
withEnrollmentFixture(function(array $f):void {
    $db=$f['db']; ensureDeliverySchema($db); ensureAttendanceDecisionSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); INSERT INTO delivery_modes(school_year_id,scope_section_id,learning_date,mode,recorded_by) VALUES(1,0,'2026-10-20','online',1)");
    finalizeEnrollmentAttendanceDate($db,'2026-10-20',10,new DateTimeImmutable('2026-10-21 18:00:00'));
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM attendance WHERE date='2026-10-20'")->fetchColumn()===0,'Online nonparticipants cannot auto-finalize as absence');
    // This tests finalizer preservation, independently of the current teacher editing window.
    saveAttendanceDecision($db,$f['admin'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-19','decision'=>'absent_excused','reason'=>'Synthetic illness','expected_revision'=>0]);
    finalizeEnrollmentAttendanceDate($db,'2026-10-19',10,new DateTimeImmutable('2026-10-21 18:00:00'));
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM attendance WHERE date='2026-10-19'")->fetchColumn()===0,'Decision protects against worker absence');
});
echo "Delivery finalization passed.\n";
