<?php
// Real QR endpoints and authorization helper, isolated from live data/providers.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.qr-access-' . bin2hex(random_bytes(6));
foreach (['', '/config', '/includes', '/students'] as $dir) mkdir($fixture . $dir);
// Explicit legacy adapter mode; annual authorization uses realSQL elsewhere.
file_put_contents($fixture.'/includes/enrollment_operations.php','<?php function enrollmentModelEnabled($db){return false;}');
file_put_contents($fixture . '/config/database.php', <<<'PHP'
<?php
$case = $argv[1]; $queries = 0; $generated = 0;
http_response_code(200);
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(['status' => http_response_code(),
        'queries' => $GLOBALS['queries'], 'generated' => $GLOBALS['generated']]);
});
class FakeStatement {
    function execute($params = []) { $GLOBALS['queries']++; }
    function fetch() {
        if ($GLOBALS['case'] === 'missing') return false;
        return ['id' => 1, 'section_id' => $GLOBALS['case'] === 'unassigned' ? null :
            ($GLOBALS['case'] === 'foreign' ? 20 : 10), 'qr_token' => 'TEST-TOKEN'];
    }
    function fetchAll() { return []; }
}
class FakeDB { function prepare($sql) { return new FakeStatement; } }
function getDB() { return new FakeDB; }
PHP);
file_put_contents($fixture . '/includes/functions.php', <<<'PHP'
<?php
require_once __DIR__ . '/../config/database.php';
function requireLogin() { if ($GLOBALS['case'] === 'guest') { http_response_code(401); exit; } }
function sendNoCacheHeaders() {}
function isAdmin() { return $GLOBALS['case'] === 'admin'; }
function isTeacher() { return !in_array($GLOBALS['case'], ['guest', 'admin', 'scanner', 'unknown']); }
function canAccessSection($id) { return $id === 10; }
function getAllowedSections() { return [['id' => 10]]; }
function setFlash(...$args) {}
function getSetting($key) { return 'Test School'; }
PHP);
file_put_contents($fixture . '/includes/qr_card.php', <<<'PHP'
<?php
require_once __DIR__ . '/qr_helper.php';
function generateStudentQRCardSVG($student, $schoolName) {
    return generateQRCodeSVG($student['qr_token'], 240);
}
PHP);
file_put_contents($fixture . '/includes/qr_helper.php', <<<'PHP'
<?php
function serveQRCode($token, $size, $download = false) {
    if ($token !== 'TEST-TOKEN') throw new RuntimeException('Wrong token served.');
    if ($GLOBALS['download'] !== $download) throw new RuntimeException('Wrong download mode.');
    $GLOBALS['generated']++; exit;
}
function generateQRCodeSVG($token, $size) {
    if ($token !== 'TEST-TOKEN') throw new RuntimeException('Wrong token generated.');
    $GLOBALS['generated']++; exit;
}
PHP);
// Authorized bulk requests reach the template only after the roster query.
file_put_contents($fixture . '/includes/header.php', '<?php exit;');
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['id' => 1, 'section' => $case === 'foreign' ? 20 : 10];
$download = $argv[2] === 'students/qr_download.php';
if ($case === 'token_url') $_GET = ['token' => 'TEST-TOKEN'];
PHP);
copy($root . '/includes/qr_access.php', $fixture . '/includes/qr_access.php');
$endpoints = ['students/qr_download.php', 'qr_image.php', 'students/generate_qr.php', 'students/bulk_qr.php'];
$checks = 0;
try {
    foreach ($endpoints as $endpoint) {
        copy($root . '/' . $endpoint, $fixture . '/' . $endpoint);
        $cases = ['guest' => 401, 'scanner' => 403, 'unknown' => 403,
            'foreign' => 403, 'teacher' => 200, 'admin' => 200];
        if ($endpoint !== 'students/bulk_qr.php') $cases['unassigned'] = 403;
        if (in_array($endpoint, ['students/qr_download.php', 'qr_image.php'])) $cases['missing'] = 404;
        if ($endpoint === 'qr_image.php') $cases['token_url'] = 400;
        foreach ($cases as $case => $expected) {
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $fixture . '/request.php',
                $fixture . '/' . $endpoint, $case, $endpoint],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($fixture . '/' . $endpoint));
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            if ($exit !== 0 || $errors !== '' || !preg_match('/RESULT:(.*)$/', $output, $match)) {
                throw new RuntimeException("$endpoint/$case failed: $errors $output");
            }
            $result = json_decode($match[1], true);
            $expectedGeneration = $expected === 200 && $endpoint !== 'students/bulk_qr.php' ? 1 : 0;
            if ($result['status'] !== $expected || $result['generated'] !== $expectedGeneration
                || (in_array($case, ['guest', 'scanner', 'unknown', 'token_url']) && $result['queries'] !== 0)
                || ($endpoint === 'students/bulk_qr.php' && $result['queries'] !== ($expected === 200 ? 1 : 0))) {
                throw new RuntimeException("$endpoint/$case unexpected result: " . $match[1]);
            }
            $checks++;
        }
    }
    // Verify the actual in-memory download renderer, using a synthetic token.
    $renderCode = 'require ' . var_export($root . '/includes/qr_helper.php', true)
        . '; serveQRCode("TEST-TOKEN", 100, true);';
    $process = proc_open([PHP_BINARY, '-r', $renderCode],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $image = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '' ||
        (!str_starts_with($image, "\x89PNG\r\n\x1a\n") && !str_contains($image, '<svg'))) {
        throw new RuntimeException('QR download rendering failed: ' . $errors);
    }
    echo "QR access checks passed ($checks cases); in-memory QR rendering passed.\n";
} finally {
    foreach (array_merge($endpoints, ['config/database.php', 'includes/functions.php',
        'includes/qr_access.php', 'includes/qr_helper.php', 'includes/qr_card.php', 'includes/header.php', 'includes/enrollment_operations.php','request.php']) as $file) {
        if (is_file($fixture . '/' . $file)) unlink($fixture . '/' . $file);
    }
    foreach (['/students', '/includes', '/config', ''] as $dir) rmdir($fixture . $dir);
}
