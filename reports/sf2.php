<?php

/**
 * DepEd School Form 2 (SF2)
 * Daily Attendance Record — Official Format
 * Covers Kinder to Grade 6
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle       = 'SF2 — Daily Attendance Record';
$db              = getDB();
$month           = (int)($_GET['month']   ?? date('n'));
$year            = (int)($_GET['year']    ?? date('Y'));
$sectionId       = (int)($_GET['section'] ?? 0);
$allowedSections = getAllowedSections();
$grades          = getGradeLevels();

// Default to first allowed section
if ($sectionId === 0 && !empty($allowedSections)) {
    $sectionId = $allowedSections[0]['id'];
}

$section     = getSection($sectionId);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthLabel  = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$schoolName  = getSetting('school_name')  ?? 'San Pablo City Central School';
$schoolYear  = getSetting('school_year')  ?? '';
$calEntries  = getCalendarMonth($month, $year);

// Students for this section
$students = $db->prepare("
    SELECT * FROM students
    WHERE section_id = ? AND is_active = 1
    ORDER BY last_name, first_name
");
$students->execute([$sectionId]);
$students = $students->fetchAll();

// Attendance matrix [student_id][day] => record
$attMatrix = [];
$stmt = $db->prepare("
    SELECT student_id, DAY(date) AS day,
           am_in, am_out, am_status,
           pm_in, pm_out, pm_status,
           attendance_type
    FROM attendance
    WHERE MONTH(date) = ? AND YEAR(date) = ?
    AND student_id IN (
        SELECT id FROM students WHERE section_id = ? AND is_active = 1
    )
");
$stmt->execute([$month, $year, $sectionId]);
foreach ($stmt->fetchAll() as $row) {
    $attMatrix[$row['student_id']][$row['day']] = $row;
}

// Count school days
$schoolDays = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
    if (!in_array($dow, [6, 7]) && !isHolidayOrNoClass($ds)) $schoolDays++;
}

$isAmOnly = ($section['schedule_type'] ?? 'full_day') === 'am_only';
$isPmOnly = ($section['schedule_type'] ?? 'full_day') === 'pm_only';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i class="bi bi-file-earmark-text-fill me-2 text-primary"></i>
            SF2 — Daily Attendance Record
        </h1>
        <p class="page-subtitle">DepEd Official Format</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-success btn-sm">
            <i class="bi bi-printer me-1"></i>Print / Save PDF
        </button>
        <a href="sf2_excel.php?month=<?= $month ?>&year=<?= $year ?>&section=<?= $sectionId ?>"
            class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
        </a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
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
            <div class="col-md-4">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <?php foreach ($allowedSections as $s): ?>
                        <option value="<?= $s['id'] ?>"
                            <?= $sectionId == $s['id'] ? 'selected' : '' ?>>
                            <?= sanitize($s['grade_level'] . ' — ' . $s['section_name']) ?>
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

<!-- SF2 Document -->
<div class="card">
    <div class="card-body p-3" id="sf2Document">

        <!-- DepEd Header -->
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
                <div><?= sanitize($section['grade_level'] ?? '') ?> — <?= sanitize($section['section_name'] ?? '') ?></div>
                <div style="font-size:0.75rem">S.Y. <?= $schoolYear ?></div>
            </div>

            <!-- Right logo (DepEd logo placeholder or duplicate school logo) -->
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                style="width:80px;height:80px;object-fit:contain;flex-shrink:0;opacity:0.15"
                alt="DepEd Logo">

        </div>

        <div style="text-align:center;margin-bottom:10px">
            <div style="font-weight:800;font-size:0.95rem;border:2px solid #000;padding:4px 8px;display:inline-block;letter-spacing:0.05em">
                SCHOOL FORM 2 (SF2)
            </div>
            <div style="font-weight:700;font-size:0.85rem;margin-top:4px">
                DAILY ATTENDANCE RECORD OF LEARNERS
            </div>
            <div style="font-size:0.8rem;margin-top:4px">
                Month/Year: <strong><?= $monthLabel ?></strong>
                &nbsp;|&nbsp; School Year: <strong><?= $schoolYear ?></strong>
                &nbsp;|&nbsp; No. of School Days: <strong><?= $schoolDays ?></strong>
                &nbsp;|&nbsp; Schedule: <strong><?= ucfirst(str_replace('_', ' ', $section['schedule_type'] ?? 'full_day')) ?></strong>
            </div>
        </div>

        <!-- SF2 Table -->
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:0.68rem;min-width:900px">
                <thead>
                    <tr>
                        <th rowspan="2" style="border:1px solid #000;padding:3px;width:25px;text-align:center">#</th>
                        <th rowspan="2" style="border:1px solid #000;padding:3px;min-width:140px">
                            Name of Learner<br>(Last Name, First Name, M.I.)
                        </th>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dayOfWeek = date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isWeekend = in_array($dayOfWeek, [6, 7]);
                            $ds        = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $calEntry  = $calEntries[$ds] ?? null;
                            $isHol     = $calEntry && in_array($calEntry['type'], ['holiday', 'no_class']);
                            $bgStyle   = ($isWeekend || $isHol) ? 'background:#d0d0d0' : '';
                        ?>
                            <th style="border:1px solid #000;padding:2px;text-align:center;width:<?= $isAmOnly || $isPmOnly ? '20px' : '38px' ?>;<?= $bgStyle ?>">
                                <?= $d ?>
                            </th>
                        <?php endfor; ?>
                        <?php if (!$isAmOnly && !$isPmOnly): ?>
                            <!-- Full day summary columns -->
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px;background:#f0fdf4">AM<br>P</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px;background:#f0fdf4">AM<br>L</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px;background:#f0fdf4">AM<br>A</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px;background:#eff6ff">PM<br>P</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px;background:#eff6ff">PM<br>L</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px;background:#eff6ff">PM<br>A</th>
                        <?php else: ?>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px">P</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px">L</th>
                            <th rowspan="2" style="border:1px solid #000;padding:3px;text-align:center;width:30px">A</th>
                        <?php endif; ?>
                    </tr>
                    <tr>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dayOfWeek = date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isWeekend = in_array($dayOfWeek, [6, 7]);
                            $ds        = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $calEntry  = $calEntries[$ds] ?? null;
                            $isHol     = $calEntry && in_array($calEntry['type'], ['holiday', 'no_class']);
                            $bgStyle   = ($isWeekend || $isHol) ? 'background:#d0d0d0' : '';
                            $dayAbbr   = strtoupper(substr(date('D', mktime(0, 0, 0, $month, $d, $year)), 0, 1));
                        ?>
                            <th style="border:1px solid #000;padding:1px;text-align:center;font-size:0.6rem;<?= $bgStyle ?>">
                                <?= $isHol ? '☆' : $dayAbbr ?>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $totAMP = $totAML = $totAMA = 0;
                    $totPMP = $totPML = $totPMA = 0;

                    foreach ($students as $idx => $stu):
                        $amP = $amL = $amA = 0;
                        $pmP = $pmL = $pmA = 0;
                    ?>
                        <tr>
                            <td style="border:1px solid #000;padding:2px;text-align:center">
                                <?= $idx + 1 ?>
                            </td>
                            <td style="border:1px solid #000;padding:2px">
                                <strong><?= sanitize($stu['last_name']) ?></strong>,
                                <?= sanitize($stu['first_name']) ?>
                                <?= $stu['middle_name'] ? sanitize(substr($stu['middle_name'], 0, 1)) . '.' : '' ?>
                            </td>
                            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                $dayOfWeek = date('N', mktime(0, 0, 0, $month, $d, $year));
                                $isWeekend = in_array($dayOfWeek, [6, 7]);
                                $ds        = sprintf('%04d-%02d-%02d', $year, $month, $d);
                                $calEntry  = $calEntries[$ds] ?? null;
                                $isHol     = $calEntry && in_array($calEntry['type'], ['holiday', 'no_class']);
                                $rec       = $attMatrix[$stu['id']][$d] ?? null;

                                $bgStyle   = ($isWeekend || $isHol) ? 'background:#d0d0d0' : '';
                                $cellStyle = "border:1px solid #000;padding:1px;text-align:center;{$bgStyle}";

                                if ($isWeekend || $isHol):
                                    echo "<td style=\"{$cellStyle}\">/ </td>";
                                elseif ($isAmOnly):
                                    $val = '';
                                    if ($rec) {
                                        $val = match ($rec['am_status']) {
                                            'present' => 'P',
                                            'late' => 'L',
                                            'absent' => 'A',
                                            default => ''
                                        };
                                        if ($rec['am_status'] === 'present') $amP++;
                                        elseif ($rec['am_status'] === 'late') $amL++;
                                        elseif ($rec['am_status'] === 'absent') $amA++;
                                    }
                                    $color = $val === 'A' ? 'color:red' : ($val === 'L' ? 'color:orange' : '');
                                    echo "<td style=\"{$cellStyle}\"><span style=\"{$color}\">{$val}</span></td>";
                                elseif ($isPmOnly):
                                    $val = '';
                                    if ($rec) {
                                        $val = match ($rec['pm_status']) {
                                            'present' => 'P',
                                            'late' => 'L',
                                            'absent' => 'A',
                                            default => ''
                                        };
                                        if ($rec['pm_status'] === 'present') $pmP++;
                                        elseif ($rec['pm_status'] === 'late') $pmL++;
                                        elseif ($rec['pm_status'] === 'absent') $pmA++;
                                    }
                                    $color = $val === 'A' ? 'color:red' : ($val === 'L' ? 'color:orange' : '');
                                    echo "<td style=\"{$cellStyle}\"><span style=\"{$color}\">{$val}</span></td>";
                                else:
                                    // Full day — show AM/PM stacked
                                    $amVal = $pmVal = '';
                                    if ($rec) {
                                        $amVal = match ($rec['am_status'] ?? '') {
                                            'present' => 'P',
                                            'late' => 'L',
                                            'absent' => 'A',
                                            default => ''
                                        };
                                        $pmVal = match ($rec['pm_status'] ?? '') {
                                            'present' => 'P',
                                            'late' => 'L',
                                            'absent' => 'A',
                                            default => ''
                                        };
                                        if ($rec['am_status'] === 'present') $amP++;
                                        elseif ($rec['am_status'] === 'late') $amL++;
                                        elseif ($rec['am_status'] === 'absent') $amA++;
                                        if ($rec['pm_status'] === 'present') $pmP++;
                                        elseif ($rec['pm_status'] === 'late') $pmL++;
                                        elseif ($rec['pm_status'] === 'absent') $pmA++;
                                    }
                                    $amColor = $amVal === 'A' ? 'color:red' : ($amVal === 'L' ? 'color:orange' : '');
                                    $pmColor = $pmVal === 'A' ? 'color:red' : ($pmVal === 'L' ? 'color:orange' : '');
                                    echo "<td style=\"{$cellStyle};font-size:0.58rem\">
                                    <div style=\"border-bottom:0.5px solid #ccc;{$amColor}\">{$amVal}</div>
                                    <div style=\"{$pmColor}\">{$pmVal}</div>
                                </td>";
                                endif;
                            endfor; ?>

                            <?php if (!$isAmOnly && !$isPmOnly): ?>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4">
                                    <?= $amP ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4;color:orange">
                                    <?= $amL ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4;color:red">
                                    <?= $amA ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff">
                                    <?= $pmP ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff;color:orange">
                                    <?= $pmL ?>
                                </td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff;color:red">
                                    <?= $pmA ?>
                                </td>
                            <?php else: ?>
                                <td style="border:1px solid #000;padding:2px;text-align:center"><?= $isAmOnly ? $amP : $pmP ?></td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;color:orange"><?= $isAmOnly ? $amL : $pmL ?></td>
                                <td style="border:1px solid #000;padding:2px;text-align:center;color:red"><?= $isAmOnly ? $amA : $pmA ?></td>
                            <?php endif; ?>

                            <?php
                            $totAMP += $amP;
                            $totAML += $amL;
                            $totAMA += $amA;
                            $totPMP += $pmP;
                            $totPML += $pmL;
                            $totPMA += $pmA;
                            ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f5f5f5;font-weight:bold">
                        <td colspan="2" style="border:1px solid #000;padding:3px;text-align:right">
                            TOTAL
                        </td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                            <td style="border:1px solid #000;padding:2px"></td>
                        <?php endfor; ?>
                        <?php if (!$isAmOnly && !$isPmOnly): ?>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4"><?= $totAMP ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4;color:orange"><?= $totAML ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#f0fdf4;color:red"><?= $totAMA ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff"><?= $totPMP ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff;color:orange"><?= $totPML ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;background:#eff6ff;color:red"><?= $totPMA ?></td>
                        <?php else: ?>
                            <td style="border:1px solid #000;padding:2px;text-align:center"><?= $isAmOnly ? $totAMP : $totPMP ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;color:orange"><?= $isAmOnly ? $totAML : $totPML ?></td>
                            <td style="border:1px solid #000;padding:2px;text-align:center;color:red"><?= $isAmOnly ? $totAMA : $totPMA ?></td>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Signature Block -->
        <div class="row mt-4">
            <div class="col-6">
                <div class="mt-5 border-top pt-1 text-center" style="width:220px;font-size:0.8rem">
                    <strong><?= sanitize($section['adviser_name'] ?? '___________________') ?></strong>
                    <div class="text-muted">Class Adviser</div>
                </div>
            </div>
            <div class="col-6 text-end">
                <div class="mt-5 border-top pt-1 text-center ms-auto" style="width:220px;font-size:0.8rem">
                    <strong>___________________</strong>
                    <div class="text-muted">School Head / Principal</div>
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
            font-size: 9px;
        }

        #sf2Document {
            padding: 0 !important;
        }

        img {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>

<?php include '../includes/footer.php'; ?>