<?php
// Real self-service routes and SQL, using only a disposable synthetic database.
require_once __DIR__ . '/../config/database.php';
$root = dirname(__DIR__);
$name = 'profile_test_' . bin2hex(random_bytes(6));
$fixture = __DIR__ . '/.' . $name;
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$admin = new PDO($dsn, DB_USER, DB_PASS, $options);
$created = false; $checks = 0;
$env = getenv(); $env['MYSQLDATABASE'] = $name;
function profileCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    $admin->exec("CREATE DATABASE `$name`"); $created = true;
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    $db->exec(file_get_contents($root . '/migrations/security_audit.sql'));
    $db->exec("CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(100), password VARCHAR(255), full_name VARCHAR(100),
        email VARCHAR(100), role VARCHAR(30), is_active INT, session_token VARCHAR(64), session_user_agent VARCHAR(255),
        session_ip VARCHAR(50), session_last_activity DATETIME)");
    $db->exec(file_get_contents($root . '/migrations/security_abuse_controls.sql'));
    foreach (['','/config','/includes','/sessions'] as $dir) mkdir($fixture . $dir);
    foreach (['config/database.php','config/deployment_security.php','config/schedule_rules.php','includes/functions.php','includes/security_audit.php','includes/pii_security.php','includes/session_security.php','includes/settings_security.php','includes/csrf.php','includes/abuse_security.php','includes/profile_management.php','profile.php'] as $file) copy($root . '/' . $file, $fixture . '/' . $file);
    foreach (['header','sidebar','footer'] as $file) file_put_contents($fixture . '/includes/' . $file . '.php','<?php /* Layout omitted in security fixture. */');
    file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1]; http_response_code(200);
require_once __DIR__ . '/includes/functions.php';
startSession();
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = ['user_id'=>1,'role'=>$case === 'operator' ? 'scanner_operator' : ($case === 'admin' ? 'admin' : 'teacher'),
    'full_name'=>'Synthetic Teacher','session_token'=>'old-token','authenticated_at'=>time()-600,
    'last_activity_at'=>time(), 'app_csrf'=>str_repeat('a',64)];
$_POST = ['action'=>'change_password','csrf_token'=>str_repeat('a',64),'current_password'=>'OldPass123',
    'new_password'=>'NewPass123','confirm_password'=>'NewPass123'];
