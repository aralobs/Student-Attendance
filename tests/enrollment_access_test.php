<?php
require_once __DIR__ . '/support/enrollment_fixture.php';
if (is_file(__DIR__ . '/../includes/enrollment_access.php')) require_once __DIR__ . '/../includes/enrollment_access.php';
withEnrollmentFixture(function ($f) {
    checkEnrollment(function_exists('requireEnrollmentWrite'), 'Enrollment authorization service is missing');
    $db = $f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    checkEnrollment(requireEnrollmentWrite($db,$f['teacher'],101,'2026-10-22')['section_id'] === 10, 'Assigned teacher writes');
    expectEnrollmentError(DomainException::class,fn()=>requireEnrollmentWrite($db,$f['otherTeacher'],101,'2026-10-22'));
    expectEnrollmentError(DomainException::class,fn()=>requireEnrollmentWrite($db,['id'=>7,'role'=>'scanner_operator'],101,'2026-10-22'));
    checkEnrollment(listEnrollmentRoster($db,1,10,'2026-10-01','2026-10-31',$f['otherTeacher'])['total'] === 0, 'Other teacher denied roster');
    checkEnrollment(listEnrollmentRoster($db,1,10,'2026-10-01','2026-10-31',['id'=>7,'role'=>'scanner_operator'])['total'] === 0, 'Scanner denied staff history');
    expectEnrollmentError(InvalidArgumentException::class,fn()=>enrollmentSectionScope('sec;DROP',$f['admin']));
    $db->beginTransaction();
    checkEnrollment(requireEnrollmentWrite($db,$f['teacher'],101,'2026-10-22')['assignment_id'] === 2001 && $db->inTransaction(), 'Guard respects caller transaction');
    $db->rollBack();
    $db->exec("UPDATE sections SET adviser_id=8,is_active=0 WHERE id=10; UPDATE students SET section_id=20,is_active=0 WHERE id=101;
      UPDATE school_years SET state='closed' WHERE id=1; UPDATE student_enrollments SET state='completed' WHERE id=1001");
    checkEnrollment(listEnrollmentRoster($db,1,10,'2026-10-01','2026-10-31',$f['teacher'])['total'] === 0, 'Former adviser revoked on history');
    checkEnrollment(listEnrollmentRoster($db,1,10,'2026-10-01','2026-10-31',$f['otherTeacher'])['total'] === 1, 'Current adviser reads archived selected section');
    expectEnrollmentError(DomainException::class,fn()=>requireEnrollmentWrite($db,$f['admin'],101,'2026-10-22'));
});
echo "Enrollment access checks passed (" . $GLOBALS['enrollment_checks'] . ").\n";
