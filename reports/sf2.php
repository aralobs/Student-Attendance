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
require_once '../includes/attendance_finalizer.php';

$pageTitle       = 'SF2 — Daily Attendance Record';
$db              = getDB();
$month           = (int)($_GET['month']   ?? date('n'));
$year            = (int)($_GET['year']    ?? date('Y'));
$sectionId       = (int)($_GET['section'] ?? 0);
$allowedSections = getAllowedSections();
$grades          = getGradeLevels();
if ($month < 1 || $month > 12 || $year < 1900 || $year > 2100) {
    http_response_code(400);
    exit('Invalid report month or year');
}

// Default to first allowed section
if ($sectionId === 0 && !empty($allowedSections)) {
    $sectionId = $allowedSections[0]['id'];
}

$section     = getSection($sectionId);
if (!canAccessSection($sectionId)) {
    http_response_code(403);
    exit('Section access denied');
}
$reportStart = sprintf('%04d-%02d-01', $year, $month);
finalizeAttendanceRange($reportStart, date('Y-m-t', strtotime($reportStart)), $sectionId);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthLabel  = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$monthUpper  = strtoupper(date('F', mktime(0, 0, 0, $month, 1, $year)));
$schoolName  = getSetting('school_name')  ?? 'San Pablo City Central School';
$schoolYear  = $section['school_year'] ?? getSetting('school_year') ?? '';
$schoolId    = getSetting('school_id') ?? '';
$schoolHead  = getSetting('school_head') ?? '';
$generatedBy = getSetting('sf2_generated_by') ?? '';
$calEntries  = getCalendarMonth($month, $year);

