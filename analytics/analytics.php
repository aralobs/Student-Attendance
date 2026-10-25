<?php
/**
 * Attendance Analytics Dashboard v2
 * Uses am_status, pm_status, attendance_type columns
 *
 * Key Metrics row is driven by TODAY's log (via get_today_log.php),
 * not by yearly totals.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireStaff();
require_once '../includes/enrollment_reports.php';

$pageTitle = 'Analytics';
$db        = getDB();
$year      = (int)($_GET['year'] ?? date('Y'));

$reportContext = prepareEnrollmentReport($db,sprintf('%04d-01-01',$year),sprintf('%04d-12-31',$year),null,true);
$reportStudents = $reportContext['students']; $reportAttendance = $reportContext['attendance']; $reportJoin = $reportContext['join'];

// Endpoint URL — relative from /analytics/ so it works in any deployment.
$endpointUrl = '../attendance/get_today_log.php';

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
$month = (int)($_GET['month'] ?? date('n'));
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

<style>
/* ═══ ENHANCED STYLING ═══════════════════════════════════════ */
.analytics-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    transition: box-shadow 0.2s ease;
}
.analytics-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,0.1); }

.analytics-card .card-header {
    background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
    border-bottom: 1px solid #e5e9f0;
    border-radius: 14px 14px 0 0 !important;
    font-weight: 600;
    padding: 0.9rem 1.15rem;
}

.metric-tile {
    border: 1px solid #e5e9f0;
    border-radius: 12px;
    background: #fff;
    transition: all 0.2s ease;
}
.metric-tile:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.metric-tile .metric-label {
    font-size: 0.7rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #6b7280;
    font-weight: 600;
}
.metric-tile .metric-value {
    font-size: 1.4rem;
    font-weight: 700;
}

/* ── Canvas wrapper: fixed height + relative positioning ── */
.chart-wrapper {
    position: relative;
    width: 100%;
    min-height: 240px;
}
.chart-wrapper canvas {
    display: block !important;
    width: 100% !important;
    height: 100% !important;
}

