<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/enrollment_migration.php';
require_once dirname(__DIR__).'/includes/enrollment_reports.php';

// One representative-volume proof, not a timing-threshold benchmark. Values
// printed below are counts/plans/timings only; no learner data or SQL literals.
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; $p=enrollmentInitializationPreview($db,$f['admin'],1);
    initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']);
    $began=hrtime(true); $db->beginTransaction();
    $section=$db->prepare("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(?,?,'Grade 3',?,'2026-2027',1)");
    for ($i=0;$i<24;$i++) $section->execute([100+$i,'Synthetic volume '.($i+1),$i%2?8:7]);
    $profile=$db->prepare("INSERT INTO students(id,lrn,first_name,last_name,gender,section_id,grade_level,school_year,qr_token) VALUES(?,?,?,'Volume','Male',?,'Grade 3','2026-2027',?)");
    $annual=$db->prepare("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(?,?,1,'active','2026-06-01','2027-03-31')");
    $assignment=$db->prepare("INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(?,?,?,'Grade 3','2026-06-01','2027-03-31')");
    for ($i=0;$i<1200;$i++) {
        $id=10000+$i; $sectionId=100+intdiv($i,50);
        $profile->execute([$id,'VOLUME'.$id,'Synthetic '.$id,$sectionId,'volume-'.$id]);
        $annual->execute([$id,$id]); $assignment->execute([$id,$id,$sectionId]);
    }
    $dates=[];
    for ($month=6;$month<=10;$month++) {
        $day=new DateTimeImmutable(sprintf('2026-%02d-01',$month)); $added=0;
        while ($added<10) {
            if ((int)$day->format('N')<=5) { $dates[]=$day->format('Y-m-d'); $added++; }
            $day=$day->modify('+1 day');
        }
    }
    $insert=$db->prepare('INSERT INTO attendance(student_id,date,enrollment_id,assignment_id,am_in,pm_out,am_status,pm_status,attendance_type) VALUES '.implode(',',array_fill(0,500,"(?,?,?,?,'07:00:00','17:10:00','present','present','full_day')")));
    $values=[];
    foreach ($dates as $date) for ($i=0;$i<1200;$i++) {
        $id=10000+$i; array_push($values,$id,$date,$id,$id);
        if (count($values)===2000) { $insert->execute($values); $values=[]; }
    }
    checkEnrollment($values===[],'Volume fixture ends on complete insert batches'); $db->commit();
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn()===1202,'Representative fixture has 1,202 learners including original two');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn()===60001,'Representative fixture has 60,001 owned scans including original scan');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sections WHERE school_year_id=1')->fetchColumn()===27,'Representative current year includes 24 added sections and three original');
    $db->query('ANALYZE TABLE student_enrollments,enrollment_sections,attendance')->fetchAll();
    echo 'Volume seed: added_learners=1200 added_sections=24 added_owned_scans=60000 months=5 school_days_per_month=10 elapsed_ms='.round((hrtime(true)-$began)/1e6,2).' php_memory_limit='.ini_get('memory_limit').".\n";

    $page1=listEnrollmentRoster($db,1,100,'2026-06-01','2027-03-31',$f['teacher'],1,25);
    $page2=listEnrollmentRoster($db,1,100,'2026-06-01','2027-03-31',$f['teacher'],2,25);
    $page3=listEnrollmentRoster($db,1,100,'2026-06-01','2027-03-31',$f['teacher'],3,25);
    checkEnrollment($page1['total']===50 && count($page1['rows'])===25 && count($page2['rows'])===25 && !$page3['rows'],'25-row repository pages cover full 50-member section');
    $ids=array_merge(array_column($page1['rows'],'student_id'),array_column($page2['rows'],'student_id'));
    checkEnrollment(count(array_unique($ids))===50 && $ids===range(10000,10049),'25-row pages contain distinct stable ordered learners without gaps');
    checkEnrollment(listEnrollmentRoster($db,1,101,'2026-06-01','2027-03-31',$f['teacher'],1,25)['total']===0,'Teacher paging excludes another adviser section');

    $plans=static function(string $label,string $sql,array $params=[]) use($db): void {
        $q=$db->prepare('EXPLAIN '.$sql); $q->execute($params); $rows=$q->fetchAll();
        $safe=[];
        foreach ($rows as $row) $safe[]=array_intersect_key($row,array_flip(['select_type','table','type','possible_keys','key','key_len','rows','Extra']));
        checkEnrollment((bool)$rows,'EXPLAIN returned a plan for '.$label);
        echo 'Query plan '.$label.': '.json_encode($safe,JSON_THROW_ON_ERROR)."\n";
    };
    $plans('annual-year-dates',"SELECT id FROM student_enrollments WHERE school_year_id=? AND state='active' AND starts_on<=? AND ends_on>=?",[1,'2026-10-31','2026-10-01']);
    $plans('section-assignment-dates','SELECT id,enrollment_id FROM enrollment_sections WHERE section_id=? AND starts_on<=? AND ends_on>=?',[100,'2026-10-31','2026-10-01']);
    $plans('attendance-owner-dates','SELECT id FROM attendance WHERE enrollment_id=? AND date BETWEEN ? AND ?',[10000,'2026-10-01','2026-10-31']);
    $scope=attendanceOwnershipScope('a',$f['admin'],1,100);
    $plans('actual-report-section-month',"SELECT a.*,s.first_name,s.middle_name,s.last_name,s.lrn,s.gender,s.photo,own.section_id,own.grade_level,sec.section_name,sec.schedule_type FROM attendance a JOIN students s ON s.id=a.student_id JOIN enrollment_sections own ON own.id=a.assignment_id AND own.enrollment_id=a.enrollment_id JOIN sections sec ON sec.id=own.section_id WHERE a.date BETWEEN ? AND ? AND ({$scope['sql']}) ORDER BY a.date,a.student_id",array_merge(['2026-10-01','2026-10-31'],$scope['params']));

    foreach ([['monthly','2026-10-01','2026-10-31',12001],['whole-year','2026-06-01','2027-03-31',60001]] as [$name,$from,$to,$scanCount]) {
        $started=hrtime(true);
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $report=loadEnrollmentReport($db,$f['admin'],1,null,$from,$to);
        checkEnrollment(count($report['roster'])===1202 && count($report['attendance'])===$scanCount,'Actual '.$name.' report loader retains every authorized learner and scan');
        checkEnrollment(count(array_unique(array_column($report['attendance'],'id')))===$scanCount,'Actual '.$name.' report scans are not duplicated');
        $sources=enrollmentReportSources($db,$report);
        $studentCount=(int)$db->query('SELECT COUNT(*) FROM '.$sources['students'].' report_students')->fetchColumn();
        $attendanceCount=(int)$db->query('SELECT COUNT(*) FROM '.$sources['attendance'].' report_attendance')->fetchColumn();
        checkEnrollment($studentCount===1202 && $attendanceCount===$scanCount,'Generated '.$name.' authorized-ID SQL executes with exact counts');
        $queryPlan=$db->query('EXPLAIN SELECT COUNT(*) FROM '.$sources['attendance'].' report_attendance')->fetchAll();
        $safePlan=[]; foreach ($queryPlan as $row) $safePlan[]=array_intersect_key($row,array_flip(['select_type','table','type','possible_keys','key','rows','Extra']));
        echo 'Report volume '.json_encode(['scope'=>$name,'learners'=>$studentCount,'attendance'=>$attendanceCount,'student_source_sql_bytes'=>strlen($sources['students']),'attendance_source_sql_bytes'=>strlen($sources['attendance']),'elapsed_ms'=>round((hrtime(true)-$started)/1e6,2),'peak_memory_bytes'=>memory_get_peak_usage(true),'authorized_attendance_plan'=>$safePlan],JSON_THROW_ON_ERROR)."\n";
        unset($report,$sources,$queryPlan);
    }
    $teacher=loadEnrollmentReport($db,$f['teacher'],1,null,'2026-10-01','2026-10-31');
    checkEnrollment(count($teacher['roster'])===602 && count($teacher['attendance'])===6001,'Teacher monthly loader restricts 12 assigned volume sections plus original history');
    $sources=enrollmentReportSources($db,$teacher);
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM '.$sources['students'].' teacher_students')->fetchColumn()===602 && (int)$db->query('SELECT COUNT(*) FROM '.$sources['attendance'].' teacher_attendance')->fetchColumn()===6001,'Teacher authorized-ID SQL preserves exact access-scoped counts');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Volume report proof sends no notifications');
});

echo 'Enrollment representative performance tests passed ('.($GLOBALS['enrollment_checks']??0)." checks).\n";