$students = $db->prepare("SELECT * FROM students WHERE section_id = ? AND is_active = 1 ORDER BY last_name, first_name");
$students->execute([$sectionId]);
$students = $students->fetchAll();
$attMatrix = [];
$stmt = $db->prepare("SELECT student_id, DAY(date) AS day, am_in, am_out, pm_in, pm_out,
    am_status, pm_status, attendance_type FROM attendance
    WHERE MONTH(date) = ? AND YEAR(date) = ? AND student_id IN
    (SELECT id FROM students WHERE section_id = ? AND is_active = 1)");
$stmt->execute([$month, $year, $sectionId]);
foreach ($stmt->fetchAll() as $row) $attMatrix[$row['student_id']][$row['day']] = $row;

// Count school days
$schoolDays = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
    if ($dow !== 6 && $dow !== 7 && !isHolidayOrNoClass($ds)) $schoolDays++;
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

// Print model: weekday columns, day-equivalent attendance, male/female groups.
// Missing attendance is not counted as present or absent.
function sf2PrintNumber($value) {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}
function sf2PrintCell($record, $off, $schedule) {
    if ($off || !$record) return ['html' => '', 'present' => 0, 'absent' => 0, 'fullAbsent' => false, 'recorded' => false];
    $sessions = $schedule === 'am_only' ? ['am'] : ($schedule === 'pm_only' ? ['pm'] : ['am', 'pm']);
    $present = $absent = 0; $statuses = [];
    foreach ($sessions as $session) {
        $status = $record[$session . '_status'] ?? '';
        $statuses[] = $status;
        if (in_array($status, ['present', 'late'], true)) $present++;
        if ($status === 'absent') $absent++;
    }
    $count = count($sessions);
    $recorded = count(array_filter($statuses, static function ($status) { return in_array($status, ['present', 'late', 'absent'], true); }));
    $fullAbsent = $absent === $count;
    if ($fullAbsent) $html = 'X';
    elseif ($count === 1) $html = $statuses[0] === 'late' ? '<span class="half-mark upper late-mark"></span>' : '';
    else {
        $html = '';
        foreach ($statuses as $index => $status) {
            $position = $index === 0 ? 'upper' : 'lower';
            if ($status === 'absent') $html .= '<span class="half-mark ' . $position . '">X</span>';
            elseif ($status === 'late') $html .= '<span class="half-mark ' . $position . ' late-mark"></span>';

        }
    }
    if ($recorded === 0) $html = '';
    return ['html' => $html, 'present' => $present / $count, 'absent' => $absent / $count, 'fullAbsent' => $fullAbsent, 'recorded' => $recorded > 0];
}
$sf2PrintDays = [];
for ($day = 1; $day <= $daysInMonth; $day++) {
    $weekday = (int)date('N', mktime(0, 0, 0, $month, $day, $year));
    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
    if ($weekday > 5) continue;
    $sf2PrintDays[] = ['day' => $day, 'weekday' => ['M', 'T', 'W', 'TH', 'F'][$weekday - 1], 'off' => isHolidayOrNoClass($date)];
}
while (count($sf2PrintDays) < 25) $sf2PrintDays[] = ['day' => null, 'weekday' => '', 'off' => true];
$sf2PrintGroups = ['M' => [], 'F' => [], 'U' => []];
$sf2PrintSummary = ['M' => ['count' => 0, 'present' => 0, 'absent' => 0, 'five' => 0], 'F' => ['count' => 0, 'present' => 0, 'absent' => 0, 'five' => 0], 'U' => ['count' => 0, 'present' => 0, 'absent' => 0, 'five' => 0]];
foreach ($students as $student) {
    $gender = strtoupper(substr($student['gender'] ?? '', 0, 1));
    if (!in_array($gender, ['M', 'F'], true)) $gender = 'U';
    $item = ['student' => $student, 'gender' => $gender, 'cells' => [], 'present' => 0, 'absent' => 0, 'recorded' => 0];
    $streak = 0; $five = false;
    foreach ($sf2PrintDays as $column) {
        $record = $column['day'] === null ? null : ($attMatrix[$student['id']][$column['day']] ?? null);
        $cell = sf2PrintCell($record, $column['off'], $section['schedule_type'] ?? 'full_day');
        $item['cells'][] = $cell;
        $item['present'] += $cell['present']; $item['absent'] += $cell['absent'];
        $item['recorded'] += (int)$cell['recorded'];
        if (!$column['off']) {
            $streak = $cell['fullAbsent'] ? $streak + 1 : 0;
            if ($streak >= 5) $five = true;
        }
    }
    $sf2PrintSummary[$gender]['count']++;
    $item['number'] = $sf2PrintSummary[$gender]['count'];
    $sf2PrintSummary[$gender]['present'] += $item['present'];
    $sf2PrintSummary[$gender]['absent'] += $item['absent'];
    if ($five) $sf2PrintSummary[$gender]['five']++;
    $sf2PrintGroups[$gender][] = $item;
}
$sf2PrintAll = array_merge($sf2PrintGroups['M'], $sf2PrintGroups['F'], $sf2PrintGroups['U']);
$sf2PrintPages = array_chunk($sf2PrintAll, 23);
if (!$sf2PrintPages) $sf2PrintPages = [[]];
$sf2PrintSummary['TOTAL'] = ['count' => count($sf2PrintAll), 'present' => array_sum(array_column($sf2PrintSummary, 'present')), 'absent' => array_sum(array_column($sf2PrintSummary, 'absent')), 'five' => array_sum(array_column($sf2PrintSummary, 'five'))];
foreach (['M', 'F', 'U'] as $gender) $sf2PrintSummary[$gender]['recorded'] = array_sum(array_column($sf2PrintGroups[$gender], 'recorded'));
$sf2PrintSummary['TOTAL']['recorded'] = array_sum(array_column($sf2PrintAll, 'recorded'));
// Historical enrolment and transfer/dropout counts are not available in the supplied queries.
// Optional integration: set $sf2SummaryOverrides[row key][M|F|TOTAL] from your historical data.
$sf2PrintSummaryRows = [
    ['key' => 'initial', 'label' => '* Enrolment as of (1st Friday of June)'],
    ['key' => 'late', 'label' => 'Late enrolment during the month (beyond cut-off)'],
    ['key' => 'registered', 'label' => 'Registered Learners as of end of month'],
    ['key' => 'enrolmentPct', 'label' => 'Percentage of Enrolment as of end of month'],
    ['key' => 'ada', 'label' => 'Average Daily Attendance'],
    ['key' => 'attendancePct', 'label' => 'Percentage of Attendance for the month'],
    ['key' => 'five', 'label' => 'Number of students absent for 5 consecutive days'],
    ['key' => 'dropped', 'label' => 'Dropped out'],
    ['key' => 'out', 'label' => 'Transferred out'],
    ['key' => 'in', 'label' => 'Transferred in'],
];
function sf2PrintSummaryValue($key, $gender, $summary, $schoolDays, $overrides) {
    if (isset($overrides[$key][$gender])) return sf2PrintNumber($overrides[$key][$gender]);
    $data = $summary[$gender];
    if ($key === 'registered') return sf2PrintNumber($data['count']);
    if ($key === 'five') return $data['recorded'] > 0 ? sf2PrintNumber($data['five']) : '';
    if ($key === 'ada') return $schoolDays > 0 && $data['recorded'] > 0 ? sf2PrintNumber($data['present'] / $schoolDays) : '';
    if ($key === 'attendancePct') return $schoolDays > 0 && $data['count'] > 0 && $data['recorded'] > 0 ? sf2PrintNumber($data['present'] / ($schoolDays * $data['count']) * 100) : '';
    if ($key === 'enrolmentPct' && isset($overrides['initial'][$gender]) && $overrides['initial'][$gender] > 0) return sf2PrintNumber($data['count'] / $overrides['initial'][$gender] * 100);
    return '';
}
function sf2PrintTotalRow($items, $label, $columns) {
    $html = '<tr class="total-row"><td colspan="2">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>';
    for ($i = 0; $i < count($columns); $i++) {
        $total = array_sum(array_map(static function ($item) use ($i) { return $item['cells'][$i]['present']; }, $items));
        $recorded = array_sum(array_map(static function ($item) use ($i) { return (int)$item['cells'][$i]['recorded']; }, $items));
        $html .= '<td>' . ($columns[$i]['off'] ? '' : ($recorded > 0 ? sf2PrintNumber($total) : '')) . '</td>';
    }
    $recorded = array_sum(array_column($items, 'recorded'));
    return $html . '<td>' . ($recorded > 0 ? sf2PrintNumber(array_sum(array_column($items, 'absent'))) : '') . '</td><td>' . ($recorded > 0 ? sf2PrintNumber(array_sum(array_column($items, 'present'))) : '') . '</td><td></td></tr>';
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
    <div class="d-flex flex-wrap gap-2">
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
            <div class="sf2-guidelines-sign"><?= sanitize($generatedBy) ?></div>
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

                            $pctEnrol = 0;
                            $adaVal   = $sessionTotal > 0 ? ($present + $late) / $sessionTotal : 0;
                            $pctAtt   = $sessionTotal > 0 ? (($present + $late) / $sessionTotal) * 100 : 0;

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
                            echo sf2MFCells($gender, $daysWithRecord > 0 ? $present : '', '', 'background:#f0fdf4');
                            echo sf2MFCells($gender, $daysWithRecord > 0 ? $late : '', 'darkorange', 'background:#fffbeb');
                            echo sf2MFCells($gender, $daysWithRecord > 0 ? $absent : '', 'red', 'background:#fef2f2');
                            ?>
                            <td class="c grp-metric">
                                <span class="rate-pill rate-neutral"></span>
                            </td>
                            <td class="c grp-metric">
                                <span class="ada-pill"><?= $daysWithRecord > 0 ? number_format($adaVal, 2) : '' ?></span>
                            </td>
                            <td class="c grp-metric">
                                <?php
                                $pctCls = $pctAtt >= 90 ? 'good' : ($pctAtt >= 75 ? 'warn' : 'bad');
                                ?>
                                <span class="rate-pill rate-<?= $pctCls ?>"><?= $daysWithRecord > 0 ? number_format($pctAtt, 2) . '%' : '' ?></span>
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

                        </td>
                        <td class="c b grp-metric">
                            <?= number_format($sumADA, 2) ?>
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
                <div class="name principal-name"><?= sanitize($schoolHead) ?></div>
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
    max-width: 100%;
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

<div id="sf2PrintRoot" aria-hidden="true">
<?php foreach ($sf2PrintPages as $sf2PageIndex => $sf2PageItems): ?>
<section class="sheet">
  <h1>School Form 2 (SF2) Daily Attendance Report of Learners</h1>
  <div class="subtitle">(This replaces Form 1, Form 2 &amp; STS Form 4 - Absenteeism and Dropout Profile)</div>

  <div class="meta">
    <label class="id-label">School ID</label><span class="id-value print-value"><?= sanitize($schoolId) ?></span>
    <label class="year-label">School Year</label><span class="year-value print-value"><?= sanitize(preg_replace('/\s*-\s*/', ' - ', $schoolYear)) ?></span>
    <label class="month-label">Report for the Month of</label><span class="month-value print-value"><?= sanitize($monthUpper) ?></span>
    <label class="school-label">Name of School</label><span class="school-value print-value"><?= sanitize(strtoupper($schoolName)) ?></span>
    <label class="grade-label">Grade Level</label><span class="grade-value print-value"><?= sanitize($section['grade_level'] ?? '') ?></span>
    <label class="section-label">Section</label><span class="section-value print-value"><?= sanitize($section['section_name'] ?? '') ?></span>
  </div>

  <table class="attendance">
    <colgroup><col style="width:2.8%"><col style="width:16.5%">
    <?php foreach ($sf2PrintDays as $column): ?><col style="width:<?= 51 / count($sf2PrintDays) ?>% "><?php endforeach; ?>
    <col style="width:6.1%"><col style="width:6.1%"><col style="width:17%"></colgroup>
    <thead>
      <tr><th rowspan="3">No.</th><th rowspan="3">NAME<br><span class="learner-name-format">(Last Name, First Name, Middle Name)</span></th><th class="date-caption" colspan="<?= count($sf2PrintDays) ?>">(1st row for date)</th><th class="month-total-header" colspan="2" rowspan="2">Total for the<br>Month</th><th class="remarks-header" rowspan="3"><b>REMARKS</b> (If DROPPED OUT, state reason, please refer to legend number 2. If TRANSFERRED IN/OUT, write the name of School.)</th></tr>
      <tr><?php foreach ($sf2PrintDays as $column): ?><th><?= $column['day'] ?? '' ?></th><?php endforeach; ?></tr>
      <tr><?php foreach ($sf2PrintDays as $column): ?><th><?= $column['weekday'] ?></th><?php endforeach; ?><th>ABSENT</th><th>PRESENT</th></tr>
    </thead>
    <tbody>
    <?php foreach (['M' => 'MALE', 'F' => 'FEMALE', 'U' => 'UNSPECIFIED'] as $gender => $label):
        $pageGroup = array_values(array_filter($sf2PageItems, static function ($item) use ($gender) { return $item['gender'] === $gender; }));
        if (!$pageGroup) continue;
        foreach ($pageGroup as $item): $student = $item['student']; ?>
      <tr><td><?= $item['number'] ?>.</td><td class="name"><?= sanitize(strtoupper($student['last_name'] . ', ' . $student['first_name'] . (!empty($student['middle_name']) ? ' ' . $student['middle_name'] : ''))) ?></td>
      <?php foreach ($item['cells'] as $cell): ?><td class="att-cell"><?= $cell['html'] ?></td><?php endforeach; ?>
      <td><?= $item['recorded'] > 0 ? sf2PrintNumber($item['absent']) : '' ?></td><td><?= $item['recorded'] > 0 ? sf2PrintNumber($item['present']) : '' ?></td><td class="remarks" data-print-student-id="<?= (int)$student['id'] ?>"></td></tr>
    <?php endforeach;
        echo sf2PrintTotalRow($pageGroup, count($pageGroup) . '. <=== ' . $label . ' | TOTAL Per Day ===>', $sf2PrintDays);
    endforeach;
    echo sf2PrintTotalRow($sf2PageItems, count($sf2PageItems) . '. Combined TOTAL Per Day' . (count($sf2PrintPages) > 1 ? ' (this page)' : ''), $sf2PrintDays); ?>
    </tbody>
  </table>

  <div class="bottom">
    <div class="box guidelines">
      <h3>GUIDELINES:</h3>
      <p>1. The attendance shall be accomplished daily. Refer to the codes for checking learners' attendance.</p>
      <p>2. Dates shall be written in the columns after Learner's Name.</p>
      <p>3. To compute the following:</p>

      <div class="formula"><span>a. Percentage of Enrolment =</span><span class="fraction"><span>Registered Learners as of end of the month</span><span>Enrolment as of 1st Friday of the school year</span></span><span>× 100</span></div>
      <div class="formula"><span>b. Average Daily Attendance =</span><span class="fraction"><span>Total Daily Attendance</span><span>Number of School Days in reporting month</span></span></div>
      <div class="formula"><span>c. Percentage of Attendance for the month =</span><span class="fraction"><span>Average daily attendance</span><span>Registered Learners as of end of the month</span></span><span>× 100</span></div>
      <p>4. Every end of the month, the class adviser will submit this form to the office of the principal for recording of summary table into School Form 4. Once signed by the principal, this form should be returned to the adviser.</p>
      <p>5. The adviser will provide necessary interventions including but not limited to home visitation to learners who were absent for 5 consecutive days and/or those at risk of dropping out.</p>
      <p>6. Attendance performance of learners will be reflected in Form 137 and Form 138 every grading period.</p>
      <p class="cutoff">*Beginning of School Year cut-off report is every 1st Friday of the School Year</p>
    </div>
    <div class="legend-column"><div class="box legend">
      <h3>1. CODES FOR CHECKING ATTENDANCE</h3>
      <p>(blank) - Present; (x) - Absent; Tardy (half shaded = Upper for Late Comer, Lower for Cutting Classes)</p>
      <h3>2. REASONS/CAUSES FOR NLS</h3>
      <p><b>a. Domestic-Related Factors</b></p>
      <p>a.1. Had to take care of siblings</p><p>a.2. Early marriage/pregnancy</p>
      <p>a.3. Parents' attitude toward schooling</p><p>a.4. Family problems</p>
      <p><b>b. Individual-Related Factors</b></p>
      <p>b.1. Illness</p><p>b.2. Overage</p><p>b.3. Death</p><p>b.4. Drug Abuse</p>
      <p>b.5. Poor academic performance</p><p>b.6. Lack of interest/Distractions</p><p>b.7. Hunger/Malnutrition</p>
      <p><b>c. School-Related Factors</b></p><p>c.1. Teacher Factor</p><p>c.2. Physical condition of classroom</p><p>c.3. Peer influence</p>
      <p><b>d. Geographic/Environmental</b></p><p>d.1. Distance between home and school</p><p>d.2. Armed conflict (incl. Tribal wars &amp; clanfeuds)</p><p>d.3. Calamities/Disasters</p>
      <p><b>e. Financial-Related</b></p><p>e.1. Child labor, work</p><p><b>f. Others</b> (Specify)</p>
    </div><div class="generated"><b><?= sanitize($generatedBy) ?></b><span>Generated thru LIS</span></div></div>
    <div class="summary-column">
      <div class="box summary">
        <table>
          <colgroup><col style="width:62%"><col style="width:10%"><col style="width:10%"><col style="width:18%"></colgroup>
          <tr><th rowspan="2" class="summary-month"><span>Month : <?= sanitize($monthUpper) ?></span><span>No. of Days of<br>Classes: <?= $schoolDays ?></span></th><th colspan="3">Summary</th></tr>
          <tr><th>M</th><th>F</th><th>TOTAL</th></tr>
          <?php foreach ($sf2PrintSummaryRows as $summaryRow): ?>
          <tr><td><?= sanitize($summaryRow['label']) ?></td>
          <?php foreach (['M','F','TOTAL'] as $gender): ?><td><?= sf2PrintSummaryValue($summaryRow['key'], $gender, $sf2PrintSummary, $schoolDays, $sf2SummaryOverrides ?? []) ?></td><?php endforeach; ?>
          </tr><?php endforeach; ?>
        </table>
      </div>
      <p class="certify">I certify that this is a true and correct report.</p>
      <div class="signatures">
        <div><div class="signature-space"></div><div class="sigline"><span class="signature-name"><?= sanitize($section['adviser_name'] ?? '') ?></span><small>(Signature of Adviser over Printed Name)</small></div></div>
        <div><p class="attested">Attested by:</p><div class="signature-space"></div><div class="sigline"><b class="signature-name"><?= sanitize($schoolHead) ?></b><small>(Signature of School Head over Printed Name)</small></div></div>
      </div>
    </div>
  </div>
<?php if (count($sf2PrintPages) > 1): ?><p class="page-count">Page <?= $sf2PageIndex + 1 ?> of <?= count($sf2PrintPages) ?> · Daily totals on this page; summary for the entire class.</p><?php endif; ?>
</section>
<?php endforeach; ?>
</div>

<style>
#sf2PrintRoot{display:none}
@media print {
 @page{size:A4 landscape;margin:0}
 html,body{margin:0!important;padding:0!important;background:white!important;width:297mm!important;min-width:0!important;height:auto!important}
 body> :not(#sf2PrintRoot){display:none!important}
 #sf2PrintRoot{display:block!important;margin:0!important;padding:0!important;position:static!important}
 #sf2PrintRoot *{print-color-adjust:exact;-webkit-print-color-adjust:exact}
#sf2PrintRoot .sheet{width:297mm;min-height:210mm;margin:12px auto;padding:7mm 0 7mm 7mm;background:white;box-shadow:0 1px 8px #aaa}#sf2PrintRoot .sheet>h1,#sf2PrintRoot .sheet>.subtitle,#sf2PrintRoot .meta,#sf2PrintRoot .attendance,#sf2PrintRoot .bottom{width:143mm}#sf2PrintRoot h1{font-size:7.15pt;text-align:center;margin:0 0 2.5mm;font-weight:700}#sf2PrintRoot .subtitle{font-size:4.4pt;font-style:italic;text-align:center;margin-bottom:2mm}#sf2PrintRoot .meta{position:relative;height:7.7mm;font-size:4.4pt}#sf2PrintRoot .meta label,#sf2PrintRoot .meta input,#sf2PrintRoot .meta textarea{position:absolute}#sf2PrintRoot .meta label{text-align:right;line-height:1.05}#sf2PrintRoot .meta input,#sf2PrintRoot .meta textarea{border:0.2mm solid #111;background:white;color:#111;border-radius:0;text-align:center;font-family:Arial;font-size:6pt;padding:0;height:3.9mm;resize:none;overflow:hidden;line-height:1.05}#sf2PrintRoot .id-label{left:18mm;top:1mm;width:9mm}#sf2PrintRoot .id-value{left:27.5mm;top:0;width:8.8mm}#sf2PrintRoot .year-label{left:37mm;top:0.3mm;width:7.8mm}#sf2PrintRoot .year-value{left:45mm;top:0;width:12.5mm}#sf2PrintRoot .month-label{left:58mm;top:0.4mm;width:15mm}#sf2PrintRoot .month-value{left:73.5mm;top:0;width:18.5mm}#sf2PrintRoot .school-label{left:9mm;top:5.1mm;width:18mm}#sf2PrintRoot .meta .school-value{left:27.5mm;top:3.9mm;width:30mm;height:3.8mm;font-size:5.5pt;line-height:0.95}#sf2PrintRoot .grade-label{left:60mm;top:5mm;width:13mm}#sf2PrintRoot .grade-value{left:73.5mm;top:3.9mm;width:18.5mm}#sf2PrintRoot .section-label{left:93mm;top:5mm;width:8mm}#sf2PrintRoot .section-value{left:101mm;top:3.9mm;width:42mm}#sf2PrintRoot table{border-collapse:collapse}#sf2PrintRoot .attendance{table-layout:fixed;font-size:4.4pt}#sf2PrintRoot .attendance th,#sf2PrintRoot .attendance td{border:0.2mm solid #111;padding:0;text-align:center;height:3.8mm;line-height:1.03;overflow-wrap:break-word}#sf2PrintRoot .attendance thead th{font-size:4.4pt}#sf2PrintRoot .attendance thead tr:first-child th{height:1.9mm}#sf2PrintRoot .attendance thead tr:nth-child(2) th{height:3mm}#sf2PrintRoot .attendance thead tr:nth-child(3) th{height:4.6mm}#sf2PrintRoot .name-col{text-align:center!important}#sf2PrintRoot .name{text-align:left!important;padding-left:0.5mm!important;font-size:4.4pt}#sf2PrintRoot .att-cell{cursor:pointer;font-size:4.4pt}#sf2PrintRoot .total-row{font-weight:normal}#sf2PrintRoot .total-row td:nth-last-child(2),#sf2PrintRoot .total-row td:nth-last-child(3){font-weight:bold}#sf2PrintRoot .remarks{font-size:4pt}#sf2PrintRoot .att-cell.tardy{background:linear-gradient(to bottom,#aaa 50%,white 50%)}#sf2PrintRoot .att-cell.cut{background:linear-gradient(to bottom,white 50%,#aaa 50%)}#sf2PrintRoot .bottom{display:grid;grid-template-columns:71.5mm 28mm 42mm;gap:0.75mm;margin-top:0;font-size:3.3pt;line-height:1.15}#sf2PrintRoot .box{padding:0}#sf2PrintRoot .box h3{font-size:3.3pt;margin:0 0 0.5mm}#sf2PrintRoot .box p{margin:0 0 0.45mm}#sf2PrintRoot .formula{display:flex;gap:1mm;align-items:center;margin:3mm 2mm;font-size:3pt}#sf2PrintRoot .formula>span:first-child{flex:1}#sf2PrintRoot .fraction{display:flex;flex-direction:column;text-align:center;flex:1.4}#sf2PrintRoot .fraction>span:first-child{border-bottom:0.2mm solid #111;padding-bottom:0.4mm}#sf2PrintRoot .fraction>span:last-child{padding-top:0.4mm}#sf2PrintRoot .cutoff{margin:5mm 3mm!important;font-size:3pt}#sf2PrintRoot .legend{border:0.2mm solid #111;min-height:57mm;padding:0.7mm;font-size:2.75pt}#sf2PrintRoot .legend h3{font-size:2.75pt;margin-bottom:1mm}#sf2PrintRoot .legend p:has(b){margin-top:2mm}#sf2PrintRoot .legend p{margin-bottom:0.5mm}#sf2PrintRoot .legend-column{position:relative}#sf2PrintRoot .generated{font-size:3.3pt;text-align:center;margin-top:5mm}#sf2PrintRoot .generated b{display:block;border-bottom:0.2mm solid #111;font-size:4.4pt}#sf2PrintRoot .summary table{width:100%;font-size:4.4pt;table-layout:fixed}#sf2PrintRoot .summary td,#sf2PrintRoot .summary th{border:0.2mm solid #111;text-align:center;padding:0.3mm;line-height:1.05}#sf2PrintRoot .summary td:first-child,#sf2PrintRoot .summary th:first-child{width:67%;font-weight:normal}#sf2PrintRoot .summary td{height:3.7mm}#sf2PrintRoot .summary tr:nth-last-child(-n+3) td{height:2.5mm}#sf2PrintRoot .certify{font-size:4.4pt;font-style:italic;margin:3mm 0 0}#sf2PrintRoot .signatures{font-size:4.4pt;text-align:center}#sf2PrintRoot .signature-space{height:6mm}#sf2PrintRoot .sigline{border-top:0.2mm solid #111;margin:0 1.5mm;padding-top:0.3mm}#sf2PrintRoot .sigline small{font-size:3pt}#sf2PrintRoot .attested{text-align:left;font-style:italic;margin:4mm 0 0}#sf2PrintRoot .editable{background:#fffde8}

#sf2PrintRoot .sheet{box-sizing:border-box;margin:0;padding:7mm 0 7mm 7mm;width:297mm;min-height:0;background:#fff;box-shadow:none;font-family:Arial,Helvetica,sans-serif;color:#000;break-after:page}
#sf2PrintRoot .sheet:last-child{break-after:auto}
#sf2PrintRoot,#sf2PrintRoot *{box-sizing:border-box}
#sf2PrintRoot h1{line-height:normal;letter-spacing:normal}
#sf2PrintRoot table{border-collapse:collapse;margin:0;color:#000}
#sf2PrintRoot .meta .print-value{position:absolute;display:flex;align-items:center;justify-content:center;height:3.9mm;border:0.2mm solid #111;font-size:6pt;line-height:1.05;text-align:center;overflow-wrap:break-word;padding:0 0.2mm}
#sf2PrintRoot .meta .school-value{height:3.8mm;font-size:5.5pt}
#sf2PrintRoot .attendance .att-cell{position:relative;cursor:default}
#sf2PrintRoot .half-mark{position:absolute;left:0;right:0;height:50%;font-size:3pt;line-height:1.4}
#sf2PrintRoot .half-mark.upper{top:0}
#sf2PrintRoot .half-mark.lower{bottom:0}
#sf2PrintRoot .late-mark{background:#aaa}
#sf2PrintRoot .remarks{text-align:left;white-space:pre-wrap;overflow-wrap:anywhere;padding:0.3mm}
#sf2PrintRoot .page-count{font-size:3.5pt;margin:1mm 0;width:143mm}
#sf2PrintRoot .bottom{break-inside:avoid}
#sf2PrintRoot .attendance tr{break-inside:avoid}
#sf2PrintRoot .summary th{font-size:3.8pt}

/* Reference PDF geometry: 7.2 mm left inset, 142.9 mm form width. */
#sf2PrintRoot .sheet{padding:7.2mm 0 7mm 7.2mm}
#sf2PrintRoot .sheet>h1,#sf2PrintRoot .sheet>.subtitle,#sf2PrintRoot .meta,#sf2PrintRoot .attendance,#sf2PrintRoot .bottom{width:142.9mm}
#sf2PrintRoot h1{height:5.67mm;margin:0;font-size:7.15pt;line-height:5.67mm}
#sf2PrintRoot .subtitle{height:3.78mm;margin:0;font-size:4.4pt;line-height:3.78mm}
#sf2PrintRoot .meta{height:7.57mm;font-size:4.95pt}
#sf2PrintRoot .meta .print-value{height:3.78mm;border:.145mm solid #000;font-size:6pt}
#sf2PrintRoot .id-label{left:18mm;top:1mm;width:9.2mm}
#sf2PrintRoot .id-value{left:27.5mm;top:0;width:8.8mm}
#sf2PrintRoot .year-label{left:36.5mm;top:.2mm;width:8mm;font-size:4.4pt}
#sf2PrintRoot .year-value{left:44.8mm;top:0;width:12.8mm}
#sf2PrintRoot .month-label{left:57.8mm;top:.2mm;width:15.4mm;font-size:4.4pt}
#sf2PrintRoot .month-value{left:73.3mm;top:0;width:18.8mm}
#sf2PrintRoot .school-label{left:9mm;top:4.9mm;width:18.2mm}
#sf2PrintRoot .meta .school-value{left:27.5mm;top:3.78mm;width:30.1mm;height:3.79mm;font-size:5.5pt}
#sf2PrintRoot .grade-label{left:59mm;top:4.9mm;width:14.2mm}
#sf2PrintRoot .grade-value{left:73.3mm;top:3.78mm;width:18.8mm}
#sf2PrintRoot .section-label{left:92.4mm;top:4.9mm;width:8.6mm}
#sf2PrintRoot .section-value{left:101.1mm;top:3.78mm;width:41.8mm}
#sf2PrintRoot .attendance th,#sf2PrintRoot .attendance td{border:.145mm solid #000;height:3.785mm;font-weight:400}
#sf2PrintRoot .attendance thead th{font-weight:700}
#sf2PrintRoot .attendance thead tr:first-child th{height:1.89mm}
#sf2PrintRoot .attendance thead tr:nth-child(2) th{height:2.91mm}
#sf2PrintRoot .attendance thead tr:nth-child(3) th{height:4.66mm}
#sf2PrintRoot .attendance tbody td{font-size:4.4pt;line-height:1.05}
#sf2PrintRoot .attendance .name{font-weight:400}
#sf2PrintRoot .attendance .total-row td:nth-last-child(2),#sf2PrintRoot .attendance .total-row td:nth-last-child(3){font-weight:700}
#sf2PrintRoot .bottom{grid-template-columns:71.5mm 28mm 42mm;gap:.7mm;font-size:3.3pt}
#sf2PrintRoot .guidelines{padding:.5mm .3mm 0}
#sf2PrintRoot .formula{margin:3mm 2mm;font-size:3pt}
#sf2PrintRoot .legend{min-height:57mm;border:.145mm solid #000;padding:.5mm;font-size:2.75pt}
#sf2PrintRoot .summary td,#sf2PrintRoot .summary th{border:.145mm solid #000;padding:.25mm;font-size:4.4pt}
#sf2PrintRoot .summary th{height:1.9mm;line-height:1.05}
#sf2PrintRoot .summary .summary-month{padding:0;font-size:4.4pt}
#sf2PrintRoot .summary-month span{display:inline-block;vertical-align:middle;width:50%;font-weight:700}
#sf2PrintRoot .summary-month span+span{border-left:.145mm solid #000}
#sf2PrintRoot .summary td:first-child{font-style:italic}
#sf2PrintRoot .summary td{height:4.6mm}
#sf2PrintRoot .summary tr:nth-last-child(-n+3) td{height:2.5mm;font-weight:700;font-style:normal}
#sf2PrintRoot .generated{margin-top:4.5mm}
#sf2PrintRoot .generated b{border-bottom:.145mm solid #000}
#sf2PrintRoot .sigline{border-top:.145mm solid #000}
#sf2PrintRoot .sheet{position:relative}
#sf2PrintRoot .page-count{position:absolute;top:202mm;left:7.2mm;width:142.9mm;margin:0;line-height:1}

#sf2PrintRoot .summary td{height:3.65mm}
#sf2PrintRoot .summary tr:nth-last-child(-n+3) td{height:2.5mm}
#sf2PrintRoot .summary th:last-child{font-size:3.7pt}
#sf2PrintRoot .signature-space{height:5mm}
#sf2PrintRoot .meta .school-value{font-size:6.05pt;line-height:1}
#sf2PrintRoot .total-row td:first-child{font-size:3.8pt}
#sf2PrintRoot .sigline{border-top:0;padding-top:0}
#sf2PrintRoot .signature-name{display:block;min-height:2mm;border-bottom:.145mm solid #000;padding-bottom:.3mm}
#sf2PrintRoot .sigline small{display:block;padding-top:.3mm}
#sf2PrintRoot .bottom{min-height:64mm}
#sf2PrintRoot .formula{display:grid;grid-template-columns:26mm 32.8mm 6.5mm;gap:.6mm;align-items:center;margin:1mm 2mm;font-size:3.3pt}
#sf2PrintRoot .fraction>span:first-child{padding-bottom:.2mm}
#sf2PrintRoot .fraction>span:last-child{padding-top:.2mm}
#sf2PrintRoot .guidelines>p:nth-of-type(n+4):not(.cutoff){text-align:justify}
#sf2PrintRoot .cutoff{margin:3mm 2mm!important;font-size:3.3pt}
#sf2PrintRoot .legend{min-height:55.5mm}
#sf2PrintRoot .legend-column{position:relative}
#sf2PrintRoot .generated{position:absolute;left:0;right:0;bottom:0;margin:0}
#sf2PrintRoot .generated b{min-height:2mm}
#sf2PrintRoot .generated span{display:block;padding-top:.3mm;font-size:3pt}
#sf2PrintRoot .summary-column{display:flex;flex-direction:column;min-height:64mm}
#sf2PrintRoot .summary td,#sf2PrintRoot .summary th{font-size:3.6pt}
#sf2PrintRoot .summary td:first-child,#sf2PrintRoot .summary th:first-child{width:62%}
#sf2PrintRoot .summary .summary-month{font-size:3.6pt}
#sf2PrintRoot .certify{margin:3mm 0 0}
#sf2PrintRoot .signatures{margin-top:auto}
#sf2PrintRoot .signature-space{height:4mm}
#sf2PrintRoot .signature-name{font-size:5.4pt;line-height:1.1}
#sf2PrintRoot .signatures>div+div .signature-name{font-size:3.6pt}
#sf2PrintRoot .subtitle{height:3mm;line-height:3mm;margin:1.5mm 0;font-size:2.7pt}
#sf2PrintRoot .meta label{font-size:3.3pt;white-space:nowrap}
#sf2PrintRoot .year-label,#sf2PrintRoot .month-label{top:1mm}
#sf2PrintRoot .meta .print-value{font-size:4.5pt;font-weight:700}
#sf2PrintRoot .meta .school-value{font-size:4.25pt;white-space:nowrap}
#sf2PrintRoot .attendance thead .learner-name-format{font-size:2.75pt;white-space:nowrap}
#sf2PrintRoot .attendance thead .date-caption{font-size:2.75pt;font-weight:400}
#sf2PrintRoot .attendance thead .month-total-header{font-size:4.5pt;line-height:1.15}
#sf2PrintRoot .attendance thead .remarks-header{font-size:2.75pt;font-weight:400;line-height:1.15}

}
</style>

<script>
(function () {
    const printRoot = document.getElementById('sf2PrintRoot');
    // Place the report directly under body so application wrappers and sidebar styles cannot shrink it.
    document.body.appendChild(printRoot);
    function syncPrintRemarks() {
        const remarks = new Map();
        document.querySelectorAll('.sf2-remark[data-student-id]').forEach(el => remarks.set(el.dataset.studentId, el.value));
        printRoot.querySelectorAll('[data-print-student-id]').forEach(el => { el.textContent = remarks.get(el.dataset.printStudentId) || ''; });
    }
    window.addEventListener('beforeprint', syncPrintRemarks);
    document.addEventListener('input', event => { if (event.target.matches('.sf2-remark')) syncPrintRemarks(); });
    syncPrintRemarks();
})();
</script>

<?php include '../includes/footer.php'; ?>
