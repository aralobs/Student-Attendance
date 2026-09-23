<?php
/**
 * Attendance by Section
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle       = 'By Section';
$db              = getDB();
$month           = (int)($_GET['month']   ?? date('n'));
$year            = (int)($_GET['year']    ?? date('Y'));
$gradeFilter     = $_GET['grade']         ?? '';
$allowedSections = getAllowedSections();
$grades          = getGradeLevels();

// School days this month
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
    if (!empty($gradeFilter) && $sec['grade_level'] !== $gradeFilter) continue;

    $stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT s.id)                AS total_students,
            SUM(a.attendance_type = 'full_day') AS full_day,
            SUM(a.attendance_type = 'partial')  AS partial,
            SUM(a.attendance_type = 'absent')   AS absent,
            SUM(a.am_status = 'late')           AS am_late,
            SUM(a.pm_status = 'late')           AS pm_late
        FROM students s
        LEFT JOIN attendance a ON a.student_id = s.id
            AND MONTH(a.date) = ? AND YEAR(a.date) = ?
        WHERE s.section_id = ? AND s.is_active = 1
    ");
    $stmt->execute([$month, $year, $sec['id']]);
    $row = $stmt->fetch();

    $attended = ($row['full_day'] ?? 0) + (($row['partial'] ?? 0) * 0.5);
    $possible = ($row['total_students'] ?? 0) * $schoolDays;
    $rate     = $possible > 0 ? round(($attended / $possible) * 100, 1) : 0;

    $sectionStats[] = array_merge($sec, $row, ['rate' => $rate]);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-people me-2 text-primary"></i>By Section
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
            <div class="col-md-2">
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
                    <option value="">All Grades</option>
                    <?php foreach($grades as $g): ?>
                    <option value="<?=$g?>" <?=$gradeFilter===$g?'selected':''?>><?=$g?></option>
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
        <h6 class="fw-700 mt-1 mb-0">Attendance by Section</h6>
        <div class="small"><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?> — <?= $schoolDays ?> school days</div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0" style="font-size:0.82rem">
                <thead class="table-light">
                    <tr>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Schedule</th>
                        <th class="text-center">Students</th>
                        <th class="text-center" style="background:#f0fdf4">Full Day</th>
                        <th class="text-center" style="background:#fef3c7">Partial</th>
                        <th class="text-center" style="background:#fee2e2">Absent</th>
                        <th class="text-center">AM Late</th>
                        <th class="text-center">PM Late</th>
                        <th class="text-center">Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sectionStats)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">No data.</td>
                    </tr>
                    <?php else: ?>
                    <?php
                    $currentGrade = '';
                    foreach ($sectionStats as $s):
                        if ($s['grade_level'] !== $currentGrade):
                            $currentGrade = $s['grade_level'];
                    ?>
                    <tr class="table-light">
                        <td colspan="10" class="fw-700 small py-1">
                            <i class="bi bi-diagram-3 me-1"></i>
                            <?= sanitize($currentGrade) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted small"><?= sanitize($s['grade_level']) ?></td>
                        <td class="fw-600"><?= sanitize($s['section_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $s['schedule_type']==='full_day'?'info':($s['schedule_type']==='am_only'?'success':'warning') ?> bg-opacity-75">
                                <?= ucfirst(str_replace('_',' ',$s['schedule_type'])) ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $s['total_students'] ?></td>
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
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>