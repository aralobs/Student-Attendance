<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
withEnrollmentFixture(function(array $f):void {
    checkEnrollment(is_file(__DIR__.'/../sections/prepare.php'),'Section preparation page exists');
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    try {
        $get=['source_year_id'=>1,'target_year_id'=>2];
        $post=$get+['section_ids'=>[10,11],'action'=>'preview'];
        $run=fn($actor,$post=[],$get=[],$method='GET')=>runEnrollmentAppEndpoint($copy,$f,'sections/prepare.php',$actor,$post,$get,$method);
        $saveHtml=function($name,$r):void {
            if(getenv('SECTION_PREPARATION_QA')==='1') file_put_contents(dirname(__DIR__).'/.qa/section-preparation-'.$name.'.html',preg_replace('/\nRESULT:.*$/s','',$r['body']));
        };
        $r=$run($f['teacher']); checkEnrollment($r['status']===403,'Teacher cannot load section preparation');
        $r=$run($f['teacher'],$post,[],'POST'); checkEnrollment($r['status']===403,'Teacher cannot submit section preparation');
        $r=$run($f['admin'],array_replace($post,['csrf_token'=>'bad']),[],'POST'); checkEnrollment($r['status']===403,'Preview requires valid CSRF');
        $r=$run($f['admin'],array_replace($post,['action'=>'confirm','csrf_token'=>'bad']),[],'POST'); checkEnrollment($r['status']===403,'Confirmation requires valid CSRF');
        $r=$run($f['admin'],[],$get);
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Current A') && str_contains($r['body'],'Select all'),'Source list renders'); $saveHtml('roster',$r);
        $r=$run($f['admin'],array_replace($post,['section_ids'=>[]]),[],'POST');
        checkEnrollment(str_contains($r['body'],'Select at least one section.'),'Empty selection explains required action');
        $r=$run($f['admin'],$post,[],'POST');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Confirm and prepare sections') && str_contains($r['body'],'Unassigned'),'Preview renders confirmation and adviser policy'); $saveHtml('preview',$r);
        preg_match('/name="expected_fingerprint" value="([a-f0-9]{64})"/',$r['body'],$m);
        checkEnrollment(isset($m[1]),'Preview includes fingerprint');
        $confirm=array_replace($post,['action'=>'confirm','expected_fingerprint'=>$m[1]]);
        $db->exec("UPDATE sections SET section_name='Changed source' WHERE id=10");
        $r=$run($f['admin'],$confirm,[],'POST'); checkEnrollment(str_contains($r['body'],'Preview the sections again'),'Stale page confirmation rejected');
        $db->exec("UPDATE sections SET section_name='Current A' WHERE id=10");
        $r=$run($f['admin'],$post,[],'POST'); preg_match('/name="expected_fingerprint" value="([a-f0-9]{64})"/',$r['body'],$m);
        $confirm['expected_fingerprint']=$m[1];
        $before=$db->query('SELECT * FROM students ORDER BY id')->fetchAll();
        $r=$run($f['admin'],$confirm,[],'POST');
        checkEnrollment($r['status']===302 && str_contains(json_encode($r['flash']),'2 sections prepared'),'Confirmation redirects with counts');
        checkEnrollment($db->query('SELECT * FROM students ORDER BY id')->fetchAll()===$before,'Endpoint does not alter students');
        $r=$run($f['admin'],$confirm,[],'POST'); checkEnrollment(str_contains($r['body'],'Preview the sections again'),'Repeat confirmation does not duplicate sections');
        $r=$run($f['admin'],$post,[],'POST'); checkEnrollment(str_contains($r['body'],'Skip existing'),'Existing copies identified in preview'); $saveHtml('existing',$r);
        $db->exec('UPDATE sections SET is_active=0 WHERE school_year_id=1');
        $r=$run($f['admin'],[],$get); checkEnrollment(str_contains($r['body'],'No active sections found'),'Empty source explained'); $saveHtml('empty',$r);
        $db->exec("UPDATE school_years SET state='closed' WHERE id=2");
        $r=$run($f['admin'],[],$get); checkEnrollment(str_contains($r['body'],'Create a planned school year'),'Missing planned year explained'); $saveHtml('no-target',$r);
        $db->exec("UPDATE system_settings SET setting_value='0' WHERE setting_key='enrollment_model_version'");
        $r=$run($f['admin']); checkEnrollment($r['status']===409,'Page requires annual enrollment mode');
    } finally {removeEnrollmentAppFixture($copy); removeEnrollmentAppFixturePhotos($copy);}
});
echo 'Section preparation page checks passed ('.($GLOBALS['enrollment_checks'] ?? 0).").\n";
