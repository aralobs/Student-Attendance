<?php
// Render the actual shared header with isolated authentication stubs.
function requireLogin(): void {}
function currentUser(): array { return ['id' => 1]; }
function startSession(): void {}
require __DIR__ . '/../includes/csrf.php';
define('BASE_URL', '/');
$pageTitle = '</title><img src=x onerror="alert(1)"><script>alert(1)</script> & "Student"';
ob_start();
require __DIR__ . '/../includes/header.php';
$html = ob_get_clean();
$dom = new DOMDocument();
@$dom->loadHTML($html);
$title = $dom->getElementsByTagName('title')->item(0);
if (!$title || $title->textContent !== $pageTitle . ' — SPCCS Kinder'
    || $dom->getElementsByTagName('img')->length !== 0
    || $dom->getElementsByTagName('script')->length !== 2
    || $dom->getElementsByTagName('script')->item(0)->getAttribute('src') !== '/assets/js/privacy_storage.js') {
    fwrite(STDERR, "FAIL: title markup escaped incorrectly\n");
    exit(1);
}
echo "PASS: malicious title remains literal text; no injected elements\n";
