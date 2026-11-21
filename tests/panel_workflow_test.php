<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';require_once dirname(__DIR__).'/includes/database_backups.php';
withEnrollmentFixture(function(array $f):void{
    $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);ensureInterventionSchema($db);ensureBackupSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $db->exec("UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $db->exec("UPDATE students SET parent_contact='09123456789' WHERE id=101");
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8));createEnrollmentAppFixture($copy);
    mkdir($copy.'/vendor');file_put_contents($copy.'/vendor/autoload.php','<?php require '.var_export(dirname(__DIR__).'/vendor/autoload.php',true).';');
    if(!is_dir($copy.'/assets/img'))mkdir($copy.'/assets/img',0700,true);copy(dirname(__DIR__).'/assets/img/school_logo.png',$copy.'/assets/img/school_logo.png');
    try{
        $get=['section'=>10,'date'=>'2026-10-20','school_year_id'=>1];
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/attendance.php',$f['teacher'],[],$get,'GET');
        file_put_contents(dirname(__DIR__).'/.qa/panel-sheet.html',$r['body']); checkEnrollment($r['status']===200 && str_contains($r['body'],'Not recorded') && str_contains($r['body'],'Three'),'Daily sheet includes learner with no scans');
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/manual.php',$f['teacher'],[],$get,'GET');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Excused absence'),'Manual form exposes explicit Excused');
        $post=['section_id'=>10,'date'=>'2026-10-20','school_year_id'=>1,'student_id'=>101,'decision'=>'present','reason'=>'','expected_revision'=>0,'scan_revision'=>hash('sha256','null')];
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/manual.php',$f['teacher'],$post,[],'POST');
        checkEnrollment($r['status']===302,'Teacher saves no-scan present decision');
        $absence=array_replace($post,['date'=>'2026-10-19','decision'=>'absent_unexcused','reason'=>'Synthetic absence']);
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/manual.php',$f['teacher'],$absence,[],'POST');checkEnrollment($r['status']===302,'New manual absence preserves daily SMS flow');
        $absence['expected_revision']=1;$r=runEnrollmentAppEndpoint($copy,$f,'attendance/manual.php',$f['teacher'],$absence,[],'POST');checkEnrollment($r['status']===302,'Unchanged absence decision can be saved without resending');
        $excused=array_replace($post,['date'=>'2026-10-22','decision'=>'absent_excused','reason'=>'Synthetic illness']);runEnrollmentAppEndpoint($copy,$f,'attendance/manual.php',$f['teacher'],$excused,[],'POST');
        checkEnrollment(is_file($copy.'/provider-calls.log') && count(file($copy.'/provider-calls.log'))===1,'Manual absence sends once; repeated and Excused saves send nothing');
        foreach(['calendar/delivery_modes.php','attendance/interventions.php','backups/index.php'] as $page){$r=runEnrollmentAppEndpoint($copy,$f,$page,$f['admin'],[],[],'GET');checkEnrollment($r['status']===200,'New page renders: '.$page);}
        $r=runEnrollmentAppEndpoint($copy,$f,'backups/index.php',$f['teacher'],[],[],'GET');checkEnrollment($r['status']===302 && !str_contains($r['body'],'Database Backups'),'Teacher redirected before viewing backups');
        $r=runEnrollmentAppEndpoint($copy,$f,'backups/create.php',$f['admin'],['csrf_token'=>'invalid'],[],'POST');checkEnrollment($r['status']===403,'Backup creation requires CSRF');
        $r=runEnrollmentAppEndpoint($copy,$f,'analytics/analytics.php',$f['teacher'],[],['section'=>10,'school_year_id'=>1,'month'=>10,'year'=>2026],'GET');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'id="monthlyDoughnut"') && str_contains($r['body'],'id="monthlyGradeChart"') && !str_contains($r['body'],'todayLogBody') && !str_contains($r['body'],'get_today_log.php'),'Analytics preserves existing charts without the duplicate live log or polling');
        file_put_contents(dirname(__DIR__).'/.qa/analytics-tooltip.html',$r['body']);
        $db->exec("INSERT INTO school_calendar(date,title,type) VALUES('2026-08-21','Ninoy Aquino Day','holiday'),('2026-08-31','National Heroes Day','holiday')");
        $db->exec("INSERT INTO delivery_modes(school_year_id,scope_section_id,learning_date,mode,deadline_at,recorded_by) VALUES(1,10,'2026-08-10','modular','2026-08-14 17:00:00',7)");
        $r=runEnrollmentAppEndpoint($copy,$f,'reports/sf2.php',$f['teacher'],[],['section'=>10,'school_year_id'=>1,'month'=>8,'year'=>2026],'GET');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'data-day-label="MODULAR"'),'SF2 reads the section delivery schedule for vertical modular labels');
        checkEnrollment(str_contains($r['body'],'data-day-label="NINOY AQUINO DAY"') && str_contains($r['body'],'data-day-label="NATIONAL HEROES DAY"'),'SF2 reads holiday names from the calendar');
        foreach(['sf2','sf4'] as $form){
            $r=runEnrollmentAppEndpoint($copy,$f,'reports/'.$form.'.php',$f['teacher'],[],['section'=>10,'school_year_id'=>1,'month'=>10,'year'=>2026,'download_pdf'=>'1'],'GET');
            checkEnrollment($r['status']===200 && str_starts_with($r['body'],'%PDF-'),'Direct PDF action returns PDF bytes: '.$form);
            $pdf=preg_replace('/\nRESULT:.*$/s','',$r['body']);$out=dirname(__DIR__).'/.qa/pdf';if(!is_dir($out))mkdir($out,0700,true);file_put_contents($out.'/workflow-'.$form.'.pdf',$pdf);
        }
        $r=runEnrollmentAppEndpoint($copy,$f,'reports/export_pdf.php',$f['teacher'],[],['section'=>10,'school_year_id'=>1,'month'=>10,'year'=>2026],'GET');
        checkEnrollment($r['status']===200 && str_starts_with($r['body'],'%PDF-'),'Legacy export URL returns PDF directly');
    }finally{removeEnrollmentAppFixture($copy);removeEnrollmentAppFixturePhotos($copy);}
});
echo 'Panel workflow passed ('.$GLOBALS['enrollment_checks']." checks, no real SMS).\n";
