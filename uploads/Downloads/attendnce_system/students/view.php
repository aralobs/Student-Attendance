<?php
/**
 * View Student Profile
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

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
    header('Location: index.php');
    exit;
}

// Attendance summary
$summary = $db->prepare("
    SELECT
        COUNT(*) AS total_days,
        SUM(status = 'present') AS present,
        SUM(status = 'late')    AS late,
        SUM(status = 'absent')  AS absent,
        SUM(status = 'excused') AS excused
    FROM attendance
    WHERE student_id = ?
");
$summary->execute([$id]);
$summary = $summary->fetch();

// Recent attendance (last 10)
$recent = $db->prepare("
    SELECT * FROM attendance
    WHERE student_id = ?
    ORDER BY date DESC
    LIMIT 10
");
$recent->execute([$id]);
$recent = $recent->fetchAll();

// Attendance rate
$rate = $summary['total_days'] > 0
    ? round((($summary['present'] + $summary['late']) / $summary['total_days']) * 100, 1)
    : 0;

$pageTitle = 'View Student — ' . $student['first_name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-person-circle me-2 text-primary"></i>Student Profile
        </h1>
        <p class="page-subtitle">Viewing student details and attendance history</p>
    </div>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= $student['id'] ?>" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <a href="generate_qr.php?id=<?= $student['id'] ?>" class="btn btn-outline-success">
            <i class="bi bi-qr-code me-1"></i>QR Code
        </a>
        <a href="index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="row g-4">

    <!-- Left: Profile Card -->
    <div class="col-lg-4">
        <div class="card text-center">
            <div class="card-body p-4">
                <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($student['photo']) ?>"
                     class="rounded-circle mb-3"
                     style="width:110px;height:110px;object-fit:cover;border:4px solid #1a56db"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student['first_name'].' '.$student['last_name']) ?>&size=110&background=1a56db&color=fff'">

                <h5 class="fw-800 mb-0">
                    <?= sanitize($student['first_name'] . ' ' .
                        ($student['middle_name'] ? substr($student['middle_name'],0,1).'. ' : '') .
                        $student['last_name']) ?>
                </h5>
                <div class="text-muted small mb-2">
                    <?= sanitize($student['section_name'] ?? 'No Section') ?>
                </div>

                <span class="badge bg-<?= $student['gender'] === 'Male' ? 'primary' : 'danger' ?> mb-3">
                    <i class="bi bi-gender-<?= strtolower($student['gender']) ?> me-1"></i>
                    <?= $student['gender'] ?>
                </span>

                <hr>

                <div class="text-start">
                    <div class="mb-2">
                        <small class="text-muted d-block">LRN</small>
                        <code><?= sanitize($student['lrn']) ?></code>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted d-block">Birth Date</small>
                        <span><?= $student['birth_date'] ? date('F j, Y', strtotime($student['birth_date'])) : '—' ?></span>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted d-block">Address</small>
                        <span><?= sanitize($student['address'] ?? '—') ?></span>
                    </div>
                </div>

                <hr>

                <div class="text-start">
                    <small class="text-muted fw-600 d-block mb-2">Parent/Guardian</small>
                    <div class="mb-1">
                        <i class="bi bi-person me-1 text-muted"></i>
                        <?= sanitize($student['parent_name'] ?? '—') ?>
                    </div>
                    <div class="mb-1">
                        <i class="bi bi-telephone me-1 text-muted"></i>
                        <?= sanitize($student['parent_contact'] ?? '—') ?>
                    </div>
                    <div>
                        <i class="bi bi-envelope me-1 text-muted"></i>
                        <?= sanitize($student['parent_email'] ?? '—') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Stats + Attendance -->
    <div class="col-lg-8">

        <!-- Attendance Summary -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card blue py-2">
                    <div class="stat-icon blue" style="width:38px;height:38px;font-size:1rem">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.4rem"><?= $summary['total_days'] ?></div>
                        <div class="stat-label">Total Days</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card green py-2">
                    <div class="stat-icon green" style="width:38px;height:38px;font-size:1rem">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.4rem"><?= $summary['present'] ?></div>
                        <div class="stat-label">Present</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card red py-2">
                    <div class="stat-icon red" style="width:38px;height:38px;font-size:1rem">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.4rem"><?= $summary['absent'] ?></div>
                        <div class="stat-label">Absent</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card orange py-2">
                    <div class="stat-icon orange" style="width:38px;height:38px;font-size:1rem">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.4rem"><?= $summary['late'] ?></div>
                        <div class="stat-label">Late</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance Rate -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-600">Attendance Rate</span>
                    <span class="fw-800 fs-5 text-<?= $rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger') ?>">
                        <?= $rate ?>%
                    </span>
                </div>
                <div class="progress" style="height:12px;border-radius:6px">
                    <div class="progress-bar bg-<?= $rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger') ?>"
                         style="width:<?= $rate ?>%;border-radius:6px">
                    </div>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <small class="text-muted">0%</small>
                    <small class="text-muted">
                        <?= $summary['present'] + $summary['late'] ?> of <?= $summary['total_days'] ?> days attended
                    </small>
                    <small class="text-muted">100%</small>
                </div>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Recent Attendance</span>
                <a href="../attendance/index.php?student=<?= $student['id'] ?>"
                   class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">
                                    No attendance records yet.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= date('M j, Y', strtotime($r['date'])) ?></td>
                                <td><?= $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—' ?></td>
                                <td><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
                                <td>
                                    <span class="status-badge badge-<?= $r['status'] ?>">
                                        <?= ucfirst($r['status']) ?>
                                    </span>
                                </td>
                                <td><small class="text-muted"><?= sanitize($r['remarks'] ?? '—') ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>