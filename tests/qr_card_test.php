<?php
require_once dirname(__DIR__) . '/includes/qr_card.php';
$student = ['id' => 123, 'first_name' => 'Alex', 'middle_name' => 'Taylor', 'last_name' => 'Rivera',
    'lrn' => '123456789012', 'qr_token' => 'STU-123456789012-ABC123', 'photo' => 'not-found.png',
    'section_name' => 'SECTION-MUST-NOT-APPEAR', 'school_year' => '2099-2100'];
$svg = generateStudentQRCardSVG($student, 'San Pablo City Central School');
$document = new DOMDocument();
if (!$document->loadXML($svg, LIBXML_NONET)) throw new RuntimeException('Invalid card SVG.');
foreach (['San Pablo City Central School', 'Alex T. Rivera', $student['lrn'], $student['qr_token'], 'Student Attendance Card'] as $text) {
    if (!str_contains($document->textContent, $text)) throw new RuntimeException('Missing card content: ' . $text);
}
foreach (['Kindergarten', '2099-2100', 'SECTION-MUST-NOT-APPEAR'] as $text) {
    if (str_contains($svg, $text)) throw new RuntimeException('Card contains changing school information.');
}
if (str_contains($svg, 'http://ui-avatars') || str_contains($svg, 'https://ui-avatars')) {
    throw new RuntimeException('Card is not self-contained.');
}
$xpath = new DOMXPath($document);
$xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');
if ($xpath->query('/svg:svg/svg:g/svg:svg')->length !== 1) throw new RuntimeException('Missing vector QR artwork.');
// A photo must be embedded so PNG conversion and offline cards retain it.
$photoPath = tempnam(dirname(__DIR__) . '/uploads/students', 'qr-card-test-');
try {
    file_put_contents($photoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aEaoAAAAASUVORK5CYII='));
    $student['photo'] = basename($photoPath);
    $photoCard = generateStudentQRCardSVG($student, 'Test School');
    if (!str_contains($photoCard, 'href="data:image/png;base64,')) throw new RuntimeException('Photo was not embedded.');
} finally {
    unlink($photoPath);
}
$student['first_name'] = '<script>&"';
$escapedCard = new DOMDocument();
if (!$escapedCard->loadXML(generateStudentQRCardSVG($student, 'Test <School> & Academy'), LIBXML_NONET)
    || $escapedCard->getElementsByTagName('script')->length !== 0) {
    throw new RuntimeException('Names were not escaped as text.');
}
if (isset($argv[1])) file_put_contents($argv[1], $svg);
echo "Reusable full QR card checks passed.\n";
