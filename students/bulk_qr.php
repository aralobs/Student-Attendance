<?php
/**
 * Bulk QR Code Print — entire section at once
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/qr_access.php';
requireQRManagement();
require_once '../includes/qr_card.php';

$sectionId = (int)($_GET['section'] ?? 0);
$db        = getDB();

$sections = getAllowedSections();

$students  = [];
if ($sectionId > 0) {
    requireQRSectionAccess($sectionId);
    if (enrollmentModelEnabled($db)) {
        $students=enrollmentOperationalRows($db,date('Y-m-d'),$sectionId,currentUser());
    } else {
    $stmt = $db->prepare("
        SELECT s.*, sec.section_name
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE s.section_id = ? AND s.is_active = 1
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$sectionId]);
    $students = $stmt->fetchAll();
    }
}

$schoolName = getSetting('school_name') ?? 'San Pablo City Central School';
$pageTitle = 'Bulk QR Codes';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i class="bi bi-grid-3x3 me-2 text-primary"></i>Bulk QR Print
        </h1>
        <p class="page-subtitle">Print all QR codes for a section at once</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($students)): ?>
        <button onclick="window.print()" class="btn btn-success">
            <i class="bi bi-printer me-1"></i>Print All (<?= count($students) ?>)
        </button>
        <?php endif; ?>
        <a href="students.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Section selector -->
<div class="card mb-4 no-print">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 fw-600">Section:</label>
            <select name="section" class="form-select form-select-sm" style="width:auto">
                <option value="">Select section...</option>
                <?php foreach ($sections as $sec): ?>
                <option value="<?= $sec['id'] ?>" <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                    <?= sanitize($sec['section_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Load</button>
        </form>
    </div>
</div>

<?php if (empty($students) && $sectionId > 0): ?>
<div class="alert alert-warning">No students found in this section.</div>
<?php endif; ?>

<!-- QR Grid -->
<?php if (!empty($students)): ?>
<div class="row g-3" id="qrGrid">
    <?php foreach ($students as $student):
        $cardSVG = generateStudentQRCardSVG($student, $schoolName);
    ?>
    <div class="col-6 col-md-4 col-lg-3">
        <div class="qr-card-item" style="border:1px solid #d1d5db;border-radius:12px;overflow:hidden">
            <?= $cardSVG ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.qr-card-item > svg { display:block; width:100%; height:auto; }
@media print {
    .no-print, nav, .top-navbar, .sidebar { display: none !important; }
    .main-content { margin: 0 !important; }
    .content-area { padding: 0 !important; }
    #qrGrid { display: grid !important; grid-template-columns: repeat(4, 1fr); gap: 8px; }
    .col-6, .col-md-4, .col-lg-3 { width: 100% !important; }
    .qr-card-item { break-inside: avoid; border: 1.5px solid #000 !important; }
    body { font-size: 10px; }
}
</style>

<?php include '../includes/footer.php'; ?>
