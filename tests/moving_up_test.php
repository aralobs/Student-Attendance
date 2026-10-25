<?php
require_once __DIR__.'/support/enrollment_fixture.php';
if (is_file(__DIR__.'/../includes/moving_up.php')) require_once __DIR__.'/../includes/moving_up.php';
$GLOBALS['moving_notifications']=0;
function sendSMS(...$args) { $GLOBALS['moving_notifications']++; throw new RuntimeException('Unexpected SMS'); }
function sendArrivalEmail(...$args) { $GLOBALS['moving_notifications']++; throw new RuntimeException('Unexpected email'); }
function movingCommand(array $changes=[]): array { return array_replace(['source_year_id'=>1,'source_section_id'=>10,'student_ids'=>[101],'target_year_id'=>2,'outcome'=>'promoted','destination_section_id'=>20],$changes); }
function enableMovingFixture(PDO $db): void { $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'"); }
withEnrollmentFixture(function($f) {
    checkEnrollment(function_exists('previewMovingUp') && function_exists('prepareMovingUp'),'Moving-up preview and preparation services are missing');
    $db=$f['db']; $cmd=movingCommand();
    expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$cmd));
    enableMovingFixture($db);
    foreach ([$f['teacher'],['id'=>7,'role'=>'scanner_operator'],['id'=>7,'role'=>'admin']] as $actor) {
        expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$actor,$cmd));
        expectEnrollmentError(DomainException::class,fn()=>prepareMovingUp($db,$actor,$cmd,'denied','anything'));
    }
    foreach ([['student_ids'=>[]],['student_ids'=>[0]],['student_ids'=>['101x']],['student_ids'=>[101,101]],['student_ids'=>[106]],['target_year_id'=>1],['destination_section_id'=>10],['destination_section_id'=>21],['outcome'=>'graduated','destination_section_id'=>null],['outcome'=>'unknown']] as $bad) {
        expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],movingCommand($bad)));
    }
    $db->exec("INSERT INTO school_years(id,label,starts_on,ends_on,state) VALUES(3,'2025-2026','2025-06-01','2026-03-31','closed'); INSERT INTO sections(id,section_name,grade_level,school_year,school_year_id) VALUES(30,'Wrong grade','Grade 5','2027-2028',2)");
    expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],movingCommand(['target_year_id'=>3])));
    expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],movingCommand(['destination_section_id'=>30])));
    $before=$db->query('SELECT * FROM students ORDER BY id')->fetchAll(); $scan=$db->query('SELECT * FROM attendance')->fetchAll(); $source=$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll();
    $preview=previewMovingUp($db,$f['admin'],$cmd);
    checkEnrollment(count($preview['rows'])===1 && strlen($preview['fingerprint'])===64,'Preview identifies one eligible final placement');
    $db->exec('UPDATE enrollment_sections SET version=version+1 WHERE id=2001');
    expectEnrollmentError(DomainException::class,fn()=>prepareMovingUp($db,$f['admin'],$cmd,'stale',$preview['fingerprint']));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===0,'Stale preview writes nothing');
    $preview=previewMovingUp($db,$f['admin'],$cmd);
    $saved=prepareMovingUp($db,$f['admin'],$cmd,'promote',$preview['fingerprint']);
    checkEnrollment(count($saved['destination_enrollment_ids'])===1 && count($saved['decision_ids'])===1 && !$saved['replayed'],'Promotion prepares enrollment and decision');
    $destination=$db->query('SELECT * FROM student_enrollments WHERE school_year_id=2')->fetch();
    checkEnrollment($destination['state']==='prepared' && $destination['starts_on']==='2027-06-01' && $destination['ends_on']==='2028-03-31','Prepared dates inherit verified year boundaries');
    $again=prepareMovingUp($db,$f['admin'],$cmd,'promote',$preview['fingerprint']);
    checkEnrollment($again['replayed'] && $again['batch_id']===$saved['batch_id'] && $again['decision_ids']===$saved['decision_ids'],'Same payload replays committed response');
    expectEnrollmentError(DomainException::class,fn()=>prepareMovingUp($db,$f['admin'],movingCommand(['outcome'=>'retained','destination_section_id'=>21]),'promote',$preview['fingerprint']));
    expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$cmd));
    checkEnrollment($before===$db->query('SELECT * FROM students ORDER BY id')->fetchAll(),'Preparation preserves full profile, LRN, QR and projection');
    checkEnrollment($scan===$db->query('SELECT * FROM attendance')->fetchAll(),'Preparation preserves attendance');
    checkEnrollment($source===$db->query('SELECT * FROM student_enrollments WHERE school_year_id=1 ORDER BY id')->fetchAll(),'Preparation preserves source state and versions');
    checkEnrollment(getEnrollmentContext($db,101,'2026-10-22')['section_id']===10,'Current attendance remains in Grade 3');
    $graduate=movingCommand(['source_section_id'=>16,'student_ids'=>[106],'outcome'=>'graduated','destination_section_id'=>null]);
    $p=previewMovingUp($db,$f['admin'],$graduate); $s=prepareMovingUp($db,$f['admin'],$graduate,'graduate',$p['fingerprint']);
    checkEnrollment($s['destination_enrollment_ids']===[] && count($s['decision_ids'])===1,'Grade 6 graduation is decision only');
    checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id=1006')->fetchColumn()==='active','Graduation leaves source active until closure');
    checkEnrollment($GLOBALS['moving_notifications']===0,'Moving up sends zero notifications');
});
withEnrollmentFixture(function($f) {
    $db=$f['db']; enableMovingFixture($db); $cmd=movingCommand(['outcome'=>'retained','destination_section_id'=>21]);
    $p=previewMovingUp($db,$f['admin'],$cmd);
    $db->exec("CREATE TRIGGER moving_fail_audit BEFORE INSERT ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    expectEnrollmentError(PDOException::class,fn()=>prepareMovingUp($db,$f['admin'],$cmd,'retain',$p['fingerprint']));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===2,'Audit failure rolls complete batch back');
    $db->exec('DROP TRIGGER moving_fail_audit');
    $s=prepareMovingUp($db,$f['admin'],$cmd,'retain',$p['fingerprint']);
    checkEnrollment((int)$db->query('SELECT section_id FROM enrollment_sections WHERE enrollment_id='.$s['destination_enrollment_ids'][0])->fetchColumn()===21,'Retention keeps Grade 3');
});
withEnrollmentFixture(function($f) {
    $db=$f['db']; enableMovingFixture($db);
    $db->exec("UPDATE enrollment_sections SET ends_on='2026-10-31' WHERE id=2001; INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(1001,11,'Grade 3','2026-11-01','2027-03-31'); UPDATE students SET section_id=16,grade_level='Grade 6' WHERE id=101");
    expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],movingCommand()));
    $p=previewMovingUp($db,$f['admin'],movingCommand(['source_section_id'=>11]));
    checkEnrollment($p['rows'][0]['grade_level']==='Grade 3','Final annual placement ignores stale profile cache');
    $db->exec('UPDATE users SET is_active=0 WHERE id=1');
    expectEnrollmentError(DomainException::class,fn()=>prepareMovingUp($db,$f['admin'],movingCommand(['source_section_id'=>11]),'revoked',$p['fingerprint']));
});
function movingUpEndpointChecks(array $f): void
{
    $root=dirname(__DIR__); $path=__DIR__.'/.moving-http-'.bin2hex(random_bytes(6)); $files=[];
    checkEnrollment(is_file($root.'/students/moving_up.php') && is_file($root.'/students/save_moving_up.php'),'Moving-up preview and confirmation endpoints are missing');
    foreach(['','/config','/includes','/students'] as $dir) mkdir($path.$dir);
    $write=function($file,$text) use($path,&$files) { file_put_contents($path.'/'.$file,$text); $files[]=$file; };
    foreach(['includes/moving_up.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/school_years.php','includes/security_audit.php','includes/pii_security.php','includes/csrf.php','students/moving_up.php','students/save_moving_up.php','students/bulk_assign_section.php'] as $file) $write($file,file_get_contents($root.'/'.$file));
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
        checkEnrollment($exit===0 && $err==='' && preg_match('/RESULT:(.*)$/',$out,$match)===1,'Moving-up HTTP fixture failed: '.$endpoint.' '.$err);
        return json_decode($match[1],true)+['body'=>$out];
    };
    try {
        $db=$f['db']; $post=movingCommand()+['request_key'=>'http-test','expected_fingerprint'=>str_repeat('a',64)];
        $r=$run('students/save_moving_up.php',$f['admin'],$post); checkEnrollment($r['status']===409,'Mode 0 rejects direct preparation POST');
        $r=$run('students/moving_up.php',$f['admin'],[],[],'GET'); checkEnrollment($r['status']===409,'Mode 0 hides annual moving-up UI');
        enableMovingFixture($db);
        $db->exec("INSERT INTO school_years(id,label,starts_on,ends_on,state) VALUES(3,'2028-2029','2028-06-05','2029-03-29','planned')");
        $getSource=['source_year_id'=>1,'source_section_id'=>10];
        $r=$run('students/moving_up.php',$f['admin'],[],$getSource,'GET');
        checkEnrollment(preg_match('/<option value="2" selected>2027-2028/',$r['body'])===1 && !str_contains($r['body'],'Create school year'),'Default selects the managed immediately following label');
        // Leave a later planned year while the immediately following label is absent.
        $db->exec("UPDATE school_years SET label='2029-2030' WHERE id=2");
        $r=$run('students/moving_up.php',$f['admin'],[],$getSource,'GET');
        preg_match('/<select[^>]*id="target-year"[^>]*>(.*?)<\/select>/s',$r['body'],$targetSelect);
        checkEnrollment(isset($targetSelect[1]) && preg_match('/<option value="[1-9][0-9]*"\s+selected/',$targetSelect[1])===0 && str_contains($r['body'],'Create school year') && !str_contains($r['body'],'Preview batch'),'Missing immediate label leaves target unset and offers creation even with later planned years');
        $r=$run('students/moving_up.php',$f['admin'],[],$getSource+['target_year_id'=>3],'GET');
        checkEnrollment(preg_match('/<option value="3" selected>2028-2029/',$r['body'])===1 && str_contains($r['body'],'Preview batch') && !str_contains($r['body'],'Create school year'),'Explicit later planned target remains selected when the immediate label is absent');
        $db->exec("UPDATE school_years SET label='2027-2028' WHERE id=2");
        $r=$run('students/moving_up.php',$f['admin'],[],$getSource+['target_year_id'=>3],'GET');
        checkEnrollment(preg_match('/<option value="3" selected>2028-2029/',$r['body'])===1 && str_contains($r['body'],'2028-06-05 through 2029-03-29'),'Explicit selection overrides matching-label default and displays stored verified dates');
        $db->exec('DELETE FROM school_years WHERE id=3');
        foreach(['students/moving_up.php','students/save_moving_up.php','students/bulk_assign_section.php'] as $endpoint) {
            $r=$run($endpoint,$f['teacher'],$post); checkEnrollment($r['status']===403,'Teacher direct POST is unauthorized: '.$endpoint);
        }
        $r=$run('students/save_moving_up.php',$f['admin'],array_replace($post,['csrf_token'=>'bad'])); checkEnrollment($r['status']===403,'Confirmation requires valid CSRF');
        $r=$run('students/bulk_assign_section.php',$f['admin'],['section_id'=>20,'student_ids'=>[101]]); checkEnrollment($r['status']===409 && (int)$db->query('SELECT section_id FROM students WHERE id=101')->fetchColumn()===10,'Legacy bulk overwrite rejects managed bypass');
        // More than the existing student-list page size: Select All must include every final section member.
        for($i=201;$i<=220;$i++) {
            $db->prepare("INSERT INTO students(id,lrn,first_name,last_name,gender,qr_token) VALUES (?,?,'Synthetic','Roster','Male',?)")->execute([$i,'SYNTHETIC'.$i,'synthetic-'.$i]);
            $db->prepare("INSERT INTO student_enrollments(student_id,school_year_id,state,starts_on,ends_on) VALUES (?,1,'active','2026-06-01','2027-03-31')")->execute([$i]); $id=(int)$db->lastInsertId();
            $db->prepare("INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (?,10,'Grade 3','2026-06-01','2027-03-31')")->execute([$id]);
        }
        $get=['source_year_id'=>1,'source_section_id'=>10,'target_year_id'=>2];
        $r=$run('students/moving_up.php',$f['admin'],[],$get,'GET');
        checkEnrollment(str_contains($r['body'],'Select all 21 students in this source-section roster') && substr_count($r['body'],'name="student_ids[]"')===21,'UI names full roster count and renders beyond page 15');
        $r=$run('students/moving_up.php',$f['admin'],$post+['select_all'=>'1']);
        checkEnrollment(str_contains($r['body'],'Confirm and prepare 21 students') && str_contains($r['body'],'Grade 4 — Next Four') && str_contains($r['body'],'2027-06-01 through 2028-03-31'),'Preview explicitly identifies count, destination and verified target dates');
        $db->exec("UPDATE school_years SET state='closed' WHERE id=2");
        $r=$run('students/moving_up.php',$f['admin'],[],['source_year_id'=>1,'source_section_id'=>10],'GET');
        checkEnrollment(str_contains($r['body'],'Create school year'),'Missing next year offers explicit create-year action');
        $db->exec("UPDATE school_years SET state='planned' WHERE id=2");
        $all=movingCommand(['student_ids'=>array_merge([101],range(201,220))]); $preview=previewMovingUp($db,$f['admin'],$all);
        $r=$run('students/save_moving_up.php',$f['admin'],$post+['select_all'=>'1']);
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===0,'Stale full-roster confirmation writes nothing');
        $r=$run('students/save_moving_up.php',$f['admin'],array_replace($post,['select_all'=>'1','expected_fingerprint'=>$preview['fingerprint']]));
        checkEnrollment($r['flash'][0]==='success' && (int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE school_year_id=2')->fetchColumn()===21,'Select All POST prepares complete filtered source roster');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106 AND school_year_id=2')->fetchColumn()===0,'Select All excludes unrelated hidden section');
    } finally { foreach($files as $file) unlink($path.'/'.$file); foreach(['/students','/includes','/config',''] as $dir) rmdir($path.$dir); }
}
withEnrollmentFixture('movingUpEndpointChecks');
withEnrollmentFixture(function($f) {
    $db=$f['db']; enableMovingFixture($db); $cmd=movingCommand();
    $preview=previewMovingUp($db,$f['admin'],$cmd);
    $db->exec('UPDATE users SET is_active=2,session_token=NULL WHERE id=1');
    $before=[];
    foreach (['school_years','sections','students','student_enrollments','enrollment_sections','attendance','moving_up_batches','moving_up_decisions','security_events','security_change_history'] as $table) $before[$table]=$db->query("SELECT * FROM $table")->fetchAll();
    expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$cmd));
    expectEnrollmentError(DomainException::class,fn()=>prepareMovingUp($db,$f['admin'],$cmd,'archived-admin',$preview['fingerprint']));
    expectEnrollmentError(DomainException::class,fn()=>listMovingUpSourceRoster($db,$f['admin'],1,10));
    foreach ($before as $table=>$rows) checkEnrollment($rows===$db->query("SELECT * FROM $table")->fetchAll(),'Archived moving-up actor preserves '.$table);
    checkEnrollment($GLOBALS['moving_notifications']===0,'Archived moving-up actor sends no notifications');
});
echo 'Moving-up behavioral checks passed ('.$GLOBALS['enrollment_checks'].").\n";
