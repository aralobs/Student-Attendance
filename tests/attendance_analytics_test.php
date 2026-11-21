<?php
require_once __DIR__.'/support/enrollment_fixture.php';
$path=dirname(__DIR__).'/includes/attendance_analytics.php'; if(is_file($path)) require_once $path;
checkEnrollment(function_exists('summarizeAttendanceTimeline'),'Shared analytics summary must exist');
$days=[['date'=>'2026-10-20','status'=>'present','present_units'=>1.0,'absent_units'=>0.0,'unresolved'=>false,'eligible'=>true,'enrollment_id'=>1,'mode'=>'face_to_face'],
['date'=>'2026-10-20','status'=>'partial','present_units'=>0.5,'absent_units'=>0.5,'unresolved'=>false,'eligible'=>true,'enrollment_id'=>2,'mode'=>'face_to_face'],
['date'=>'2026-10-21','status'=>'absent_excused','present_units'=>0.0,'absent_units'=>1.0,'unresolved'=>false,'eligible'=>true,'enrollment_id'=>1,'mode'=>'online'],
['date'=>'2026-10-21','status'=>'not_recorded','present_units'=>0.0,'absent_units'=>0.0,'unresolved'=>true,'eligible'=>true,'enrollment_id'=>2,'mode'=>'online']];
$s=summarizeAttendanceTimeline($days);
checkEnrollment($s['eligible_days']===4 && $s['attendance_rate']===37.5,'Rate denominator includes eligible unresolved learner-days');
checkEnrollment($s['absent_units']===1.5 && $s['coverage']===75.0,'Excused totals and completion coverage explicit');
checkEnrollment($s['learners']===2,'Learners distinct from learner-days');
checkEnrollment(summarizeAttendanceTimeline([])['attendance_rate']===null,'Zero denominator is N/A');
withEnrollmentFixture(function(array $f):void {
    $db=$f['db']; ensureDeliverySchema($db); ensureAttendanceDecisionSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $now=new DateTimeImmutable('2026-10-25 18:00:00');
    $data=loadMonthlyAttendanceAnalytics($db,$f['teacher'],1,2026,10,10,$now);
    checkEnrollment(count($data['daily'])===31,'Every date has a daily row');
    checkEnrollment($data['summary']['counts']['partial']===1,'Existing scan day counted');
    expectEnrollmentError(DomainException::class,fn()=>loadMonthlyAttendanceAnalytics($db,$f['otherTeacher'],1,2026,10,10,$now));
    expectEnrollmentError(DomainException::class,fn()=>loadMonthlyAttendanceAnalytics($db,$f['teacher'],1,2027,10,10,$now));
});
echo "Attendance analytics passed.\n";
