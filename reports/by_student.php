<?php
/**
 * Attendance by Student
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle       = 'By Student';
$db              = getDB();
$search          = trim($_GET['search']   ?? '');
$sectionId       = (int)($_GET['section'] ?? 0);
$gradeLevel      = $_GET['grade']         ?? '';
$month           = (int)($_GET['month']   ?? date('n'));
$year            = (int)($_GET['year']    ?? date('Y'));
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

// Build WHERE
$where  = ['s.is_active = 1'];
$params = [$month, $year];

if (!isAdmin()) {
    $ids          = array_column($allowedSections, 'id') ?: [0];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[]      = "s.section_id IN ({$placeholders})";
    $params       = array_merge($params, $ids);
}
if (!empty($search)) {
    $where[]  = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if (!empty($gradeLevel)) {
    $where[]  = 'sec.grade_level = ?';
    $params[] = $gradeLevel;
}
if ($sectionId > 0) {
    $where[]  = 's.section_id = ?';
    $params[] = $sectionId;
}
$whereSQL = implode(' AND ', $where);
$orderSQL = gradeLevelOrderSQL('sec.grade_level');

$students = $db->prepare("
    SELECT s.id, s.first_name, s.last_name, s.lrn, s.photo,
           sec.grade_level, sec.section_name, sec.schedule_type,
           SUM(a.attendance_type = 'full_day') AS full_day,
           SUM(a.attendance_type = 'partial')  AS partial,
           SUM(a.attendance_type = 'absent')   AS absent,
           SUM(a.am_status = 'late')           AS am_late,
           SUM(a.pm_status = 'late')           AS pm_late
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    LEFT JOIN attendance a ON a.student_id = s.id
        AND MONTH(a.date) = ? AND YEAR(a.date) = ?
    WHERE {$whereSQL}
    GROUP BY s.id
    ORDER BY {$orderSQL}, sec.section_name, s.last_name
");
$students->execute($params);
$students = $students->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-person-lines-fill me-2 text-primary"></i>By Student
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
                    <option value="">All</option>
                    <?php foreach($grades as $g): ?>
                    <option value="<?=$g?>" <?=$gradeLevel===$g?'selected':''?>><?=$g?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
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
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name or LRN..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Generate</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i>
        Student Attendance
        <span class="badge bg-primary ms-1"><?= count($students) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0" style="font-size:0.8rem">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Grade / Section</th>
                        <th class="text-center">Days</th>
                        <th class="text-center" style="background:#f0fdf4">Full</th>
                        <th class="text-center" style="background:#fef3c7">Partial</th>
                        <th class="text-center" style="background:#fee2e2">Absent</th>
                        <th class="text-center">AM Late</th>
                        <th class="text-center">PM Late</th>
                        <th class="text-center">Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-4 text-muted">
                            No students found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <?php
                    $attended = ($s['full_day'] ?? 0) + (($s['partial'] ?? 0) * 0.5);
                    $rate     = $schoolDays > 0
                        ? round(($attended / $schoolDays) * 100, 1)
                        : 0;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($s['photo']) ?>"
                                     class="student-photo-sm"
                                     onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=<?= urlencode($s['first_name'].' '.$s['last_name']) ?>&size=40&background=1a56db&color=fff'">
                                <div>
                                    <div class="fw-600">
                                        <?= sanitize($s['last_name'].', '.$s['first_name']) ?>
                                    </div>
                                    <div class="text-muted" style="font-size:0.7rem">
                                        <?= sanitize($s['lrn']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="small">
                            <?= sanitize($s['grade_level'].' / '.$s['section_name']) ?>
                        </td>
                        <td class="text-center"><?= $schoolDays ?></td>
                        <td class="text-center">
                            <span class="badge bg-success bg-opacity-75"><?= $s['full_day'] ?? 0 ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-warning text-dark"><?= $s['partial'] ?? 0 ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-danger bg-opacity-75"><?= $s['absent'] ?? 0 ?></span>
                        </td>
                        <td class="text-center"><?= $s['am_late'] ?? 0 ?></td>
                        <td class="text-center"><?= $s['pm_late'] ?? 0 ?></td>
                        <td class="text-center">
                            <span class="fw-700 text-<?= $rate>=90?'success':($rate>=75?'warning':'danger') ?>">
                                <?= $rate ?>%
                            </span>
                            <div class="progress mt-1" style="height:4px">
                                <div class="progress-bar bg-<?= $rate>=90?'success':($rate>=75?'warning':'danger') ?>"
                                     style="width:<?= $rate ?>%"></div>
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

<?php include '../includes/footer.php'; ?>