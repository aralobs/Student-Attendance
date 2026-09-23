<?php
/**
 * Absence Summary Report
 * Daily absence counts, peak dates, cumulative by student
 * Holiday-aware — no false absences on holidays
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Absence Summary';
$db        = getDB();

$month      = (int)($_GET['month']   ?? date('n'));
$year       = (int)($_GET['year']    ?? date('Y'));
$gradeLevel = $_GET['grade']         ?? '';
$sectionId  = (int)($_GET['section'] ?? 0);

$grades   = getGradeLevels();
$sections = getAllowedSections();

// Build filter
$sectionFilter  = '';
$sectionParams  = [];
if ($gradeLevel) { $sectionFilter .= ' AND sec.grade_level = ?'; $sectionParams[] = $gradeLevel; }
if ($sectionId)  { $sectionFilter .= ' AND s.section_id = ?';   $sectionParams[] = $sectionId; }

// Calendar for this month — mark holidays
$calEntries = getCalendarMonth($month, $year);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Daily absence counts
$dailyAbsences = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr   = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dayOfWeek = date('N', mktime(0,0,0,$month,$d,$year));
    if (in_array($dayOfWeek, [6,7])) continue; // skip weekends

    $isHol = isset($calEntries[$dateStr]) &&
             in_array($calEntries[$dateStr]['type'], ['holiday','no_class']);

    if ($isHol) {
        $dailyAbsences[$dateStr] = [
            'date'    => $dateStr,
            'count'   => 0,
            'holiday' => true,
            'title'   => $calEntries[$dateStr]['title'],
        ];
        continue;
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        LEFT JOIN sections sec ON s.section_id = sec.id
        WHERE a.date = ? AND a.attendance_type = 'absent'
        AND s.is_active = 1 {$sectionFilter}
    ");
    $stmt->execute(array_merge([$dateStr], $sectionParams));
    $count = $stmt->fetch()['cnt'];

    $dailyAbsences[$dateStr] = [
        'date'    => $dateStr,
        'count'   => (int)$count,
        'holiday' => false,
        'title'   => '',
    ];
}

// Peak absence date
$peakDate  = null;
$peakCount = 0;
foreach ($dailyAbsences as $entry) {
    if (!$entry['holiday'] && $entry['count'] > $peakCount) {
        $peakCount = $entry['count'];
        $peakDate  = $entry['date'];
    }
}

// Top 10 most absent students this month
$topAbsent = $db->prepare("
    SELECT s.first_name, s.last_name, s.lrn,
           sec.grade_level, sec.section_name,
           COUNT(*) AS absent_count
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE MONTH(a.date) = ? AND YEAR(a.date) = ?
    AND a.attendance_type = 'absent'
    AND s.is_active = 1
    {$sectionFilter}
    GROUP BY a.student_id
    ORDER BY absent_count DESC
    LIMIT 10
");
$topAbsent->execute(array_merge([$month, $year], $sectionParams));
$topAbsent = $topAbsent->fetchAll();

// Total school days (non-holiday weekdays)
$schoolDays = count(array_filter($dailyAbsences, fn($e) => !$e['holiday']));

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-x-circle me-2 text-danger"></i>Absence Summary
        </h1>
        <p class="page-subtitle">
            <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="index_reports.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m=1;$m<=12;$m++): ?>
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
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Grade</label>
                <select name="grade" class="form-select form-select-sm">
                    <option value="">All Grades</option>
                    <?php foreach($grades as $g): ?>
                    <option value="<?=$g?>" <?=$gradeLevel===$g?'selected':''?>><?=$g?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="">All Sections</option>
                    <?php foreach($sections as $s): ?>
                    <option value="<?=$s['id']?>" <?=$sectionId==$s['id']?'selected':''?>>
                        <?= sanitize($s['grade_level'].' — '.$s['section_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Key metrics -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card blue py-2">
            <div class="stat-icon blue" style="width:40px;height:40px;font-size:1rem">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="stat-number" style="font-size:1.4rem"><?= $schoolDays ?></div>
                <div class="stat-label">School Days</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card red py-2">
            <div class="stat-icon red" style="width:40px;height:40px;font-size:1rem">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
            <div>
                <div class="stat-number" style="font-size:1.4rem"><?= $peakCount ?></div>
                <div class="stat-label">Peak Absences</div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100 border-danger">
            <div class="card-body py-2">
                <div class="small text-muted fw-600">Peak Absence Date</div>
                <?php if ($peakDate): ?>
                <div class="fw-800 fs-5 text-danger">
                    <?= date('l, F j, Y', strtotime($peakDate)) ?>
                </div>
                <div class="small text-muted"><?= $peakCount ?> students absent</div>
                <?php else: ?>
                <div class="text-muted small">No data</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Daily chart + table -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2 text-danger"></i>
                Daily Absences — <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>
            </div>
            <div class="card-body">
                <div class="chart-container" style="height:220px">
                    <canvas id="absenceChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="bi bi-table me-2"></i>Daily Breakdown
            </div>
            <div class="card-body p-0" style="max-height:350px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-white">
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th class="text-center">Absences</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dailyAbsences as $entry): ?>
                        <tr class="<?= $entry['holiday'] ? 'table-secondary' : ($entry['count'] === $peakCount && $peakCount > 0 ? 'table-danger' : '') ?>">
                            <td class="small fw-600">
                                <?= date('M j', strtotime($entry['date'])) ?>
                            </td>
                            <td class="small text-muted">
                                <?= date('D', strtotime($entry['date'])) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($entry['holiday']): ?>
                                <span class="text-muted">—</span>
                                <?php else: ?>
                                <span class="badge bg-<?= $entry['count'] > 0 ? 'danger' : 'success' ?>">
                                    <?= $entry['count'] ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= $entry['holiday'] ? sanitize($entry['title']) : '' ?>
                                <?= (!$entry['holiday'] && $entry['count'] === $peakCount && $peakCount > 0)
                                    ? '<span class="badge bg-danger">Peak</span>' : '' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top absent students -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
                Most Absent Students
            </div>
            <div class="card-body p-0">
                <?php if (empty($topAbsent)): ?>
                <div class="text-center text-muted py-3 small">No data</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($topAbsent as $rank => $s): ?>
                    <li class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-600 small">
                                    <?= $rank + 1 ?>. <?= sanitize($s['last_name'].', '.$s['first_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:0.72rem">
                                    <?= sanitize($s['grade_level'].' — '.$s['section_name']) ?>
                                </div>
                            </div>
                            <span class="badge bg-danger"><?= $s['absent_count'] ?>d</span>
                        </div>
                        <div class="progress mt-1" style="height:4px">
                            <?php $pct = $schoolDays > 0 ? round(($s['absent_count']/$schoolDays)*100) : 0; ?>
                            <div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$chartLabels = json_encode(array_map(
    fn($e) => date('M j', strtotime($e['date'])),
    $dailyAbsences
));
$chartData = json_encode(array_map(
    fn($e) => $e['holiday'] ? null : $e['count'],
    $dailyAbsences
));
$chartColors = json_encode(array_map(
    fn($e) => $e['holiday']
        ? 'rgba(156,163,175,0.3)'
        : ($e['count'] === $peakCount && $peakCount > 0
            ? 'rgba(220,38,38,0.85)'
            : 'rgba(220,38,38,0.5)'),
    $dailyAbsences
));

$extraJS = <<<JS
<script>
new Chart(document.getElementById('absenceChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: {$chartLabels},
        datasets: [{
            label: 'Absences',
            data: {$chartData},
            backgroundColor: {$chartColors},
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.raw === null ? 'Holiday/No Class' : ctx.raw + ' absent'
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});
</script>
JS;
include '../includes/footer.php';
?>