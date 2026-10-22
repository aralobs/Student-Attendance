<?php
// Real endpoint SQL + shared quotas, in a disposable MySQL database. Authentication
// and attendance writes are stubbed; no application records or notifications used.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/abuse_security.php';
$root = dirname(__DIR__);
$name = 'abuse_routes_' . bin2hex(random_bytes(6));
$fixture = __DIR__ . '/.' . $name;
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($dsn, DB_USER, DB_PASS, $options);
$created = false; $checks = 0;
$env = getenv(); $env['MYSQLDATABASE'] = $name;
function routeCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
function routeRequest(string $route, string $case): array {
    global $fixture, $env;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $fixture . '/sessions', '-d', 'auto_prepend_file=' . $fixture . '/request.php', $fixture . '/' . $route, $case], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname($fixture . '/' . $route), $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    routeCheck($exit === 0 && ($err === '' || str_contains($err, 'Security rate-limit storage unavailable.')), "$route/$case execution error: $err");
    routeCheck((bool)preg_match('/RESULT:(.*)$/', $out, $match), "$route/$case missing result: $out");
    $result = json_decode($match[1], true); $result['body'] = $out;
    return $result;
}
try {
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $created = true;
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    $schema = file_get_contents($root . '/migrations/security_abuse_controls.sql'); $db->exec($schema);
    $db->exec("CREATE TABLE sections (id INT PRIMARY KEY, adviser_id INT, is_active INT, section_name VARCHAR(50), schedule_type VARCHAR(20),
        am_in_start TIME, am_in_end TIME, am_late_threshold TIME, am_out_start TIME, am_out_end TIME,
        pm_in_start TIME, pm_in_end TIME, pm_late_threshold TIME, pm_out_start TIME, pm_out_end TIME);
        INSERT INTO sections (id,adviser_id,is_active,section_name,schedule_type) VALUES (10,7,1,'Own','am_only'),(20,8,1,'Other','am_only'),(30,7,0,'Inactive','am_only');
        CREATE TABLE students (id INT PRIMARY KEY, section_id INT, is_active INT, qr_token VARCHAR(100) UNIQUE, qr_code VARCHAR(255), lrn VARCHAR(20));
        INSERT INTO students VALUES (1,10,1,'STU-LEGACY-ABCDEF','old.png','123456789001'),(2,20,1,'OTHER_TOKEN','other.png','123456789002'),(3,30,1,'INACTIVE_TOKEN',NULL,'123456789003');
        CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(100), password VARCHAR(255), role VARCHAR(50), full_name VARCHAR(50), is_active INT);");
    $db->prepare('INSERT INTO users VALUES (1, ?, ?, ?, ?, 1)')->execute(['testadmin', password_hash('a sufficiently long phrase', PASSWORD_BCRYPT), 'admin', 'Synthetic Admin']);
    foreach (['','/config','/includes','/attendance','/sessions'] as $dir) mkdir($fixture . $dir);
    copy($root . '/config/database.php', $fixture . '/config/database.php');
    copy($root . '/config/deployment_security.php', $fixture . '/config/deployment_security.php');
    copy($root . '/config/hosting_environment.php', $fixture . '/config/hosting_environment.php');
    copy($root . '/includes/browser_security.php', $fixture . '/includes/browser_security.php');
    copy($root . '/index.php', $fixture . '/index.php');
    copy($root . '/attendance/scan_process.php', $fixture . '/attendance/scan_process.php');
    file_put_contents($fixture . '/includes/mail_helper.php', '<?php');
    file_put_contents($fixture . '/includes/attendance_scan.php', <<<'PHP'
<?php
function saveAttendanceScan($db, $studentId, ...$args) {
    $GLOBALS['savedStudent'] = $studentId;
    return ['success'=>false, 'message'=>'Synthetic scan stopped before attendance write'];
}
PHP);
    file_put_contents($fixture . '/includes/functions.php', '<?php require_once ' . var_export($root . '/includes/abuse_security.php', true) . '; require_once ' . var_export($root . '/includes/csrf.php', true) . ';' . <<<'PHP'

function securityAuditContext(...$args) {}
function startSession(): void {
    if (str_starts_with($GLOBALS['case'], 'login') && session_status() === PHP_SESSION_NONE) session_start();
}
function requireLogin(): void { if ($GLOBALS['case'] === 'guest') { http_response_code(401); exit; } }
function currentUser(): array {
    $case = $GLOBALS['case'];
    $role = str_starts_with($case, 'teacher') ? 'teacher' : ($case === 'unknown_role' ? 'unknown' : ($case === 'admin' ? 'admin' : 'scanner_operator'));
    return ['id' => $case === 'teacher_empty' ? 9 : 7, 'role' => $role];
}
function isHolidayOrNoClass($date): bool { return false; }
function getAttendanceScheduleType($section): string { return 'am_only'; }
function getSetting($name) { return 'Synthetic school'; }
function registerSession($id): string { $GLOBALS['loggedIn'] = $id; return 'synthetic-session-token'; }
PHP);
    file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1]; $GLOBALS['case'] = $case;
