<?php

/**
 * DepEd School Form 4 (SF4)
 * Monthly Attendance Report for the School
 * Summary of attendance per grade/section per month
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin(); // SF4 is admin only

$pageTitle       = 'SF4 — Monthly Attendance Summary';
$db              = getDB();
$month           = (int)($_GET['month'] ?? date('n'));
$year            = (int)($_GET['year']  ?? date('Y'));
$schoolName      = getSetting('school_name')  ?? 'San Pablo City Central School';
$schoolAddress   = getSetting('school_address') ?? '';
$schoolYear      = getSetting('school_year')   ?? '';
$monthLabel      = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth     = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$calEntries      = getCalendarMonth($month, $year);

// Count school days
$schoolDays = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
    if (!in_array($dow, [6, 7]) && !isHolidayOrNoClass($ds)) $schoolDays++;
}

// Get all sections grouped by grade
$allSections = $db->query("
    SELECT s.*, u.full_name AS adviser_name
    FROM sections s
    LEFT JOIN users u ON s.adviser_id = u.id
    WHERE s.is_active = 1
    ORDER BY " . gradeLevelOrderSQL('s.grade_level') . ", s.section_name
")->fetchAll();

// Build stats per section
$sectionData = [];
foreach ($allSections as $sec) {
    // Total enrolled
    $enrolled = $db->prepare("
        SELECT COUNT(*) FROM students
        WHERE section_id = ? AND is_active = 1
    ");
    $enrolled->execute([$sec['id']]);
    $totalEnrolled = $enrolled->fetchColumn();

    // Male/Female count
    $genderStmt = $db->prepare("
        SELECT gender, COUNT(*) AS cnt
        FROM students
        WHERE section_id = ? AND is_active = 1
        GROUP BY gender
    ");
    $genderStmt->execute([$sec['id']]);
    $genderRows = $genderStmt->fetchAll();
    $male = $female = 0;
    foreach ($genderRows as $g) {
        if ($g['gender'] === 'Male')   $male   = $g['cnt'];
        if ($g['gender'] === 'Female') $female = $g['cnt'];
    }

    // Attendance stats for the month
    $stats = $db->prepare("
        SELECT
            SUM(a.attendance_type = 'full_day') AS full_day,
            SUM(a.attendance_type = 'partial')  AS partial,
            SUM(a.attendance_type = 'absent')   AS absent,
            SUM(a.am_status = 'present')        AS am_present,
            SUM(a.am_status = 'late')           AS am_late,
            SUM(a.am_status = 'absent')         AS am_absent,
            SUM(a.pm_status = 'present')        AS pm_present,
            SUM(a.pm_status = 'late')           AS pm_late,
            SUM(a.pm_status = 'absent')         AS pm_absent,
            COUNT(DISTINCT a.date)              AS days_with_records
        FROM students s
        LEFT JOIN attendance a ON a.student_id = s.id
            AND MONTH(a.date) = ? AND YEAR(a.date) = ?
        WHERE s.section_id = ? AND s.is_active = 1
    ");
    $stats->execute([$month, $year, $sec['id']]);
    $row = $stats->fetch();

    // Attendance rate
    $possible = $totalEnrolled * $schoolDays;
    $attended = ($row['full_day'] ?? 0) + (($row['partial'] ?? 0) * 0.5);
    $rate     = $possible > 0 ? round(($attended / $possible) * 100, 1) : 0;

    $sectionData[] = array_merge($sec, $row, [
        'total_enrolled' => $totalEnrolled,
        'male'           => $male,
        'female'         => $female,
        'school_days'    => $schoolDays,
        'rate'           => $rate,
    ]);
}

// Grand totals
$grandTotals = [
    'enrolled'   => 0,
    'male' => 0,
    'female' => 0,
    'full_day'   => 0,
    'partial' => 0,
    'absent' => 0,
    'am_present' => 0,
    'am_late' => 0,
    'am_absent' => 0,
    'pm_present' => 0,
    'pm_late' => 0,
    'pm_absent' => 0,
];
foreach ($sectionData as $s) {
    $grandTotals['enrolled']   += $s['total_enrolled'] ?? 0;
    $grandTotals['male']       += $s['male'] ?? 0;
    $grandTotals['female']     += $s['female'] ?? 0;
    $grandTotals['full_day']   += $s['full_day']   ?? 0;
    $grandTotals['partial']    += $s['partial']    ?? 0;
    $grandTotals['absent']     += $s['absent']     ?? 0;
    $grandTotals['am_present'] += $s['am_present'] ?? 0;
    $grandTotals['am_late']    += $s['am_late']    ?? 0;
    $grandTotals['am_absent']  += $s['am_absent']  ?? 0;
    $grandTotals['pm_present'] += $s['pm_present'] ?? 0;
    $grandTotals['pm_late']    += $s['pm_late']    ?? 0;
    $grandTotals['pm_absent']  += $s['pm_absent']  ?? 0;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>
            SF4 — Monthly Attendance Summary
        </h1>
        <p class="page-subtitle">DepEd Official Format</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-success btn-sm">
            <i class="bi bi-printer me-1"></i>Print / Save PDF
        </button>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
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
                            <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
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
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- SF4 Document -->
<div class="card">
    <div class="card-body p-3" id="sf4Document">

        <!-- Header -->
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:12px;border-bottom:2px solid #000;padding-bottom:10px">

            <!-- Left logo -->
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                style="width:80px;height:80px;object-fit:contain;flex-shrink:0"
                alt="School Logo">

            <!-- Center text -->
            <div style="flex:1;text-align:center;font-size:0.82rem">
                <div style="font-size:0.72rem">Republic of the Philippines</div>
                <div style="font-weight:700;font-size:0.9rem">Department of Education</div>
                <div style="font-weight:700"><?= sanitize($schoolName) ?></div>
                <?php if ($schoolAddress): ?>
                    <div style="font-size:0.75rem"><?= sanitize($schoolAddress) ?></div>
                <?php endif; ?>
                <div style="font-size:0.75rem">S.Y. <?= $schoolYear ?></div>
            </div>

            <!-- Right logo -->
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                style="width:80px;height:80px;object-fit:contain;flex-shrink:0;opacity:0.15"
                alt="DepEd Logo">

        </div>

        <div style="text-align:center;margin-bottom:10px">
            <div style="font-weight:800;font-size:0.95rem;border:2px solid #000;padding:4px 8px;display:inline-block;letter-spacing:0.05em">
                SCHOOL FORM 4 (SF4)
            </div>
            <div style="font-weight:700;font-size:0.85rem;margin-top:4px">
                MONTHLY LEARNER MOVEMENT AND ATTENDANCE REPORT
            </div>
            <div style="font-size:0.8rem;margin-top:4px">
                Month: <strong><?= $monthLabel ?></strong>
                &nbsp;|&nbsp; S.Y.: <strong><?= $schoolYear ?></strong>
                &nbsp;|&nbsp; No. of School Days: <strong><?= $schoolDays ?></strong>
            </div>
        </div>

        <!-- SF4 Table -->
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:0.68rem">
                <thead>
                    <tr style="background:#e8e8e8">
                        <th rowspan="3" style="border:1px solid #000;padding:3px;text-align:center;width:20px">#</th>
                        <th rowspan="3" style="border:1px solid #000;padding:3px;min-width:100px">Grade Level</th>
                        <th rowspan="3" style="border:1px solid #000;padding:3px;min-width:100px">Section</th>
                        <th rowspan="3" style="border:1px solid #000;padding:3px;text-align:center">Schedule</th>
                        <th rowspan="3" style="border:1px solid #000;padding:3px;text-align:center">Adviser</th>
                        <th colspan="3" style="border:1px solid #000;padding:3px;text-align:center">
                            Enrollment
                        </th>
                        <th rowspan="3" style="border:1px solid #000;padding:3px;text-align:center;width:35px">
                            School<br>Days
                        </th>
                        <th colspan="3" style="border:1px solid #000;padding:3px;text-align:center;background:#f0fdf4">
                            ☀️ AM Session
                        </th>
                        <th colspan="3" style="border:1px solid #000;padding:3px;text-align:center;background:#eff6ff">
                            🌙 PM Session
                        </th>
                        <th colspan="3" style="border:1px solid #000;padding:3px;text-align:center;background:#fef3c7">
                            Overall
                        </th>
                        <th rowspan="3" style="border:1px solid #000;padding:3px;text-align:center;width:40px">
                            Attend.<br>Rate
                        </th>
                    </tr>
                    <tr style="background:#f0f0f0">
                        <th style="border:1px solid #000;padding:2px;text-align:center">Total</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center">M</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center">F</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4">P</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4">L</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4">A</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff">P</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff">L</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff">A</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#fef3c7">Full</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#fef3c7">Part.</th>
                        <th style="border:1px solid #000;padding:2px;text-align:center;background:#fef3c7">Abs.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $currentGrade = '';
                    $gradeIdx     = 0;
                    foreach ($sectionData as $idx => $s):
                        if ($s['grade_level'] !== $currentGrade):
                            $currentGrade = $s['grade_level'];
                    ?>
                            <tr style="background:#f8f8f8">
                                <td colspan="18" style="border:1px solid #000;padding:2px 4px;font-weight:700;font-size:0.72rem">
                                    <?= sanitize($currentGrade) ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="border:1px solid #000;padding:2px;text-align:center">
                                <?= $idx + 1 ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px">
                                <?= sanitize($s['grade_level']) ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px;font-weight:bold">
                                <?= sanitize($s['section_name']) ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px;text-align:center">
                                <?= ucfirst(str_replace('_', ' ', $s['schedule_type'])) ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px">
                                <?= sanitize($s['adviser_name'] ?? '—') ?>
                            </td>
                            <!-- Enrollment -->
                            <td style="border:1px solid #000;padding:2px;text-align:center;font-weight:bold">
                                <?= $s['total_enrolled'] ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px;text-align:center">
                                <?= $s['male'] ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px;text-align:center">
                                <?= $s['female'] ?>
                            </td>
                            <!-- School Days -->
                            <td style="border:1px solid #000;padding:2px;text-align:center">
                                <?= $schoolDays ?>
                            </td>
                            <!-- AM -->
                            <?php if ($s['schedule_type'] === 'pm_only'): ?>
                                <td colspan="3" style="border:1px solid #000;padding:2px;text-align:center;background:#f5f5f5;color:#999;font-size:0.6rem">
                                    PM Only
                                </td>
                            <?php else: ?>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f9fffe">
                                    <?= $s['am_present'] ?? 0 ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f9fffe;color:darkorange">
                                    <?= $s['am_late'] ?? 0 ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f9fffe;color:red">
                                    <?= $s['am_absent'] ?? 0 ?>
                                </td>
                            <?php endif; ?>
                            <!-- PM -->
                            <?php if ($s['schedule_type'] === 'am_only'): ?>
                                <td colspan="3" style="border:1px solid #000;padding:2px;text-align:center;background:#f5f5f5;color:#999;font-size:0.6rem">
                                    AM Only
                                </td>
                            <?php else: ?>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f5f8ff">
                                    <?= $s['pm_present'] ?? 0 ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f5f8ff;color:darkorange">
                                    <?= $s['pm_late'] ?? 0 ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f5f8ff;color:red">
                                    <?= $s['pm_absent'] ?? 0 ?>
                                </td>
                            <?php endif; ?>
                            <!-- Overall -->
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#fffdf0;color:green;font-weight:bold">
                                <?= $s['full_day'] ?? 0 ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#fffdf0;color:darkorange">
                                <?= $s['partial'] ?? 0 ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#fffdf0;color:red">
                                <?= $s['absent'] ?? 0 ?>
                            </td>
                            <!-- Rate -->
                            <td style="border:1px solid #000;padding:2px;text-align:center;font-weight:bold;
                                   color:<?= $s['rate'] >= 90 ? 'green' : ($s['rate'] >= 75 ? 'darkorange' : 'red') ?>">
                                <?= $s['rate'] ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#e0e0e0;font-weight:bold">
                        <td colspan="5" style="border:1px solid #000;padding:3px;text-align:right">
                            GRAND TOTAL
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">
                            <?= $grandTotals['enrolled'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">
                            <?= $grandTotals['male'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">
                            <?= $grandTotals['female'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">
                            <?= $schoolDays ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">
                            <?= $grandTotals['am_present'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:darkorange">
                            <?= $grandTotals['am_late'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:red">
                            <?= $grandTotals['am_absent'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">
                            <?= $grandTotals['pm_present'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:darkorange">
                            <?= $grandTotals['pm_late'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:red">
                            <?= $grandTotals['pm_absent'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:green">
                            <?= $grandTotals['full_day'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:darkorange">
                            <?= $grandTotals['partial'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center;color:red">
                            <?= $grandTotals['absent'] ?>
                        </td>
                        <td style="border:1px solid #000;padding:2px;text-align:center">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Certification block -->
        <div style="font-size:0.8rem;margin-top:20px">
            <p>
                I certify that the data presented herein are true and correct
                based on the attendance records of this school for the month of
                <strong><?= $monthLabel ?></strong>.
            </p>
        </div>

        <!-- Signature block -->
        <div class="row mt-3" style="font-size:0.8rem">
            <div class="col-6">
                <div class="border-top pt-1 mt-5 text-center" style="width:220px">
                    <strong>___________________________</strong>
                    <div>Prepared by: School Head / Principal</div>
                    <div class="text-muted">Date: _______________</div>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="border-top pt-1 mt-5 text-center ms-auto" style="width:220px">
                    <strong>___________________________</strong>
                    <div>Received by: District Supervisor</div>
                    <div class="text-muted">Date: _______________</div>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    @media print {

        .no-print,
        .sidebar,
        .top-navbar,
        .page-header {
            display: none !important;
        }

        .main-content {
            margin: 0 !important;
        }

        .content-area {
            padding: 0 !important;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
        }

        body {
            font-size: 8px;
        }

        #sf4Document {
            padding: 0 !important;
        }

        table {
            page-break-inside: auto;
        }

        tr {
            page-break-inside: avoid;
        }

        img {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>

<?php include '../includes/footer.php'; ?>