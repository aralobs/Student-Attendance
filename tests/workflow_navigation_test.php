<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
withEnrollmentFixture(function(array $f):void {
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    try {
        $run=fn($endpoint,$post=[],$get=[],$method='GET',$actor=null)=>runEnrollmentAppEndpoint($copy,$f,$endpoint,$actor ?? $f['admin'],$post,$get,$method);
        $write=function($name,$r):void {
            if(getenv('WORKFLOW_NAVIGATION_QA')==='1') file_put_contents(dirname(__DIR__).'/.qa/navigation-'.$name.'.html',preg_replace('/\nRESULT:.*$/s','',$r['body']));
        };
        $back='../students/moving_up.php?source_year_id=1&source_section_id=10&target_year_id=2';
        $get=['source_year_id'=>1,'target_year_id'=>2,'return_to'=>$back];
        $r=$run('sections/prepare.php',[],$get);
        checkEnrollment(str_contains($r['body'],'Back to Moving Up') && str_contains($r['body'],htmlspecialchars($back,ENT_QUOTES,'UTF-8')),'Back link restores Moving Up filters');
        $write('back-moving',$r);
        $r=$run('sections/prepare.php',[],array_replace($get,['return_to'=>'sections.php?view=archived&school_year_id=2']));
        checkEnrollment(str_contains($r['body'],'Back to Sections') && str_contains($r['body'],'sections.php?school_year_id=2&amp;view=archived'),'Back link restores Sections filters');
        $write('back-sections',$r);
        foreach(['https://evil.example/','//evil.example/','javascript:alert(1)', '../students/moving_up.php?bad=1', ['bad']] as $bad) {
            $r=$run('sections/prepare.php',[],array_replace($get,['return_to'=>$bad]));
            checkEnrollment(!str_contains($r['body'],'evil.example') && !str_contains($r['body'],'javascript:') && str_contains($r['body'],'Back to '),'Back link only permits known local pages');
        }
        $r=$run('sections/prepare.php',$get+['section_ids'=>[10],'action'=>'preview'],[],'POST');
        checkEnrollment(str_contains($r['body'],'name="return_to"') && str_contains($r['body'],'Back to Moving Up'),'Return context persists through preview');
        $r=$run('students/moving_up.php',[],['source_year_id'=>1,'source_section_id'=>10,'target_year_id'=>2]);
        checkEnrollment(str_contains($r['body'],'id="enrollmentCorrectionsModal"') && str_contains($r['body'],'data-bs-target="#enrollmentCorrectionsModal"'),'Moving Up launches corrections modal');
        $write('moving',$r);
        $r=$run('students/enrollment_corrections.php',[],['modal'=>'1']); $write('modal-blank',$r);
        $r=$run('students/enrollment_corrections.php',[],['modal'=>1,'enrollment_id'=>1001]);
        $write('modal-loaded',$r);
        checkEnrollment($r['status']===200 && !str_contains($r['body'],'class="sidebar') && !str_contains($r['body'],'class="wrapper') && str_contains($r['body'],'name="modal" value="1"'),'Modal content omits navigation and keeps modal context');
        preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s',$r['body'],$forms);
        foreach($forms[1] as $form) checkEnrollment(str_contains($form,'name="modal" value="1"'),'Every modal form preserves embedded context');
        $r=$run('students/enrollment_corrections.php',[],['modal'=>1], 'GET',$f['teacher']); checkEnrollment($r['status']===403,'Modal still requires administrator');
        $r=$run('students/enrollment_corrections.php',['modal'=>'1','csrf_token'=>'bad'],[],'POST'); checkEnrollment($r['status']===403,'Modal preview still requires CSRF');
        $r=$run('students/save_enrollment_correction.php',['modal'=>'1','correction_id'=>999,'expected_fingerprint'=>str_repeat('a',64)],[],'POST');
        checkEnrollment($r['status']===409 && str_contains($r['body'],'enrollment_corrections.php?modal=1'),'Save error retry stays in modal');
        $correction=['modal'=>'1','enrollment_id'=>1001,'kind'=>'historical_assignment','assignment_id'=>2001,'section_id'=>11,'starts_on'=>'2026-10-01','ends_on'=>'2027-03-31','reason'=>'Synthetic modal correction'];
        $r=$run('students/enrollment_corrections.php',$correction,[],'POST');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Confirm correction'),'Modal preview displays confirmation'); $write('modal-preview',$r);
        preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s',$r['body'],$forms);
        foreach($forms[1] as $form) checkEnrollment(str_contains($form,'name="modal" value="1"'),'Preview confirmation preserves embedded context');
        $stored=$db->query('SELECT id,preview_fingerprint FROM enrollment_corrections ORDER BY id DESC LIMIT 1')->fetch();
        $r=$run('students/save_enrollment_correction.php',['modal'=>'1','enrollment_id'=>1001,'correction_id'=>$stored['id'],'expected_fingerprint'=>$stored['preview_fingerprint']],[],'POST');
        checkEnrollment($r['status']===302 && $db->query('SELECT state FROM enrollment_corrections ORDER BY id DESC LIMIT 1')->fetchColumn()==='applied','Modal confirmation applies the reviewed correction');
        $r=$run('students/enrollment_corrections.php',[],['modal'=>'1','enrollment_id'=>1001]); $write('modal-saved',$r);
        $r=$run('students/enrollment_corrections.php');
        checkEnrollment(str_contains($r['body'],'class="wrapper'),'Standalone corrections still renders normally');
    } finally {removeEnrollmentAppFixture($copy); removeEnrollmentAppFixturePhotos($copy);}
});
echo 'Workflow navigation checks passed ('.($GLOBALS['enrollment_checks'] ?? 0).").\n";
