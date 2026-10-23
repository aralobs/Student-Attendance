<?php
/**
 * Partial Attendance Report
 * Students who attended AM only or PM only on selected date/range
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireStaff();
require_once '../includes/enrollment_reports.php';

$pageTitle       = 'Partial Attendance';
$db              = getDB();
$dateFrom        = $_GET['date_from']  ?? date('Y-m-d');
$dateTo          = $_GET['date_to']    ?? date('Y-m-d');
$sectionId       = (int)($_GET['section'] ?? 0);
$gradeLevel      = $_GET['grade']         ?? '';
$reportContext = prepareEnrollmentReport($db, $dateFrom, $dateTo, $sectionId ?: null);
$reportStudents = $reportContext['students']; $reportAttendance = $reportContext['attendance']; $reportJoin = $reportContext['join'];
$allowedSections = $reportContext['sections'] ?? getAllowedSections();
$grades          = getGradeLevels();

$where  = ["a.date BETWEEN ? AND ?", "a.attendance_type = 'partial'", "s.is_active = 1"];
$params = [$dateFrom, $dateTo];

if (!isAdmin()) {
    $ids          = array_column($allowedSections,'id') ?: [0];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[]      = "s.section_id IN ({$placeholders})";
    $params       = array_merge($params, $ids);
}
if (!empty($gradeLevel)) { $where[] = 'sec.grade_level = ?'; $params[] = $gradeLevel; }
if ($sectionId > 0)      { $where[] = 's.section_id = ?';   $params[] = $sectionId; }

$whereSQL = implode(' AND ', $where);
$orderSQL = gradeLevelOrderSQL('sec.grade_level');

$records = $db->prepare("
    SELECT a.date,
           a.am_in, a.am_out, a.am_status,
           a.pm_in, a.pm_out, a.pm_status,
           a.attendance_type, a.remarks,
           s.first_name, s.last_name, s.lrn, s.photo,
           sec.grade_level, sec.section_name, sec.schedule_type
    FROM {$reportAttendance} a
    JOIN {$reportStudents} s ON a.student_id = s.id {$reportJoin}
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$whereSQL}
    ORDER BY a.date DESC, {$orderSQL}, s.last_name
");
$records->execute($params);
$records = $records->fetchAll();

// Breakdown: AM only vs PM only
$amOnly = array_filter($records, fn($r) => $r[getAttendanceEvents($r)[0]] && empty($r[getAttendanceEvents($r)[1]]));
$pmOnly = array_filter($records, fn($r) => !$r[getAttendanceEvents($r)[0]] && !empty($r[getAttendanceEvents($r)[1]]));

include '../includes/header.php';
include '../includes/sidebar.php';
enrollmentReportNotice($reportContext);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-clock-history me-2 text-warning"></i>Partial Attendance
        </h1>
        <p class="page-subtitle">Students with an incomplete daily Time-In / Time-Out pair</p>
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
            <?php if ($reportContext['managed']): ?><input type="hidden" name="school_year_id" value="<?= (int)$reportContext['year']['id'] ?>"><?php endif; ?>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo) ?>">
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
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach($allowedSections as $s): ?>
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

<!-- Summary -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card orange py-2">
            <div class="stat-icon orange" style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <div class="stat-number" style="font-size:1.4rem"><?= count($records) ?></div>
                <div class="stat-label">Total Partial</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card green py-2">
            <div class="stat-icon green" style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-sun"></i>
            </div>
            <div>
                <div class="stat-number" style="font-size:1.4rem"><?= count($amOnly) ?></div>
                <div class="stat-label">Missing Time-Out</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card blue py-2">
            <div class="stat-icon blue" style="width:38px;height:38px;font-size:1rem">
                <i class="bi bi-moon"></i>
            </div>
            <div>
                <div class="stat-number" style="font-size:1.4rem"><?= count($pmOnly) ?></div>
                <div class="stat-label">Missing Time-In</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i>
        Partial Attendance Records
        <span class="badge bg-warning text-dark ms-1"><?= count($records) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0" style="font-size:0.8rem">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Grade / Section</th>
                        <th class="text-center" style="background:#f0fdf4">Time-In</th>
                        <th class="text-center" style="background:#f0fdf4">Time-Out</th>
                        <th class="text-center">Pattern</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            No partial attendance records found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($records as $i => $r): ?>
                    <?php
                    $hasAM   = !empty($r[getAttendanceEvents($r)[0]]);
                    $out = $r[getAttendanceEvents($r)[1]];
                    $hasPM = !empty($out);
                    $pattern = $hasAM && !$hasPM ? 'Missing Time-Out' : (!$hasAM && $hasPM ? 'Missing Time-In' : 'Partial');
                    $pColor  = $hasAM && !$hasPM ? 'success' : 'primary';
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-600 small">
                            <?= date('M j, Y', strtotime($r['date'])) ?>
                            <div class="text-muted" style="font-size:0.7rem">
                                <?= date('D', strtotime($r['date'])) ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-600">
                                <?= sanitize($r['last_name'].', '.$r['first_name']) ?>
                            </div>
                            <div class="text-muted" style="font-size:0.7rem">
                                <?= sanitize($r['lrn']) ?>
                            </div>
                        </td>
                        <td class="small">
                            <?= sanitize($r['grade_level'].' / '.$r['section_name']) ?>
                        </td>
                        <td class="text-center" style="background:#fafffe">
                            <?= $r[getAttendanceEvents($r)[0]]  ? date('h:i A', strtotime($r[getAttendanceEvents($r)[0]]))  : '<span class="text-danger">—</span>' ?>
                        </td>
                        <td class="text-center" style="background:#fafffe">
                            <?= $out ? date('h:i A', strtotime($out)) : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $pColor ?>">
                                <?= $pattern ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= sanitize($r['remarks'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
