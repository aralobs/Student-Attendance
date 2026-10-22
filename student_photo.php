<?php
require_once __DIR__ . '/includes/student_access.php';
require_once __DIR__ . '/includes/student_photos.php';
requireStudentManagement();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(404); exit('Photo not found.'); }
[$scope, $params] = studentRecordScope('s');
$stmt = getDB()->prepare("SELECT s.first_name, s.last_name, s.photo FROM students s WHERE s.id = ? AND s.is_active = 1 AND ($scope)");
$stmt->execute(array_merge([$id], $params));
$student = $stmt->fetch();
if (!$student) { http_response_code(404); exit('Photo not found.'); }
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
$path = studentPhotoPath((string)($student['photo'] ?? ''));
$info = $path && filesize($path) <= 2 * 1024 * 1024 ? @getimagesize($path) : false;
if ($info && in_array($info['mime'], ['image/jpeg','image/png','image/gif','image/webp'], true)) {
    header('Content-Type: ' . $info['mime']);
    readfile($path);
} else {
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo studentAvatarSVG((string)$student['first_name'], (string)$student['last_name']);
}
