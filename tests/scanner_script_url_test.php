<?php
// Evaluate the scanner's footer expression, not an authenticated school request.
define('BASE_URL', 'https://school.example.test/');
function csrfToken(): string { return 'synthetic'; }
function currentUser(): array { return ['id' => 1]; }
$today = '2026-10-11'; $_SESSION = [];
$source = file_get_contents(__DIR__ . '/../attendance/scanner.php');
$start = strpos($source, '$extraJS =');
$end = strpos($source, "include '../includes/footer.php';", $start);
if ($start === false || $end === false) throw new RuntimeException('Scanner footer not found');
eval(substr($source, $start, $end - $start));
preg_match_all('/<script[^>]+src="([^"]+)"/', $extraJS, $matches);
$expected = BASE_URL . 'assets/vendor/html5-qrcode-2.3.8.min.js';
if (!in_array($expected, $matches[1], true) || str_contains($extraJS, '<?=')) {
    throw new RuntimeException('Scanner renders an invalid camera library URL');
}
echo "Scanner renders a valid same-origin camera library URL.\n";
