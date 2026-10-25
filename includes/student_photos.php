<?php
/** Local photo storage; the default is outside XAMPP's htdocs directory. */
function studentPhotoDirectory(): string {
    $configured = getenv('STUDENT_PHOTO_DIR');
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $parent = $documentRoot !== '' ? dirname(rtrim($documentRoot, '/\\')) : dirname(__DIR__, 3);
    $directory = $configured !== false && $configured !== '' ? rtrim($configured, '/\\') : $parent . '/private/attendance-system/student-photos';
    if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/|\\\\\\\\)~', $directory)) throw new RuntimeException('Photo storage must be an absolute private path.');
    if ($documentRoot !== '') {
        $normalize = static function (string $path): string {
            $parts = [];
            foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
                if ($part === '' || $part === '.') continue;
                if ($part === '..') array_pop($parts); else $parts[] = $part;
            }
            return strtolower(implode('/', $parts));
        };
        $root = $normalize($documentRoot); $target = $normalize($directory);
        if ($target === $root || str_starts_with($target, $root . '/')) throw new RuntimeException('Photo storage must be outside the document root.');
    }
    return $directory;
}

function studentPhotoPath(string $filename): ?string {
    if ($filename === '' || $filename !== basename($filename) || str_contains($filename, "\0") || str_contains($filename, '\\')) return null;
    // Legacy location is blocked by web-server rules until CLI migration runs.
    foreach ([studentPhotoDirectory(), dirname(__DIR__) . '/uploads/students'] as $directory) {
        $root = realpath($directory);
        $file = $root ? realpath($root . '/' . $filename) : false;
        if ($file && dirname($file) === $root && is_file($file)) return $file;
    }
    return null;
}

function studentAvatarSVG(string $first, string $last): string {
    $initials = mb_strtoupper(mb_substr($first, 0, 1, 'UTF-8') . mb_substr($last, 0, 1, 'UTF-8'), 'UTF-8');
    $initials = htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    return '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150" viewBox="0 0 150 150"><rect width="150" height="150" rx="75" fill="#1a56db"/><text x="75" y="78" dominant-baseline="middle" text-anchor="middle" font-family="Arial,sans-serif" font-size="48" fill="white">' . $initials . '</text></svg>';
}

function sanitizeStudentPhoto(string $bytes): array {
    if ($bytes === '' || strlen($bytes) > 2 * 1024 * 1024) throw new InvalidArgumentException('Photo must be under 2MB.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_GIF,IMAGETYPE_WEBP], true)
        || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 12000000) throw new InvalidArgumentException('Invalid image or excessive dimensions.');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Photo decoder is unavailable.');
    $image = @imagecreatefromstring($bytes);
    if (!$image) throw new InvalidArgumentException('Photo could not be decoded.');
    $jpeg = $info[2] === IMAGETYPE_JPEG;
    ob_start();
    try {
        if (!$jpeg) { imagealphablending($image, false); imagesavealpha($image, true); }
        $ok = $jpeg ? imagejpeg($image, null, 90) : imagepng($image, null, 6);
        $clean = ob_get_contents();
    } finally { ob_end_clean(); imagedestroy($image); }
    if (!$ok || !$clean || strlen($clean) > 2 * 1024 * 1024) throw new InvalidArgumentException('Photo could not be safely stored under 2MB.');
    return ['extension'=>$jpeg ? 'jpg' : 'png','bytes'=>$clean];
}
function storeStudentPhotoUpload(array $upload): string {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
        || !is_uploaded_file($upload['tmp_name']) || filesize($upload['tmp_name']) > 2 * 1024 * 1024) throw new InvalidArgumentException('Invalid photo upload.');
    $clean = sanitizeStudentPhoto(file_get_contents($upload['tmp_name']));
    $directory = studentPhotoDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Photo storage is unavailable.');
    $filename = bin2hex(random_bytes(24)) . '.' . $clean['extension'];
    if (file_put_contents($directory . '/' . $filename, $clean['bytes'], LOCK_EX) !== strlen($clean['bytes'])) throw new RuntimeException('Photo upload failed.');
    @chmod($directory . '/' . $filename, 0600);
    return $filename;
}
