<?php
/**
 * Dashboard
 */
require_once 'config/database.php';
require_once 'includes/functions.php';
requireLogin();

$pageTitle = 'Dashboard';
$db        = getDB();
$today     = date('Y-m-d');
$stats     = getDashboardStats();

// ── Last 7 days attendance trend ─────────────────────────────
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $db->prepare("SELECT
        COUNT(CASE WHEN status IN ('present','late') THEN 1 END) AS present,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) AS absent
        FROM attendance WHERE date = ?");
    $stmt->execute([$d]);
    $row = $stmt->fetch();
    $trend[] = [
        'date'    => date('M d', strtotime($d)),
        'present' => (int)$row['present'],
        'absent'  => (int)$row['absent'],
    ];
}

// ── Recent attendance logs ────────────────────────────────────
$recent = $db->query("
    SELECT a.*, s.first_name, s.last_name, s.photo,
           sec.section_name
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.date = '{$today}'
    ORDER BY a.created_at DESC
    LIMIT 10
")->fetchAll();

// ── Section summary ───────────────────────────────────────────
$sectionStats = $db->query("
    SELECT sec.section_name,
           COUNT(s.id) AS total,
           SUM(CASE WHEN a.status IN ('present','late') THEN 1 ELSE 0 END) AS present,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent
    FROM sections sec
    LEFT JOIN students s ON s.section_id = sec.id AND s.is_active = 1
    LEFT JOIN attendance a ON a.student_id = s.id AND a.date = '{$today}'
    GROUP BY sec.id
    ORDER BY sec.section_name
")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<?php showFlash(); ?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</h1>
        <p class="page-subtitle"><?= date('l, F j, Y') ?> — Today's Overview</p>
    </div>
    <a href="attendance/scanner.php" class="btn btn-primary">
        <i class="bi bi-qr-code-scan me-1"></i>Open QR Scanner
    </a>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['total_students'] ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['present_today'] ?></div>
                <div class="stat-label">Present Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['absent_today'] ?></div>
                <div class="stat-label">Absent Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card orange">
            <div class="stat-icon orange"><i class="bi bi-clock-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['late_today'] ?></div>
                <div class="stat-label">Late Today</div>
            </div>
        </div>
    </div>
</div>

<!-- Charts + Section Summary -->
<div class="row g-3 mb-4">
    <!-- Attendance Trend Chart -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-graph-up me-2 text-primary"></i>7-Day Attendance Trend</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Summary -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-diagram-3 me-2 text-primary"></i>Section Summary
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th class="text-center">Present</th>
                                <th class="text-center">Absent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sectionStats as $sec): ?>
                            <tr>
                                <td class="fw-600"><?= sanitize($sec['section_name']) ?></td>
                                <td class="text-center">
                                    <span class="status-badge badge-present"><?= $sec['present'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge badge-absent"><?= $sec['absent'] ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Today's Attendance Log -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2 text-primary"></i>Today's Attendance Log</span>
        <a href="attendance/index.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            No attendance records yet today.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recent as $row): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?= BASE_URL ?>uploads/students/<?= $row['photo'] ?>"
                                     class="student-photo-sm"
                                     onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=<?= urlencode($row['first_name'].' '.$row['last_name']) ?>&size=40&background=1a56db&color=fff'">
                                <span class="fw-600">
                                    <?= sanitize($row['first_name'] . ' ' . $row['last_name']) ?>
                                </span>
                            </div>
                        </td>
                        <td><?= sanitize($row['section_name'] ?? '—') ?></td>
                        <td><?= $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '—' ?></td>
                        <td><?= $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '—' ?></td>
                        <td>
                            <span class="status-badge badge-<?= $row['status'] ?>">
                                <?= ucfirst($row['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $row['scan_type'] === 'qr' ? 'info' : 'secondary' ?> bg-opacity-25 text-dark">
                                <?= strtoupper($row['scan_type']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Pass trend data to JS
$trendLabels  = json_encode(array_column($trend, 'date'));
$trendPresent = json_encode(array_column($trend, 'present'));
$trendAbsent  = json_encode(array_column($trend, 'absent'));
$extraJS = <<<JS
<script>
(function() {
    const ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$trendLabels},
            datasets: [
                {
                    label: 'Present',
                    data: {$trendPresent},
                    backgroundColor: 'rgba(14,159,110,0.75)',
                    borderRadius: 6
                },
                {
                    label: 'Absent',
                    data: {$trendAbsent},
                    backgroundColor: 'rgba(224,36,36,0.65)',
                    borderRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
})();
</script>
JS;
include 'includes/footer.php';
?>