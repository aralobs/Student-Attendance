<?php
// Isolated section-save authorization tests; no application DB changes.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.section-access-' . bin2hex(random_bytes(6));
foreach (['', '/config', '/includes', '/sections'] as $dir) mkdir($fixture . $dir);
file_put_contents($fixture . '/config/database.php', <<<'PHP'
<?php
$case = $argv[1]; $saved = [];
http_response_code(200);
register_shutdown_function(function () { echo "\nRESULT:" . json_encode(['status' => http_response_code(), 'saved' => $GLOBALS['saved']]); });
class FakeStatement extends PDOStatement {
    public function __construct() {}
    function execute(?array $params = null): bool { $GLOBALS['saved'][] = $params; return true; }
}
class FakeDB extends PDO {
    function __construct() { parent::__construct('sqlite::memory:'); }
    function prepare(string $sql, array $options = []): PDOStatement|false {
        // The real model guard reads a synthetic legacy-mode setting only.
        if (str_starts_with($sql,'SELECT setting_value')) return parent::prepare('SELECT 0 WHERE ? IS NOT NULL');
        return new FakeStatement;
    }
}
function getDB() { return new FakeDB; }
PHP);
file_put_contents($fixture . '/includes/functions.php', <<<'PHP'
<?php
require_once __DIR__ . '/../config/schedule_rules.php';
function requireLogin() { if ($GLOBALS['case'] === 'guest') { http_response_code(401); exit; } }
function isAdmin() { return str_starts_with($GLOBALS['case'], 'admin'); }
function isTeacher() { return str_starts_with($GLOBALS['case'], 'teacher'); }
function canAccessSection($id) { return $id === 10; }
function getSection($id) { return ['id' => 10, 'is_active' => $GLOBALS['case'] === 'teacher_archived' ? 0 : 1,
    'section_name' => 'Assigned', 'grade_level' => 'Grade 1', 'adviser_id' => 7, 'school_year' => '2026-2027']; }
function getGradeLevels() { return ['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6']; }
function currentUser() { return ['id' => 7]; }
function setFlash(...$args) {}
PHP);
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1]; $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['id' => $case === 'teacher_foreign' ? 20 : ($case === 'teacher_create' ? 0 : 10),
    'section_name' => 'Posted name', 'grade_level' => 'Grade 6', 'adviser_id' => 999, 'school_year' => '2099-2100',
    'schedule_type' => str_ends_with($case, '_am') ? 'am_only' : (str_ends_with($case, '_pm') ? 'pm_only' : 'full_day'),
    'am_in_start' => '07:00', 'am_in_end' => '07:45', 'am_late_threshold' => '07:30',
    'pm_in_start' => '13:00', 'pm_in_end' => '13:30', 'pm_late_threshold' => '13:01',
    'am_out_start' => '11:00', 'am_out_end' => '11:45', 'pm_out_start' => '15:00', 'pm_out_end' => '15:45'];
if ($case === 'admin_invalid') $_POST['schedule_type'] = 'invalid';
PHP);
copy($root . '/config/schedule_rules.php', $fixture . '/config/schedule_rules.php');
copy($root . '/sections/save.php', $fixture . '/sections/save.php');
copy($root . '/includes/school_years.php', $fixture . '/includes/school_years.php');
file_put_contents($fixture . '/includes/security_audit.php', "<?php\n");
file_put_contents($fixture . '/includes/functions.php', "\nfunction startSession(): void {}\n", FILE_APPEND);
file_put_contents($fixture . '/includes/functions.php', 'require_once ' . var_export($root . '/includes/csrf.php', true) . ';' . "\n" . '$_SESSION["app_csrf"] = str_repeat("a", 64); $_POST["csrf_token"] = $_SESSION["app_csrf"];' . "\n", FILE_APPEND);

try {
    foreach (['guest' => 401, 'scanner' => 403, 'teacher_foreign' => 403, 'teacher_create' => 403,
        'teacher_archived' => 403, 'teacher_am' => 302, 'teacher_full' => 302, 'teacher_pm' => 302, 'admin_pm' => 302, 'admin_am' => 302,
        'admin_full' => 302, 'admin_invalid' => 302] as $case => $status) {
        $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $fixture . '/request.php',
            $fixture . '/sections/save.php', $case], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture . '/sections');
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        if ($exit !== 0 || $errors !== '' || !preg_match('/RESULT:(.*)$/', $output, $match)) throw new RuntimeException("$case failed: $errors $output");
        $result = json_decode($match[1], true);
        $expectedWrites = $status === 302 && $case !== 'admin_invalid' ? 1 : 0;
        if ($result['status'] !== $status || count($result['saved']) !== $expectedWrites) throw new RuntimeException("$case unexpected result: " . $match[1]);
        if ($expectedWrites) {
            $saved = $result['saved'][0];
            $type = str_ends_with($case, '_am') ? 'am_only' : (str_ends_with($case, '_pm') ? 'pm_only' : 'full_day');
            if ($saved[2] !== $type || $saved[5] !== '07:00:00' || $saved[13] !== '15:00:00') throw new RuntimeException("$case lost chosen schedule or custom times");
            if (str_starts_with($case, 'teacher') && array_slice($saved, 0, 5) !== ['Assigned', 'Grade 1', $type, 7, '2026-2027']) throw new RuntimeException("$case changed protected section metadata");
        }
    }
    echo "Section schedule access checks passed (12 cases).\n";
} finally {
    foreach (['config/database.php', 'config/schedule_rules.php', 'includes/functions.php', 'includes/school_years.php', 'includes/security_audit.php', 'request.php', 'sections/save.php'] as $file) unlink($fixture . '/' . $file);
    foreach (['/sections', '/includes', '/config', ''] as $dir) rmdir($fixture . $dir);
}
