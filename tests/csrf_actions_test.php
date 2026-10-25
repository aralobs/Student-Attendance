<?php
// Exercise real endpoints in child processes with synthetic authenticated
// sessions. The DB stub stops immediately; no application DB is accessed.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.csrf-actions-' . bin2hex(random_bytes(6));
foreach (['', '/config', '/includes', '/users', '/students', '/sections', '/attendance'] as $dir) mkdir($fixture . $dir);
$pages = ['attendance/manual.php', 'students/add.php', 'students/edit.php', 'users/add.php', 'users/edit.php', 'students/master_list.php', 'students/section_advisers.php'];
$actions = ['attendance/override.php', 'attendance/scan_process.php', 'sections/save.php', 'students/bulk_assign_section.php', 'users/toggle.php'];
foreach (['users', 'students', 'sections'] as $directory) {
    foreach (['archive', 'restore', 'delete'] as $name) $actions[] = "$directory/$name.php";
}
file_put_contents($fixture . '/config/database.php', '<?php');
foreach (['mail_helper.php', 'attendance_scan.php', 'student_photos.php'] as $name) file_put_contents($fixture . '/includes/' . $name, '<?php');
copy($root . '/includes/student_access.php', $fixture . '/includes/student_access.php');
copy($root . '/includes/csrf.php', $fixture . '/includes/csrf.php');
copy($root . '/includes/abuse_security.php', $fixture . '/includes/abuse_security.php');
file_put_contents($fixture . '/includes/functions.php', <<<'PHP'
<?php
require_once __DIR__ . '/csrf.php';
function startSession(): void {}
function requireLogin(): void {}
function requireAdmin(): void {}
function isAdmin(): bool { return true; }
function isTeacher(): bool { return false; }
function currentUser(): array { return ['id' => 1, 'role' => 'admin']; }
require_once __DIR__ . '/abuse_security.php';
function getDB() { $GLOBALS['reachedDB'] = true; exit; }
function setFlash(...$args): void {}
function getScheduleForGrade(...$args): array { return []; }
function getGradeLevels(): array { return ['Grade 1']; }
PHP);
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1];
http_response_code(200);
$_SERVER['REQUEST_METHOD'] = in_array($case, ['GET', 'PUT'], true) ? $case : 'POST';
$_SESSION = ['app_csrf' => str_repeat('a', 64)];
$_POST = ['id' => 1, 'attendance_id' => 1, 'token' => 'SYNTHETIC_TOKEN', 'action' => 'update_adviser', 'section_name' => 'A', 'grade_level' => 'Grade 1'];
$_GET = ['id' => 1];
if ($case === 'valid') $_POST['csrf_token'] = str_repeat('a', 64);
if ($case === 'wrong' || $case === 'other_session') $_POST['csrf_token'] = str_repeat('b', 64);
if ($case === 'array') $_POST['csrf_token'] = ['bad'];
if ($case === 'empty') $_POST['csrf_token'] = '';
if ($case === 'GET') $_POST['csrf_token'] = str_repeat('a', 64);
if ($case === 'query_only') $_GET['csrf_token'] = str_repeat('a', 64);
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(['status' => http_response_code(), 'reachedDB' => $GLOBALS['reachedDB'] ?? false]);
});
PHP);
$checks = 0;
try {
    foreach (array_merge($pages, $actions) as $endpoint) {
        copy($root . '/' . $endpoint, $fixture . '/' . $endpoint);
        foreach (['GET', 'PUT', 'missing', 'wrong', 'array', 'empty', 'query_only', 'other_session', 'valid'] as $case) {
            $expected = $case === 'valid' || ($case === 'GET' && in_array($endpoint, $pages, true)) ? 200 : (in_array($case, ['GET','PUT'], true) ? 405 : 403);
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $fixture . '/request.php', $fixture . '/' . $endpoint, $case], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, dirname($fixture . '/' . $endpoint));
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            if ($exit !== 0 || $errors !== '' || !preg_match('/RESULT:(.*)$/', $output, $match)) throw new RuntimeException("$endpoint/$case: $errors $output");
            $result = json_decode($match[1], true);
            if ($result['status'] !== $expected || $result['reachedDB'] !== ($expected === 200)) throw new RuntimeException("$endpoint/$case unexpected: " . $match[1]);
            $checks++;
        }
    }
    echo "CSRF endpoint checks passed ($checks cases; no application DB access).\n";
} finally {
    foreach (array_merge($pages, $actions, ['config/database.php', 'includes/functions.php', 'includes/student_access.php', 'includes/csrf.php', 'includes/abuse_security.php', 'includes/mail_helper.php', 'includes/attendance_scan.php', 'includes/student_photos.php', 'request.php']) as $file) if (is_file($fixture . '/' . $file)) unlink($fixture . '/' . $file);
    foreach (['/config', '/includes', '/users', '/students', '/sections', '/attendance', ''] as $dir) rmdir($fixture . $dir);
}
