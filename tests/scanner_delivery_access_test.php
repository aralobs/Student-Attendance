<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/delivery_modes.php';
require_once dirname(__DIR__).'/includes/attendance_scan.php';
require_once dirname(__DIR__).'/includes/scanner_availability.php';
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; ensureDeliverySchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); INSERT INTO users(id,username,password,full_name,role) VALUES(9,'fixture_scanner','unused','Synthetic Scanner','scanner_operator'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $db->prepare('UPDATE school_years SET starts_on=?,ends_on=? WHERE id=1')->execute([$today,$today]);
    $db->prepare('UPDATE student_enrollments SET starts_on=?,ends_on=? WHERE school_year_id=1')->execute([$today,$today]);
    $db->prepare('UPDATE enrollment_sections SET starts_on=?,ends_on=?')->execute([$today,$today]);
    $db->prepare("INSERT INTO school_calendar(date,title,type) VALUES(?,'Synthetic school day','school_day')")->execute([$today]);
    $insert=$db->prepare('INSERT INTO delivery_modes(school_year_id,scope_section_id,learning_date,mode,deadline_at,recorded_by) VALUES(1,?,?,?, ?,1)');
    $insert->execute([0,$today,'modular',$today.' 23:59:59']);
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    $render=fn($actor)=>runEnrollmentAppEndpoint($copy,$f,'attendance/scanner.php',$actor,[],[],'GET');
    $controls=function(array $response,bool $enabled): void {
        checkEnrollment($response['status']===200,'Scanner renders');
        $doc=new DOMDocument(); @$doc->loadHTML($response['body']); $xp=new DOMXPath($doc);
        foreach(['startBtn','manualInput','recordBtn'] as $id) {
            $control=$xp->query('//*[@id="'.$id.'"]')->item(0);
            checkEnrollment($control && $control->hasAttribute('disabled')!==$enabled,'Scanner control '.$id.' follows face-to-face availability');
        }
    };
    $reject=function() use($copy,$f,$db): void {
        $before=$db->query('SELECT * FROM attendance')->fetchAll();
        $response=runEnrollmentAppEndpoint($copy,$f,'attendance/scan_process.php',$f['teacher'],['token'=>'synthetic-101'],[],'POST');
        $data=json_decode(preg_replace('/\nRESULT:.*$/s','',$response['body']),true,512,JSON_THROW_ON_ERROR);
        checkEnrollment($data['success']===false,'Non-face-to-face QR request is rejected');
        checkEnrollment(preg_match('/face-to-face|holiday|no_class|No attendance recording/i',$data['message'])===1,'Rejection is caused by delivery/calendar rules, not an invalid token');
        checkEnrollment($before===$db->query('SELECT * FROM attendance')->fetchAll(),'Rejected scan does not create or alter attendance');
        checkEnrollment(!is_file($copy.'/provider-calls.log'),'Rejected scan sends no notifications');
    };
    try {
        $controls($render($f['admin']),false);
        $controls($render($f['teacher']),false);
        $controls($render(['id'=>9,'role'=>'scanner_operator']),false);
        $reject();
        $db->exec("UPDATE delivery_modes SET mode='online',deadline_at=NULL");
        $controls($render($f['admin']),false); $reject();
        $insert->execute([11,$today,'face_to_face',null]);
        $controls($render($f['admin']),true);
        $controls($render(['id'=>9,'role'=>'scanner_operator']),true);
        $controls($render($f['teacher']),false);
        $reject();
        $insert->execute([10,$today,'face_to_face',null]);
        $controls($render($f['teacher']),true);
        foreach(['holiday','no_class'] as $type) {
            $db->prepare('UPDATE school_calendar SET type=? WHERE date=?')->execute([$type,$today]);
            $controls($render($f['admin']),false); $reject();
        }
        $db->prepare("UPDATE school_calendar SET type='school_day' WHERE date=?")->execute([$today]);
        $db->exec('DELETE FROM delivery_modes');
        $controls($render($f['teacher']),true);
        $scan=saveAttendanceScan($db,101,$today,$db->query('SELECT * FROM sections WHERE id=10')->fetch(),7,new DateTimeImmutable($today.' 07:00:00',new DateTimeZone('Asia/Manila')));
        checkEnrollment($scan['success'],'Eligible face-to-face attendance still records');
        $insert->execute([0,$today,'modular',$today.' 23:59:59']);
        file_put_contents(dirname(__DIR__).'/.qa/scanner-delivery-disabled.html',$render($f['admin'])['body']);
        $db->exec("UPDATE system_settings SET setting_value='0' WHERE setting_key='enrollment_model_version'");
        checkEnrollment(scannerDeliveryAvailability($db,$f['admin'],'2026-10-31')['enabled'],'Legacy unspecified dates retain their existing face-to-face default');
        $db->prepare("UPDATE school_calendar SET type='holiday' WHERE date=?")->execute([$today]);
        $controls($render($f['admin']),false); $reject();
    } finally { removeEnrollmentAppFixture($copy); removeEnrollmentAppFixturePhotos($copy); }
});
echo 'Scanner delivery access passed ('.$GLOBALS['enrollment_checks']." checks, no notifications).\n";
