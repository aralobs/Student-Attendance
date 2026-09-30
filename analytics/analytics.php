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
requireLogin();

$pageTitle = 'Analytics';
$db        = getDB();
$year      = (int)($_GET['year'] ?? date('Y'));

// Endpoint URL — resolved by PHP so it works regardless of folder moves.
$endpointUrl = dirname(dirname($_SERVER['SCRIPT_NAME']))
             . '/attendance/get_today_log.php';

// Build section filter for teachers
$sectionFilter = '';
$sectionParams = [];
if (!isAdmin()) {
    $user            = currentUser();
    $allowedSections = getAllowedSections();
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
    FROM attendance a
    JOIN students s ON a.student_id = s.id
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
    FROM attendance a
    JOIN students s ON a.student_id = s.id
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
        LEFT JOIN students s  ON s.section_id  = sec.id AND s.is_active = 1
        LEFT JOIN attendance a ON a.student_id = s.id AND YEAR(a.date) = ?
        WHERE sec.grade_level = ?
    ");
    $stmt->execute([$year, $grade]);
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
$allowedSecs  = getAllowedSections();
$sectionRates = [];
foreach ($allowedSecs as $sec) {
    $stmt = $db->prepare("
        SELECT
            COUNT(a.id)                                      AS total,
            SUM(a.attendance_type IN ('full_day','partial')) AS attended
        FROM students s
        LEFT JOIN attendance a ON a.student_id = s.id AND YEAR(a.date) = ?
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

include '../includes/header.php';
include '../includes/sidebar.php';
?>

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
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-list-check me-2 text-primary"></i>
                    Today's Log — <span id="todayLogDate"><?= date('F j, Y') ?></span>
                    <span id="todayLogCount" class="badge bg-primary ms-2">—</span>
                </span>
                <div class="d-flex gap-2 align-items-center">
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
                        <option value="pending">Pending</option>
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
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Full Day</div>
                                    <div class="fw-bold fs-5 text-success" id="todayStatFull">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Partial</div>
                                    <div class="fw-bold fs-5 text-warning" id="todayStatPartial">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Absent</div>
                                    <div class="fw-bold fs-5 text-danger" id="todayStatAbsent">0</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small text-uppercase fw-600">Pending</div>
                                    <div class="fw-bold fs-5 text-secondary" id="todayStatPending">0</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-body p-0" style="max-height:480px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0" id="todayLogTable">
                    <thead class="sticky-top bg-white" style="z-index:1">
                        <tr>
                            <th>Student</th>
                            <th class="text-center">AM In</th>
                            <th class="text-center">AM Out</th>
                            <th class="text-center">PM In</th>
                            <th class="text-center">PM Out</th>
                            <th class="text-center">Type</th>
                        </tr>
                    </thead>
                    <tbody id="todayLogBody">
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <span class="spinner-border spinner-border-sm me-2"></span>
                                Loading today's log...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small d-flex justify-content-between">
                <span id="todayLogUpdated">—</span>
                <span>Showing all students recorded today.</span>
            </div>
        </div>
    </div>
</div>

<!-- Grade Level Rates (full width) -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card h-100">
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
        <div class="card h-100">
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
                    <thead class="sticky-top bg-white">
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
        <div class="card h-100">
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
    const statPending = document.getElementById('todayStatPending');
    const chartCanvas = document.getElementById('todayLogChart');

    // Key Metrics row
    const kpiRate    = document.getElementById('kpiRate');
    const kpiFullDay = document.getElementById('kpiFullDay');
    const kpiPartial = document.getElementById('kpiPartial');
    const kpiAbsent  = document.getElementById('kpiAbsent');
    const kpiAmLate  = document.getElementById('kpiAmLate');
    const kpiPmLate  = document.getElementById('kpiPmLate');

    if (!tbody) return;

    const ENDPOINT = {$endpointJs};
    let rows = [];
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
        if (statPending) statPending.textContent = stats.pending;
    }

    function updateChart(stats) {
        const data = {
            labels: ['Full Day', 'Partial', 'Absent', 'Pending'],
            datasets: [{
                data: [
                    stats.full_day,
                    stats.partial,
                    stats.absent,
                    stats.pending
                ],
                backgroundColor: [
                    'rgba(14,159,110,0.85)',
                    'rgba(245,158,11,0.85)',
                    'rgba(224,36,36,0.75)',
                    'rgba(156,163,175,0.75)'
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

        const filtered = rows.filter(r => {
            const matchesTerm =
                term === '' ||
                (r.name    || '').toLowerCase().includes(term) ||
                (r.lrn     || '').toLowerCase().includes(term) ||
                (r.section || '').toLowerCase().includes(term);
            const matchesGrade = grade === '' || r.grade === grade;
            const matchesType  = type  === '' || r.attendance_type === type;
            return matchesTerm && matchesGrade && matchesType;
        });

        countEl.textContent = filtered.length + ' student' +
                              (filtered.length === 1 ? '' : 's');

        // Compute once, feed everyone
        const stats = computeStats(filtered);
        updateKeyMetrics(stats);
        updateChartTiles(stats);
        updateChart(stats);

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">' +
                '<i class="bi bi-inbox me-1"></i>No students match your filter.</td></tr>';
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
              '<td class="text-center">' + typeBadge(r.attendance_type) + '</td>' +
            '</tr>'
        )).join('');
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>Loading today\\'s log...</td></tr>';

        try {
            const res = await fetch(ENDPOINT, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const all = await res.json();
            if (!Array.isArray(all)) throw new Error('Invalid response');

            rows = all;

            render();
            updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">' +
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
    setInterval(load, 60000);
})();
</script>
JS;
include '../includes/footer.php';
?>