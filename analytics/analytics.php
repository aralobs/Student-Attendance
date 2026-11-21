<?php
/**
 * Attendance Analytics Dashboard v2
 * Uses am_status, pm_status, attendance_type columns
 *
 * Key Metrics reflect the selected calendar year; live logs stay on Dashboard.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireStaff();
require_once '../includes/enrollment_reports.php';
require_once '../includes/analytics_absence_reasons.php';

$pageTitle = 'Analytics';
$db        = getDB();
$defaultReportDate = enrollmentReportDefaultDate($db);
$year      = (int)($_GET['year'] ?? date('Y', strtotime($defaultReportDate)));

$reportContext = prepareEnrollmentReport($db,sprintf('%04d-01-01',$year),sprintf('%04d-12-31',$year),null,true);
$reportStudents = $reportContext['students']; $reportAttendance = $reportContext['attendance']; $reportJoin = $reportContext['join'];

// Build section filter for teachers
$sectionFilter = '';
$sectionParams = [];
if (!isAdmin()) {
    $user            = currentUser();
    $allowedSections = ($reportContext['sections'] ?? getAllowedSections());
    $ids             = array_column($allowedSections, 'id') ?: [0];
    $placeholders    = implode(',', array_fill(0, count($ids), '?'));
    $sectionFilter   = "AND s.section_id IN ({$placeholders})";
    $sectionParams   = $ids;
}

// ── Overall attendance rate (YEAR — still used for Total Present/Absent cards) ──
$stmt = $db->prepare("
    SELECT
        COUNT(*)                                         AS total,
        SUM(a.attendance_type IN ('full_day','partial')) AS attended,
        SUM(a.attendance_type = 'full_day')              AS full_day,
        SUM(a.attendance_type = 'partial')               AS partial,
        SUM(a.attendance_type = 'absent')                AS absent,
        SUM(a.am_status = 'late')                        AS am_late,
        SUM(a.pm_status = 'late')                        AS pm_late
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    WHERE YEAR(a.date) = ? AND s.is_active = 1 {$sectionFilter}
");
$stmt->execute(array_merge([$year], $sectionParams));
$overall = $stmt->fetch();

$totalRecords = (int)($overall['total'] ?? 0);
$attendanceRate = $totalRecords > 0
    ? round(($overall['attended'] / $totalRecords) * 100, 1)
    : 0;

$totalPresent = (int)($overall['attended'] ?? 0);
$totalAbsent  = (int)($overall['absent']   ?? 0);

// ── Top 10 most absent students ───────────────────────────────
$stmt = $db->prepare("
    SELECT s.first_name, s.last_name,
           sec.section_name, sec.grade_level,
           COUNT(*) AS absent_count
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.attendance_type = 'absent'
    AND YEAR(a.date) = ?
    AND s.is_active = 1 {$sectionFilter}
    GROUP BY a.student_id, s.first_name, s.last_name, sec.section_name, sec.grade_level
    ORDER BY absent_count DESC
    LIMIT 10
");
$stmt->execute(array_merge([$year], $sectionParams));
$mostAbsent = $stmt->fetchAll();

// ── Grade level attendance rates ──────────────────────────────
$gradeRates = [];
foreach (getGradeLevels() as $grade) {
    $stmt = $db->prepare("
        SELECT
            COUNT(a.id)                                      AS total,
            SUM(a.attendance_type IN ('full_day','partial')) AS attended
        FROM sections sec
        LEFT JOIN {$reportStudents} s ON s.section_id  = sec.id AND s.is_active = 1
        LEFT JOIN {$reportAttendance} a ON a.student_id = s.id {$reportJoin} AND YEAR(a.date) = ?
        WHERE sec.grade_level = ? {$sectionFilter}
    ");
    $stmt->execute(array_merge([$year, $grade], $sectionParams));
    $row = $stmt->fetch();
    $gradeRates[$grade] = [
        'total'    => (int)($row['total']    ?? 0),
        'attended' => (int)($row['attended'] ?? 0),
        'rate'     => $row['total'] > 0
            ? round(($row['attended'] / $row['total']) * 100, 1)
            : 0,
    ];
}

// ── Section attendance rates ──────────────────────────────────
$allowedSecs  = ($reportContext['sections'] ?? getAllowedSections());
$sectionRates = [];
foreach ($allowedSecs as $sec) {
    $stmt = $db->prepare("
        SELECT
            COUNT(a.id)                                      AS total,
            SUM(a.attendance_type IN ('full_day','partial')) AS attended
        FROM {$reportStudents} s
        LEFT JOIN {$reportAttendance} a ON a.student_id = s.id {$reportJoin} AND YEAR(a.date) = ?
        WHERE s.section_id = ? AND s.is_active = 1
    ");
    $stmt->execute([$year, $sec['id']]);
    $row = $stmt->fetch();
    $rate = $row['total'] > 0
        ? round(($row['attended'] / $row['total']) * 100, 1)
        : 0;
    $sectionRates[] = [
        'section_name' => $sec['section_name'],
        'grade_level'  => $sec['grade_level'],
        'total'        => (int)($row['total']    ?? 0),
        'attended'     => (int)($row['attended'] ?? 0),
        'rate'         => $rate,
    ];
}

// ═══ Monthly Analytics ═══════════════════════════════════════
$month = (int)($_GET['month'] ?? date('n', strtotime($defaultReportDate)));
if ($month < 1 || $month > 12) $month = (int)date('n');

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Monthly overall breakdown
$stmt = $db->prepare("
    SELECT
        COUNT(*)                                         AS total,
        SUM(a.attendance_type IN ('full_day','partial')) AS attended,
        SUM(a.attendance_type = 'full_day')              AS full_day,
        SUM(a.attendance_type = 'partial')               AS partial,
        SUM(a.attendance_type = 'absent')                AS absent,
        SUM(a.am_status = 'late')                        AS am_late,
        SUM(a.pm_status = 'late')                        AS pm_late
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    WHERE YEAR(a.date) = ?
      AND MONTH(a.date) = ?
      AND s.is_active = 1 {$sectionFilter}
");
$stmt->execute(array_merge([$year, $month], $sectionParams));
$monthly = $stmt->fetch();

$monthlyTotal    = (int)($monthly['total']    ?? 0);
$monthlyAttended = (int)($monthly['attended'] ?? 0);
$monthlyRate     = $monthlyTotal > 0
    ? round(($monthlyAttended / $monthlyTotal) * 100, 1)
    : 0;

$monthlyFullDay = (int)($monthly['full_day'] ?? 0);
$monthlyPartial = (int)($monthly['partial']  ?? 0);
$monthlyAbsent  = (int)($monthly['absent']   ?? 0);
$monthlyAmLate  = (int)($monthly['am_late']  ?? 0);
$monthlyPmLate  = (int)($monthly['pm_late']  ?? 0);
$monthlyTopAbsenceReason = loadAnalyticsTopAbsenceReason($db,$reportContext,$year,$month,currentUser());

// Monthly top 10 most absent
$stmt = $db->prepare("
    SELECT s.first_name, s.last_name,
           sec.section_name, sec.grade_level,
           COUNT(*) AS absent_count
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.attendance_type = 'absent'
      AND YEAR(a.date) = ?
      AND MONTH(a.date) = ?
      AND s.is_active = 1 {$sectionFilter}
    GROUP BY a.student_id, s.first_name, s.last_name, sec.section_name, sec.grade_level
    ORDER BY absent_count DESC
    LIMIT 10
");
$stmt->execute(array_merge([$year, $month], $sectionParams));
$monthlyMostAbsent = $stmt->fetchAll();

// Daily breakdown
$stmt = $db->prepare("
    SELECT DAY(a.date) AS day,
           SUM(a.attendance_type IN ('full_day','partial')) AS present,
           SUM(a.attendance_type = 'absent')                AS absent
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    WHERE YEAR(a.date) = ?
      AND MONTH(a.date) = ?
      AND s.is_active = 1 {$sectionFilter}
    GROUP BY DAY(a.date)
    ORDER BY day
");
$stmt->execute(array_merge([$year, $month], $sectionParams));
$dailyRows = $stmt->fetchAll();

$daysInMonth  = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$dailyLabels  = [];
$dailyPresent = [];
$dailyAbsent  = [];
$dailyMap     = [];
foreach ($dailyRows as $r) {
    $dailyMap[(int)$r['day']] = $r;
}
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dailyLabels[]  = $d;
    $dailyPresent[] = isset($dailyMap[$d]) ? (int)$dailyMap[$d]['present'] : 0;
    $dailyAbsent[]  = isset($dailyMap[$d]) ? (int)$dailyMap[$d]['absent']  : 0;
}

// Monthly per-grade rates
$monthlyGradeRates = [];
foreach (getGradeLevels() as $grade) {
    $stmt = $db->prepare("
        SELECT
            COUNT(a.id)                                      AS total,
            SUM(a.attendance_type IN ('full_day','partial')) AS attended
        FROM sections sec
        LEFT JOIN {$reportStudents} s  ON s.section_id  = sec.id AND s.is_active = 1
        LEFT JOIN {$reportAttendance} a ON a.student_id = s.id {$reportJoin}
                              AND YEAR(a.date)  = ?
                              AND MONTH(a.date) = ?
        WHERE sec.grade_level = ? {$sectionFilter}
    ");
    $stmt->execute(array_merge([$year, $month, $grade], $sectionParams));
    $row = $stmt->fetch();
    $monthlyGradeRates[$grade] = [
        'total'    => (int)($row['total']    ?? 0),
        'attended' => (int)($row['attended'] ?? 0),
        'rate'     => $row['total'] > 0
            ? round(($row['attended'] / $row['total']) * 100, 1)
            : 0,
    ];
}
$monthlyGradeLabels = array_keys($monthlyGradeRates);
$monthlyGradeValues = array_map(fn($g) => $g['rate'], array_values($monthlyGradeRates));

include '../includes/header.php';
include '../includes/sidebar.php';
enrollmentReportNotice($reportContext);
?>

<div class="card analytics-card monthly-analytics-page mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="h6 mb-0">
            <i class="bi bi-bar-chart-fill me-2 text-primary"></i>Analytics
            <span class="month-badge ms-2"><i class="bi bi-calendar-check"></i><?= $year ?></span>
        </h1>
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <?php if ($reportContext['managed']): ?><input type="hidden" name="school_year_id" value="<?= (int)$reportContext['year']['id'] ?>"><?php endif; ?>
            <input type="hidden" name="month" value="<?= $month ?>">
            <label for="analyticsCalendarYear" class="small text-muted">Calendar year</label>
            <select id="analyticsCalendarYear" name="year" class="form-select form-select-sm" style="width:auto">
                <?php for ($y = 2024; $y <= date('Y') + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
        </form>
    </div>
    <div class="card-body">
        <div class="section-divider"><i class="bi bi-calendar-range"></i> Key Metrics &mdash; <?= $year ?></div>
        <div class="row g-2">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label">Attendance Rate</div>
                    <div class="metric-value text-<?= $attendanceRate >= 90 ? 'success' : ($attendanceRate >= 75 ? 'warning' : 'danger') ?>" id="kpiRate"><?= $attendanceRate ?>%</div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label">Full Day</div>
                    <div class="metric-value text-success" id="kpiFullDay"><?= number_format((int)($overall['full_day'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label">Partial</div>
                    <div class="metric-value text-warning" id="kpiPartial"><?= number_format((int)($overall['partial'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label">Absent</div>
                    <div class="metric-value text-danger" id="kpiAbsent"><?= number_format($totalAbsent) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label">AM Late</div>
                    <div class="metric-value text-warning" id="kpiAmLate"><?= number_format((int)($overall['am_late'] ?? 0)) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label">PM Late</div>
                    <div class="metric-value text-warning" id="kpiPmLate"><?= number_format((int)($overall['pm_late'] ?? 0)) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- ══════════════ MONTHLY ANALYTICS + GRAPHS ═════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card analytics-card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-calendar3 me-2 text-primary"></i>
                    Monthly Analytics
                    <span class="month-badge ms-2">
                        <i class="bi bi-calendar-check"></i>
                        <?= $monthNames[$month] . ' ' . $year ?>
                    </span>
                </span>
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <?php if ($reportContext['managed']): ?><input type="hidden" name="school_year_id" value="<?= (int)$reportContext['year']['id'] ?>"><?php endif; ?>
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <select name="month" class="form-select form-select-sm" style="width:auto">
                        <?php foreach ($monthNames as $num => $name): ?>
                            <option value="<?= $num ?>" <?= $num == $month ? 'selected' : '' ?>>
                                <?= $name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-funnel me-1"></i>Apply
                    </button>
                </form>
            </div>

            <div class="card-body">
                <!-- Monthly summary tiles -->
                <div class="row g-2 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="metric-tile p-3 text-center">
                            <div class="metric-label">Attendance Rate</div>
                            <div class="metric-value text-<?= $monthlyRate >= 90 ? 'success' : ($monthlyRate >= 75 ? 'warning' : 'danger') ?>">
                                <?= $monthlyRate ?>%
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="metric-tile p-3 text-center">
                            <div class="metric-label">Present</div>
                            <div class="metric-value text-success"><?= number_format($monthlyAttended) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="metric-tile p-3 text-center">
                            <div class="metric-label">Absent</div>
                            <div class="metric-value text-danger"><?= number_format($monthlyAbsent) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="metric-tile p-3 text-center">
                            <div class="metric-label">Total Records</div>
                            <div class="metric-value text-secondary"><?= number_format($monthlyTotal) ?></div>
                        </div>
                    </div>
                </div>

                <div class="section-divider">
                    <i class="bi bi-bar-chart-line-fill"></i> Visual Insights
                </div>

                <!-- Row 1: Doughnut + Line -->
                <div class="row g-3 mb-3">
                    <div class="col-lg-4">
                        <div class="metric-tile p-3 h-100">
                            <div class="small fw-600 mb-2 text-center text-muted">
                                <i class="bi bi-pie-chart-fill me-1 text-primary"></i>
                                Type Breakdown
                            </div>
                            <div class="chart-wrapper" style="height:240px">
                                <canvas id="monthlyDoughnut"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="metric-tile p-3 h-100">
                            <div class="small fw-600 mb-2 text-center text-muted">
                                <i class="bi bi-graph-up me-1 text-primary"></i>
                                Daily Present vs Absent
                            </div>
                            <div class="chart-wrapper" style="height:240px">
                                <canvas id="monthlyChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Per-grade bar chart -->
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <div class="metric-tile p-3">
                            <div class="small fw-600 mb-2 text-center text-muted">
                                <i class="bi bi-bar-chart-fill me-1 text-primary"></i>
                                Attendance Rate by Grade Level —
                                <?= $monthNames[$month] . ' ' . $year ?>
                            </div>
                            <div class="chart-wrapper" style="height:260px">
                                <canvas id="monthlyGradeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Most absent this month -->
            <div class="card-body p-0 border-top">
                <div class="px-3 py-2 bg-light fw-600 small">
                    <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
                    Most Absent Students — <?= $monthNames[$month] . ' ' . $year ?>
                </div>
                <div class="table-responsive" style="max-height:320px;overflow-y:auto">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="sticky-thead">
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Grade / Section</th>
                                <th class="text-center">Absences</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monthlyMostAbsent)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox me-1"></i>
                                    No absence data for this month.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($monthlyMostAbsent as $rank => $row): ?>
                            <tr>
                                <td>
                                    <?php if ($rank < 3): ?>
                                    <span class="rank-badge rank-<?= $rank + 1 ?>"><?= $rank + 1 ?></span>
                                    <?php else: ?>
                                    <span class="rank-badge rank-n"><?= $rank + 1 ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-600 small">
                                    <?= sanitize($row['last_name'].', '.$row['first_name']) ?>
                                </td>
                                <td class="small text-muted">
                                    <?= sanitize($row['grade_level'].' / '.$row['section_name']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger">
                                        <?= $row['absent_count'] ?>d
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
    </div>
</div>

<div class="card analytics-card mb-4">
    <div class="card-header d-flex align-items-center flex-wrap gap-2">
        <span><i class="bi bi-calendar-range me-2 text-primary"></i>Year Overview</span>
        <span class="month-badge"><i class="bi bi-calendar-check"></i><?= $year ?></span>
    </div>
    <div class="card-body">
        <div class="section-divider"><i class="bi bi-bar-chart-line-fill"></i> Annual Insights</div>
<!-- Grade Level Rates (full width) -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card metric-tile h-100">
            <div class="card-header">
                <i class="bi bi-diagram-3 me-2 text-primary"></i>
                By Grade Level — <?= $year ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($gradeRates as $grade => $data): ?>
                    <div class="col-md-6 mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="fw-600"><?= sanitize($grade) ?></span>
                            <span class="text-<?= $data['rate'] >= 90 ? 'success' : ($data['rate'] >= 75 ? 'warning' : 'danger') ?>">
                                <?= $data['rate'] ?>%
                            </span>
                        </div>
                        <div class="progress" style="height:7px;border-radius:4px">
                            <div class="progress-bar bg-<?= $data['rate'] >= 90 ? 'success' : ($data['rate'] >= 75 ? 'warning' : 'danger') ?>"
                                 style="width:<?= $data['rate'] ?>%;border-radius:4px"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Section Rates + Most Absent -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card metric-tile h-100">
            <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span>
                    <i class="bi bi-people me-2 text-primary"></i>Section Attendance Rates
                </span>
                <div class="d-flex gap-2 flex-wrap">
                    <input type="text"
                           id="sectionSearch"
                           aria-label="Search sections"
                           class="form-control form-control-sm"
                           placeholder="Search section..."
                           style="width:160px">
                    <select id="gradeFilter" aria-label="Filter sections by grade" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Grade Levels</option>
                        <?php foreach (getGradeLevels() as $grade): ?>
                            <option value="<?= sanitize($grade) ?>"><?= sanitize($grade) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="card-body p-0 table-responsive" style="max-height:350px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0" id="sectionTable">
                    <thead class="sticky-thead">
                        <tr>
                            <th>Section</th>
                            <th>Grade</th>
                            <th class="text-center">Present</th>
                            <th class="text-center">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sectionRates as $sr): ?>
                        <tr data-grade="<?= sanitize($sr['grade_level']) ?>"
                            data-section="<?= sanitize($sr['section_name']) ?>">
                            <td class="fw-600 small"><?= sanitize($sr['section_name']) ?></td>
                            <td class="small text-muted"><?= sanitize($sr['grade_level']) ?></td>
                            <td class="text-center small"><?= number_format($sr['attended']) ?></td>
                            <td class="text-center small"><?= number_format($sr['total']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="noSectionResults" style="display:none">
                            <td colspan="4" class="text-center text-muted py-4">
                                No sections match your filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card metric-tile h-100">
            <div class="card-header">
                <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
                Most Absent Students — <?= $year ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Student</th>
                                <th>Grade / Section</th>
                                <th class="text-center">Absences</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mostAbsent)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No absence data.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($mostAbsent as $rank => $row): ?>
                            <tr>
                                <td>
                                    <span class="rank-badge rank-<?= $rank < 3 ? $rank + 1 : 'n' ?>"><?= $rank + 1 ?></span>
                                </td>
                                <td class="fw-600 small">
                                    <?= sanitize($row['last_name'].', '.$row['first_name']) ?>
                                </td>
                                <td class="small text-muted">
                                    <?= sanitize($row['grade_level'].' / '.$row['section_name']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger">
                                        <?= $row['absent_count'] ?>d
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
    </div>
</div>

    </div>
</div>

<?php
// Inject all monthly chart data as a PHP-generated JS object
$chartDataJs = json_encode([
    'full_day'    => $monthlyFullDay,
    'partial'     => $monthlyPartial,
    'absent'      => $monthlyAbsent,
    'topAbsenceReason' => $monthlyTopAbsenceReason,
    'dailyLabels' => $dailyLabels,
    'dailyPresent'=> $dailyPresent,
    'dailyAbsent' => $dailyAbsent,
    'gradeLabels' => $monthlyGradeLabels,
    'gradeValues' => $monthlyGradeValues,
    'monthName'   => $monthNames[$month],
    'year'        => $year,
]);

$extraJS = <<<JS
<script>
// ── Section Attendance Rates filter ──────────────────────────
(function () {
    const searchInput = document.getElementById('sectionSearch');
    const gradeSelect = document.getElementById('gradeFilter');
    const table       = document.getElementById('sectionTable');
    const noResults   = document.getElementById('noSectionResults');

    if (!table) return;

    const rows = table.querySelectorAll('tbody tr[data-section]');

    function applyFilter() {
        const term  = (searchInput.value || '').toLowerCase().trim();
        const grade = gradeSelect.value.toLowerCase();
        let visible = 0;

        rows.forEach(row => {
            const rowSection = (row.dataset.section || '').toLowerCase();
            const rowGrade   = (row.dataset.grade   || '').toLowerCase();

            const matchesSearch = term === '' || rowSection.includes(term);
            const matchesGrade  = grade === '' || rowGrade === grade;

            if (matchesSearch && matchesGrade) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        noResults.style.display = visible === 0 ? '' : 'none';
    }

    searchInput.addEventListener('input', applyFilter);
    gradeSelect.addEventListener('change', applyFilter);
})();

// Monthly charts use Chart.js responsive sizing to reuse instances on resize.
(function () {
    var MONTHLY = {$chartDataJs};

    var COLORS = {
        full_day: 'rgba(14,159,110,0.9)',
        partial:  'rgba(245,158,11,0.9)',
        absent:   'rgba(224,36,36,0.85)'
    };

    function initCharts() {
        if (typeof Chart === 'undefined') return false;

        Chart.defaults.font.family =
            '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#475569';

        // ── Doughnut ─────────────────────────────────────────
        var dCanvas = document.getElementById('monthlyDoughnut');
        if (dCanvas && dCanvas.dataset.init !== '1') {
            var dCtx = dCanvas.getContext('2d');
            if (dCtx) {
                dCanvas.dataset.init = '1';
                new Chart(dCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Full Day', 'Partial', 'Absent'],
                        datasets: [{
                            data: [MONTHLY.full_day, MONTHLY.partial, MONTHLY.absent],
                            backgroundColor: [COLORS.full_day, COLORS.partial, COLORS.absent],
                            borderColor: '#fff',
                            borderWidth: 3,
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 600 },
                        cutout: '62%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { boxWidth: 12, padding: 12, font: { size: 11 } }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                        var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                        var label = ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                                        return ctx.label === 'Absent'
                                            ? [label, 'Top reason: ' + (MONTHLY.topAbsenceReason || 'Not recorded')]
                                            : label;
                                    }
                                }
                            }
                        }
                    }
                });
                console.log('[Monthly] Doughnut ✓');
            }
        }

        // ── Line ─────────────────────────────────────────────
        var lCanvas = document.getElementById('monthlyChart');
        if (lCanvas && lCanvas.dataset.init !== '1') {
            var lCtx = lCanvas.getContext('2d');
            if (lCtx) {
                lCanvas.dataset.init = '1';
                new Chart(lCtx, {
                    type: 'line',
                    data: {
                        labels: MONTHLY.dailyLabels,
                        datasets: [
                            {
                                label: 'Present',
                                data: MONTHLY.dailyPresent,
                                borderColor: COLORS.full_day,
                                backgroundColor: 'rgba(14,159,110,0.15)',
                                fill: true, tension: 0.35,
                                pointRadius: 3, pointHoverRadius: 6,
                                pointBackgroundColor: COLORS.full_day,
                                borderWidth: 2
                            },
                            {
                                label: 'Absent',
                                data: MONTHLY.dailyAbsent,
                                borderColor: COLORS.absent,
                                backgroundColor: 'rgba(224,36,36,0.15)',
                                fill: true, tension: 0.35,
                                pointRadius: 3, pointHoverRadius: 6,
                                pointBackgroundColor: COLORS.absent,
                                borderWidth: 2
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 600 },
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { position: 'top' } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } },
                            x: { title: { display: true, text: 'Day of Month' } }
                        }
                    }
                });
                console.log('[Monthly] Line ✓');
            }
        }

        // ── Bar (per grade) ──────────────────────────────────
        var gCanvas = document.getElementById('monthlyGradeChart');
        if (gCanvas && gCanvas.dataset.init !== '1') {
            var gCtx = gCanvas.getContext('2d');
            if (gCtx) {
                gCanvas.dataset.init = '1';
                new Chart(gCtx, {
                    type: 'bar',
                    data: {
                        labels: MONTHLY.gradeLabels,
                        datasets: [{
                            label: 'Attendance Rate (%)',
                            data: MONTHLY.gradeValues,
                            backgroundColor: MONTHLY.gradeValues.map(function (v) {
                                return v >= 90 ? COLORS.full_day :
                                       v >= 75 ? COLORS.partial :
                                                 COLORS.absent;
                            }),
                            borderRadius: 8,
                            borderWidth: 0,
                            barPercentage: 0.6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 600 },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) { return ctx.parsed.y + '%'; }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true, max: 100,
                                ticks: { callback: function (v) { return v + '%'; } }
                            }
                        }
                    }
                });
                console.log('[Monthly] Bar ✓');
            }
        }
        return true;
    }

    // Expose so the CDN loader can call it as soon as Chart is ready
    window.__initMonthlyCharts = initCharts;

    // Try immediately, then poll (in case CDN still loading)
    if (!initCharts()) {
        var tries = 0;
        var poll = setInterval(function () {
            if (initCharts() || ++tries > 100) clearInterval(poll);
        }, 100);
    }

})();
</script>
JS;
include '../includes/footer.php';
?>
