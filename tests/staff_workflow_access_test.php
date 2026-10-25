<?php
// Real route guards and navigation, isolated from the application DB and SMS.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.staff-workflow-' . bin2hex(random_bytes(6));
$routes = ['attendance/attendance.php', 'reports/daily.php', 'reports/by_student.php',
    'reports/by_section.php', 'reports/am_pm.php', 'reports/date_range.php', 'reports/partial.php',
    'reports/export_excel.php', 'reports/index_reports.php', 'reports/sf4.php',
    'analytics/analytics.php', 'calendar/calendar.php', 'attendance/end_of_day.php', 'attendance/end_of_day_status.php'];
foreach (['', '/config', '/includes', '/attendance', '/reports', '/analytics', '/calendar'] as $dir) mkdir($fixture . $dir);
foreach (array_merge($routes, ['includes/functions.php', 'includes/end_of_day_alerts.php',
    'includes/attendance_finalizer.php', 'includes/settings_security.php', 'includes/csrf.php',
    'includes/sms_status.php','includes/notification_templates.php','includes/enrollment_reports.php','includes/enrollment_operations.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/school_years.php',
    'includes/abuse_security.php', 'includes/sidebar.php', 'includes/footer.php', 'config/schedule_rules.php']) as $file) {
    copy($root . '/' . $file, $fixture . '/' . $file);
}
file_put_contents($fixture . '/config/database.php', <<<'PHP'
<?php
define('BASE_URL', '/');
date_default_timezone_set('Asia/Manila');
function getDB(...$args) {
    if (!empty($GLOBALS['staffNavigation'])) {
        static $navigationDB;
        if (!$navigationDB) {
            $navigationDB=new PDO('sqlite::memory:');
            $navigationDB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
            $navigationDB->exec("CREATE TABLE system_settings(setting_key TEXT,setting_value TEXT); INSERT INTO system_settings VALUES('enrollment_model_version','0')");
        }
        return $navigationDB;
    }
    $GLOBALS['reachedDB'] = true; exit;
}
PHP);
file_put_contents($fixture . '/includes/session_security.php', <<<'PHP'
<?php
function startSession(): void {}
function checkSession(bool $touch = true): ?string { return $GLOBALS['case'] === 'expired' ? 'timeout' : null; }
function clearLocalSession(): void { $_SESSION = []; }
PHP);
file_put_contents($fixture . '/includes/header.php', '<?php getDB();');
file_put_contents($fixture . '/includes/calendarific.php', '<?php');
file_put_contents($fixture . '/includes/security_audit.php', '<?php function registerSecurityRequestAudit(): void {} function securityAuditContext(int $id): void {} function securityAuditAlertCount(): int { return 0; }');
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1]; http_response_code(200);
$_SESSION = $case === 'guest' ? [] : ['role' => $case === 'expired' ? 'teacher' : $case, 'user_id' => 7];
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/dashboard.php'; $_SERVER['SCRIPT_NAME'] = '/reports/report.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(['status' => http_response_code(), 'db' => $GLOBALS['reachedDB'] ?? false]);
});
PHP);
$checks = 0;
try {
    foreach ($routes as $route) {
        foreach (['guest', 'expired', 'admin', 'teacher', 'scanner_operator', 'unknown'] as $role) {
            $eod = str_contains($route, 'end_of_day');
            $expected = $role === 'guest' || $role === 'scanner_operator' && !$eod ? 302
                : ($role === 'expired' ? 401 : ($role === 'unknown' || $eod && $role !== 'teacher' ? 403 : 200));
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $fixture . '/request.php', $fixture . '/' . $route, $role],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($fixture . '/' . $route));
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            if ($exit !== 0 || $errors !== '' || !preg_match('/RESULT:(.*)$/', $output, $match)) throw new RuntimeException("$route/$role failed: $errors $output");
            $result = json_decode($match[1], true);
            if ($result['status'] !== $expected || $result['db'] !== ($expected === 200)) throw new RuntimeException("$route/$role unexpected: " . $match[1]);
            $checks++;
        }
    }
    $GLOBALS['staffNavigation']=true;
    // Render the actual navigation and footer for every supported role.
    foreach (['admin', 'teacher', 'scanner_operator'] as $role) {
        $_SESSION = ['role' => $role, 'user_id' => 7];
        $_SERVER['PHP_SELF'] = '/dashboard.php';
        require_once $fixture . '/includes/functions.php';
        $currentUser = currentUser();
        ob_start(); include $fixture . '/includes/sidebar.php'; include $fixture . '/includes/footer.php'; $html = ob_get_clean();
        if (str_contains($html, 'attendance/end_of_day.php') !== ($role === 'teacher')
            || str_contains($html, 'id="endOfDayAlert"') !== ($role === 'teacher')) throw new RuntimeException("$role has incorrect review navigation");
        $checks++;
    }
    // Exercise the teacher-specific SF4 query before the rendering header runs.
    $source = file_get_contents($root . '/reports/sf4.php');
    $source = substr($source, 0, strpos($source, '$sectionData = []'));
    file_put_contents($fixture . '/sf4_scope.php', <<<'PHP'
<?php
define('BASE_URL', '/');
function requireStaff() {}
function currentUser() { return ['id' => 7, 'role' => 'teacher']; }
function isAdmin() { return false; }
function isTeacher() { return true; }
function getSetting($key) { return ''; }
function isHolidayOrNoClass($date) { return false; }
function gradeLevelOrderSQL($column) { return $column; }
class ScopeStatement {
    function execute($params) { if ($params !== [7]) throw new RuntimeException('Wrong adviser scope'); }
    function fetchAll() { return []; }
}
class ScopeDB { function prepare($sql) { return new ScopeStatement; } }
function getDB() { return new ScopeDB; }
function prepareEnrollmentReport($db,$from,$to) { return ['managed'=>false,'students'=>'students','attendance'=>'attendance','join'=>'','year'=>null]; }
function enrollmentReportDay($context,$date) { return true; }
PHP
        . preg_replace('/require_once .*?;/', '', substr($source, 5)));
    $process = proc_open([PHP_BINARY, $fixture . '/sf4_scope.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '') throw new RuntimeException("SF4 teacher scope failed: $errors $output");
    $checks++;
    echo "Staff workflow checks passed ($checks cases; no application DB or SMS access).\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($fixture);
}
