<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/student_photos.php';
$source = realpath(__DIR__ . '/../uploads/students');
if (!$source) { echo "No legacy photo directory to migrate.\n"; exit; }
$destination = studentPhotoDirectory();
if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) throw new RuntimeException('Photo storage is unavailable.');
$count = 0;
foreach (glob($source . '/*') ?: [] as $file) {
    if (!is_file($file) || is_link($file) || !in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg','jpeg','png','gif','webp'], true)) continue;
    $target = $destination . '/' . basename($file);
    if (is_file($target)) {
        if (hash_file('sha256', $target) !== hash_file('sha256', $file)) throw new RuntimeException('Photo filename conflict; no file overwritten.');
    } elseif (!copy($file, $target)) throw new RuntimeException('Photo migration failed.');
    @chmod($target, 0600);
    if (!unlink($file)) throw new RuntimeException('Could not remove public photo copy.');
    $count++;
}
echo "Moved $count photos into private storage. Database filenames unchanged.\n";
