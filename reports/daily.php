<?php
/**
 * Daily Attendance Report — v2
 * Fixed: empty grade/section handling, v2 column names
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireStaff();
require_once '../includes/enrollment_reports.php';

$pageTitle  = 'Daily Attendance Report';
$db         = getDB();
$date       = $_GET['date']    ?? date('Y-m-d');
$gradeLevel = $_GET['grade']   ?? '';
$sectionId  = (int)($_GET['section'] ?? 0);

$grades          = getGradeLevels();
$reportContext = prepareEnrollmentReport($db, $date, $date, $sectionId ?: null);
$reportStudents = $reportContext['students']; $reportAttendance = $reportContext['attendance']; $reportJoin = $reportContext['join'];
$allowedSections = $reportContext['sections'] ?? getAllowedSections();
$calEntry        = getCalendarEntry($date);

// ── Build WHERE ───────────────────────────────────────────────
$where  = ['a.date = ?', 's.is_active = 1'];
$params = [$date];

// Teacher restriction
if (!isAdmin()) {
    $ids          = array_column($allowedSections, 'id') ?: [0];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[]      = "s.section_id IN ({$placeholders})";
    $params       = array_merge($params, $ids);
}

// Grade filter — only apply if not empty
if (!empty($gradeLevel)) {
    $where[]  = 'sec.grade_level = ?';
    $params[] = $gradeLevel;
}

// Section filter — only apply if > 0
if ($sectionId > 0) {
    $where[]  = 's.section_id = ?';
    $params[] = $sectionId;
}

$whereSQL = implode(' AND ', $where);
$orderSQL = gradeLevelOrderSQL('sec.grade_level');

// ── Records ───────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT a.*,
           s.first_name, s.last_name, s.lrn,
           sec.section_name, sec.grade_level, sec.schedule_type
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$whereSQL}
    ORDER BY {$orderSQL}, sec.section_name, s.last_name, s.first_name
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// ── Summary ───────────────────────────────────────────────────
$summary = [
    'full_day' => 0,
    'partial'  => 0,
    'absent'   => 0,
    'am_late'  => 0,
    'pm_late'  => 0,
];
foreach ($records as $r) {
    if ($r['attendance_type'] === 'full_day') $summary['full_day']++;
    if ($r['attendance_type'] === 'partial')  $summary['partial']++;
    if ($r['attendance_type'] === 'absent')   $summary['absent']++;
    if ($r['am_status'] === 'late')           $summary['am_late']++;
    if ($r['pm_status'] === 'late')           $summary['pm_late']++;
}

// ── Section label for header ──────────────────────────────────
$sectionLabel = '';
if ($sectionId > 0) {
    foreach ($allowedSections as $s) {
        if ($s['id'] === $sectionId) {
            $sectionLabel = $s['grade_level'] . ' — ' . $s['section_name'];
            break;
        }
    }
} elseif (!empty($gradeLevel)) {
    $sectionLabel = $gradeLevel . ' (All Sections)';
} else {
    $sectionLabel = 'All Grades & Sections';
}

include '../includes/header.php';
include '../includes/sidebar.php';
enrollmentReportNotice($reportContext);
?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i class="bi bi-calendar-day me-2 text-primary"></i>Daily Attendance Report
        </h1>
        <p class="page-subtitle"><?= date('l, F j, Y', strtotime($date)) ?></p>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <a href="export_excel.php?date=<?= urlencode($date) ?>&grade=<?= urlencode($gradeLevel) ?>&section=<?= $sectionId ?><?= $reportContext['managed'] ? '&amp;school_year_id='.(int)$reportContext['year']['id'] : '' ?>"
           class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
        </a>
        <a href="index_reports.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <?php if ($reportContext['managed']): ?><input type="hidden" name="school_year_id" value="<?= (int)$reportContext['year']['id'] ?>"><?php endif; ?>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Date</label>
                <input type="date" name="date"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date) ?>">
            </div>
            <?php if (isAdmin()): ?>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Grade</label>
                <select name="grade" class="form-select form-select-sm">
                    <option value="">All Grades</option>
                    <?php foreach ($grades as $g): ?>
                    <option value="<?= $g ?>"
                            <?= $gradeLevel === $g ? 'selected' : '' ?>>
                        <?= $g ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="0">All Sections</option>
                    <?php foreach ($allowedSections as $sec): ?>
                    <option value="<?= $sec['id'] ?>"
                            <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                        <?= sanitize($sec['grade_level'].' — '.$sec['section_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Generate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Report Header (print-friendly) -->
<div class="card mb-3">
    <div class="card-body text-center py-3">
        <h5 class="fw-800 mb-0"><?= sanitize(getSetting('school_name') ?? '') ?></h5>
        <div class="text-muted small">S.Y. <?= ($reportContext['year']['label'] ?? getSetting('school_year')) ?></div>
        <h6 class="fw-700 mt-2 mb-0">Daily Attendance Report</h6>
        <div class="fw-600"><?= date('l, F j, Y', strtotime($date)) ?></div>
        <div class="text-muted small"><?= sanitize($sectionLabel) ?></div>
        <?php if ($calEntry && $calEntry['type'] !== 'school_day'): ?>
        <div class="mt-1">
            <span class="badge bg-<?= entryColor($calEntry['type']) ?>">
                <?= ucfirst(str_replace('_',' ',$calEntry['type'])) ?>:
                <?= sanitize($calEntry['title']) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-2 mb-3">
    <?php foreach ([
        ['Full Day', 'full_day', 'success'],
        ['Partial',  'partial',  'warning'],
        ['Absent',   'absent',   'danger'],
        ['AM Late',  'am_late',  'secondary'],
        ['PM Late',  'pm_late',  'secondary'],
        ['Total',    null,       'primary'],
    ] as [$label,$key,$color]): ?>
    <div class="col">
        <div class="card text-center py-2 border-<?= $color ?>">
            <div class="fw-800 fs-4 text-<?= $color ?>">
                <?= $key ? $summary[$key] : count($records) ?>
            </div>
            <div class="small text-muted"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Attendance Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0"
                   style="font-size:0.78rem">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>LRN</th>
                        <th>Student Name</th>
                        <th>Grade / Section</th>
                        <th class="text-center" style="background:#f0fdf4">Time-In</th>
                        <th class="text-center" style="background:#f0fdf4">Time-Out</th>
                        <th class="text-center" style="background:#f0fdf4">AM</th>
                        <th class="text-center" style="background:#eff6ff">PM</th>
                        <th class="text-center">Type</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                            No attendance records for this date.
                            <?php if ($calEntry && isHolidayOrNoClass($date)): ?>
                            <div class="small mt-1">
                                This date is marked as
                                <strong><?= sanitize($calEntry['title']) ?></strong>.
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($records as $i => $r): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td>
                            <code style="font-size:0.68rem">
                                <?= sanitize($r['lrn']) ?>
                            </code>
                        </td>
                        <td class="fw-600">
                            <?= sanitize($r['last_name'].', '.$r['first_name']) ?>
                        </td>
                        <td class="small">
                            <div class="fw-600"><?= sanitize($r['grade_level'] ?? '—') ?></div>
                            <div class="text-muted"><?= sanitize($r['section_name'] ?? '—') ?></div>
                        </td>

                        <?php $out = $r[getAttendanceEvents($r)[1]]; ?>
                        <td class="text-center small"><?= $r[getAttendanceEvents($r)[0]] ? date('h:i A', strtotime($r[getAttendanceEvents($r)[0]])) : '-' ?></td>
                        <td class="text-center small"><?= $out ? date('h:i A', strtotime($out)) : '-' ?></td>
                        <td class="text-center"><?= getAttendanceScheduleType($r) === 'pm_only' ? 'N/A' : sessionStatusBadge($r['am_status']) ?></td>
                        <td class="text-center"><?= getAttendanceScheduleType($r) === 'am_only' ? 'N/A' : sessionStatusBadge($r['pm_status']) ?></td>
                        <td class="text-center">
                            <?= attendanceTypeBadge($r['attendance_type']) ?>
                        </td>
                        <td class="small text-muted">
                            <?= sanitize($r['remarks'] ?? '') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>

                <!-- Totals footer -->
                <?php if (!empty($records)): ?>
                <tfoot class="table-light fw-700">
                    <tr>
                        <td colspan="4" class="text-end">TOTALS</td>
                        <td colspan="2" class="text-center" style="background:#f0fdf4">
                            AM Late: <?= $summary['am_late'] ?>
                        </td>
                        <td colspan="2" class="text-center" style="background:#eff6ff">
                            PM Late: <?= $summary['pm_late'] ?>
                        </td>
                        <td class="text-center">
                            FD: <?= $summary['full_day'] ?> |
                            P: <?= $summary['partial'] ?> |
                            A: <?= $summary['absent'] ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Signature block -->
<div class="row mt-4 no-print">
    <div class="col-md-4">
        <div class="border-top pt-2 mt-5 text-center" style="width:220px">
            <div class="fw-600 small">Prepared by</div>
            <div class="text-muted small">Class Adviser</div>
        </div>
    </div>
    <div class="col-md-4 offset-md-4 text-end">
        <div class="border-top pt-2 mt-5 text-center ms-auto" style="width:220px">
            <div class="fw-600 small">Noted by</div>
            <div class="text-muted small">School Principal</div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
