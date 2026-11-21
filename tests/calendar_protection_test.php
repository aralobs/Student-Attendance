<?php
require_once __DIR__.'/support/enrollment_fixture.php';
$path=dirname(__DIR__).'/includes/calendar_protection.php';if(is_file($path))require_once $path;
checkEnrollment(function_exists('saveProtectedCalendarEntry'),'Calendar writes must use shared historical protection');
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);
 $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
 expectEnrollmentError(DomainException::class,fn()=>saveProtectedCalendarEntry($db,$f['admin'],['date'=>'2026-10-21','title'=>'Unsafe closure','type'=>'holiday','description'=>'']));
 saveAttendanceDecision($db,$f['teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-20','decision'=>'present','reason'=>'','expected_revision'=>0]);
 expectEnrollmentError(DomainException::class,fn()=>saveProtectedCalendarEntry($db,$f['admin'],['date'=>'2026-10-20','title'=>'Unsafe closure','type'=>'holiday','description'=>'']));
 saveProtectedCalendarEntry($db,$f['admin'],['date'=>'2026-10-19','title'=>'Synthetic closure','type'=>'holiday','description'=>'']);
 $db->exec("UPDATE school_years SET state='closed' WHERE id=1");
 expectEnrollmentError(DomainException::class,fn()=>saveProtectedCalendarEntry($db,$f['admin'],['date'=>'2026-10-19','delete'=>true]));
});echo "Calendar historical protection passed.\n";
