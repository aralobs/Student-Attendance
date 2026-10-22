<?php
require_once __DIR__ . '/qr_helper.php';
require_once __DIR__ . '/student_photos.php';

/** A self-contained card shared by preview, printing, and downloads. */
function generateStudentQRCardSVG(array $student, string $schoolName): string {
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $first = trim($student['first_name'] ?? '');
    $last = trim($student['last_name'] ?? '');
    $middle = trim($student['middle_name'] ?? '');
    $initial = static fn(string $text): string => function_exists('mb_substr') ? mb_substr($text, 0, 1, 'UTF-8') : substr($text, 0, 1);
    $name = trim($first . ' ' . ($middle !== '' ? $initial($middle) . '. ' : '') . $last);
    $nameSize = max(10, min(17, 580 / max(1, strlen($name))));
    // Fit unusually long names within the card instead of clipping them.
    $nameFit = strlen($name) > 55 ? ' textLength="345" lengthAdjust="spacingAndGlyphs"' : '';
    $schoolSize = max(10, min(17, 600 / max(1, strlen($schoolName))));
    $initials = $escape(strtoupper($initial($first) . $initial($last)));
    $clipId = 'photo-' . substr(hash('sha256', $student['qr_token']), 0, 16);
    $photoMarkup = '<text x="200" y="156" text-anchor="middle" font-size="34" fill="white">' . $initials . '</text>';

    // Embed only a validated local image so downloaded cards work offline.
    $photo = (string)($student['photo'] ?? '');
    $photoPath = studentPhotoPath($photo);
    if ($photoPath && filesize($photoPath) <= 2 * 1024 * 1024) {
        $info = @getimagesize($photoPath);
        if ($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            $bytes = file_get_contents($photoPath);
            if ($bytes !== false) {
                $photoMarkup = '<image x="157" y="102" width="86" height="86" preserveAspectRatio="xMidYMid slice" clip-path="url(#' . $clipId . ')" href="data:'
                    . $info['mime'] . ';base64,' . base64_encode($bytes) . '"/>';
            }
        }
    }

    // Include the QR as nested vector artwork, retaining the exact existing token.
    $document = new DOMDocument();
    $document->loadXML(generateQRCodeSVG($student['qr_token'], 240), LIBXML_NONET);
    $qr = $document->documentElement;
    $qr->setAttribute('x', '80');
    $qr->setAttribute('y', '282');
    $qrMarkup = $document->saveXML($qr);

    $school = $escape($schoolName);
    $displayName = $escape($name);
    $lrn = $escape((string)($student['lrn'] ?? ''));
    $token = $escape($student['qr_token']);
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="600" viewBox="0 0 400 600" role="img" aria-label="Student attendance card">
  <defs><clipPath id="$clipId"><circle cx="200" cy="145" r="43"/></clipPath></defs>
  <rect width="400" height="600" rx="12" fill="white"/>
  <g font-family="Arial, sans-serif">
    <text x="200" y="42" text-anchor="middle" font-size="$schoolSize" font-weight="700" fill="#1670ee">$school</text>
    <text x="200" y="63" text-anchor="middle" font-size="12" fill="#64748b">Student Attendance Card</text>
    <path d="M25 82H375 M25 266H375 M25 551H375" stroke="#d1d5db"/>
    <circle cx="200" cy="145" r="44" fill="#1d4ed8" stroke="#3b82f6" stroke-width="3"/>
    $photoMarkup
    <text x="200" y="221" text-anchor="middle" font-size="$nameSize" font-weight="700" fill="#111827"$nameFit>$displayName</text>
    <text x="200" y="245" text-anchor="middle" font-size="12" fill="#475569">LRN: $lrn</text>
    $qrMarkup
    <text x="200" y="537" text-anchor="middle" font-size="9" fill="#64748b">$token</text>
    <text x="200" y="573" text-anchor="middle" font-size="10" fill="#64748b">Present this QR code for daily attendance scanning.</text>
    <text x="200" y="589" text-anchor="middle" font-size="10" fill="#64748b">Keep this card safe. Do not share the code.</text>
  </g>
</svg>
SVG;
}
