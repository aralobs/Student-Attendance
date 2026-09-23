<?php
echo '<h3>Imagick: ' . (extension_loaded('imagick') ? '✅ YES' : '❌ NO') . '</h3>';
echo '<h3>GD: '      . (extension_loaded('gd')      ? '✅ YES' : '❌ NO') . '</h3>';

// Test BaconQrCode SVG (Tester only, no other connection, stand alone file)
require_once './vendor/autoload.php';
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd());
$writer   = new Writer($renderer);
$svg      = $writer->writeString('TEST-QR-123');
echo '<h3>SVG Generation: ✅ Works</h3>';
echo '<div>' . $svg . '</div>';
?>