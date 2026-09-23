<?php
/**
 * Student QR Code Card — View & Print
 * Uses inline SVG QR (no file storage needed)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/qr_helper.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, sec.section_name
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found.');
    header('Location: index.php');
    exit;
}

// Generate QR SVG inline (no file needed)
$qrSVG = generateQRCodeSVG($student['qr_token'], 220);

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
        <a href="qr_download.php?id=<?= $student['id'] ?>" class="btn btn-outline-primary">
            <i class="bi bi-download me-1"></i>Download PNG
        </a>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Card preview -->
<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">

        <!-- ID Card -->
        <div id="qrCard" class="card text-center shadow">
            <div class="card-body p-4">

                <!-- School header -->
                <div class="mb-2">
                    <div class="fw-800 text-primary" style="font-size:0.95rem; line-height:1.3">
                        San Pablo City Central School
                    </div>
                    <div class="text-muted" style="font-size:0.75rem">
                        Kindergarten Department &bull; S.Y. <?= getSetting('school_year') ?>
                    </div>
                </div>

                <hr class="my-2">

                <!-- Photo -->
                <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($student['photo']) ?>"
                     class="rounded-circle mb-2"
                     style="width:85px; height:85px; object-fit:cover; border:3px solid #1a56db"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student['first_name'].' '.$student['last_name']) ?>&size=85&background=1a56db&color=fff'"
                     alt="Student Photo">

                <!-- Name -->
                <h5 class="fw-800 mb-0" style="font-size:1rem">
                    <?= sanitize($student['first_name'] . ' ' . ($student['middle_name'] ? substr($student['middle_name'],0,1).'. ' : '') . $student['last_name']) ?>
                </h5>
                <div class="text-muted small mb-1">
                    <?= sanitize($student['section_name'] ?? 'No Section') ?>
                </div>
                <div class="small mb-2">
                    <span class="fw-600">LRN:</span>
                    <code><?= sanitize($student['lrn']) ?></code>
                </div>

                <hr class="my-2">

                <!-- QR Code (inline SVG) -->
                <div class="d-flex justify-content-center mb-2">
                    <?= $qrSVG ?>
                </div>

                <div class="text-muted" style="font-size:0.65rem; word-break:break-all">
                    <?= sanitize($student['qr_token']) ?>
                </div>

                <hr class="my-2">

                <div class="text-muted" style="font-size:0.68rem">
                    Present this QR code for daily attendance scanning.<br>
                    Keep this card safe. Do not share the code.
                </div>

            </div>
        </div>

        <!-- Bulk print hint -->
        <div class="alert alert-info py-2 mt-3 small no-print">
            <i class="bi bi-lightbulb me-1"></i>
            Need to print all QR codes at once?
            <a href="bulk_qr.php?section=<?= $student['section_id'] ?>">Print entire section</a>
        </div>

    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
function printCard() {
    const card    = document.getElementById('qrCard').outerHTML;
    const win     = window.open('', '_blank', 'width=400,height=600');
    const school  = document.querySelector('.fw-800.text-primary')?.textContent ?? '';

    win.document.write(`
        <!DOCTYPE html><html><head>
        <title>QR Card</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { display:flex; justify-content:center; padding:20px; font-family:'Segoe UI',sans-serif; }
            .card { max-width:320px; border:2px solid #1a56db; border-radius:12px; }
            @media print { body { padding:0; } }
        </style>
        </head><body>
        ${card}
        <script>
            window.onload = () => { window.print(); window.close(); }
        <\/script>
        </body></html>
    `);
    win.document.close();
}
</script>
JS;
include '../includes/footer.php';
?>