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
$monthUpper  = strtoupper(date('F', mktime(0, 0, 0, $month, 1, $year)));
$schoolName  = getSetting('school_name')  ?? 'San Pablo City Central School';
$schoolYear  = getSetting('school_year')  ?? '';
$schoolId    = getSetting('school_id')    ?: '109791';
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

// Sessions per school day
$sessionsPerDay = ($isAmOnly || $isPmOnly) ? 1 : 2;
$sessionTotal   = $schoolDays * $sessionsPerDay;

// Helper: render an M / F cell pair
function sf2MFCells($gender, $value, $color = '', $bg = '')
{
    $base = "border:1px solid #cbd5e1;padding:2px;text-align:center;{$bg}" . ($color ? "color:{$color};" : '');
    $m = ($gender === 'M') ? $value : '';
    $f = ($gender === 'F') ? $value : '';
    return "<td style=\"{$base}\">{$m}</td><td style=\"{$base}\">{$f}</td>";
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- ============ PAGE HEADER ============ -->
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
        <a href="index_reports.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- ============ FILTERS ============ -->
<div class="card mb-3 no-print sf2-filter-card">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end" id="sf2FilterForm">
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
                <button type="submit" class="btn btn-primary btn-sm" id="genBtn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="genSpin"></span>
                    <i class="bi bi-arrow-clockwise me-1"></i>Generate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ SF2 DOCUMENT ============ -->
<div class="card sf2-card">
    <div class="card-body p-3" id="sf2Document">

        <!-- Guidelines block -->
        <div class="sf2-guidelines">
            <div class="sf2-guidelines-title">GUIDELINES:</div>
            <div>1. The attendance shall be accomplished daily. Refer to the codes for checking learners' attendance.</div>
            <div>2. Dates shall be written in the columns after Learner's Name.</div>
            <div>3. To compute the following:</div>
            <div class="sf2-indent">A. Percentage of Enrolment = (Registered Learners as of end of the month ÷ Enrolment as of 1st Friday of the school year) × 100</div>
            <div class="sf2-indent">B. Average Daily Attendance = Total Daily Attendance ÷ Number of School Days in reporting month</div>
            <div class="sf2-indent">C. Percentage of Attendance for the month = (Average Daily Attendance ÷ Registered Learners as of end of the month) × 100</div>
            <div>4. Every end of the month, the class adviser will submit this form to the office of the principal for recording of summary table into School Form 4. Once signed by the principal, this form should be returned to the adviser.</div>
            <div>5. The adviser will provide necessary interventions including but not limited to home visitation to learner/s who were absent for 5 consecutive days and/or those at risk of dropping out.</div>
            <div>6. Attendance performance of learners will be reflected in Form 137 and Form 138 every grading period.</div>
            <div class="sf2-guidelines-note">*Beginning of School Year cut-off report is every 1st Friday of the School Year</div>
            <div class="sf2-guidelines-sign">MARCIA CIABAL VILLEGAS</div>
            <div class="sf2-guidelines-sub">(This replaces Form 1, Form 2 &amp; STS Form 4 - Absenteeism and Dropout Profile)</div>
        </div>

        <!-- School ID / School Year / Report for the Month -->
        <table class="sf2-meta-table">
            <tr>
                <td style="width:33%">
                    <strong>School ID:</strong> <?= sanitize($schoolId) ?>
                </td>
                <td style="width:34%;text-align:center">
                    <strong>School Year:</strong> <?= sanitize($schoolYear) ?>
                </td>
                <td style="width:33%;text-align:right">
                    <strong>Report for the Month:</strong> <?= $monthUpper ?>
                </td>
            </tr>
        </table>

        <!-- DepEd Header -->
        <div class="sf2-header">
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                 class="sf2-logo" alt="School Logo">
            <div class="sf2-header-text">
                <div class="sf2-republic">Republic of the Philippines</div>
                <div class="sf2-deped">Department of Education</div>
                <div class="sf2-school"><?= sanitize($schoolName) ?></div>
                <div class="sf2-section"><?= sanitize($section['grade_level'] ?? '') ?> — <?= sanitize($section['section_name'] ?? '') ?></div>
                <div class="sf2-sy">S.Y. <?= sanitize($schoolYear) ?></div>
            </div>
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                 class="sf2-logo sf2-logo-ghost" alt="DepEd Logo">
        </div>

        <div class="sf2-title-block">
            <div class="sf2-badge">
                <i class="bi bi-clipboard-check-fill"></i>
                SCHOOL FORM 2 (SF2)
            </div>
            <div class="sf2-subtitle">
                DAILY ATTENDANCE REPORT OF LEARNERS
            </div>
            <div class="sf2-meta">
                Month/Year: <strong><?= $monthLabel ?></strong>
                <span class="sf2-sep">|</span>
                No. of School Days: <strong><?= $schoolDays ?></strong>
                <span class="sf2-sep">|</span>
                Schedule: <strong><?= ucfirst(str_replace('_', ' ', $section['schedule_type'] ?? 'full_day')) ?></strong>
            </div>
        </div>

        <!-- SF2 Table -->
        <div class="sf2-table-wrap">
            <table class="sf2-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="col-idx">#</th>
                        <th rowspan="2" class="col-name">
                            Name of Learner<br>(Last Name, First Name, M.I.)
                        </th>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dayOfWeek = date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isWeekend = in_array($dayOfWeek, [6, 7]);
                            $ds        = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $calEntry  = $calEntries[$ds] ?? null;
                            $isHol     = $calEntry && in_array($calEntry['type'], ['holiday', 'no_class']);
                        ?>
                            <th class="day-header <?= ($isWeekend || $isHol) ? 'day-off' : '' ?>">
                                <?= $d ?>
                            </th>
                        <?php endfor; ?>
                        <th colspan="2" class="grp-enroll">Enrolment</th>
                        <th colspan="2" class="grp-present">Present</th>
                        <th colspan="2" class="grp-late">Late</th>
                        <th colspan="2" class="grp-absent">Absent</th>
                        <th rowspan="2" class="col-pct grp-metric">%<br>Enrolment</th>
                        <th rowspan="2" class="col-ada grp-metric">Average<br>Daily<br>Attendance</th>
                        <th rowspan="2" class="col-pctatt grp-metric">% Attendance<br>for the<br>Month</th>
                        <th rowspan="2" class="col-remarks grp-metric">Remarks</th>
                    </tr>
                    <tr>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++):
                            $dayOfWeek = date('N', mktime(0, 0, 0, $month, $d, $year));
                            $isWeekend = in_array($dayOfWeek, [6, 7]);
                            $ds        = sprintf('%04d-%02d-%02d', $year, $month, $d);
                            $calEntry  = $calEntries[$ds] ?? null;
                            $isHol     = $calEntry && in_array($calEntry['type'], ['holiday', 'no_class']);
                            $dayAbbr   = strtoupper(substr(date('D', mktime(0, 0, 0, $month, $d, $year)), 0, 1));
                        ?>
                            <th class="day-subheader <?= ($isWeekend || $isHol) ? 'day-off' : '' ?>">
                                <?= $isHol ? '☆' : $dayAbbr ?>
                            </th>
                        <?php endfor; ?>
                        <?php for ($k = 0; $k < 4; $k++): ?>
                            <th class="mfc">M</th>
                            <th class="mfc">F</th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $tot = [
                        'enrol'   => ['M' => 0, 'F' => 0],
                        'present' => ['M' => 0, 'F' => 0],
                        'late'    => ['M' => 0, 'F' => 0],
                        'absent'  => ['M' => 0, 'F' => 0],
                    ];
                    $sumPctEnrol = $sumADA = $sumPctAtt = 0;
                    $studentCount = count($students);

                    foreach ($students as $idx => $stu):
                        $amP = $amL = $amA = 0;
                        $pmP = $pmL = $pmA = 0;
                        $daysWithRecord = 0;
                        $gender = strtoupper(substr($stu['gender'] ?? '', 0, 1));
                    ?>
                        <tr class="student-row">
                            <td class="c row-num-cell">
                                <span class="row-num"><?= $idx + 1 ?></span>
                            </td>
                            <td class="name-cell">
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

                                $isDayOff = ($isWeekend || $isHol);
                                $cellCls  = 'day-cell' . ($isDayOff ? ' day-off' : '');

                                if ($isDayOff):
                                    echo "<td class=\"{$cellCls}\">/</td>";
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
                                        if ($val !== '') $daysWithRecord++;
                                    }
                                    $cls = $val === 'A' ? 'mark-absent' : ($val === 'L' ? 'mark-late' : ($val === 'P' ? 'mark-present' : ''));
                                    echo "<td class=\"{$cellCls}\"><span class=\"{$cls}\">{$val}</span></td>";
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
                                        if ($val !== '') $daysWithRecord++;
                                    }
                                    $cls = $val === 'A' ? 'mark-absent' : ($val === 'L' ? 'mark-late' : ($val === 'P' ? 'mark-present' : ''));
                                    echo "<td class=\"{$cellCls}\"><span class=\"{$cls}\">{$val}</span></td>";
                                else:
                                    // Full day — AM stacked above PM
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
                                        if ($amVal !== '' || $pmVal !== '') $daysWithRecord++;
                                    }
                                    $amCls = $amVal === 'A' ? 'mark-absent' : ($amVal === 'L' ? 'mark-late' : ($amVal === 'P' ? 'mark-present' : ''));
                                    $pmCls = $pmVal === 'A' ? 'mark-absent' : ($pmVal === 'L' ? 'mark-late' : ($pmVal === 'P' ? 'mark-present' : ''));
                                    echo "<td class=\"{$cellCls} full-day-cell\">
                                        <div class=\"am-cell\"><span class=\"{$amCls}\">{$amVal}</span></div>
                                        <div class=\"pm-cell\"><span class=\"{$pmCls}\">{$pmVal}</span></div>
                                    </td>";
                                endif;
                            endfor;

                            // Per-learner totals
                            $present = $amP + $pmP;
                            $late    = $amL + $pmL;
                            $absent  = $amA + $pmA;

                            $pctEnrol = $sessionTotal > 0 ? ($daysWithRecord / $sessionTotal) * 100 : 0;
                            $adaVal   = $schoolDays  > 0 ? $present / $schoolDays : 0;
                            $pctAtt   = $sessionTotal > 0 ? ($present / $sessionTotal) * 100 : 0;

                            $sumPctEnrol += $pctEnrol;
                            $sumADA      += $adaVal;
                            $sumPctAtt   += $pctAtt;

                            if ($gender === 'M' || $gender === 'F') {
                                $tot['enrol'][$gender]++;
                                $tot['present'][$gender] += $present;
                                $tot['late'][$gender]    += $late;
                                $tot['absent'][$gender]  += $absent;
                            }

                            echo sf2MFCells($gender, 1, '', 'background:#eff6ff');
                            echo sf2MFCells($gender, $present, '', 'background:#f0fdf4');
                            echo sf2MFCells($gender, $late, 'darkorange', 'background:#fffbeb');
                            echo sf2MFCells($gender, $absent, 'red', 'background:#fef2f2');
                            ?>
                            <td class="c grp-metric">
                                <span class="rate-pill rate-neutral"><?= number_format($pctEnrol, 2) ?>%</span>
                            </td>
                            <td class="c grp-metric">
                                <span class="ada-pill"><?= number_format($adaVal, 2) ?></span>
                            </td>
                            <td class="c grp-metric">
                                <?php
                                $pctCls = $pctAtt >= 90 ? 'good' : ($pctAtt >= 75 ? 'warn' : 'bad');
                                ?>
                                <span class="rate-pill rate-<?= $pctCls ?>"><?= number_format($pctAtt, 2) ?>%</span>
                            </td>
                            <td class="remark-cell">
                                <textarea
                                    class="sf2-remark"
                                    data-student-id="<?= $stu['id'] ?>"
                                    rows="1"
                                    placeholder="Add remark..."
                                ></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="grand-row">
                        <td colspan="2" class="r">TOTAL</td>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                            <td class="c"></td>
                        <?php endfor; ?>
                        <?php
                        $totCols = [
                            'enrol'   => ['cls' => 'grp-enroll',  'color' => ''],
                            'present' => ['cls' => 'grp-present', 'color' => ''],
                            'late'    => ['cls' => 'grp-late',    'color' => 'darkorange'],
                            'absent'  => ['cls' => 'grp-absent',  'color' => 'red'],
                        ];
                        foreach ($totCols as $key => $meta):
                            $c = $meta['color'] ? "color:{$meta['color']};" : '';
                        ?>
                            <td class="c b <?= $meta['cls'] ?>" style="<?= $c ?>"><?= $tot[$key]['M'] ?></td>
                            <td class="c b <?= $meta['cls'] ?>" style="<?= $c ?>"><?= $tot[$key]['F'] ?></td>
                        <?php endforeach; ?>
                        <td class="c b grp-metric">
                            <?= $studentCount ? number_format($sumPctEnrol / $studentCount, 2) : '0.00' ?>%
                        </td>
                        <td class="c b grp-metric">
                            <?= $studentCount ? number_format($sumADA / $studentCount, 2) : '0.00' ?>
                        </td>
                        <td class="c b grp-metric">
                            <?php
                            $footPct = $studentCount ? $sumPctAtt / $studentCount : 0;
                            $footCls = $footPct >= 90 ? 'good' : ($footPct >= 75 ? 'warn' : 'bad');
                            ?>
                            <span class="rate-pill rate-<?= $footCls ?>">
                                <?= number_format($footPct, 2) ?>%
                            </span>
                        </td>
                        <td class="grp-metric"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Legend -->
        <div class="sf2-legend">
            <span class="legend-chip"><strong>Codes:</strong></span>
            <span class="legend-chip"><span class="mark-present">P</span> = Present</span>
            <span class="legend-chip"><span class="mark-late">L</span> = Late</span>
            <span class="legend-chip"><span class="mark-absent">A</span> = Absent</span>
            <span class="legend-chip"><strong>/</strong> = Weekend / Holiday / No Class</span>
            <?php if (!$isAmOnly && !$isPmOnly): ?>
                <span class="legend-chip"><em>Each daily cell shows AM (upper) and PM (lower).</em></span>
            <?php endif; ?>
        </div>

        <!-- Signature block -->
        <div class="sf2-cert">
            <strong>Prepared by:</strong>
        </div>
        <div class="signature-block">
            <div class="sig-line">
                <div class="line"></div>
                <div class="name"><?= sanitize($section['adviser_name'] ?? '___________________________') ?></div>
                <div class="role">Class Adviser</div>
            </div>
            <div class="sig-line">
                <div class="line"></div>
                <div class="name principal-name">KRISTEL IRIS ESTRELLADO IGOT</div>
                <div class="role">School Head / Principal</div>
            </div>
        </div>

        <!-- Footer note -->
        <div class="sf2-footer-note">
            This form replaces Form 1, Form 2 &amp; STS Form 4 - Absenteeism and Dropout Profile
        </div>

    </div>
