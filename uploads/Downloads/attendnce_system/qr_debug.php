<?php
require_once './vendor/autoload.php';
require_once './includes/qr_helper.php';

$token   = 'STU-TEST-123456';
$tmpPath = sys_get_temp_dir() . '/qr_test.png';

echo '<h3>Temp Path: ' . $tmpPath . '</h3>';

$result = generateQRCode($token, $tmpPath, 300);

echo '<h3>Generation Result: ' . ($result ? '✅ true' : '❌ false') . '</h3>';

if (file_exists($tmpPath)) {
    $size = filesize($tmpPath);
    echo '<h3>File Size: ' . $size . ' bytes</h3>';

    // Check PNG header bytes
    $bytes = file_get_contents($tmpPath, false, null, 0, 8);
    $hex   = bin2hex($bytes);
    echo '<h3>PNG Header (hex): ' . $hex . '</h3>';
    echo '<h3>Valid PNG: ' . (substr($hex, 0, 16) === '89504e470d0a1a0a' ? '✅ YES' : '❌ NO') . '</h3>';

    // Try displaying it
    $base64 = base64_encode(file_get_contents($tmpPath));
    echo '<h3>Display Test:</h3>';
    echo '<img src="data:image/png;base64,' . $base64 . '" style="border:2px solid red">';

    unlink($tmpPath);
} else {
    echo '<h3>❌ File was NOT created</h3>';
}

// Also test SVG
echo '<h3>SVG Test:</h3>';
$svg = generateQRCodeSVG($token, 200);
echo $svg;
?>