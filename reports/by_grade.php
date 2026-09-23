<?php
/**
 * Attendance by Grade Level
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle  = 'By Grade Level';
$db         = getDB();
$month      = (int)($_GET['month'] ?? date('n'));
$year       = (int)($_GET['year']  ?? date('Y'));
$grades     = getGradeLevels();

// Count school days this month
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$schoolDays  = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow     = (int)date('N', mktime(0,0,0,$month,$d,$year));
    if (!in_array($dow,[6,7]) && !isHolidayOrNoClass($dateStr)) {
        $schoolDays++;
    }
}

// Stats per grade
$gradeStats = [];
foreach ($grades as $grade) {
    $stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT s.id)                             AS total_students,
            SUM(a.attendance_type = 'full_day')              AS full_day,
            SUM(a.attendance_type = 'partial')               AS partial,
            SUM(a.attendance_type = 'absent')                AS absent,
            SUM(a.am_status = 'late')                        AS am_late,
            SUM(a.pm_status = 'late')                        AS pm_late,
            COUNT(DISTINCT a.date)                           AS days_recorded
        FROM students s
        LEFT JOIN sections sec ON s.section_id = sec.id
        LEFT JOIN attendance a ON a.student_id = s.id
            AND MONTH(a.date) = ? AND YEAR(a.date) = ?
        WHERE sec.grade_level = ? AND s.is_active = 1
    ");
    $stmt->execute([$month, $year, $grade]);
    $row = $stmt->fetch();

    $attended = ($row['full_day'] ?? 0) + (($row['partial'] ?? 0) * 0.5);
    $possible = ($row['total_students'] ?? 0) * $schoolDays;
    $rate     = $possible > 0 ? round(($attended / $possible) * 100, 1) : 0;

    $gradeStats[$grade] = array_merge($row, ['rate' => $rate, 'possible' => $possible]);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-bar-chart-steps me-2 text-primary"></i>By Grade Level
        </h1>
        <p class="page-subtitle">
            <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>
            — <?= $schoolDays ?> school days
        </p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="index_reports.php" class="btn btn-outline-secondary btn-sm no-print">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?=$m?>" <?=$m==$month?'selected':''?>>
                        <?= date('F',mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for($y=2024;$y<=date('Y')+1;$y++): ?>
                    <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Report header -->
<div class="card mb-3">
    <div class="card-body text-center py-2">
        <h5 class="fw-800 mb-0"><?= sanitize(getSetting('school_name') ?? '') ?></h5>
        <div class="text-muted small">S.Y. <?= getSetting('school_year') ?></div>
        <h6 class="fw-700 mt-1 mb-0">Attendance by Grade Level</h6>
        <div class="small"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></div>
    </div>
</div>

<!-- Chart -->
<div class="card mb-4">
    <div class="card-header">
        <i class="bi bi-bar-chart me-2 text-primary"></i>Attendance Rate by Grade
    </div>
    <div class="card-body">
        <div class="chart-container" style="height:250px">
            <canvas id="gradeChart"></canvas>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0" style="font-size:0.82rem">
                <thead class="table-light">
                    <tr>
                        <th>Grade Level</th>
                        <th class="text-center">Students</th>
                        <th class="text-center">School Days</th>
                        <th class="text-center" style="background:#f0fdf4">Full Day</th>
                        <th class="text-center" style="background:#fef3c7">Partial</th>
                        <th class="text-center" style="background:#fee2e2">Absent</th>
                        <th class="text-center">AM Late</th>
                        <th class="text-center">PM Late</th>
                        <th class="text-center">Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gradeStats as $grade => $s): ?>
                    <tr>
                        <td class="fw-700">
                            <span class="badge bg-primary bg-opacity-75 me-1">
                                <?= sanitize($grade) ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $s['total_students'] ?></td>
                        <td class="text-center"><?= $schoolDays ?></td>
                        <td class="text-center" style="background:#f9fffe">
                            <span class="badge bg-success bg-opacity-75"><?= $s['full_day'] ?? 0 ?></span>
                        </td>
                        <td class="text-center" style="background:#fffdf0">
                            <span class="badge bg-warning text-dark"><?= $s['partial'] ?? 0 ?></span>
                        </td>
                        <td class="text-center" style="background:#fff8f8">
                            <span class="badge bg-danger bg-opacity-75"><?= $s['absent'] ?? 0 ?></span>
                        </td>
                        <td class="text-center"><?= $s['am_late'] ?? 0 ?></td>
                        <td class="text-center"><?= $s['pm_late'] ?? 0 ?></td>
                        <td class="text-center">
                            <span class="fw-700 text-<?= $s['rate']>=90?'success':($s['rate']>=75?'warning':'danger') ?>">
                                <?= $s['rate'] ?>%
                            </span>
                            <div class="progress mt-1" style="height:5px">
                                <div class="progress-bar bg-<?= $s['rate']>=90?'success':($s['rate']>=75?'warning':'danger') ?>"
                                     style="width:<?= $s['rate'] ?>%"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$chartLabels = json_encode(array_keys($gradeStats));
$chartRates  = json_encode(array_map(fn($s) => $s['rate'], $gradeStats));
$chartColors = json_encode(array_map(
    fn($s) => $s['rate'] >= 90
        ? 'rgba(14,159,110,0.75)'
        : ($s['rate'] >= 75 ? 'rgba(245,158,11,0.75)' : 'rgba(224,36,36,0.65)'),
    $gradeStats
));

$extraJS = <<<JS
<script>
new Chart(document.getElementById('gradeChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: {$chartLabels},
        datasets: [{
            label: 'Attendance Rate (%)',
            data: {$chartRates},
            backgroundColor: {$chartColors},
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                ticks: { callback: v => v + '%' }
            }
        }
    }
});
</script>
JS;
include '../includes/footer.php';
?>