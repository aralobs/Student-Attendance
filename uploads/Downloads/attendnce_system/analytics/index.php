<?php
/**
 * Attendance Analytics Dashboard
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Analytics';
$db        = getDB();
$year      = (int)($_GET['year'] ?? date('Y'));

// Monthly attendance totals (for bar chart)
$monthly = [];
for ($m = 1; $m <= 12; $m++) {
    $stmt = $db->prepare("
        SELECT
            SUM(status IN ('present','late')) AS present,
            SUM(status = 'absent')            AS absent
        FROM attendance
        WHERE MONTH(date) = ? AND YEAR(date) = ?
    ");
    $stmt->execute([$m, $year]);
    $row = $stmt->fetch();
    $monthly[] = [
        'month'   => date('M', mktime(0,0,0,$m,1)),
        'present' => (int)$row['present'],
        'absent'  => (int)$row['absent'],
    ];
}

// Overall attendance rate
$overall = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(status IN ('present','late')) AS attended
    FROM attendance WHERE YEAR(date) = ?");
$overall->execute([$year]);
$overall = $overall->fetch();
$attendanceRate = $overall['total'] > 0
    ? round(($overall['attended'] / $overall['total']) * 100, 1)
    : 0;

// Top 10 most absent students
$mostAbsent = $db->prepare("
    SELECT s.first_name, s.last_name, sec.section_name,
           COUNT(*) AS absent_count
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.status = 'absent' AND YEAR(a.date) = ?
    GROUP BY a.student_id
    ORDER BY absent_count DESC
    LIMIT 10
");
$mostAbsent->execute([$year]);
$mostAbsent = $mostAbsent->fetchAll();

// Section attendance rates
$sectionRates = $db->prepare("
    SELECT sec.section_name,
           COUNT(a.id)                          AS total,
           SUM(a.status IN ('present','late'))  AS attended
    FROM sections sec
    LEFT JOIN students s ON s.section_id = sec.id AND s.is_active = 1
    LEFT JOIN attendance a ON a.student_id = s.id AND YEAR(a.date) = ?
    GROUP BY sec.id
    ORDER BY sec.section_name
");
$sectionRates->execute([$year]);
$sectionRates = $sectionRates->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Analytics</h1>
        <p class="page-subtitle">Attendance statistics and insights</p>
    </div>
    <form method="GET" class="d-flex gap-2">
        <select name="year" class="form-select form-select-sm" style="width:auto">
            <?php for ($y = 2024; $y <= date('Y'); $y++): ?>
            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </form>
</div>

<!-- Key metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-graph-up-arrow"></i></div>
            <div>
                <div class="stat-number"><?= $attendanceRate ?>%</div>
                <div class="stat-label">Overall Rate (<?= $year ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-check2-all"></i></div>
            <div>
                <div class="stat-number"><?= number_format($overall['attended']) ?></div>
                <div class="stat-label">Total Attended Days</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
            <div>
                <div class="stat-number"><?= number_format($overall['total'] - $overall['attended']) ?></div>
                <div class="stat-label">Total Absent Days</div>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Chart + Section Rates -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2 text-primary"></i>Monthly Attendance — <?= $year ?>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-pie-chart me-2 text-primary"></i>Section Rates
            </div>
            <div class="card-body">
                <div class="chart-container" style="height:200px">
                    <canvas id="sectionChart"></canvas>
                </div>
                <div class="mt-3">
                    <?php foreach ($sectionRates as $sr): ?>
                    <?php $rate = $sr['total'] > 0 ? round(($sr['attended']/$sr['total'])*100) : 0; ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-600"><?= sanitize($sr['section_name']) ?></span>
                            <span><?= $rate ?>%</span>
                        </div>
                        <div class="progress" style="height:6px">
                            <div class="progress-bar bg-<?= $rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger') ?>"
                                 style="width:<?= $rate ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Most Absent Students -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
        Students with Most Absences — <?= $year ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student</th>
                        <th>Section</th>
                        <th>Absences</th>
                        <th>Indicator</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mostAbsent)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No absence data available.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($mostAbsent as $rank => $row): ?>
                    <tr>
                        <td>
                            <?php if ($rank === 0): ?>
                            <span class="badge bg-danger">🥇 #1</span>
                            <?php elseif ($rank === 1): ?>
                            <span class="badge bg-warning text-dark">🥈 #2</span>
                            <?php else: ?>
                            <span class="text-muted">#<?= $rank + 1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-600">
                            <?= sanitize($row['last_name'] . ', ' . $row['first_name']) ?>
                        </td>
                        <td><?= sanitize($row['section_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge bg-danger"><?= $row['absent_count'] ?> day(s)</span>
                        </td>
                        <td style="width:200px">
                            <div class="progress" style="height:8px">
                                <?php
                                $maxAbsent = $mostAbsent[0]['absent_count'] ?? 1;
                                $pct = round(($row['absent_count'] / $maxAbsent) * 100);
                                ?>
                                <div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div>
                            </div>
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
$monthlyLabels  = json_encode(array_column($monthly, 'month'));
$monthlyPresent = json_encode(array_column($monthly, 'present'));
$monthlyAbsent  = json_encode(array_column($monthly, 'absent'));
$sectionLabels  = json_encode(array_column($sectionRates, 'section_name'));
$sectionData    = json_encode(array_map(fn($s) =>
    $s['total'] > 0 ? round(($s['attended']/$s['total'])*100, 1) : 0,
    $sectionRates
));

$extraJS = <<<JS
<script>
// Monthly Chart
new Chart(document.getElementById('monthlyChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: {$monthlyLabels},
        datasets: [
            { label: 'Present', data: {$monthlyPresent}, backgroundColor: 'rgba(14,159,110,0.75)', borderRadius: 5 },
            { label: 'Absent',  data: {$monthlyAbsent},  backgroundColor: 'rgba(224,36,36,0.65)',  borderRadius: 5 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 5 } } }
    }
});

// Section Pie Chart
new Chart(document.getElementById('sectionChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: {$sectionLabels},
        datasets: [{
            data: {$sectionData},
            backgroundColor: ['#1a56db','#0e9f6e','#ff5a1f','#7c3aed','#0694a2']
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }
    }
});
</script>
JS;
include '../includes/footer.php';
?>