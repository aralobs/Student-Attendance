<?php
/**
 * View Student Profile — v2
 * Updated for new attendance schema (am_in, am_out, pm_in, pm_out)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, sec.section_name, sec.grade_level, sec.schedule_type
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

// Attendance summary using new columns
$summary = $db->prepare("
    SELECT
        COUNT(*)                                     AS total_days,
        SUM(attendance_type = 'full_day')            AS full_day,
        SUM(attendance_type = 'partial')             AS partial,
        SUM(attendance_type = 'absent')              AS absent,
        SUM(am_status = 'late' OR pm_status = 'late') AS late_count
    FROM attendance
    WHERE student_id = ?
");
$summary->execute([$id]);
$summary = $summary->fetch();

// Recent attendance - last 10
$recent = $db->prepare("
    SELECT * FROM attendance
    WHERE student_id = ?
    ORDER BY date DESC
    LIMIT 10
");
$recent->execute([$id]);
$recent = $recent->fetchAll();

// Attendance rate (full_day = 1, partial = 0.5)
$attended = ($summary['full_day'] ?? 0) + (($summary['partial'] ?? 0) * 0.5);
$rate     = $summary['total_days'] > 0
    ? round(($attended / $summary['total_days']) * 100, 1)
    : 0;

$pageTitle = 'Student — ' . $student['first_name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-person-circle me-2 text-primary"></i>Student Profile
        </h1>
        <p class="page-subtitle">Attendance history and student details</p>
    </div>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= $student['id'] ?>" class="btn btn-primary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <a href="generate_qr.php?id=<?= $student['id'] ?>" class="btn btn-outline-success">
            <i class="bi bi-qr-code me-1"></i>QR Code
        </a>
        <a href="students.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="row g-4">

    <!-- Profile Card -->
    <div class="col-lg-4">
        <div class="card text-center">
            <div class="card-body p-4">
                <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($student['photo']) ?>"
                     class="rounded-circle mb-3"
                     style="width:110px;height:110px;object-fit:cover;border:4px solid #1a56db"
                     onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=<?= urlencode($student['first_name'].' '.$student['last_name']) ?>&size=110&background=1a56db&color=fff'">

                <h5 class="fw-800 mb-0">
                    <?= sanitize($student['first_name'] . ' ' .
                        ($student['middle_name'] ? substr($student['middle_name'],0,1).'. ' : '') .
                        $student['last_name']) ?>
                </h5>
                <div class="text-muted small mb-1">
                    <?= sanitize($student['grade_level'] ?? '') ?>
                    <?= $student['section_name'] ? ' — ' . sanitize($student['section_name']) : '' ?>
                </div>
                <div class="mb-2">
                    <span class="badge bg-<?= $student['schedule_type'] === 'full_day' ? 'info' : ($student['schedule_type'] === 'am_only' ? 'success' : 'warning') ?>">
                        <?= ucfirst(str_replace('_',' ', $student['schedule_type'] ?? 'full_day')) ?>
                    </span>
                </div>
                <span class="badge bg-<?= $student['gender'] === 'Male' ? 'primary' : 'danger' ?> mb-3">
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
                        <?= $student['birth_date']
                            ? date('F j, Y', strtotime($student['birth_date']))
                            : '—' ?>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted d-block">Address</small>
                        <?= sanitize($student['address'] ?? '—') ?>
                    </div>
                </div>

                <hr>

                <div class="text-start">
                    <small class="text-muted fw-600 d-block mb-2">
                        <i class="bi bi-person-lines-fill me-1"></i>Parent / Guardian
                    </small>
                    <div class="mb-1 small">
                        <i class="bi bi-person me-1 text-muted"></i>
                        <?= sanitize($student['parent_name'] ?? '—') ?>
                    </div>
                    <div class="mb-1 small">
                        <i class="bi bi-telephone me-1 text-muted"></i>
                        <?= sanitize($student['parent_contact'] ?? '—') ?>
                    </div>
                    <div class="small">
                        <i class="bi bi-envelope me-1 text-muted"></i>
                        <?= sanitize($student['parent_email'] ?? '—') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats + History -->
    <div class="col-lg-8">

        <!-- Summary Stats -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card blue py-2">
                    <div class="stat-icon blue" style="width:36px;height:36px;font-size:0.9rem">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.3rem">
                            <?= $summary['total_days'] ?>
                        </div>
                        <div class="stat-label">Total Days</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card green py-2">
                    <div class="stat-icon green" style="width:36px;height:36px;font-size:0.9rem">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.3rem">
                            <?= $summary['full_day'] ?>
                        </div>
                        <div class="stat-label">Full Day</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card orange py-2">
                    <div class="stat-icon orange" style="width:36px;height:36px;font-size:0.9rem">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.3rem">
                            <?= $summary['partial'] ?>
                        </div>
                        <div class="stat-label">Partial</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card red py-2">
                    <div class="stat-icon red" style="width:36px;height:36px;font-size:0.9rem">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <div>
                        <div class="stat-number" style="font-size:1.3rem">
                            <?= $summary['absent'] ?>
                        </div>
                        <div class="stat-label">Absent</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rate -->
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
                         style="width:<?= $rate ?>%;border-radius:6px"></div>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <small class="text-muted">0%</small>
                    <small class="text-muted">
                        <?= number_format($attended, 1) ?> of <?= $summary['total_days'] ?> days
                        (partial = 0.5)
                    </small>
                    <small class="text-muted">100%</small>
                </div>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Recent Attendance</span>
                <a href="../attendance/students.php"
                   class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" style="font-size:0.82rem">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-center">AM In</th>
                                <th class="text-center">AM Out</th>
                                <th class="text-center">PM In</th>
                                <th class="text-center">PM Out</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">
                                    No attendance records yet.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($recent as $r): ?>
                            <tr>
                                <td class="fw-600">
                                    <?= date('M j, Y', strtotime($r['date'])) ?>
                                    <div class="text-muted" style="font-size:0.7rem">
                                        <?= date('D', strtotime($r['date'])) ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?= $r['am_in']
                                        ? date('h:i A', strtotime($r['am_in']))
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center">
                                    <?= $r['am_out']
                                        ? date('h:i A', strtotime($r['am_out']))
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center">
                                    <?= $r['pm_in']
                                        ? date('h:i A', strtotime($r['pm_in']))
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center">
                                    <?= $r['pm_out']
                                        ? date('h:i A', strtotime($r['pm_out']))
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td><?= attendanceTypeBadge($r['attendance_type']) ?></td>
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