</div>

<style>
/* ============================================================
   SF2 — Enhanced Visual Design (matched to SF4) — FIXED
   ============================================================ */

/* ---------- Filter Card ---------- */
.sf2-filter-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    background: #fff;
}

/* ---------- SF2 Document Card ---------- */
.sf2-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    background: #fff;
    overflow: hidden;
}
#sf2Document {
    padding: 22px !important;
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    background: #fff;
}

/* ---------- Guidelines Block ---------- */
.sf2-guidelines {
    border: 1px solid #cbd5e1;
    border-left: 4px solid #1e3a8a;
    background: #f8fafc;
    padding: 10px 14px;
    font-size: 0.68rem;
    line-height: 1.5;
    color: #334155;
    border-radius: 6px;
    margin-bottom: 10px;
}
.sf2-guidelines-title {
    font-weight: 700;
    color: #1e3a8a;
    letter-spacing: 0.06em;
    margin-bottom: 4px;
    text-transform: uppercase;
}
.sf2-indent { padding-left: 16px; }
.sf2-guidelines-note {
    font-style: italic;
    margin-top: 4px;
    color: #64748b;
}
.sf2-guidelines-sign {
    text-align: center;
    font-weight: 700;
    margin-top: 6px;
    color: #1e293b;
    letter-spacing: 0.03em;
}
.sf2-guidelines-sub {
    text-align: center;
    font-size: 0.62rem;
    color: #64748b;
}

