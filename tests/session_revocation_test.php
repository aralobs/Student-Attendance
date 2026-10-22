<?php
// Exercise real authentication and management routes against a disposable DB.
require_once __DIR__ . '/../config/database.php';
$root = dirname(__DIR__);
$name = 'session_test_' . bin2hex(random_bytes(6));
$fixture = __DIR__ . '/.' . $name;
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$admin = new PDO($dsn, DB_USER, DB_PASS, $options);
$created = false; $checks = 0;
$env = getenv(); $env['MYSQLDATABASE'] = $name;
function revokeCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    $admin->exec("CREATE DATABASE `$name`"); $created = true;
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    $db->exec(file_get_contents($root . '/migrations/security_audit.sql'));
    $db->exec("CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(100), password VARCHAR(255), full_name VARCHAR(100),
        email VARCHAR(100), role VARCHAR(30), is_active INT, session_token VARCHAR(64), session_user_agent VARCHAR(255),
        session_ip VARCHAR(50), session_last_activity DATETIME)");
    $db->prepare('INSERT INTO users VALUES (99,?,?,?,NULL,?,1,?,NULL,NULL,NOW())')->execute(['actor',password_hash('ActorPass123',PASSWORD_BCRYPT),'Synthetic Admin','admin','actor-token']);
    foreach (['','/config','/includes','/users','/sessions'] as $dir) mkdir($fixture . $dir);
    foreach (['config/database.php','config/deployment_security.php','config/schedule_rules.php','includes/functions.php','includes/security_audit.php','includes/pii_security.php','includes/session_security.php','includes/settings_security.php','includes/csrf.php','includes/abuse_security.php','users/edit.php','users/toggle.php','users/archive.php','users/restore.php','logout.php'] as $file) copy($root . '/' . $file, $fixture . '/' . $file);
    file_put_contents($fixture . '/includes/header.php','<?php exit;');
    file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1]; http_response_code(200);
require_once __DIR__ . '/includes/functions.php';
startSession();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = ['user_id'=>99,'role'=>'admin','session_token'=>'actor-token','authenticated_at'=>time()-600,'last_activity_at'=>time(), 'app_csrf'=>str_repeat('a',64)];
$_GET = ['id'=>1];
$_POST = ['id'=>1,'csrf_token'=>str_repeat('a',64),'full_name'=>'Updated Synthetic Name','username'=>'subject','email'=>'', 'role'=>'teacher','is_active'=>1,'password'=>'','password_confirm'=>''];
if ($case === 'password') $_POST['password'] = $_POST['password_confirm'] = 'NewPass123';
if ($case === 'invalid_password') $_POST['password'] = $_POST['password_confirm'] = 'short';
if ($case === 'role') $_POST['role'] = 'admin';
if ($case === 'deactivate') $_POST['is_active'] = 0;
if ($case === 'logout_replaced') $_SESSION = ['user_id'=>1,'role'=>'teacher','session_token'=>'old-token'];
register_shutdown_function(function () {
    $row = getDB()->query('SELECT * FROM users WHERE id=1')->fetch();
    startSession();
    $_SESSION = ['user_id'=>1,'role'=>'teacher','session_token'=>'old-token','authenticated_at'=>time()-600,'last_activity_at'=>time()];
    $reason = checkSession(false);
    echo "\nRESULT:" . json_encode(['status'=>http_response_code(),'token'=>$row['session_token'],
        'active'=>(int)$row['is_active'],'role'=>$row['role'],'newPassword'=>password_verify('NewPass123',$row['password']),
        'reason'=>$reason]);
});
PHP);
    $cases = ['profile'=>['users/edit.php',1,'teacher','old-token',null,302],
        'password'=>['users/edit.php',1,'teacher',null,'invalid',302],
        'invalid_password'=>['users/edit.php',1,'teacher','old-token',null,200],
        'role'=>['users/edit.php',1,'admin',null,'invalid',302],
        'deactivate'=>['users/edit.php',0,'teacher',null,'invalid',302],
        'toggle_off'=>['users/toggle.php',0,'teacher',null,'invalid',302],
        'toggle_on'=>['users/toggle.php',1,'teacher',null,'invalid',302],
        'archive'=>['users/archive.php',2,'teacher',null,'invalid',302],
        'restore'=>['users/restore.php',1,'teacher',null,'invalid',302],
        'logout_replaced'=>['logout.php',1,'teacher','replacement-token','session_taken',302]];
    foreach ($cases as $case=>$expected) {
        $db->exec('DELETE FROM users WHERE id=1');
        $initialActive = $case === 'restore' ? 2 : ($case === 'toggle_on' ? 0 : 1);
        $token = $case === 'logout_replaced' ? 'replacement-token' : 'old-token';
        $db->prepare('INSERT INTO users VALUES (1,?,?,?,NULL,?,?,?,?,NULL,NOW())')->execute(['subject',password_hash('OldPass123',PASSWORD_BCRYPT),'Synthetic Teacher','teacher',$initialActive,$token,'test-agent']);
        $process = proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-d','auto_prepend_file='.$fixture.'/request.php',$fixture.'/'.$expected[0],$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($fixture.'/'.$expected[0]),$env);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        revokeCheck($exit === 0 && $err === '', "$case execution failed: $err $out");
        revokeCheck((bool)preg_match('/RESULT:(.*)$/',$out,$match), "$case missing result");
        $result = json_decode($match[1],true);
        revokeCheck($result['status'] === $expected[5] && $result['active'] === $expected[1] && $result['role'] === $expected[2], "$case unexpected account state");
        revokeCheck($result['token'] === $expected[3] && $result['reason'] === $expected[4], "$case token revocation failed");
        revokeCheck($result['newPassword'] === ($case === 'password'), "$case unexpected password change");
    }
    echo "Session revocation checks passed ($checks checks; real SQL and routes in disposable DB).\n";
} finally {
    if (is_dir($fixture)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
    if ($created) $admin->exec("DROP DATABASE `$name`");
}
