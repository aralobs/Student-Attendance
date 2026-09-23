<?php
/**
 * AM vs PM Attendance Report
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle       = 'AM / PM Report';
$db              = getDB();
$month           = (int)($_GET['month']   ?? date('n'));
$year            = (int)($_GET['year']    ?? date('Y'));
$gradeLevel      = $_GET['grade']         ?? '';
$allowedSections = getAllowedSections();
$grades          = getGradeLevels();

// School days
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$schoolDays  = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow = (int)date('N', mktime(0,0,0,$month,$d,$year));
    if (!in_array($dow,[6,7]) && !isHolidayOrNoClass($ds)) $schoolDays++;
}

// Stats per section
$sectionStats = [];
foreach ($allowedSections as $sec) {
    if (!empty($gradeLevel) && $sec['grade_level'] !== $gradeLevel) continue;
    if ($sec['schedule_type'] === 'am_only') {
        // Only AM
        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT s.id)           AS total_students,
                SUM(a.am_status = 'present')   AS am_present,
                SUM(a.am_status = 'late')      AS am_late,
                SUM(a.am_status = 'absent')    AS am_absent,
                0 AS pm_present, 0 AS pm_late, 0 AS pm_absent
            FROM students s
            LEFT JOIN attendance a ON a.student_id = s.id
                AND MONTH(a.date) = ? AND YEAR(a.date) = ?
            WHERE s.section_id = ? AND s.is_active = 1
        ");
    } elseif ($sec['schedule_type'] === 'pm_only') {
        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT s.id)           AS total_students,
                0 AS am_present, 0 AS am_late, 0 AS am_absent,
                SUM(a.pm_status = 'present')   AS pm_present,
                SUM(a.pm_status = 'late')      AS pm_late,
                SUM(a.pm_status = 'absent')    AS pm_absent
            FROM students s
            LEFT JOIN attendance a ON a.student_id = s.id
                AND MONTH(a.date) = ? AND YEAR(a.date) = ?
            WHERE s.section_id = ? AND s.is_active = 1
        ");
    } else {
        $stmt = $db->prepare("
            SELECT
                COUNT(DISTINCT s.id)           AS total_students,
                SUM(a.am_status = 'present')   AS am_present,
                SUM(a.am_status = 'late')      AS am_late,
                SUM(a.am_status = 'absent')    AS am_absent,
                SUM(a.pm_status = 'present')   AS pm_present,
                SUM(a.pm_status = 'late')      AS pm_late,
                SUM(a.pm_status = 'absent')    AS pm_absent
            FROM students s
            LEFT JOIN attendance a ON a.student_id = s.id
                AND MONTH(a.date) = ? AND YEAR(a.date) = ?
            WHERE s.section_id = ? AND s.is_active = 1
        ");
    }
    $stmt->execute([$month, $year, $sec['id']]);
    $row = $stmt->fetch();
    $sectionStats[] = array_merge($sec, $row);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-sun me-2 text-warning"></i>AM / PM Report
        </h1>
        <p class="page-subtitle">
            <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?>
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
            <?php if (isAdmin()): ?>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Grade</label>
                <select name="grade" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach($grades as $g): ?>
                    <option value="<?=$g?>" <?=$gradeLevel===$g?'selected':''?>><?=$g?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Generate</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body text-center py-2">
        <h5 class="fw-800 mb-0"><?= sanitize(getSetting('school_name') ?? '') ?></h5>
        <h6 class="fw-700 mt-1 mb-0">AM / PM Attendance Comparison</h6>
        <div class="small"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0" style="font-size:0.8rem">
                <thead>
                    <tr>
                        <th rowspan="2">Grade</th>
                        <th rowspan="2">Section</th>
                        <th rowspan="2">Schedule</th>
                        <th rowspan="2" class="text-center">Students</th>
                        <th colspan="3" class="text-center"
                            style="background:#f0fdf4">☀️ AM Session</th>
                        <th colspan="3" class="text-center"
                            style="background:#eff6ff">🌙 PM Session</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="background:#f0fdf4">Present</th>
                        <th class="text-center" style="background:#f0fdf4">Late</th>
                        <th class="text-center" style="background:#f0fdf4">Absent</th>
                        <th class="text-center" style="background:#eff6ff">Present</th>
                        <th class="text-center" style="background:#eff6ff">Late</th>
                        <th class="text-center" style="background:#eff6ff">Absent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sectionStats)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            No data found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($sectionStats as $s): ?>
                    <tr>
                        <td class="small fw-600"><?= sanitize($s['grade_level']) ?></td>
                        <td class="fw-600"><?= sanitize($s['section_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $s['schedule_type']==='full_day'?'info':($s['schedule_type']==='am_only'?'success':'warning') ?> bg-opacity-75 small">
                                <?= ucfirst(str_replace('_',' ',$s['schedule_type'])) ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $s['total_students'] ?></td>

                        <!-- AM -->
                        <?php if ($s['schedule_type'] === 'pm_only'): ?>
                        <td colspan="3" class="text-center text-muted small"
                            style="background:#f9f9f9">PM Only</td>
                        <?php else: ?>
                        <td class="text-center" style="background:#fafffe">
                            <span class="badge bg-success bg-opacity-75">
                                <?= $s['am_present'] ?? 0 ?>
                            </span>
                        </td>
                        <td class="text-center" style="background:#fafffe">
                            <span class="badge bg-warning text-dark">
                                <?= $s['am_late'] ?? 0 ?>
                            </span>
                        </td>
                        <td class="text-center" style="background:#fafffe">
                            <span class="badge bg-danger bg-opacity-75">
                                <?= $s['am_absent'] ?? 0 ?>
                            </span>
                        </td>
                        <?php endif; ?>

                        <!-- PM -->
                        <?php if ($s['schedule_type'] === 'am_only'): ?>
                        <td colspan="3" class="text-center text-muted small"
                            style="background:#f9f9f9">AM Only</td>
                        <?php else: ?>
                        <td class="text-center" style="background:#f5f8ff">
                            <span class="badge bg-success bg-opacity-75">
                                <?= $s['pm_present'] ?? 0 ?>
                            </span>
                        </td>
                        <td class="text-center" style="background:#f5f8ff">
                            <span class="badge bg-warning text-dark">
                                <?= $s['pm_late'] ?? 0 ?>
                            </span>
                        </td>
                        <td class="text-center" style="background:#f5f8ff">
                            <span class="badge bg-danger bg-opacity-75">
                                <?= $s['pm_absent'] ?? 0 ?>
                            </span>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>