.grade-progress-item {
    padding: 0.6rem 0.75rem;
    border-radius: 10px;
    transition: background 0.15s ease;
}
.grade-progress-item:hover { background: #f8fafc; }

.month-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    font-weight: 700;
    font-size: 0.75rem;
    color: #fff;
}
.rank-1 { background: linear-gradient(135deg, #dc2626, #ef4444); }
.rank-2 { background: linear-gradient(135deg, #ea580c, #f97316); }
.rank-3 { background: linear-gradient(135deg, #ca8a04, #eab308); }
.rank-n { background: #94a3b8; }

.section-divider {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 0.5rem 0 1rem;
    color: #64748b;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}
.section-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, #e2e8f0, transparent);
}

.sticky-thead th {
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
    box-shadow: 0 1px 0 #e5e9f0;
}
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-bar-chart-fill me-2 text-primary"></i>Analytics
        </h1>
        <p class="page-subtitle">
            Attendance statistics and insights — <?= $year ?>
            <span class="text-muted small ms-2">
                (Key Metrics reflect <strong>today</strong>)
            </span>
        </p>
    </div>
    <form method="GET" class="d-flex gap-2">
            <?php if ($reportContext['managed']): ?><input type="hidden" name="school_year_id" value="<?= (int)$reportContext['year']['id'] ?>"><?php endif; ?>
        <input type="hidden" name="month" value="<?= $month ?>">
        <select name="year" class="form-select form-select-sm" style="width:auto">
            <?php for ($y = 2024; $y <= date('Y') + 1; $y++): ?>
            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </form>
</div>

<!-- ══════════════════════════════════════════════════════════════
     KEY METRICS — driven by TODAY's log (populated via JS)
     ══════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card green py-2 h-100">
            <div class="stat-icon green flex-shrink-0"
                 style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-graph-up-arrow"></i>
            </div>
            <div class="min-w-0">
                <div class="stat-number text-truncate"
                     style="font-size:1.4rem"
                     id="kpiRate">—</div>
                <div class="stat-label text-truncate">Attend. Rate</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card blue py-2 h-100">
            <div class="stat-icon blue flex-shrink-0"
                 style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="min-w-0">
                <div class="stat-number text-truncate"
                     style="font-size:1.4rem"
                     id="kpiFullDay">—</div>
                <div class="stat-label text-truncate">Full Day</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card orange py-2 h-100">
            <div class="stat-icon orange flex-shrink-0"
                 style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="min-w-0">
                <div class="stat-number text-truncate"
                     style="font-size:1.4rem"
                     id="kpiPartial">—</div>
                <div class="stat-label text-truncate">Partial</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card red py-2 h-100">
            <div class="stat-icon red flex-shrink-0"
                 style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="min-w-0">
                <div class="stat-number text-truncate"
                     style="font-size:1.4rem"
                     id="kpiAbsent">—</div>
                <div class="stat-label text-truncate">Absent</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card orange py-2 h-100">
            <div class="stat-icon orange flex-shrink-0"
                 style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-sun"></i>
            </div>
            <div class="min-w-0">
                <div class="stat-number text-truncate"
                     style="font-size:1.4rem"
                     id="kpiAmLate">—</div>
                <div class="stat-label text-truncate">AM Late</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="stat-card orange py-2 h-100">
            <div class="stat-icon orange flex-shrink-0"
                 style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-moon"></i>
            </div>
            <div class="min-w-0">
                <div class="stat-number text-truncate"
                     style="font-size:1.4rem"
                     id="kpiPmLate">—</div>
                <div class="stat-label text-truncate">PM Late</div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     Total Present / Total Absent Summary (still YEAR-based)
     ══════════════════════════════════════════════════════════════ -->


<!-- Present / Absent ratio bar (YEAR) -->


<!-- ══════════════════════════════════════════════════════════════
     TODAY'S LOG — chart + table, drives Key Metrics
     ══════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card analytics-card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-list-check me-2 text-primary"></i>
                    Today's Log — <span id="todayLogDate"><?= date('F j, Y') ?></span>
                    <span id="todayLogCount" class="badge bg-primary ms-2">—</span>
                </span>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select id="todayLogGrade" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Grade Levels</option>
                        <?php foreach (getGradeLevels() as $grade): ?>
                            <option value="<?= sanitize($grade) ?>"><?= sanitize($grade) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="todayLogType" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Types</option>
                        <option value="full_day">Full Day</option>
                        <option value="partial">Partial</option>
                        <option value="absent">Absent</option>
                    </select>
                    <input type="text"
                           id="todayLogSearch"
                           class="form-control form-control-sm"
                           placeholder="Search name / LRN / section..."
                           style="width:220px">
                    <button id="todayLogRefresh" class="btn btn-sm btn-outline-primary" title="Refresh">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>

            <!-- Embedded breakdown chart -->
            <div class="card-body pb-2">
                <div class="row g-3 align-items-center">
                    <div class="col-md-7">
                        <div style="height:220px">
                            <canvas id="todayLogChart"></canvas>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="metric-tile p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Full Day</div>
                                    <div class="fw-bold fs-5 text-success" id="todayStatFull">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-tile p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Partial</div>
                                    <div class="fw-bold fs-5 text-warning" id="todayStatPartial">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-tile p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Absent</div>
                                    <div class="fw-bold fs-5 text-danger" id="todayStatAbsent">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="metric-tile p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Scanned</div>
                                    <div class="fw-bold fs-5 text-secondary" id="todayStatScanned">0</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-body p-0" style="max-height:480px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0" id="todayLogTable">
                    <thead class="sticky-thead">
                        <tr>
                            <th>Student</th>
                            <th class="text-center">AM In</th>
                            <th class="text-center">AM Out</th>
                            <th class="text-center">PM In</th>
                            <th class="text-center">PM Out</th>
                            <th>Last Event</th>
                            <th class="text-center">Type</th>
                        </tr>
                    </thead>
                    <tbody id="todayLogBody">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <span class="spinner-border spinner-border-sm me-2"></span>
                                Loading today's log...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small d-flex justify-content-between">
                <span id="todayLogUpdated">—</span>
                <span>Showing students scanned today.</span>
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

<div class="section-divider"><i class="bi bi-calendar-range"></i> Year Overview — <?= $year ?></div>
<!-- Grade Level Rates (full width) -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card analytics-card h-100">
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
        <div class="card analytics-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span>
                    <i class="bi bi-people me-2 text-primary"></i>Section Attendance Rates
                </span>
                <div class="d-flex gap-2">
                    <input type="text"
                           id="sectionSearch"
                           class="form-control form-control-sm"
                           placeholder="Search section..."
                           style="width:160px">
                    <select id="gradeFilter" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Grade Levels</option>
                        <?php foreach (getGradeLevels() as $grade): ?>
                            <option value="<?= sanitize($grade) ?>"><?= sanitize($grade) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="card-body p-0" style="max-height:350px;overflow-y:auto">
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
        <div class="card analytics-card h-100">
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
                                    <?php if ($rank === 0): ?>
                                    <span class="badge bg-danger">#1</span>
                                    <?php elseif ($rank === 1): ?>
                                    <span class="badge bg-warning text-dark">#2</span>
                                    <?php elseif ($rank === 2): ?>
                                    <span class="badge bg-secondary">#3</span>
                                    <?php else: ?>
                                    <span class="text-muted small">#<?= $rank + 1 ?></span>
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

<?php
$endpointJs = json_encode($endpointUrl);

// Inject all monthly chart data as a PHP-generated JS object
$chartDataJs = json_encode([
    'full_day'    => $monthlyFullDay,
    'partial'     => $monthlyPartial,
    'absent'      => $monthlyAbsent,
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

// ── Today's Log (drives Key Metrics + embedded chart) ────────
(function () {
    const tbody      = document.getElementById('todayLogBody');
    const searchBox  = document.getElementById('todayLogSearch');
    const gradeSel   = document.getElementById('todayLogGrade');
    const typeSel    = document.getElementById('todayLogType');
    const refreshBtn = document.getElementById('todayLogRefresh');
    const updatedEl  = document.getElementById('todayLogUpdated');
    const countEl    = document.getElementById('todayLogCount');

    // Embedded chart tiles
    const statFull    = document.getElementById('todayStatFull');
    const statPartial = document.getElementById('todayStatPartial');
    const statAbsent  = document.getElementById('todayStatAbsent');
    const statScanned = document.getElementById('todayStatScanned');
    const chartCanvas = document.getElementById('todayLogChart');

    // Key Metrics row
    const kpiRate    = document.getElementById('kpiRate');
    const kpiFullDay = document.getElementById('kpiFullDay');
    const kpiPartial = document.getElementById('kpiPartial');
    const kpiAbsent  = document.getElementById('kpiAbsent');
    const kpiAmLate  = document.getElementById('kpiAmLate');
    const kpiPmLate  = document.getElementById('kpiPmLate');

    if (!tbody) return;

    const METRICS_ENDPOINT = {$endpointJs};
    const ENDPOINT = METRICS_ENDPOINT + '?scans_only=1';
    let rows = [];
    let metricsRows = [];
    let chart = null;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => (
            { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
        ));
    }

    function fmtCell(time, status) {
        if (!time) {
            if (status === 'absent') {
                return '<span class="badge bg-danger" style="font-size:0.6rem">Absent</span>';
            }
            return '<span class="text-muted">—</span>';
        }
        const late = (status === 'late')
            ? ' <span class="badge bg-warning text-dark" style="font-size:0.6rem">Late</span>'
            : '';
        return esc(time) + late;
    }

    function typeBadge(type) {
        const map = {
            full_day: 'success',
            partial:  'warning text-dark',
            absent:   'danger',
            pending:  'secondary'
        };
        const cls = map[type] || 'secondary';
        const label = (type || '—').replace('_', ' ');
        return '<span class="badge bg-' + cls + '" style="font-size:0.65rem">' +
               esc(label) + '</span>';
    }

    function rowClass(type) {
        if (type === 'absent')  return 'table-danger';
        if (type === 'pending') return 'table-light';
        return '';
    }

    function lastEventCell(r) {
        const labels = {am_in: 'AM In', am_out: 'AM Out', pm_in: 'PM In', pm_out: 'PM Out'};
        return '<span class="badge bg-secondary">' + esc(labels[r.last_event] || '') + '</span>' +
               '<div class="text-muted small">' + esc(r.last_event_time || '') + '</div>';
    }

    // ── Compute today's stats ────────────────────────────────
    function computeStats(list) {
        const s = {
            total:    0,
            present:  0,   // full_day + partial
            full_day: 0,
            partial:  0,
            absent:   0,
            pending:  0,
            am_late:  0,
            pm_late:  0
        };

        list.forEach(r => {
            s.total++;

            const t = r.attendance_type || 'pending';
            if (t === 'full_day')      { s.full_day++; s.present++; }
            else if (t === 'partial')  { s.partial++;  s.present++; }
            else if (t === 'absent')   { s.absent++; }
            else                       { s.pending++; }

            if (r.am_status === 'late') s.am_late++;
            if (r.pm_status === 'late') s.pm_late++;
        });

        return s;
    }

    function updateKeyMetrics(stats) {
        const rate = stats.total > 0
            ? ((stats.present / stats.total) * 100).toFixed(1)
            : '0.0';

        if (kpiRate)    kpiRate.textContent    = rate + '%';
        if (kpiFullDay) kpiFullDay.textContent = stats.full_day;
        if (kpiPartial) kpiPartial.textContent = stats.partial;
        if (kpiAbsent)  kpiAbsent.textContent  = stats.absent;
        if (kpiAmLate)  kpiAmLate.textContent  = stats.am_late;
        if (kpiPmLate)  kpiPmLate.textContent  = stats.pm_late;
    }

    function updateChartTiles(stats) {
        if (statFull)    statFull.textContent    = stats.full_day;
        if (statPartial) statPartial.textContent = stats.partial;
        if (statAbsent)  statAbsent.textContent  = stats.absent;
        if (statScanned) statScanned.textContent = stats.total;
    }

    function updateChart(stats) {
        const data = {
            labels: ['Full Day', 'Partial', 'Absent'],
            datasets: [{
                data: [
                    stats.full_day,
                    stats.partial,
                    stats.absent
                ],
                backgroundColor: [
                    'rgba(14,159,110,0.85)',
                    'rgba(245,158,11,0.85)',
                    'rgba(224,36,36,0.75)'
                ],
                borderWidth: 0
            }]
        };

        if (chart) {
            chart.data = data;
            chart.update();
        } else if (chartCanvas) {
            chart = new Chart(chartCanvas.getContext('2d'), {
                type: 'bar',
                data: data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: {
                            display: true,
                            text: "Today's Attendance Breakdown",
                            font: { size: 13 }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, stepSize: 1 }
                        }
                    }
                }
            });
        }
    }

    function render() {
        const term  = (searchBox.value || '').toLowerCase().trim();
        const grade = gradeSel.value;
        const type  = typeSel.value;

        const matchesFilters = r => {
            const matchesTerm =
                term === '' ||
                (r.name    || '').toLowerCase().includes(term) ||
                (r.lrn     || '').toLowerCase().includes(term) ||
                (r.section || '').toLowerCase().includes(term);
            const matchesGrade = grade === '' || r.grade === grade;
            const matchesType  = type  === '' || r.attendance_type === type;
            return matchesTerm && matchesGrade && matchesType;
        };
        const filtered = rows.filter(matchesFilters);

        countEl.textContent = filtered.length + ' student' +
                              (filtered.length === 1 ? '' : 's');

        // Class metrics include unscanned learners; log summaries include scans only.
        const stats = computeStats(filtered);
        updateKeyMetrics(computeStats(metricsRows.filter(matchesFilters)));
        updateChartTiles(stats);
        updateChart(stats);

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
                '<i class="bi bi-inbox me-1"></i>' + (rows.length ? 'No scans match your filter.' : 'No scans today.') + '</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(r => (
            '<tr class="' + rowClass(r.attendance_type) + '">' +
              '<td>' +
                '<div class="fw-600 small">' + esc(r.name) + '</div>' +
                '<div class="text-muted" style="font-size:0.7rem">' +
                    esc(r.grade) + ' — ' + esc(r.section) +
                '</div>' +
              '</td>' +
              '<td class="text-center small">' + fmtCell(r.am_in,  r.am_status) + '</td>' +
              '<td class="text-center small">' + fmtCell(r.am_out, null)         + '</td>' +
              '<td class="text-center small">' + fmtCell(r.pm_in,  r.pm_status) + '</td>' +
              '<td class="text-center small">' + fmtCell(r.pm_out, null)         + '</td>' +
              '<td>' + lastEventCell(r) + '</td>' +
              '<td class="text-center">' + typeBadge(r.attendance_type) + '</td>' +
            '</tr>'
        )).join('');
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>Loading today\\'s log...</td></tr>';

        try {
            const responses = await Promise.all([ENDPOINT, METRICS_ENDPOINT].map(url => fetch(url, {credentials: 'same-origin'})));
            for (const res of responses) if (!res.ok) throw new Error('HTTP ' + res.status);
            const [scans, all] = await Promise.all(responses.map(res => res.json()));
            if (!Array.isArray(scans) || !Array.isArray(all)) throw new Error('Invalid response');
            rows = scans;
            metricsRows = all;

            render();
            updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">' +
                'Failed to load today\\'s log: ' + esc(err.message) + '</td></tr>';
            countEl.textContent = '—';

            // Clear metrics on failure
            ['kpiRate','kpiFullDay','kpiPartial','kpiAbsent','kpiAmLate','kpiPmLate']
                .forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = '—';
                });
        }
    }

    searchBox.addEventListener('input', render);
    gradeSel.addEventListener('change', render);
    typeSel.addEventListener('change', render);
    refreshBtn.addEventListener('click', load);

    load();
    setInterval(load, 30000);
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
                                        return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
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
