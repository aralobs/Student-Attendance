<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';
checkEnrollment(function_exists('processAbsenceInterventions'),'Intervention dispatcher exists');
withEnrollmentFixture(function(array $f):void {
    $db=$f['db']; ensureDeliverySchema($db); ensureAttendanceDecisionSchema($db); ensureInterventionSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE student_enrollments SET starts_on='2026-10-19' WHERE id=1001; UPDATE enrollment_sections SET starts_on='2026-10-19' WHERE id=2001; UPDATE students SET parent_contact='09123456789' WHERE id=101");
    foreach(range(19,23) as $n) saveAttendanceDecision($db,$f['teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-'.$n,'decision'=>'absent_unexcused','reason'=>'Synthetic absence','expected_revision'=>0]);
    $now=new DateTimeImmutable('2026-10-23 18:00:00',new DateTimeZone('Asia/Manila'));
    $r=recomputeEnrollmentInterventions($db,1001,'2026-10-19',$now);
    checkEnrollment($r['count']===5 && $r['state']==='intervention_required','Five committed absences produce an episode');
    $sends=0; $sender=function()use(&$sends){$sends++;return 'sent';};
    putenv('ATTENDANCE_INTERVENTION_ENABLED_AT'); processAbsenceInterventions($db,$now,$sender);
    checkEnrollment($sends===0,'Disabled by default');
    putenv('ATTENDANCE_INTERVENTION_ENABLED_AT=2026-10-23 00:00:00');
    processAbsenceInterventions($db,$now,$sender);
    checkEnrollment($sends===0,'Historical late confirmation cannot silently send');
    $db->exec("UPDATE attendance_decisions SET confirmed_at=CONCAT(learning_date,' 16:00:00')");
    processAbsenceInterventions($db,$now,$sender); processAbsenceInterventions($db,$now,$sender);
    checkEnrollment($sends===1,'Exactly one local reservation across repeated dispatch');
    saveAttendanceDecision($db,$f['teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-23','decision'=>'present','reason'=>'Synthetic correction','expected_revision'=>1]);
    recomputeEnrollmentInterventions($db,1001,'2026-10-23',$now);
    checkEnrollment((int)$db->query('SELECT superseded FROM absence_notifications')->fetchColumn()===1,'Correction supersedes sent alert without deleting it');
    checkEnrollment($db->query('SELECT status FROM absence_notifications')->fetchColumn()==='sent','Sent outcome retained');
    putenv('ATTENDANCE_INTERVENTION_ENABLED_AT');
});
echo "Intervention SMS passed (synthetic sender only).\n";