if ($case === 'guest') $_SESSION = [];
if ($case === 'csrf') unset($_POST['csrf_token']);
if ($case === 'csrf_array') $_POST['csrf_token'] = [];
if ($case === 'put') $_SERVER['REQUEST_METHOD'] = 'PUT';
if ($case === 'get') $_SERVER['REQUEST_METHOD'] = 'GET';
if ($case === 'wrong') $_POST['current_password'] = 'WrongPass123';
if ($case === 'current_array') $_POST['current_password'] = [];
if ($case === 'short') $_POST['new_password'] = $_POST['confirm_password'] = '1234567';
if ($case === 'long') $_POST['new_password'] = $_POST['confirm_password'] = str_repeat('a',17);
if ($case === 'minimum') $_POST['new_password'] = $_POST['confirm_password'] = '12345678';
if ($case === 'maximum') $_POST['new_password'] = $_POST['confirm_password'] = str_repeat('b',16);
if ($case === 'mismatch') $_POST['confirm_password'] = 'Different123';
if ($case === 'new_array') $_POST['new_password'] = [];
if ($case === 'bad_action') $_POST['action'] = 'promote';
if ($case === 'target_id') $_POST += ['id'=>2,'user_id'=>2,'role'=>'admin','is_active'=>0,'username'=>'hacked'];
if (str_starts_with($case,'profile')) {
    $_POST += ['full_name'=>'Updated Teacher','email'=>'teacher@example.test'];
    $_POST['action'] = 'update_profile';
}
if ($case === 'profile_empty') $_POST['full_name'] = '';
if ($case === 'profile_long') $_POST['full_name'] = str_repeat('x',101);
if ($case === 'profile_email') $_POST['email'] = 'invalid';
if ($case === 'profile_array') $_POST['email'] = [];
if ($case === 'profile_duplicate') $_POST['email'] = 'other@example.test';
if ($case === 'profile_wrong') $_POST['current_password'] = 'WrongPass123';
if ($case === 'profile_blank') $_POST['email'] = '';
if ($case === 'profile_same') { $_POST['full_name'] = 'Synthetic Teacher'; $_POST['email'] = ''; }
register_shutdown_function(function () {
    $rows = getDB()->query('SELECT * FROM users ORDER BY id')->fetchAll();
    echo "\nRESULT:" . json_encode(['status'=>http_response_code(),'rows'=>$rows,
        'session'=>$_SESSION, 'sessionActive'=>session_status() === PHP_SESSION_ACTIVE]);
});
PHP);
    $cases = ['password'=>302,'minimum'=>302,'maximum'=>302,'admin'=>302,'target_id'=>302,
        'guest'=>302,'operator'=>403,'csrf'=>403,'csrf_array'=>403,'put'=>405,'get'=>200,
        'wrong'=>400,'current_array'=>400,'short'=>400,'long'=>400,'mismatch'=>400,'new_array'=>400,'bad_action'=>400,
        'profile'=>302,'profile_blank'=>302,'profile_same'=>302,'profile_empty'=>400,'profile_long'=>400,
        'profile_email'=>400,'profile_array'=>400,'profile_duplicate'=>400,'profile_wrong'=>400,'limited'=>429];
    foreach ($cases as $case=>$status) {
        $db->exec('DELETE FROM users'); $db->exec('DELETE FROM security_rate_limits');
        $role = $case === 'operator' ? 'scanner_operator' : ($case === 'admin' ? 'admin' : 'teacher');
        $oldHash = password_hash('OldPass123',PASSWORD_DEFAULT);
        $db->prepare('INSERT INTO users VALUES (1,?,?,?,NULL,?,1,?,?,?,NOW())')->execute(['subject',$oldHash,'Synthetic Teacher',$role,'old-token','agent','127.0.0.1']);
        $db->prepare('INSERT INTO users VALUES (2,?,?,?,?,?,1,?,NULL,NULL,NOW())')->execute(['other',password_hash('OtherPass123',PASSWORD_DEFAULT),'Other Teacher','other@example.test','teacher','other-token']);
        $other = $db->query('SELECT * FROM users WHERE id=2')->fetch();
        if ($case === 'limited') {
            $key = hash('sha256', "profile.user\0" . '1');
            $db->prepare('INSERT INTO security_rate_limits VALUES (?,?,10,?)')->execute([$key,time(),time()+900]);
        }
        $process = proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-d','auto_prepend_file='.$fixture.'/request.php',$fixture.'/profile.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture,$env);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        profileCheck($exit === 0 && $err === '', "$case failed: $err $out");
        profileCheck((bool)preg_match('/RESULT:(.*)$/',$out,$match), "$case missing result");
        $result = json_decode($match[1],true); $row = $result['rows'][0];
        profileCheck($result['status'] === $status, "$case unexpected status " . $result['status']);
        profileCheck($result['rows'][1] === $other, "$case modified another account");
        profileCheck($row['role'] === $role && $row['username'] === 'subject' && (int)$row['is_active'] === 1, "$case modified privileged fields");
        $passwordChanged = in_array($case,['password','minimum','maximum','admin','target_id'],true);
        if ($passwordChanged) {
            $new = $case === 'minimum' ? '12345678' : ($case === 'maximum' ? str_repeat('b',16) : 'NewPass123');
            profileCheck(password_verify($new,$row['password']) && !password_verify('OldPass123',$row['password']), "$case incorrect hash");
            profileCheck($row['session_token'] === null && $row['session_user_agent'] === null && $row['session_ip'] === null && $row['session_last_activity'] === null, "$case failed to revoke session");
            profileCheck($result['session'] === [] && !$result['sessionActive'], "$case retained local session");
        } else {
            profileCheck($row['password'] === $oldHash && $row['session_token'] === 'old-token', "$case unexpectedly changed password/token");
        }
        $profileChanged = in_array($case,['profile','profile_blank'],true);
        profileCheck($row['full_name'] === ($profileChanged ? 'Updated Teacher' : 'Synthetic Teacher'), "$case unexpected name");
        profileCheck($row['email'] === ($case === 'profile' ? 'teacher@example.test' : null), "$case unexpected email");
        if ($profileChanged) profileCheck($result['session']['full_name'] === 'Updated Teacher', "$case stale session name");
        if ($case === 'get') {
            profileCheck(substr_count($out,'name="csrf_token"') === 1 && str_contains($out,'name="new_password"'), 'Missing secure profile form');
            profileCheck(substr_count($out,'name="current_password"') === 1, 'Current password field is duplicated');
            $page = substr($out, 0, strrpos($out, "\nRESULT:"));
            profileCheck(!str_contains($page,$oldHash) && !str_contains($page,'value="OldPass123"'), 'Password leaked in form');
        }
    }
    echo "Profile management checks passed ($checks checks; real routes and SQL in disposable DB).\n";
} finally {
    if (is_dir($fixture)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
    if ($created) $admin->exec("DROP DATABASE `$name`");
}