http_response_code(200);
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = []; $_SESSION = ['app_csrf' => str_repeat('a',64), 'session_token' => 'synthetic-scanner-session'];
$_POST = ['csrf_token'=>str_repeat('a',64), 'token'=>'STU-LEGACY-ABCDEF', 'username'=>'testadmin', 'password'=>'incorrect'];
if ($case === 'teacher_foreign') $_POST['token'] = 'OTHER_TOKEN';
if ($case === 'inactive') $_POST['token'] = 'INACTIVE_TOKEN';
if ($case === 'unknown_token') $_POST['token'] = 'UNKNOWN_TOKEN';
if ($case === 'oversized') $_POST['token'] = str_repeat('a',101);
if ($case === 'array') $_POST['token'] = ['invalid'];
if ($case === 'nul') $_POST['token'] = "BAD\0TOKEN";
if ($case === 'missing_csrf') unset($_POST['csrf_token']);
if ($case === 'login_valid') $_POST['password'] = 'a sufficiently long phrase';
if ($case === 'login_case') $_POST['username'] = 'TESTADMIN';
if ($case === 'login_accent') $_POST['username'] = 'téstadmin';
if ($case === 'login_username_array') $_POST['username'] = ['bad'];
if ($case === 'login_password_array') $_POST['password'] = ['bad'];
if ($case === 'login_oversized') $_POST['password'] = str_repeat('a',1025);
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(['status'=>http_response_code(),'savedStudent'=>$GLOBALS['savedStudent']??null,'loggedIn'=>$GLOBALS['loggedIn']??null]);
});
PHP);
    foreach (['guest'=>401,'unknown_role'=>403,'missing_csrf'=>403,'teacher'=>200,'teacher_foreign'=>200,'teacher_empty'=>200,'scanner'=>200,'admin'=>200,'inactive'=>200,'unknown_token'=>200,'oversized'=>400,'array'=>400,'nul'=>400] as $case => $status) {
        $db->exec('DELETE FROM security_rate_limits');
        $result = routeRequest('attendance/scan_process.php', $case);
        routeCheck($result['status'] === $status, "Unexpected scanner status for $case");
        routeCheck($result['savedStudent'] === (in_array($case,['teacher','scanner','admin'],true) ? 1 : null), "Out-of-scope/malformed scan reached attendance for $case");
    }
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SESSION['session_token'] = 'synthetic-scanner-session';
    foreach (['traffic','probes'] as $type) {
        $db->exec('DELETE FROM security_rate_limits');
        $bucket = $type === 'traffic' ? abuseBucket('scan.user','7',120,60) : abuseBucket('scan.invalid.user','7',20,60);
        $db->prepare('INSERT INTO security_rate_limits VALUES (?,?,?,?)')->execute([$bucket['key'],time(),$bucket['limit']+1,time()+60]);
        $result = routeRequest('attendance/scan_process.php','scanner');
        routeCheck($result['status'] === 429 && $result['savedStudent'] === null && str_contains($result['body'],'"retry_after":'), 'Scanner quota did not reject before attendance');
    }
    $db->exec('DROP TABLE security_rate_limits');
    routeCheck(routeRequest('attendance/scan_process.php','scanner')['status'] === 503, 'Scanner failed open on storage outage');
    routeCheck(routeRequest('index.php','login_valid')['status'] === 503, 'Login failed open on storage outage');
    $db->exec($schema);
    foreach (['login_valid'=>302,'login_username_array'=>400,'login_password_array'=>400,'login_oversized'=>400] as $case=>$status) {
        $db->exec('DELETE FROM security_rate_limits'); $result = routeRequest('index.php',$case);
        routeCheck($result['status'] === $status, "Login input/status failed for $case");
        routeCheck($result['loggedIn'] === ($case === 'login_valid' ? 1 : null), 'Invalid login granted a session');
    }
    $db->exec('DELETE FROM security_rate_limits');
    for ($i=0;$i<10;$i++) routeCheck(routeRequest('index.php','login_case')['status'] === 200, 'Login quota activated too early');
    routeCheck(routeRequest('index.php','login_valid')['status'] === 429, 'Account/case quota bypassed by correct password');
    routeCheck(routeRequest('index.php','login_accent')['status'] === 429, 'SQL collation variation bypassed account ID quota');
    // Explicit rotation uses the actual CLI script against this test DB only.
    $before = $db->query('SELECT id,qr_token FROM students ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $process = proc_open([PHP_BINARY, $root . '/migrations/rotate_student_qr_tokens.php', '--legacy', '--confirm'], [1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, $env);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    routeCheck(proc_close($process) === 0 && $error === '' && str_contains($output,'Rotated 3'), 'Legacy rotation failed');
    foreach ($db->query('SELECT * FROM students') as $student) {
        routeCheck($student['qr_token'] !== $before[$student['id']] && (bool)preg_match('/^STU-' . $student['lrn'] . '-[A-F0-9]{64}$/D',$student['qr_token']) && $student['qr_code'] === null, 'Legacy token not replaced with LRN-based token');
    }
    $db->exec('DELETE FROM security_rate_limits');
    routeCheck(routeRequest('attendance/scan_process.php','scanner')['savedStudent'] === null, 'Rotated legacy card still accepted');
    echo "Abuse endpoint checks passed ($checks checks; isolated SQL, no attendance writes or provider calls).\n";
} finally {
    if (is_dir($fixture)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
    if ($created) $admin->exec("DROP DATABASE `$name`");
}
