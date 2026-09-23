<?php
/**
 * Bulk QR Code Print — entire section at once
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/qr_helper.php';

$sectionId = (int)($_GET['section'] ?? 0);
$db        = getDB();

$sections  = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();

$students  = [];
if ($sectionId > 0) {
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
        <a href="index.php" class="btn btn-outline-secondary">
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
        $qrSVG = generateQRCodeSVG($student['qr_token'], 160);
    ?>
    <div class="col-6 col-md-4 col-lg-3">
        <div class="card text-center p-2 qr-card-item" style="border:1.5px solid #1a56db; border-radius:10px; font-size:0.75rem">

            <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($student['photo']) ?>"
                 class="rounded-circle mx-auto mt-2 mb-1"
                 style="width:50px;height:50px;object-fit:cover;border:2px solid #1a56db"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student['first_name'].' '.$student['last_name']) ?>&size=50&background=1a56db&color=fff'">

            <div class="fw-700" style="font-size:0.8rem; line-height:1.2">
                <?= sanitize($student['last_name'] . ', ' . $student['first_name']) ?>
            </div>
            <div class="text-muted" style="font-size:0.68rem">
                <?= sanitize($student['section_name'] ?? '') ?>
            </div>

            <div class="d-flex justify-content-center my-1">
                <?= $qrSVG ?>
            </div>

            <div class="text-muted" style="font-size:0.6rem; word-break:break-all; padding:0 4px">
                <?= sanitize($student['lrn']) ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
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