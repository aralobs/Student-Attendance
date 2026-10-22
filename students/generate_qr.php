<?php
/**
 * Student QR Code Card — View & Print
 * Uses inline SVG QR (no file storage needed)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/qr_access.php';
requireQRManagement();
require_once '../includes/qr_card.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, sec.section_name
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.id = ? AND s.is_active = 1
");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found.');
    header('Location: students.php');
    exit;
}

// Generate QR SVG inline (no file needed)
requireQRSectionAccess((int)($student['section_id'] ?? 0));
$cardSVG = generateStudentQRCardSVG($student, getSetting('school_name') ?? 'San Pablo City Central School');

$pageTitle = 'QR Code — ' . $student['first_name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i class="bi bi-qr-code me-2 text-primary"></i>Student QR Code
        </h1>
        <p class="page-subtitle">Print and give to student/parent</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="printCard()" class="btn btn-success">
            <i class="bi bi-printer me-1"></i>Print Card
        </button>
        <button type="button" id="downloadCardBtn" onclick="downloadCardPNG()" class="btn btn-outline-primary">
            <i class="bi bi-download me-1"></i>Download Card PNG
        </button>
        <a href="qr_download.php?id=<?= $student['id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-download me-1"></i>Download Card SVG
        </a>
        <a href="students.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Card preview -->
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">

        <!-- ID Card -->
        <div id="qrCard" class="shadow rounded" style="max-width:400px;margin:auto;overflow:hidden">
            <?= $cardSVG ?>
        </div>
        <style>#qrCard > svg { display:block; width:100%; height:auto; }</style>
        <div id="cardDownloadStatus" class="small text-danger mt-2" role="status" aria-live="polite"></div>

        <!-- Bulk print hint -->
        <div class="alert alert-info py-2 mt-3 small no-print">
            <i class="bi bi-lightbulb me-1"></i>
            Need to print all QR codes at once?
            <a href="bulk_qr.php?section=<?= $student['section_id'] ?>">Print entire section</a>
        </div>

    </div>
</div>

<?php
$extraJS = '<script src="' . BASE_URL . 'assets/js/qr_card.js"></script>';
include '../includes/footer.php';
?>
