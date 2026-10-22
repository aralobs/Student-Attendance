<?php
/**
 * Download the complete reusable student card as a self-contained SVG.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/qr_access.php';
requireQRManagement();
require_once '../includes/qr_card.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    http_response_code(404);
    exit('Student not found');
}
requireQRSectionAccess((int)($student['section_id'] ?? 0));

// Generate in memory: no PII-derived filenames or shared temporary files.
$card = generateStudentQRCardSVG($student, getSetting('school_name') ?? 'San Pablo City Central School');
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="student-attendance-card.svg"');
header('Cache-Control: no-store, private');
echo $card;
exit;