/* ---------- Meta Table (School ID / SY / Month) ---------- */
.sf2-meta-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
    margin-bottom: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    overflow: hidden;
}
.sf2-meta-table td {
    border: 1px solid #cbd5e1;
    padding: 6px 10px;
    background: #f8fafc;
}

/* ---------- Header ---------- */
.sf2-header {
    display: flex;
    align-items: center;
    gap: 18px;
    padding-bottom: 12px;
    margin-bottom: 14px;
    border-bottom: 3px double #1e3a8a;
}
.sf2-logo {
    width: 80px;
    height: 80px;
    object-fit: contain;
    flex-shrink: 0;
}
.sf2-logo-ghost { opacity: 0.15; }
.sf2-header-text {
    flex: 1;
    text-align: center;
    font-size: 0.82rem;
    line-height: 1.4;
}
.sf2-republic { font-size: 0.72rem; color: #555; }
.sf2-deped    { font-weight: 700; font-size: 0.9rem; color: #1e3a8a; }
.sf2-school   { font-weight: 800; font-size: 0.95rem; }
.sf2-section  { font-size: 0.78rem; color: #374151; }
.sf2-sy       { font-size: 0.75rem; color: #555; }

/* ---------- Title Block ---------- */
.sf2-title-block {
    text-align: center;
    margin-bottom: 14px;
}
.sf2-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: #fff;
    padding: 6px 20px;
    border-radius: 8px;
    font-weight: 800;
    letter-spacing: 0.08em;
    font-size: 0.9rem;
    box-shadow: 0 3px 10px rgba(30,58,138,0.30);
}
.sf2-subtitle {
    font-weight: 700;
    font-size: 0.85rem;
    margin-top: 8px;
    color: #1f2937;
    letter-spacing: 0.04em;
}
.sf2-meta {
    font-size: 0.78rem;
    margin-top: 6px;
    color: #374151;
}
.sf2-sep { margin: 0 8px; color: #9ca3af; }

/* ---------- Table Wrapper ---------- */
.sf2-table-wrap {
    overflow-x: auto;
    border-radius: 8px;
    border: 1px solid #d1d5db;
}

/* ============================================================
   SF2 TABLE — Fixed layout fix
   ============================================================ */
.sf2-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.65rem;
    table-layout: auto;          /* was: fixed — lets day + metric columns size naturally */
    min-width: 100%;
}

/* All cells: prevent mid-word breaking */
.sf2-table th,
.sf2-table td {
    border-right: 1px solid #cbd5e1;
    border-bottom: 1px solid #cbd5e1;
    padding: 4px 3px;
    vertical-align: middle;
    line-height: 1.3;
    overflow-wrap: normal;
    word-break: normal;          /* keep words intact */
    hyphens: none;
}
.sf2-table th:last-child,
.sf2-table td:last-child { border-right: none; }
.sf2-table tbody tr:last-child td { border-bottom: none; }

/* ---------- Table Header ---------- */
.sf2-table thead th {
    background: #e8eef7;
    text-align: center;
    font-weight: 700;
    color: #1e293b;
    font-size: 0.62rem;
    border-bottom: 2px solid #1e3a8a;
    white-space: nowrap;         /* keep "Present"/"Late" etc. on one line */
    padding: 5px 4px;
}
.sf2-table thead tr:nth-child(2) th { background: #dde7f5; }

/* Day headers — narrow, fixed */
.sf2-table .day-header {
    width: 24px;
    min-width: 22px;
    max-width: 28px;
}
.sf2-table .day-subheader {
    font-size: 0.58rem;
    padding: 1px;
    width: 24px;
    min-width: 22px;
}
.sf2-table .day-off {
    background: #d0d0d0 !important;
    color: #666 !important;
}

/* ---------- Column Width Hints ---------- */
.sf2-table .col-idx      { width: 28px; min-width: 28px; }
.sf2-table .col-name     { width: 170px; min-width: 170px; text-align: left; padding-left: 8px; white-space: normal; }
.sf2-table .mfc          { width: 22px; min-width: 22px; }
.sf2-table .col-pct      { min-width: 58px; }
.sf2-table .col-ada      { min-width: 70px; }
.sf2-table .col-pctatt   { min-width: 74px; }
.sf2-table .col-remarks  { min-width: 160px; width: 160px; }

/* ---------- Column Group Headers ---------- */
.sf2-table thead .grp-enroll  { background: #dbeafe !important; }
.sf2-table thead .grp-present { background: #dcfce7 !important; }
.sf2-table thead .grp-late    { background: #fef3c7 !important; }
.sf2-table thead .grp-absent  { background: #fee2e2 !important; }
.sf2-table thead .grp-metric  { background: #f3e8ff !important; }

/* Multi-line metric headers: allow controlled wrap so labels fit */
.sf2-table thead .col-ada,
.sf2-table thead .col-pctatt,
.sf2-table thead .col-pct,
.sf2-table thead .col-remarks {
    white-space: normal;
    line-height: 1.15;
    font-size: 0.6rem;
    padding: 4px 3px;
}

/* ---------- Body Cell Colors ---------- */
.sf2-table tbody .grp-enroll  { background: #eff6ff; }
.sf2-table tbody .grp-present { background: #f0fdf4; }
.sf2-table tbody .grp-late    { background: #fffbeb; }
.sf2-table tbody .grp-absent  { background: #fef2f2; }
.sf2-table tbody .grp-metric  { background: #faf5ff; }

/* ---------- Zebra + Hover ---------- */
.sf2-table tbody tr.student-row:nth-of-type(even) {
    background: #fafbfd;
}
.sf2-table tbody tr.student-row:hover {
    background: #fef9e7;
    transition: background 0.15s ease;
}
.sf2-table tbody tr.student-row:hover td {
    background: inherit;
}
.sf2-table tbody tr.student-row:hover .grp-enroll  { background: #dbeafe; }
.sf2-table tbody tr.student-row:hover .grp-present { background: #dcfce7; }
.sf2-table tbody tr.student-row:hover .grp-late    { background: #fef3c7; }
.sf2-table tbody tr.student-row:hover .grp-absent  { background: #fee2e2; }
.sf2-table tbody tr.student-row:hover .grp-metric  { background: #f3e8ff; }

/* ---------- Cell Modifiers ---------- */
.sf2-table .c { text-align: center; }
.sf2-table .r { text-align: right; }
.sf2-table .b { font-weight: 700; }

.sf2-table .name-cell {
    font-weight: 600;
    color: #1e293b;
    padding-left: 8px;
    white-space: normal;
}
.sf2-table .row-num-cell { padding: 3px; }

/* Row number chip */
.row-num {
    display: inline-block;
    background: #e5e7eb;
    color: #374151;
    border-radius: 999px;
    padding: 1px 7px;
    font-size: 0.6rem;
    font-weight: 700;
    min-width: 18px;
}

/* ---------- Daily Cells ---------- */
.sf2-table .day-cell {
    text-align: center;
    padding: 1px;
    font-size: 0.62rem;
    font-weight: 700;
}
.sf2-table .day-cell.day-off {
    background: #d0d0d0 !important;
    color: #666 !important;
    font-weight: 400;
}
.sf2-table .full-day-cell {
    padding: 0;
    vertical-align: top;
}
.sf2-table .am-cell,
.sf2-table .pm-cell {
    min-height: 0.9em;
    text-align: center;
    font-size: 0.58rem;
    font-weight: 700;
    line-height: 1.1;
    padding: 1px 0;
}
.sf2-table .am-cell {
    border-bottom: 0.5px solid #94a3b8;
}

/* Marks */
.mark-present { color: #15803d; }
.mark-late    { color: #d97706; }
.mark-absent  { color: #dc2626; }

/* ---------- Metric Pills ---------- */
.rate-pill {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.62rem;
    white-space: nowrap;
}
.rate-good    { background: #d1fae5; color: #065f46; }
.rate-warn    { background: #fef3c7; color: #92400e; }
.rate-bad     { background: #fee2e2; color: #991b1b; }
.rate-neutral { background: #e0e7ff; color: #3730a3; }

.ada-pill {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 999px;
    background: #ede9fe;
    color: #5b21b6;
    font-weight: 700;
    font-size: 0.62rem;
    white-space: nowrap;
}

/* ---------- Grand Total Row ---------- */
.sf2-table tfoot .grand-row td {
    background: #e0e7ff !important;
    border-top: 2px solid #1e3a8a;
    font-weight: 800;
    color: #1e293b;
    padding: 5px 3px;
}

/* ---------- Remarks Cell ---------- */
.sf2-table .remark-cell {
    padding: 0;
    vertical-align: top;
    background: #fff;
}
.sf2-remark {
    width: 100%;
    border: none;
    outline: none;
    resize: vertical;
    font-size: 0.66rem;
    font-family: Arial, Helvetica, sans-serif;
    padding: 3px 5px;
    background: transparent;
    min-height: 24px;
    box-sizing: border-box;
    color: #1e293b;
}
.sf2-remark:focus {
    background: #fef9e7;
    box-shadow: inset 0 0 0 2px #fbbf24;
}

/* ---------- Legend ---------- */
.sf2-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 14px;
    font-size: 0.7rem;
    color: #374151;
    margin-top: 10px;
    padding: 8px 12px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px dashed #d1d5db;
}
.legend-chip { white-space: nowrap; }

/* ---------- Certification ---------- */
.sf2-cert {
    font-size: 0.78rem;
    margin-top: 18px;
    color: #374151;
}

/* ---------- Signature Block ---------- */
.signature-block {
    display: flex;
    justify-content: space-between;
    gap: 40px;
    margin-top: 36px;
    font-size: 0.78rem;
}
.sig-line {
    flex: 1;
    text-align: center;
}
.sig-line .line {
    height: 32px;
    border-bottom: 1.5px solid #000;
    margin-bottom: 4px;
    max-width: 260px;
    margin-left: auto;
    margin-right: auto;
}
.sig-line .name { font-weight: 700; font-size: 0.78rem; }
.sig-line .name.principal-name {
    font-weight: 800;
    font-size: 0.82rem;
    color: #1e293b;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}
.sig-line .role { font-size: 0.72rem; color: #555; }

/* ---------- Footer Note ---------- */
.sf2-footer-note {
    margin-top: 14px;
    text-align: center;
    font-size: 0.65rem;
    font-style: italic;
    color: #64748b;
}

/* ============================================================
   PRINT STYLES — A4 Landscape
   ============================================================ */
@media print {
    @page {
        size: A4 landscape;
        margin: 8mm;
    }

    .no-print,
    .sidebar,
    .top-navbar,
    .page-header,
    .sf2-filter-card {
        display: none !important;
    }

    .main-content,
    .content-area {
        margin: 0 !important;
        padding: 0 !important;
    }

    .card,
    .sf2-card {
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }

    #sf2Document { padding: 0 !important; }

    body { font-size: 9px; }

    .sf2-table-wrap {
        overflow: visible !important;
        border: none !important;
    }
    .sf2-table { min-width: 0 !important; }

    /* Preserve ALL colors when printing */
    *, *::before, *::after {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Table: header repeat + row page-break control */
    .sf2-table { font-size: 0.58rem; }
    .sf2-table thead { display: table-header-group; }
    .sf2-table tfoot { display: table-footer-group; }
    .sf2-table tr    { page-break-inside: avoid; }

    /* Flatten gradient for cleaner ink */
    .sf2-badge {
        background: #1e3a8a !important;
        box-shadow: none !important;
    }

    /* Remarks print like plain text */
    .sf2-remark {
        border: none !important;
        overflow: visible !important;
        resize: none !important;
        height: auto !important;
        min-height: 0 !important;
        white-space: pre-wrap !important;
        word-wrap: break-word !important;
        padding: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
    }

    .sf2-guidelines { font-size: 0.62rem; }
    .signature-block { margin-top: 20px; }
    .sf2-legend { font-size: 0.65rem; padding: 4px 6px; }
    .sf2-footer-note { font-size: 0.6rem; }
}
</style>

<script>
(function () {
    // Unique storage key per section + month + year
    const STORAGE_KEY = 'sf2_remarks_<?= (int)$sectionId ?>_<?= (int)$month ?>_<?= (int)$year ?>';
    const textareas   = document.querySelectorAll('.sf2-remark');

    // 1. Load saved remarks
    let saved = {};
    try {
        saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    } catch (e) {
        saved = {};
    }

    // 2. Populate
    textareas.forEach(function (ta) {
        const id = ta.dataset.studentId;
        if (saved[id]) {
            ta.value = saved[id];
            autoGrow(ta);
        }
    });

    // 3. Save on input (debounced) + auto-grow
    let saveTimer = null;
    textareas.forEach(function (ta) {
        ta.addEventListener('input', function () {
            autoGrow(ta);
            clearTimeout(saveTimer);
            saveTimer = setTimeout(persist, 400);
        });
        ta.addEventListener('blur', persist);
    });

    function persist() {
        const data = {};
        textareas.forEach(function (ta) {
            const v = ta.value.trim();
            if (v) data[ta.dataset.studentId] = v;
        });
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (e) {
            console.warn('Could not save remarks:', e);
        }
    }

    function autoGrow(el) {
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight) + 'px';
    }

    // Loading spinner on Generate
    document.getElementById('sf2FilterForm')?.addEventListener('submit', function () {
        document.getElementById('genSpin')?.classList.remove('d-none');
        document.getElementById('genBtn').disabled = true;
    });
})();
</script>

<?php include '../includes/footer.php'; ?>