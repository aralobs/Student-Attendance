<?php
/**
 * Real MySQL/MariaDB concurrency regression checks, with notification stubs.
 * Run: php tests/scan_concurrency_test.php
 * Uses configured DB credentials to create/drop a randomly named test database;
 * never reads or modifies the application's attendance data.
 */
require_once dirname(__DIR__) . '/includes/attendance_scan.php';

function checkScan(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$name = 'scan_test_' . bin2hex(random_bytes(8));
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
    $db->exec("CREATE TABLE attendance (
        id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, date DATE NOT NULL,
        am_in TIME NULL, am_out TIME NULL, pm_in TIME NULL, pm_out TIME NULL,
        am_status VARCHAR(10) NULL, pm_status VARCHAR(10) NULL,
        attendance_type VARCHAR(10) NOT NULL DEFAULT 'absent', recorded_by INT NULL,
        UNIQUE KEY unique_attendance (student_id, date)) ENGINE=InnoDB");
    $db->exec('CREATE TABLE deliveries (channel VARCHAR(10), event VARCHAR(20)) ENGINE=InnoDB');
    foreach (['', '/attendance', '/config', '/includes'] as $dir) mkdir($fixture . $dir);
    $config = '<?php ' . "date_default_timezone_set('Asia/Manila');\n";
    $config .= 'function getDB(){static $db; return $db ??= new PDO(' . var_export($dsn . ';dbname=' . $name, true) . ',' . var_export(DB_USER, true) . ',' . var_export(DB_PASS, true) . ',' . var_export($options, true) . ');}';
    file_put_contents($fixture . '/config/database.php', $config);
    $functions = <<<'PHP'
<?php
function requireLogin() {}
function currentUser() { return ['id' => 1]; }
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
    foreach (['getNextAttendanceEvent', 'getSessionStatus', 'computeAttendanceType'] as $function) {
        $reflection = new ReflectionFunction($function);
        $lines = file($reflection->getFileName());
        $functions .= "\n" . implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }
    file_put_contents($fixture . '/includes/functions.php', $functions);
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
    copy(dirname(__DIR__) . '/includes/attendance_scan.php', $fixture . '/includes/attendance_scan.php');
    copy(dirname(__DIR__) . '/attendance/scan_process.php', $fixture . '/attendance/scan_process.php');
    // Supply synthetic student and section records for the endpoint lookup.
    $db->exec("CREATE TABLE sections (id INT PRIMARY KEY, section_name VARCHAR(20), schedule_type VARCHAR(10),
        am_in_start TIME, am_in_end TIME, am_late_threshold TIME, am_out_start TIME, am_out_end TIME,
        pm_in_start TIME, pm_in_end TIME, pm_late_threshold TIME, pm_out_start TIME, pm_out_end TIME) ENGINE=InnoDB");
    $db->exec("INSERT INTO sections VALUES (1, 'Test', 'full_day', NULL, NULL, '08:00:00', NULL, NULL, NULL, NULL, '13:00:00', NULL, NULL)");
    $db->exec("CREATE TABLE students (id INT PRIMARY KEY, section_id INT, qr_token VARCHAR(20), is_active INT,
        first_name VARCHAR(20), last_name VARCHAR(20), parent_contact VARCHAR(20), parent_email VARCHAR(40), grade_level VARCHAR(10)) ENGINE=InnoDB");
    $db->exec("INSERT INTO students VALUES (1, 1, 'test-token', 1, 'Test', 'Student', 'test-phone', 'test@example.invalid', '1')");
    file_put_contents($fixture . '/worker.php', <<<'PHP'
<?php
$mode = $argv[1];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['token'] = 'test-token';
file_put_contents($argv[2], 'ready');
while (!is_file($argv[3])) usleep(10000);
chdir(__DIR__ . '/attendance');
require 'scan_process.php';
PHP);

    $today = date('Y-m-d');
    $session = date('H:i:s') >= '12:00:00' ? 'pm' : 'am';
    $run = function (string $mode, bool $race = false) use ($fixture, $db, $today, &$processes): array {
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
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            checkScan($exit === 0, 'Worker failed: ' . $errors);
            $result = json_decode($output, true);
            checkScan(is_array($result), 'Invalid endpoint JSON: ' . $output . $errors);
            if ($errors !== '') $result['_errors'] = $errors;
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
                ->execute([$today, date('H:i:s', time() - 600)]);
        }
        $results = $run('normal', true);
        checkScan(count(array_filter($results, fn($r) => $r['success'])) === 1, "$case: expected one accepted scan");
        $winner = array_values(array_filter($results, fn($r) => $r['success']))[0];
        checkScan($winner['event'] === $session . ($case === 'missing' ? '_in' : '_out'), "$case: wrong event");
        checkScan($winner['sms_sent'] && $winner['email_sent'], "$case: notifications failed");
        $loser = array_values(array_filter($results, fn($r) => !$r['success']))[0];
        checkScan($loser['message'] === "You're already scanned", "$case: cooldown not observed: " . json_encode($loser));
        checkScan((int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 1, "$case: duplicate rows");
        checkScan((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 2, "$case: duplicate notifications");
        checkScan(!$run('normal')[0]['success'], "$case: repeat accepted");
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

    // Fixed-time checks cover all four event columns and the cooldown boundary.
    $db->exec('DELETE FROM attendance');
    $section = ['schedule_type' => 'full_day', 'am_late_threshold' => '08:00:00', 'pm_late_threshold' => '13:00:00'];
    foreach (['am_in' => '08:00:01', 'am_out' => '08:05:01', 'pm_in' => '13:00:00', 'pm_out' => '13:05:00'] as $event => $time) {
        $result = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today $time"));
        checkScan($result['success'] && $result['event'] === $event, "$event not saved correctly");
        checkScan(!$db->inTransaction(), "$event left transaction open");
        $repeat = saveAttendanceScan($db, 1, $today, $section, 1, (new DateTimeImmutable("$today $time"))->modify('+299 seconds'));
        checkScan(!$repeat['success'] && !$db->inTransaction(), "$event cooldown boundary failed");
    }
    checkScan($result['record']['attendance_type'] === 'full_day', 'Attendance type not recomputed');
    checkScan($result['record']['am_status'] === 'late' && $result['record']['pm_status'] === 'present', 'Arrival status changed');
    $complete = saveAttendanceScan($db, 1, $today, $section, 1, new DateTimeImmutable("$today 13:10:00"));
    checkScan(!empty($complete['complete']) && !$db->inTransaction(), 'Completed day left transaction open');
    echo "Scan concurrency checks passed (row races, repeats, rollback, notification failure, all events, cooldown boundaries).\n";
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
