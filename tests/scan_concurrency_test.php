<?php
/**
 * Real MySQL/MariaDB concurrency regression checks, with notification stubs.
 * Run: php tests/scan_concurrency_test.php
 * Uses configured DB credentials to create/drop a randomly named test database;
 * never reads or modifies the application's attendance data.
 */
require_once dirname(__DIR__) . '/includes/attendance_scan.php';
require_once dirname(__DIR__) . '/includes/attendance_finalizer.php';

function checkScan(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

putenv('PII_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
$name = 'enrollment_test_' . bin2hex(random_bytes(8));
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$name)) throw new RuntimeException('Invalid disposable database name');
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, DB_USER, DB_PASS, $options);
$fixture = __DIR__ . '/.' . $name;
$processes = [];
$created = false;
try {
    $admin->exec("CREATE DATABASE `$name`");
    $created = true;
    $db = new PDO($dsn . ';dbname=' . $name, DB_USER, DB_PASS, $options);
    $db->exec("CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT); INSERT INTO system_settings VALUES('enrollment_model_version','0')");
    $db->exec("CREATE TABLE attendance (
        id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, date DATE NOT NULL,
        am_in TIME NULL, am_out TIME NULL, pm_in TIME NULL, pm_out TIME NULL,
        am_status VARCHAR(10) NULL, pm_status VARCHAR(10) NULL,
        attendance_type VARCHAR(10) NOT NULL DEFAULT 'absent', recorded_by INT NULL, remarks TEXT NULL,
        UNIQUE KEY unique_attendance (student_id, date)) ENGINE=InnoDB");
    $db->exec(file_get_contents(dirname(__DIR__) . '/migrations/security_audit.sql'));
    $db->exec('CREATE TABLE deliveries (channel VARCHAR(10), event VARCHAR(20)) ENGINE=InnoDB');
    foreach (['', '/attendance', '/config', '/includes', '/sessions'] as $dir) mkdir($fixture . $dir);
    $config = '<?php ' . "date_default_timezone_set('Asia/Manila');\n";
    $config .= 'function getDB(){static $db; return $db ??= new PDO(' . var_export($dsn . ';dbname=' . $name, true) . ',' . var_export(DB_USER, true) . ',' . var_export(DB_PASS, true) . ',' . var_export($options, true) . ');}';
    file_put_contents($fixture . '/config/database.php', $config);
    $functions = <<<'PHP'
<?php
function requireLogin() {}
function currentUser() { return ['id' => 1, 'role' => 'scanner_operator']; }
function startSession() {}
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/abuse_security.php';
function isHolidayOrNoClass($date) { return false; }
function buildSMSMessage($template, $student) { return $template; }
function sendSMS($number, $message, $studentId, $event) {
    if (getDB()->inTransaction()) throw new RuntimeException('Notification inside transaction');
    getDB()->prepare('INSERT INTO deliveries VALUES (?, ?)')->execute(['sms', $event]);
    if ($GLOBALS['mode'] === 'notification_failure') throw new RuntimeException('Simulated lost provider response');
    return true;
}
PHP;
    // Exercise the application's real event selection/status/type functions.
    foreach (['getScheduleForGrade', 'getAttendanceScheduleType', 'getAttendanceEvents', 'getNextAttendanceEvent', 'getSessionStatus', 'computeAttendanceType'] as $function) {
        $reflection = new ReflectionFunction($function);
        $lines = file($reflection->getFileName());
        $functions .= "\n" . implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
    file_put_contents($fixture . '/includes/functions.php', $functions);
    copy(dirname(__DIR__) . '/includes/security_audit.php', $fixture . '/includes/security_audit.php');
    copy(dirname(__DIR__) . '/includes/pii_security.php', $fixture . '/includes/pii_security.php');
    copy(dirname(__DIR__) . '/includes/csrf.php', $fixture . '/includes/csrf.php');
    copy(dirname(__DIR__) . '/includes/abuse_security.php', $fixture . '/includes/abuse_security.php');
    foreach (['enrollment_operations','enrollment_access','enrollment_repository','school_years'] as $dependency) copy(dirname(__DIR__).'/includes/'.$dependency.'.php',$fixture.'/includes/'.$dependency.'.php');
    $db->exec(file_get_contents(dirname(__DIR__) . '/migrations/security_abuse_controls.sql'));
    file_put_contents($fixture . '/includes/mail_helper.php', <<<'PHP'
<?php
function sendArrivalEmail($student, $session) { return deliverEmail(strtolower($session) . '_arrival'); }
function sendDepartureEmail($student, $session) { return deliverEmail(strtolower($session) . '_departure'); }
function deliverEmail($event) {
    if (getDB()->inTransaction()) throw new RuntimeException('Notification inside transaction');
    getDB()->prepare('INSERT INTO deliveries VALUES (?, ?)')->execute(['email', $event]);
    return true;
}
PHP);
    file_put_contents($fixture . '/includes/attendance_scan.php', str_replace("new DateTimeImmutable('now')", "new DateTimeImmutable(date('Y-m-d') . (\$GLOBALS['mode'] === 'departure' ? ' 15:30:00' : ' 08:00:01'))", file_get_contents(dirname(__DIR__) . '/includes/attendance_scan.php')));
    copy(dirname(__DIR__) . '/attendance/scan_process.php', $fixture . '/attendance/scan_process.php');
    // Supply synthetic student and section records for the endpoint lookup.
    $db->exec("CREATE TABLE sections (id INT PRIMARY KEY, section_name VARCHAR(20), schedule_type VARCHAR(10),
        am_in_start TIME, am_in_end TIME, am_late_threshold TIME, am_out_start TIME, am_out_end TIME,
        pm_in_start TIME, pm_in_end TIME, pm_late_threshold TIME, pm_out_start TIME, pm_out_end TIME) ENGINE=InnoDB");
    $db->exec("INSERT INTO sections VALUES (1, 'Test', 'full_day', NULL, NULL, '08:00:00', NULL, NULL, NULL, NULL, '13:00:00', NULL, NULL)");
    $db->exec("CREATE TABLE students (id INT PRIMARY KEY, section_id INT, qr_token VARCHAR(20), is_active INT,
        first_name VARCHAR(20), last_name VARCHAR(20), parent_contact VARCHAR(20), parent_email VARCHAR(40), grade_level VARCHAR(10)) ENGINE=InnoDB");
    $db->exec("INSERT INTO students VALUES (1, 1, 'test-token', 1, 'Test', 'Student', 'test-phone', 'test@example.invalid', 'Grade 3')");
    $db->exec("ALTER TABLE sections ADD grade_level VARCHAR(10) DEFAULT 'Grade 3'");
    $db->exec('ALTER TABLE sections ADD is_active INT DEFAULT 1, ADD adviser_id INT DEFAULT 1');
    file_put_contents($fixture . '/worker.php', <<<'PHP'
<?php
$mode = $argv[1];
session_save_path(__DIR__ . '/sessions');
session_id('kiosk-' . sha1($argv[2]));
session_start();
$_SESSION['scanner_last_result'] ??= ['student_id' => 900 + (int)substr($argv[2], -1), 'success' => true];
$previousScan = $_SESSION['scanner_last_result'];
register_shutdown_function(function () use ($previousScan) {
    file_put_contents($GLOBALS['argv'][2] . '.session-result', json_encode([
        'before' => $previousScan, 'after' => $_SESSION['scanner_last_result'] ?? null]));
});
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['app_csrf'] = str_repeat('a',64);
$_SESSION['session_token'] = sha1($argv[2]);
$_POST['csrf_token'] = str_repeat('a',64);
$_POST['token'] = 'test-token';
file_put_contents($argv[2], 'ready');
while (!is_file($argv[3])) usleep(10000);
chdir(__DIR__ . '/attendance');
require 'scan_process.php';
PHP);

    $today = date('Y-m-d');
    $session = 'am';
    $previousSessions = [];
    $run = function (string $mode, bool $race = false) use ($fixture, $db, $today, &$processes, &$previousSessions): array {
        $count = $race ? 2 : 1;
        $gate = $fixture . '/go';
        if (is_file($gate)) unlink($gate);
        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $ready = $fixture . '/ready' . $i;
            if (is_file($ready)) unlink($ready);
            $process = proc_open([PHP_BINARY, $fixture . '/worker.php', $mode, $ready, $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            checkScan(is_resource($process), 'Worker failed to start');
            $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 10;
        do {
            $readyCount = 0;
            for ($i = 0; $i < $count; $i++) $readyCount += is_file($fixture . '/ready' . $i) ? 1 : 0;
            if ($readyCount === $count) break;
            usleep(10000);
        } while (microtime(true) < $deadline);
        checkScan($readyCount === $count, 'Workers not ready');
        if ($race) {
            // Hold the exact row until both independent kiosk requests start.
            $db->beginTransaction();
            $db->prepare("INSERT INTO attendance (student_id, date) VALUES (1, ?) ON DUPLICATE KEY UPDATE id = id")->execute([$today]);
        }
        file_put_contents($gate, 'go');
        if ($race) {
            usleep(300000);
            // Rollback preserves the missing-row case and releases both workers.
            $db->rollBack();
        }
        $results = [];
        foreach ($processes as $i => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            checkScan($exit === 0, 'Worker failed: ' . $errors);
            $result = json_decode($output, true);
            checkScan(is_array($result), 'Invalid endpoint JSON: ' . $output . $errors);
            if ($errors !== '') $result['_errors'] = $errors;
            $sessionSnapshot = json_decode(file_get_contents($fixture . '/ready' . $i . '.session-result'), true);
            if (isset($previousSessions[$i])) checkScan($sessionSnapshot['before'] === $previousSessions[$i], 'Kiosk session result lost between requests');
            if ($result['success']) {
                foreach (['student_id', 'event', 'date', 'attendance_type'] as $field) {
                    checkScan($sessionSnapshot['after'][$field] === $result[$field], "Successful scan not persisted: $field");
                }
            } else {
                checkScan($sessionSnapshot['after'] === $sessionSnapshot['before'], 'Rejected scan replaced kiosk result');
            }
            $previousSessions[$i] = $sessionSnapshot['after'];
            $result['_session_after'] = $sessionSnapshot['after'];
            $results[] = $result;
        }
        $processes = [];
        return $results;
    };

    foreach (['missing', 'existing'] as $case) {
        $db->exec('DELETE FROM attendance');
        $db->exec('DELETE FROM deliveries');
        if ($case === 'existing') {
            $db->prepare("INSERT INTO attendance (student_id, date, {$session}_in) VALUES (1, ?, ?)")
                ->execute([$today, '08:00:01']);
        }
        $mode = $case === 'existing' ? 'departure' : 'normal';
        $results = $run($mode, true);
        checkScan(count(array_filter($results, fn($r) => $r['success'])) === 1, "$case: expected one accepted scan");
        $winner = array_values(array_filter($results, fn($r) => $r['success']))[0];
        checkScan($winner['event'] === ($case === 'missing' ? 'am_in' : 'pm_out'), "$case: wrong event");
        checkScan($winner['sms_sent'] && $winner['email_sent'], "$case: notifications failed");
        $loser = array_values(array_filter($results, fn($r) => !$r['success']))[0];
        checkScan($loser['message'] === "You're already scanned", "$case: cooldown not observed: " . json_encode($loser));
        checkScan((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 1, "$case: duplicate rows");
        checkScan((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 2, "$case: duplicate notifications");
        checkScan(!$run($mode)[0]['success'], "$case: repeat accepted");
        checkScan((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 2, "$case: repeat notified");
    }
    $db->exec('DELETE FROM attendance');
    $db->exec('DELETE FROM deliveries');
    $db->exec("CREATE TRIGGER fail_scan BEFORE UPDATE ON attendance FOR EACH ROW
        BEGIN IF NEW.attendance_type <> OLD.attendance_type THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated save failure'; END IF; END");
    checkScan(!$run('normal')[0]['success'], 'Save failure accepted');
    checkScan((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 0, 'Partial save was not rolled back');
    checkScan((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 0, 'Failed save notified');
    $db->exec('DROP TRIGGER fail_scan');
    $result = $run('notification_failure')[0];
    checkScan($result['success'] && !$result['sms_sent'] && $result['email_sent'], 'Notification failure lost saved scan');
    checkScan(!$run('normal')[0]['success'], 'Notification failure retry accepted');
    checkScan((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 2, 'Notification failure retry duplicated delivery');

    $db->exec('DELETE FROM attendance');
    $db->exec('DELETE FROM deliveries');
    $db->prepare("INSERT INTO attendance (student_id, date, am_in, am_out, pm_in, pm_out) VALUES (1, ?, '01:00:00', '01:05:00', '01:10:00', '01:15:00')")->execute([$today]);
    checkScan(!$run('normal')[0]['success'], 'Completed attendance accepted');
    checkScan((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 0, 'Completed attendance notified');

    // Fixed-time checks cover both required event columns and the cooldown boundary.
    $db->exec('DELETE FROM attendance');
    $section = ['schedule_type' => 'full_day', 'am_late_threshold' => '08:00:00', 'pm_late_threshold' => '13:00:00'];
    foreach (['am_in' => '08:00:01', 'pm_out' => '15:30:00'] as $event => $time) {
        $result = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today $time"));
        checkScan($result['success'] && $result['event'] === $event, "$event not saved correctly");
        checkScan(!$db->inTransaction(), "$event left transaction open");
        $repeat = saveAttendanceScan($db, 1, $today, $section, 1, (new DateTimeImmutable("$today $time"))->modify('+299 seconds'));
        checkScan(!$repeat['success'] && !$db->inTransaction(), "$event cooldown boundary failed");
    }
    checkScan($result['record']['attendance_type'] === 'full_day', 'Attendance type not recomputed');
    checkScan($result['record']['am_status'] === 'late' && $result['record']['pm_status'] === 'present', 'Arrival status changed');
    $complete = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 16:00:00"));
    checkScan(!empty($complete['complete']) && !$db->inTransaction(), 'Completed day left transaction open');
    // Exercise real database writes for each grade, with only the daily pair populated.
    foreach (getGradeLevels() as $grade) {
        $db->exec('DELETE FROM attendance');
        $rule = getScheduleForGrade($grade);
        $section = array_merge($rule['am'], $rule['pm'] ?? [], ['grade_level' => $grade, 'schedule_type' => $rule['default_type']]);
        $arrival = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 08:01:01"));
        checkScan($arrival['success'] && $arrival['event'] === 'am_in', "$grade arrival save");
        checkScan($arrival['record']['attendance_type'] === 'partial', "$grade one AM scan must be partial");
        $early = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 08:06:01"));
        checkScan(!$early['success'] && !$db->inTransaction(), "$grade premature departure rejected");
        $out = getAttendanceEvents($section)[1];
        $outTime = $section[substr($out, 0, 2) . '_out_start'];
        $departure = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today $outTime"));
        checkScan($departure['success'] && $departure['event'] === $out, "$grade departure save");
        checkScan($departure['record']['attendance_type'] === 'full_day', "$grade two scans complete");
        checkScan($departure['record']['pm_in'] === null, "$grade must never write PM arrival");
        checkScan($departure['record'][$out === 'am_out' ? 'pm_out' : 'am_out'] === null, "$grade unused departure stays empty");
        if ($out === 'pm_out') checkScan($departure['record']['pm_status'] === 'present', "$grade PM presence without PM arrival");
        $third = saveAttendanceScan($db, 1, $today, $section, 1, (new DateTimeImmutable("$today $outTime"))->modify('+300 seconds'));
        checkScan(!empty($third['complete']) && !$db->inTransaction(), "$grade third scan rejected");
        $db->exec('DELETE FROM attendance');
        $missedArrival = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 15:30:00"));
        checkScan(!$missedArrival['success'] && !$db->inTransaction(), "$grade afternoon arrival rejected");
        checkScan((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 0, "$grade invalid scan leaves no row");
    }
    foreach (getGradeLevels() as $grade) {
        $db->exec('DELETE FROM attendance');
        $section = array_merge(getScheduleForGrade('Grade 3')['pm'], ['grade_level' => $grade, 'schedule_type' => 'pm_only']);
        $morning = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 08:00:00"));
        checkScan(!$morning['success'], "$grade PM section rejects AM scan");
        $arrival = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 13:01:01"));
        checkScan($arrival['success'] && $arrival['event'] === 'pm_in', "$grade PM arrival save");
        checkScan($arrival['record']['attendance_type'] === 'partial', "$grade one PM scan must be partial");
        $repeat = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 13:01:02"));
        checkScan(!$repeat['success'], "$grade PM duplicate arrival");
        $departure = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 15:30:00"));
        checkScan($departure['success'] && $departure['event'] === 'pm_out', "$grade PM departure save");
        $record = $departure['record'];
        checkScan($record['attendance_type'] === 'full_day' && $record['pm_status'] === 'late', "$grade PM completion and lateness");
        checkScan($record['am_in'] === null && $record['am_out'] === null && $record['am_status'] === null, "$grade no AM scans or status required");
        $third = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 15:35:00"));
        checkScan(!empty($third['complete']) && !$db->inTransaction(), "$grade PM third scan rejected");
    }
    // Stored labels must reflect the required pair, even when old data is stale.
    foreach (['am_only', 'full_day', 'pm_only'] as $schedule) {
        $db->exec('DELETE FROM attendance');
        $db->prepare('UPDATE sections SET schedule_type = ? WHERE id = 1')->execute([$schedule]);
        $in = $schedule === 'pm_only' ? 'pm_in' : 'am_in';
        $out = $schedule === 'am_only' ? 'am_out' : 'pm_out';
        $db->prepare("INSERT INTO attendance (student_id, date, $in, attendance_type, am_status, pm_status)
            VALUES (1, ?, '08:00:00', 'full_day', 'late', 'late')")->execute([$today]);
        checkScan(reconcileAttendanceCompletion($today, 1, $db) === 1, "$schedule stale full-day label repaired");
        $row = $db->query('SELECT * FROM attendance')->fetch();
        checkScan($row['attendance_type'] === 'partial', "$schedule one scan displays partial");
        checkScan($row['am_status'] === 'late' && $row['pm_status'] === 'late', "$schedule completion repair preserves session statuses");
        $db->exec("UPDATE attendance SET $out = '15:30:00'");
        checkScan(reconcileAttendanceCompletion($today, 1, $db) === 1, "$schedule stale partial label repaired");
        checkScan($db->query('SELECT attendance_type FROM attendance')->fetchColumn() === 'full_day', "$schedule two scans display full day");
        checkScan(reconcileAttendanceCompletion($today, 1, $db) === 0, "$schedule completion refresh is idempotent");
        $db->exec("UPDATE attendance SET attendance_type = 'holiday', $out = NULL");
        checkScan(reconcileAttendanceCompletion($today, 1, $db) === 0, "$schedule holiday unchanged");
    }
    echo "Scan concurrency checks passed (row races, repeats, rollback, notification failure, two daily events, cooldown boundaries, isolated kiosk session persistence).\n";
} finally {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    foreach ($processes as [$process, $pipes]) {
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
    if (is_dir($fixture)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
    if ($created) $admin->exec("DROP DATABASE `$name`");
}
