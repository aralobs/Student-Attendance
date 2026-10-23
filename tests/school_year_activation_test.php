<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/../includes/moving_up.php';
require_once __DIR__.'/../includes/enrollment_operations.php';
$GLOBALS['activation_notifications']=0;
function sendSMS(...$args) { $GLOBALS['activation_notifications']++; throw new RuntimeException('Unexpected SMS'); }
function sendArrivalEmail(...$args) { $GLOBALS['activation_notifications']++; throw new RuntimeException('Unexpected email'); }
function activationFixture(PDO $db): void { $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'"); }
function activationPrepare(array $f, bool $graduate=false): void {
    $command=['source_year_id'=>1,'source_section_id'=>$graduate?16:10,'student_ids'=>[$graduate?106:101],'target_year_id'=>2,'outcome'=>$graduate?'graduated':'promoted','destination_section_id'=>$graduate?null:20];
    $p=previewMovingUp($f['db'],$f['admin'],$command);
    prepareMovingUp($f['db'],$f['admin'],$command,$graduate?'graduate':'promote',$p['fingerprint']);
}
withEnrollmentFixture(function($f) {
    checkEnrollment(function_exists('schoolYearActivationPreview') && function_exists('activateSchoolYear'),'School-year activation services are missing');
    $db=$f['db'];
    expectEnrollmentError(DomainException::class,fn()=>schoolYearActivationPreview($db,$f['admin'],2));
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,'anything'));
    activationFixture($db);
    foreach ([$f['teacher'],['id'=>7,'role'=>'scanner_operator'],['id'=>7,'role'=>'admin']] as $actor) {
        expectEnrollmentError(DomainException::class,fn()=>schoolYearActivationPreview($db,$actor,2));
        expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$actor,2,'anything'));
    }
    activationPrepare($f); activationPrepare($f,true);
    $identity=$db->query('SELECT id,lrn,qr_token,first_name,last_name,parent_contact FROM students ORDER BY id')->fetchAll();
    $attendance=$db->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
    $assignments=$db->query('SELECT * FROM enrollment_sections ORDER BY id')->fetchAll();
    $p=schoolYearActivationPreview($db,$f['admin'],2);
    checkEnrollment($p['ready'] && $p['counts']['prepared']===1 && $p['counts']['graduated']===1 && strlen($p['fingerprint'])===64,'Preview counts prepared destinations and graduation metadata');
    $db->exec('UPDATE enrollment_sections SET version=version+1 WHERE enrollment_id IN (SELECT id FROM student_enrollments WHERE school_year_id=2)');
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    checkEnrollment(getActiveSchoolYear($db)['id']===1,'Stale confirmation leaves active year intact');
    $p=schoolYearActivationPreview($db,$f['admin'],2);
    $db->exec('UPDATE users SET is_active=0 WHERE id=1');
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    $db->exec('UPDATE users SET is_active=1 WHERE id=1');
    $p=schoolYearActivationPreview($db,$f['admin'],2);
    activateSchoolYear($db,$f['admin'],2,$p['fingerprint']);
    checkEnrollment(getActiveSchoolYear($db)['id']===2,'One active target year');
    checkEnrollment($db->query('SELECT state FROM school_years WHERE id=1')->fetchColumn()==='closed','Outgoing year closes');
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM student_enrollments WHERE school_year_id=1 AND state='completed'")->fetchColumn()===2,'Outgoing noncancelled enrollments close');
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM student_enrollments WHERE school_year_id=2 AND state='active'")->fetchColumn()===1,'Only prepared destination activates');
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM moving_up_decisions WHERE state='applied'")->fetchColumn()===2,'Promotion and graduation decisions applied');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106')->fetchColumn()===1,'Graduation creates no annual enrollment');
    checkEnrollment(getEnrollmentContext($db,101,'2026-10-22','read')['school_year_id']===1,'Historical attendance context preserved');
    checkEnrollment(getEnrollmentContext($db,101,'2026-10-22','write')===null,'Closed source cannot accept operational attendance');
    $db->beginTransaction(); try { expectEnrollmentError(DomainException::class,fn()=>lockAttendanceEnrollment($db,101,'2027-06-01')); } finally { $db->rollBack(); }
    checkEnrollment($identity===$db->query('SELECT id,lrn,qr_token,first_name,last_name,parent_contact FROM students ORDER BY id')->fetchAll(),'Profile identity and contact preserved');
    checkEnrollment($attendance===$db->query('SELECT * FROM attendance ORDER BY id')->fetchAll(),'Existing scans preserved');
    checkEnrollment(array_slice($assignments,0,2)===$db->query('SELECT * FROM enrollment_sections WHERE enrollment_id IN (1001,1006) ORDER BY id')->fetchAll(),'Historical assignments preserved');
    checkEnrollment($db->query("SELECT setting_value FROM system_settings WHERE setting_key='school_year'")->fetchColumn()==='2027-2028','Legacy setting follows activated year');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students WHERE section_id IS NOT NULL')->fetchColumn()===0,'Future placements do not project before operational dates');
    checkEnrollment($GLOBALS['activation_notifications']===0 && (int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Lifecycle sends zero notifications');
});
withEnrollmentFixture(function($f) {
    $db=$f['db']; activationFixture($db); activationPrepare($f);
    $p=schoolYearActivationPreview($db,$f['admin'],2);
    checkEnrollment($p['ready'] && $p['counts']['unassigned']===1 && count($p['follow_up'])===1,'Source without destination is explicit follow-up');
    $before=[]; foreach(['school_years','student_enrollments','moving_up_decisions','students','system_settings','security_change_history','security_events'] as $table) $before[$table]=$db->query("SELECT * FROM $table")->fetchAll();
    $db->exec("CREATE TRIGGER activation_fail_audit BEFORE INSERT ON security_change_history FOR EACH ROW BEGIN IF EXISTS(SELECT 1 FROM security_events WHERE event_id=NEW.event_id AND action='school_year.activate') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic late audit failure'; END IF; END");
    expectEnrollmentError(PDOException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    foreach($before as $table=>$rows) checkEnrollment($rows===$db->query("SELECT * FROM $table")->fetchAll(),'Audit failure rolls back '.$table);
    $db->exec('DROP TRIGGER activation_fail_audit');
    activateSchoolYear($db,$f['admin'],2,$p['fingerprint']);
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106')->fetchColumn()===1,'Unspecified source gets no invented target');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_decisions WHERE source_enrollment_id=1006')->fetchColumn()===0,'Unspecified outcome remains unspecified');
});
withEnrollmentFixture(function($f) {
    $db=$f['db']; activationFixture($db); activationPrepare($f);
    $p=schoolYearActivationPreview($db,$f['admin'],2);
    $db->exec('UPDATE attendance SET enrollment_id=NULL,assignment_id=NULL WHERE id=501');
    $blocked=schoolYearActivationPreview($db,$f['admin'],2);
    checkEnrollment(!$blocked['ready'] && count($blocked['blocking_issues'])>0,'Unmapped outgoing scan blocks future activation');
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    $db->exec('UPDATE attendance SET enrollment_id=1001,assignment_id=2001 WHERE id=501');
    foreach(['active','completed'] as $state) {
        $db->exec("UPDATE student_enrollments SET state='$state' WHERE school_year_id=2");
        $p=schoolYearActivationPreview($db,$f['admin'],2);
        checkEnrollment(!$p['ready'],'Unexpected target '.$state.' enrollment blocks activation');
        expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    }
    $db->exec("UPDATE student_enrollments SET state='prepared' WHERE school_year_id=2");
    $p=schoolYearActivationPreview($db,$f['admin'],2); $db->exec('UPDATE sections SET adviser_id=8 WHERE id=20');
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    $p=schoolYearActivationPreview($db,$f['admin'],2); $db->exec('UPDATE students SET is_active=0 WHERE id=101');
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],2,$p['fingerprint']));
    checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],2)['ready'],'Unavailable prepared profile blocks activation');
});
withEnrollmentFixture(function($f) {
    $db=$f['db']; activationFixture($db);
    $db->exec("INSERT INTO school_years(id,label,starts_on,ends_on,state) VALUES(3,'2025-2026','2025-06-01','2026-03-31','closed'); INSERT INTO sections(id,section_name,grade_level,school_year,school_year_id) VALUES(30,'Old Six','Grade 6','2025-2026',3); INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(1007,106,3,'completed','2025-06-01','2026-03-31'); INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(1007,30,'Grade 6','2025-06-01','2026-03-31')");
    $c=['source_year_id'=>3,'source_section_id'=>30,'student_ids'=>[106],'target_year_id'=>2,'outcome'=>'graduated','destination_section_id'=>null];
    $p=previewMovingUp($db,$f['admin'],$c); prepareMovingUp($db,$f['admin'],$c,'old-graduate',$p['fingerprint']);
    $old=$db->query('SELECT * FROM student_enrollments WHERE id=1007')->fetch();
    $p=schoolYearActivationPreview($db,$f['admin'],2); activateSchoolYear($db,$f['admin'],2,$p['fingerprint']);
    checkEnrollment($old===$db->query('SELECT * FROM student_enrollments WHERE id=1007')->fetch(),'Already closed source placement remains unchanged');
    checkEnrollment($db->query('SELECT state FROM moving_up_decisions WHERE source_enrollment_id=1007')->fetchColumn()==='applied','Historical graduation metadata applies');
});
withEnrollmentFixture(function($f) {
    $db=$f['db']; activationFixture($db); activationPrepare($f,true);
    $db->exec("UPDATE sections SET grade_level='Grade 5' WHERE id=16; UPDATE enrollment_sections SET grade_level='Grade 5',version=version+1 WHERE id=2006");
    checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],2)['ready'],'Fresh preview rejects graduation whose corrected source is no longer Grade 6');
});
function activationEndpointChecks(array $f): void
{
    $root=dirname(__DIR__); $path=__DIR__.'/.activation-http-'.bin2hex(random_bytes(6)); $files=[];
    checkEnrollment(is_file($root.'/settings/activate_school_year.php'),'Activation confirmation endpoint is missing');
    foreach(['','/config','/includes','/settings'] as $dir) mkdir($path.$dir);
    $write=function($file,$text) use($path,&$files) { file_put_contents($path.'/'.$file,$text); $files[]=$file; };
    foreach(['includes/enrollment_commands.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/enrollment_migration.php','includes/school_years.php','includes/security_audit.php','includes/pii_security.php','includes/csrf.php','settings/school_years.php','settings/activate_school_year.php'] as $file) $write($file,file_get_contents($root.'/'.$file));
    foreach(['header.php','sidebar.php','footer.php'] as $file) $write('includes/'.$file,"<?php\n");
    $write('config/database.php', <<<'PHP'
<?php
$request=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$request['database'])) throw new RuntimeException('Unsafe fixture');
define('BASE_URL','/'); $_SERVER['REQUEST_METHOD']=$request['method']; $_POST=$request['post']; $_GET=$request['get']; $_SESSION=['app_csrf'=>str_repeat('a',64)]; http_response_code(200);
function getDB(): PDO { global $request; return $GLOBALS['fixture_db'] ??= new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$request['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); }
register_shutdown_function(function() { echo "\nRESULT:".json_encode(['status'=>http_response_code(),'flash'=>$GLOBALS['fixture_flash'] ?? null]); });
PHP);
    $write('includes/functions.php', <<<'PHP'
<?php
require_once __DIR__.'/csrf.php';
function startSession() {}
function currentUser() { return $GLOBALS['request']['actor']; }
function isAdmin() { return currentUser()['role']==='admin'; }
function requireLogin() { if (!(currentUser()['id'] ?? 0)) { http_response_code(401); exit; } }
function requireAdmin() { requireLogin(); if (!isAdmin()) { http_response_code(403); exit; } }
function sanitize($text) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
function setFlash($kind,$text) { $GLOBALS['fixture_flash']=[$kind,$text]; }
function showFlash() {}
PHP);
    $run=function($endpoint,$actor,$post=[],$get=[],$method='POST') use($f,$path) {
        $post+=['csrf_token'=>str_repeat('a',64)];
        $payload=base64_encode(json_encode(['database'=>$f['database'],'actor'=>$actor,'post'=>$post,'get'=>$get,'method'=>$method]));
        $p=proc_open([PHP_BINARY,$path.'/'.$endpoint,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($path.'/'.$endpoint));
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        checkEnrollment($exit===0 && $err==='' && preg_match('/RESULT:(.*)$/',$out,$match)===1,'Activation HTTP fixture failed: '.$endpoint.' '.$err);
        return json_decode($match[1],true)+['body'=>$out];
    };
    try {
        $db=$f['db']; $post=['target_year_id'=>2,'expected_fingerprint'=>str_repeat('a',64)];
        $r=$run('settings/activate_school_year.php',$f['admin'],$post); checkEnrollment($r['status']===409,'Mode 0 rejects activation');
        $r=$run('settings/school_years.php',$f['admin'],[],['activate'=>2],'GET'); checkEnrollment(!str_contains($r['body'],'Confirm activation'),'Mode 0 offers no bootstrap activation');
        activationFixture($db); activationPrepare($f);
        foreach([$f['teacher'],['id'=>7,'role'=>'scanner_operator']] as $actor) foreach(['settings/school_years.php','settings/activate_school_year.php'] as $endpoint) {
            $r=$run($endpoint,$actor,$post); checkEnrollment($r['status']===403,'Only administrator can access activation endpoint');
        }
        foreach(['settings/school_years.php','settings/activate_school_year.php'] as $endpoint) {
            $r=$run($endpoint,['id'=>0,'role'=>'guest'],$post); checkEnrollment($r['status']===401,'Anonymous activation endpoint access denied');
        }
        $r=$run('settings/activate_school_year.php',$f['admin'],array_replace($post,['csrf_token'=>'bad'])); checkEnrollment($r['status']===403,'Activation requires CSRF');
        $r=$run('settings/activate_school_year.php',$f['admin'],$post,[],'GET'); checkEnrollment($r['status']===405,'Activation requires POST');
        $r=$run('settings/school_years.php',$f['admin'],[],['activate'=>2],'GET');
        checkEnrollment(str_contains($r['body'],'2027-06-01') && str_contains($r['body'],'2028-03-31') && str_contains($r['body'],'2026-2027') && str_contains($r['body'],'Unassigned students') && str_contains($r['body'],'Confirm activation'),'Readiness page exposes dates, affected year, follow-up and confirmation');
        $r=$run('settings/activate_school_year.php',$f['admin'],$post); checkEnrollment($r['status']===409 && getActiveSchoolYear($db)['id']===1,'Stale endpoint returns conflict without writes');
        $preview=schoolYearActivationPreview($db,$f['admin'],2);
        $db->exec("UPDATE users SET role='teacher' WHERE id=1");
        $r=$run('settings/activate_school_year.php',$f['admin'],array_replace($post,['expected_fingerprint'=>$preview['fingerprint']])); checkEnrollment($r['status']===409 && getActiveSchoolYear($db)['id']===1,'Endpoint rechecks current database authority');
        $db->exec("UPDATE users SET role='admin' WHERE id=1");
        $preview=schoolYearActivationPreview($db,$f['admin'],2);
        $r=$run('settings/activate_school_year.php',$f['admin'],array_replace($post,['expected_fingerprint'=>$preview['fingerprint']])); checkEnrollment($r['flash'][0]==='success' && getActiveSchoolYear($db)['id']===2,'Fresh confirmation activates target');
    } finally { foreach($files as $file) unlink($path.'/'.$file); foreach(['/settings','/includes','/config',''] as $dir) rmdir($path.$dir); }
}
withEnrollmentFixture(function($f) {
    $db=$f['db']; activationFixture($db);
    $db->exec("INSERT INTO students(id,lrn,first_name,last_name,gender,qr_token) VALUES(201,'SYNTHETIC201','Synthetic','Future','Female','synthetic-201')");
    $id=createAnnualEnrollment($db,$f['admin'],201,2,20,'2027-06-01','2028-03-31');
    $p=schoolYearActivationPreview($db,$f['admin'],2);
    checkEnrollment($p['ready'],'Future-only prepared entrant does not require an invented outgoing enrollment');
    $db->exec("INSERT INTO attendance(student_id,date,am_status,attendance_type,recorded_by) VALUES(201,'2026-10-22','present','partial',7)");
    checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],2)['ready'],'Outgoing unmapped scan still blocks for a future-only prepared entrant');
    $db->exec("DELETE FROM attendance WHERE student_id=201");
    activateSchoolYear($db,$f['admin'],2,$p['fingerprint']);
    checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id='.$id)->fetchColumn()==='active','Future-only prepared entrant activates without source fabrication');
});
withEnrollmentFixture('activationEndpointChecks');
echo 'School-year activation behavioral checks passed ('.$GLOBALS['enrollment_checks'].").\n";
