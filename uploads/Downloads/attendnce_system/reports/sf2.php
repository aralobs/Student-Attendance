<?php
/**
 * SF2 Report Generator
 * DepEd School Form 2 — Daily Attendance Record
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'SF2 Reports';
$db        = getDB();

$month     = (int)($_GET['month']   ?? date('n'));
$year      = (int)($_GET['year']    ?? date('Y'));
$sectionId = (int)($_GET['section'] ?? 0);
$sections  = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();

if ($sectionId === 0 && !empty($sections)) {
    $sectionId = $sections[0]['id'];
}

// Calculate month days
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthLabel   = date('F Y', mktime(0,0,0,$month,1,$year));

// Get students for section
$students = $db->prepare("
    SELECT * FROM students
    WHERE section_id = ? AND is_active = 1
    ORDER BY last_name, first_name
");
$students->execute([$sectionId]);
$students = $students->fetchAll();

// Build attendance matrix [student_id][day] => status
$attMatrix = [];
$stmt = $db->prepare("
    SELECT student_id, DAY(date) AS day, status
    FROM attendance
    WHERE MONTH(date) = ? AND YEAR(date) = ?
    AND student_id IN (
        SELECT id FROM students WHERE section_id = ? AND is_active = 1
    )
");
$stmt->execute([$month, $year, $sectionId]);
foreach ($stmt->fetchAll() as $row) {
    $attMatrix[$row['student_id']][$row['day']] = $row['status'];
}

// Get school/section info
$schoolName = getSetting('school_name');
$schoolYear = getSetting('school_year');
$selectedSection = '';
foreach ($sections as $s) {
    if ($s['id'] == $sectionId) {
        $selectedSection = $s['section_name'];
        break;
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title"><i class="bi bi-file-earmark-text-fill me-2 text-primary"></i>SF2 Reports</h1>
        <p class="page-subtitle">DepEd School Form 2 — Daily Attendance</p>
    </div>
    <div class="d-flex gap-2">
        <a href="export_pdf.php?month=<?= $month ?>&year=<?= $year ?>&section=<?= $sectionId ?>"
           class="btn btn-danger" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
        </a>
        <a href="export_excel.php?month=<?= $month ?>&year=<?= $year ?>&section=<?= $sectionId ?>"
           class="btn btn-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                        <?= date('F', mktime(0,0,0,$m,1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label mb-1 small fw-600">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = 2024; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                        <?= sanitize($sec['section_name']) ?>
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

<!-- SF2 Table -->
<div class="card">
    <div class="card-body p-2 p-md-3">
        <!-- Header -->
        <div class="text-center mb-3">
            <h5 class="fw-800 mb-0">Republic of the Philippines</h5>
            <h6 class="mb-0">Department of Education</h6>
            <h6 class="mb-0"><?= sanitize($schoolName) ?></h6>
            <h6 class="mb-0">Kindergarten Department — <?= sanitize($selectedSection) ?></h6>
            <div class="mt-2">
                <strong>SCHOOL FORM 2 (SF2) — DAILY ATTENDANCE RECORD</strong>
            </div>
            <div><?= $monthLabel ?> &nbsp;|&nbsp; S.Y. <?= $schoolYear ?></div>
        </div>

        <!-- Legend -->
        <div class="d-flex gap-3 mb-2 flex-wrap no-print" style="font-size:0.8rem">
            <span><strong>P</strong> = Present</span>
            <span><strong>L</strong> = Late</span>
            <span><strong>A</strong> = Absent</span>
            <span><strong>E</strong> = Excused</span>
        </div>

        <div class="table-responsive" style="overflow-x:auto">
            <table class="table table-bordered table-sm sf2-table" style="font-size:0.75rem;min-width:900px">
                <thead>
                    <tr class="bg-light">
                        <th rowspan="2" style="width:30px">#</th>
                        <th rowspan="2" style="min-width:150px">Student Name</th>
                        <?php
                        // Day headers
                        for ($d = 1; $d <= $daysInMonth; $d++):
                            $dayOfWeek = date('N', mktime(0,0,0,$month,$d,$year));
                            $isWeekend = in_array($dayOfWeek, [6,7]);
                        ?>
                        <th class="text-center <?= $isWeekend ? 'bg-secondary text-white' : '' ?>"
                            style="width:28px">
                            <?= $d ?>
                        </th>
                        <?php endfor; ?>
                        <th class="text-center bg-light" style="width:40px">P</th>
                        <th class="text-center bg-light" style="width:40px">L</th>
                        <th class="text-center bg-light" style="width:40px">A</th>
                        <th class="text-center bg-light" style="width:40px">E</th>
                    </tr>
                    <tr class="bg-light">
                        <th colspan="2" style="font-size:0.65rem;text-align:center">
                            <em>Day of week</em>
                        </th>
                        <?php for ($d = 3; $d <= $daysInMonth; $d++):
                            $dayAbbr = date('D', mktime(0,0,0,$month,$d,$year));
                        ?>
                        <th class="text-center" style="font-size:0.6rem"><?= substr($dayAbbr,0,1) ?></th>
                        <?php endfor; ?>
                        <th colspan="4"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totalsP = $totalsL = $totalsA = $totalsE = 0;
                    foreach ($students as $idx => $stu):
                        $p = $l = $a = $e = 0;
                    ?>
                    <tr>
                        <td class="text-center"><?= $idx + 1 ?></td>
                        <td>
                            <strong><?= sanitize($stu['last_name']) ?></strong>,
                            <?= sanitize($stu['first_name']) ?>
                            <?= $stu['middle_name'] ? sanitize(substr($stu['middle_name'],0,1)) . '.' : '' ?>
                        </td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dayOfWeek = date('N', mktime(0,0,0,$month,$d,$year));
                            $isWeekend = in_array($dayOfWeek, [6,7]);
                            $stat      = $attMatrix[$stu['id']][$d] ?? null;
                            $cellClass = '';
                            $cellVal   = '';
                            if ($isWeekend) {
                                $cellClass = 'bg-secondary text-white';
                                $cellVal   = '—';
                            } elseif ($stat === 'present') { $cellVal = 'P'; $p++; }
                            elseif ($stat === 'late')     { $cellVal = 'L'; $l++; }
                            elseif ($stat === 'absent')   { $cellVal = 'A'; $a++; }
                            elseif ($stat === 'excused')  { $cellVal = 'E'; $e++; }
                        ?>
                        <td class="text-center <?= $cellClass ?>">
                            <?php if (!$isWeekend && $stat): ?>
                            <span class="<?= $stat === 'absent' ? 'text-danger fw-bold' : ($stat === 'late' ? 'text-warning fw-bold' : '') ?>">
                                <?= $cellVal ?>
                            </span>
                            <?php else: ?>
                            <?= $cellVal ?>
                            <?php endif; ?>
                        </td>
                        <?php endfor; ?>
                        <td class="text-center fw-bold text-success"><?= $p ?></td>
                        <td class="text-center fw-bold text-warning"><?= $l ?></td>
                        <td class="text-center fw-bold text-danger"><?= $a ?></td>
                        <td class="text-center fw-bold text-info"><?= $e ?></td>
                        <?php $totalsP += $p; $totalsL += $l; $totalsA += $a; $totalsE += $e; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-light fw-bold">
                        <td colspan="2" class="text-end">TOTAL</td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <td></td>
                        <?php endfor; ?>
                        <td class="text-center text-success"><?= $totalsP ?></td>
                        <td class="text-center text-warning"><?= $totalsL ?></td>
                        <td class="text-center text-danger"><?= $totalsA ?></td>
                        <td class="text-center text-info"><?= $totalsE ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Signature block -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="border-top pt-2 mt-5 text-center" style="width:250px">
                    <div class="fw-600">Class Adviser</div>
                    <small class="text-muted"><?= sanitize($selectedSection) ?></small>
                </div>
            </div>
            <div class="col-md-6 text-end">
                <div class="border-top pt-2 mt-5 text-center ms-auto" style="width:250px">
                    <div class="fw-600">School Principal</div>
                    <small class="text-muted"><?= sanitize($schoolName) ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>