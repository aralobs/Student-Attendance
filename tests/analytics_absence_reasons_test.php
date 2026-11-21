<?php
require_once __DIR__.'/support/enrollment_fixture.php';
$helper=dirname(__DIR__).'/includes/analytics_absence_reasons.php';
if(is_file($helper))require_once $helper;
checkEnrollment(function_exists('topAnalyticsAbsenceReason'),'Absence tooltip reason aggregation must exist');
checkEnrollment(topAnalyticsAbsenceReason(['Sickness','sick','Family emergency',''])==='Sickness','Equivalent sickness reasons count together');
checkEnrollment(topAnalyticsAbsenceReason(['Automatically marked absent','Absent','','No scans'])===null,'Missing reasons and generated attendance notes are not absence causes');
checkEnrollment(topAnalyticsAbsenceReason(['Family emergency','Sickness'])==='Family reasons','Ties choose one deterministic category');
checkEnrollment(topAnalyticsAbsenceReason(['Private synthetic explanation'])==='Other recorded reason','Tooltip exposes a short category rather than private free text');
require_once dirname(__DIR__).'/includes/attendance_decisions.php';
withEnrollmentFixture(function(array $f):void {
    $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    foreach(['2026-10-12'=>'online','2026-10-13'=>'modular'] as $date=>$mode){
        $cmd=['year_id'=>1,'section_id'=>10,'from'=>$date,'to'=>$date,'mode'=>$mode,'deadline_at'=>$mode==='modular'?$date.' 17:00:00':null];
        $preview=saveDeliveryModes($db,$f['teacher'],$cmd);$cmd['confirm_token']=$preview['token'];saveDeliveryModes($db,$f['teacher'],$cmd);
        saveAttendanceDecision($db,$f['admin'],['student_id'=>101,'section_id'=>10,'date'=>$date,'decision'=>'absent_excused','reason'=>'Sickness','expected_revision'=>0]);
    }
    saveAttendanceDecision($db,$f['admin'],['student_id'=>106,'section_id'=>16,'date'=>'2026-10-12','decision'=>'absent_excused','reason'=>'Family emergency','expected_revision'=>0]);
    $report=overlayAttendanceDecisions($db,loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31'));
    $context=array_merge(enrollmentReportSources($db,$report),['managed'=>true,'join'=>' AND a.enrollment_id=s.enrollment_id AND a.report_section_id=s.section_id']);
    checkEnrollment(loadAnalyticsTopAbsenceReason($db,$context,2026,10,$f['teacher'])==='Sickness','Reasons use authorized month-scoped decision rows including Online and Modular');
    checkEnrollment(loadAnalyticsTopAbsenceReason($db,$context,2026,9,$f['teacher'])===null,'Other months do not leak into the tooltip');
    checkEnrollment(loadAnalyticsTopAbsenceReason($db,$context,2026,10,$f['otherTeacher'])===null,'Unassigned teachers cannot obtain reason aggregates');
    $source=$context['attendance'];
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM $source a WHERE a.attendance_type='absent'")->fetchColumn()===2,'Both Online and Modular confirmed absences reach chart totals');
    foreach(['2026-10-14'=>'online','2026-10-15'=>'modular','2026-10-16'=>'modular'] as $date=>$mode){
        $cmd=['year_id'=>1,'section_id'=>10,'from'=>$date,'to'=>$date,'mode'=>$mode,'deadline_at'=>$mode==='modular'?$date.' 17:00:00':null];
        $preview=saveDeliveryModes($db,$f['teacher'],$cmd);$cmd['confirm_token']=$preview['token'];saveDeliveryModes($db,$f['teacher'],$cmd);
    }
    saveAttendanceDecision($db,$f['admin'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-14','decision'=>'present','participation_method'=>'live_class','reason'=>'','expected_revision'=>0]);
    saveAttendanceDecision($db,$f['admin'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-15','decision'=>'pending','reason'=>'','expected_revision'=>0]);
    saveAttendanceDecision($db,$f['admin'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-16','decision'=>'present','participation_method'=>'module_submission','reason'=>'','expected_revision'=>0]);
    $report=overlayAttendanceDecisions($db,loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31'));
    $source=enrollmentReportSources($db,$report)['attendance'];
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM $source a WHERE a.attendance_type='absent'")->fetchColumn()===2,'Pending participation does not become absent');
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM $source a WHERE a.attendance_type='full_day'")->fetchColumn()===2,'Confirmed Online participation and Modular submission reach present totals');
});
echo 'Analytics absence reasons passed ('.$GLOBALS['enrollment_checks']." checks).\n";
