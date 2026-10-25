<?php
// Synthetic data only, in a disposable MySQL DB and temporary photo/key storage.
$root = dirname(__DIR__); $name = 'pii_test_' . bin2hex(random_bytes(6));
$previousTestDatabase=getenv('MYSQLDATABASE');
// Parent helpers call getDB too: bind its immutable DB_NAME before configuration loads.
putenv('MYSQLDATABASE='.$name);
require_once __DIR__ . '/../config/database.php';
$fixture = __DIR__ . '/.' . $name; $created = false; $checks = 0;
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$admin = new PDO($dsn, DB_USER, DB_PASS, $options);
$env = getenv(); $env['MYSQLDATABASE'] = $name; $env['PII_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
$env['STUDENT_PHOTO_DIR'] = $fixture . '/photos';
function piiCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    $admin->exec("CREATE DATABASE `$name`"); $created = true;
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    $db->exec(file_get_contents($root . '/migrations/security_audit.sql'));
    $db->exec("CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT); INSERT INTO system_settings VALUES('enrollment_model_version','0')");
    $db->exec('CREATE TABLE users (id INT PRIMARY KEY,role VARCHAR(30),is_active INT,session_token VARCHAR(64),session_ip VARCHAR(50),session_last_activity DATETIME);
        CREATE TABLE sections (id INT PRIMARY KEY,adviser_id INT,is_active INT);
        INSERT INTO sections VALUES (10,7,1),(20,8,1),(30,7,0);
        CREATE TABLE students (id INT PRIMARY KEY,section_id INT,is_active INT,first_name VARCHAR(100),last_name VARCHAR(100),photo VARCHAR(255));
        INSERT INTO students VALUES (1,10,1,"Sample","Student","fixture.png"),(2,20,1,"Other","Student","fixture.png"),
            (3,10,0,"Archived","Student","fixture.png"),(4,30,1,"Inactive","Student","fixture.png"),
            (5,10,1,"Local","Avatar","missing.png"),(6,10,1,"Bad","Image","not-image.png");');
    $db->exec(file_get_contents($root . '/migrations/pii_storage.sql'));
    $db->exec(file_get_contents($root . '/migrations/security_abuse_controls.sql'));
    foreach (['','/config','/includes','/reports','/photos','/sessions'] as $dir) mkdir($fixture . $dir);
    foreach (['config/database.php','config/hosting_environment.php','config/deployment_security.php','config/schedule_rules.php','includes/functions.php','includes/sms_status.php','includes/notification_templates.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/enrollment_reports.php','includes/enrollment_operations.php','includes/school_years.php','includes/security_audit.php','includes/pii_security.php','includes/session_security.php','includes/settings_security.php','includes/csrf.php','includes/abuse_security.php','includes/student_access.php','includes/student_photos.php','includes/sf2_remarks.php','student_photo.php','reports/save_sf2_remark.php'] as $file) copy($root . '/' . $file,$fixture . '/' . $file);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aEaoAAAAASUVORK5CYII=');
    file_put_contents($fixture . '/photos/fixture.png',$png);
    file_put_contents($fixture . '/photos/not-image.png','<?php this must never be served');
    file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case=$argv[1]; http_response_code(200);
require_once __DIR__ . '/includes/functions.php'; startSession();
$_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['REQUEST_METHOD']=str_starts_with($case,'photo_') ? 'GET' : 'POST';
$_SERVER['DOCUMENT_ROOT']='';
$_SESSION=['user_id'=>7,'role'=>$case === 'admin' || $case === 'photo_admin' ? 'admin' : ($case === 'scanner' || $case === 'photo_scanner' ? 'scanner_operator' : 'teacher'),
    'session_token'=>'test-token','authenticated_at'=>time()-600,'last_activity_at'=>time(),'app_csrf'=>str_repeat('a',64)];
$_GET=['id'=>1];
$_POST=['csrf_token'=>str_repeat('a',64),'student_id'=>1,'section_id'=>10,'month'=>10,'year'=>2026,'version'=>0,'remark'=>'Synthetic remark'];
if ($case === 'guest' || $case === 'photo_guest') $_SESSION=[];
if ($case === 'csrf') unset($_POST['csrf_token']);
if ($case === 'method') $_SERVER['REQUEST_METHOD']='GET';
if ($case === 'foreign') {$_POST['student_id']=2;$_POST['section_id']=20;}
if ($case === 'mismatch') $_POST['student_id']=2;
if ($case === 'inactive') {$_POST['student_id']=4;$_POST['section_id']=30;}
if ($case === 'archived') $_POST['student_id']=3;
if ($case === 'bad_month') $_POST['month']=13;
if ($case === 'bad_version') $_POST['version']=[];
if ($case === 'long') $_POST['remark']=str_repeat('a',1001);
if ($case === 'bad_text') $_POST['remark']=[];
if ($case === 'conflict') $_POST['version']=2;
if ($case === 'clear') {$_POST['version']=1;$_POST['remark']='';}
if ($case === 'role_input') $_POST['role']='admin';
if ($case === 'photo_foreign' || $case === 'photo_admin') $_GET['id']=2;
if ($case === 'photo_archived') $_GET['id']=3;
if ($case === 'photo_inactive') $_GET['id']=4;
if ($case === 'photo_avatar') $_GET['id']=5;
if ($case === 'photo_invalid') $_GET['id']=6;
if ($case === 'photo_bad_id') $_GET['id']=[];
register_shutdown_function(function () { echo "\nRESULT:" . json_encode(['status'=>http_response_code()]); });
PHP);
    $cases=['valid'=>200,'admin'=>200,'role_input'=>200,'guest'=>302,'scanner'=>403,'csrf'=>403,'method'=>405,
        'foreign'=>403,'mismatch'=>403,'inactive'=>403,'archived'=>403,'bad_month'=>400,'bad_version'=>400,'long'=>400,'bad_text'=>400,'conflict'=>409,
        'photo_valid'=>200,'photo_admin'=>200,'photo_guest'=>302,'photo_scanner'=>403,'photo_foreign'=>404,
        'photo_archived'=>404,'photo_inactive'=>404,'photo_avatar'=>200,'photo_invalid'=>200,'photo_bad_id'=>404];
    foreach ($cases as $case=>$expected) {
        $db->exec('DELETE FROM users; DELETE FROM sf2_remarks; DELETE FROM sf2_remark_history; DELETE FROM security_rate_limits;');
        $role=$case==='admin'||$case==='photo_admin'?'admin':($case==='scanner'||$case==='photo_scanner'?'scanner_operator':'teacher');
        $db->prepare('INSERT INTO users VALUES (7,?,1,?,NULL,NOW())')->execute([$role,'test-token']);
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-d','auto_prepend_file='.$fixture.'/request.php',$fixture.(str_starts_with($case,'photo_')?'/student_photo.php':'/reports/save_sf2_remark.php'),$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture,$env);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        piiCheck($exit===0 && $err==='', "$case execution failed: $err");
        piiCheck((bool)preg_match('/RESULT:(.*)$/',$out,$match), "$case missing result");
        piiCheck(json_decode($match[1],true)['status']===$expected, "$case incorrect response: " . $out);
        $saved=in_array($case,['valid','admin','role_input'],true);
        piiCheck((int)$db->query('SELECT COUNT(*) FROM sf2_remarks')->fetchColumn()===($saved?1:0), "$case unexpected write");
        piiCheck((int)$db->query('SELECT COUNT(*) FROM sf2_remark_history')->fetchColumn()===($saved?1:0), "$case unexpected history write");
        if ($saved) {
            $cipher=$db->query('SELECT remark_ciphertext FROM sf2_remarks')->fetchColumn();
            piiCheck(str_starts_with($cipher,'pii:v1:') && !str_contains($cipher,'Synthetic remark'), 'Remark stored as plaintext');
            piiCheck(!str_contains($db->query('SELECT change_ciphertext FROM sf2_remark_history')->fetchColumn(),'Synthetic remark'),'History stored as plaintext');
        }
        if ($case==='photo_valid'||$case==='photo_admin') piiCheck(str_starts_with($out,$png),'Wrong photo bytes');
        if ($case==='photo_avatar'||$case==='photo_invalid') piiCheck(str_starts_with($out,'<svg') && !str_contains($out,'<?php') && !str_contains($out,'ui-avatars'),'Unsafe fallback');
    }
    // Real helper round trips, integrity, version conflicts, clearing, scope changes.
    putenv('PII_ENCRYPTION_KEY='.$env['PII_ENCRYPTION_KEY']);
    require_once $root . '/includes/sf2_remarks.php';
    ini_set('session.save_path', $fixture . '/sessions');
    startSession(); $_SESSION=['user_id'=>7,'role'=>'teacher'];
    $text='Synthetic sensitive report note';
    $cipher=encryptPII($text,'test-context');
    piiCheck(decryptPII($cipher,'test-context')===$text,'Encryption round trip failed');
    foreach (['other-context',$cipher] as $test) {
        try {
            if ($test===$cipher) { $bytes=base64_decode(substr($cipher,7));$bytes[15]=chr(ord($bytes[15])^1); decryptPII('pii:v1:'.base64_encode($bytes),'test-context'); }
            else decryptPII($cipher,$test);
            throw new LogicException('Unauthenticated ciphertext accepted');
        } catch (RuntimeException $expected) { $checks++; }
    }
    $db->exec('DELETE FROM sf2_remarks; DELETE FROM sf2_remark_history;');
    piiCheck(saveSF2Remark($db,1,10,10,2026,$text,0)===1,'Initial version incorrect');
    piiCheck(loadSF2Remarks($db,10,10,2026)[1]['text']===$text,'Saved remark not loaded');
    try {saveSF2Remark($db,1,10,10,2026,'Overwrite',0);throw new LogicException('Stale version accepted');} catch (SF2RemarkConflict $expected) {$checks++;}
    piiCheck(saveSF2Remark($db,1,10,10,2026,'',1)===2,'Clear not versioned');
    piiCheck(loadSF2Remarks($db,10,10,2026)[1]['text']==='','Clear not persisted');
    piiCheck((int)$db->query('SELECT COUNT(*) FROM sf2_remark_history')->fetchColumn()===2,'Change history overwritten');
    $history=$db->query('SELECT * FROM sf2_remark_history WHERE version=2')->fetch();
    $change=json_decode(decryptPII($history['change_ciphertext'],'sf2-audit:'.$history['event_id']),true);
    piiCheck($change===['before'=>$text,'after'=>''],'Encrypted history incorrect');
    $_SESSION['user_id']=8;
    piiCheck(loadSF2Remarks($db,10,10,2026)===[],'Cross-section read allowed');
    try {saveSF2Remark($db,1,10,10,2026,'Unauthorized',2);throw new LogicException('Cross-section write allowed');} catch (DomainException $expected) {$checks++;}
    $_SESSION['user_id']=7;
    $db->exec('UPDATE sections SET adviser_id=8 WHERE id=10');
    piiCheck(loadSF2Remarks($db,10,10,2026)===[],'Revoked adviser read allowed');
    // Filesystem boundaries and SVG escaping use temporary synthetic files only.
    require_once $root . '/includes/student_photos.php';
    putenv('STUDENT_PHOTO_DIR='.$fixture.'/photos'); $_SERVER['DOCUMENT_ROOT']='';
    piiCheck(studentPhotoPath('fixture.png')===$fixture.'/photos/fixture.png' || realpath(studentPhotoPath('fixture.png'))===realpath($fixture.'/photos/fixture.png'),'Private photo not found');
    foreach (['../request.php','..\\request.php','a/b.png',"bad\0.png"] as $bad) piiCheck(studentPhotoPath($bad)===null,'Photo path traversal allowed');
    $doc=new DOMDocument();piiCheck($doc->loadXML(studentAvatarSVG('<script>','&evil'),LIBXML_NONET) && $doc->getElementsByTagName('script')->length===0,'Avatar injection');
    $_SERVER['DOCUMENT_ROOT']=$root;
    try {studentPhotoDirectory();throw new LogicException('Public storage allowed');} catch (RuntimeException $expected) {$checks++;}
    foreach (['attendance_system.sql','config/database_schema_update.sql'] as $file) {
        // Task 1 requires this non-PII structural singleton, never student/year seed data.
        $schema=preg_replace('/^INSERT IGNORE INTO school_year_control \(id,version\) VALUES \(1,1\);\h*\R/m','',file_get_contents($root.'/'.$file),-1,$singletons);
        piiCheck($singletons===1,'Expected exactly one structural control singleton');
        piiCheck(!preg_match('/^\h*(?:INSERT|REPLACE)\b/im',$schema),'Populated schema import remains');
    }
    echo "PII storage checks passed ($checks checks; disposable DB, synthetic photos and keys).\n";
} finally {
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    putenv('PII_ENCRYPTION_KEY'); putenv('STUDENT_PHOTO_DIR');
    if (is_dir($fixture)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); rmdir($fixture);
    }
    if ($created) $admin->exec("DROP DATABASE `$name`");
    putenv($previousTestDatabase===false ? 'MYSQLDATABASE' : 'MYSQLDATABASE='.$previousTestDatabase);
}
