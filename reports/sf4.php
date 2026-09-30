<?php

/**
 * DepEd School Form 4 (SF4) — Revised
 * Monthly Learner Movement and Attendance Report
 * Aligned to official DepEd template + enhanced visual design
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle     = 'SF4 — Monthly Learner Movement and Attendance Report';
$db            = getDB();

// --- Input validation ---
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year  = max(2020, min(2100, (int)($_GET['year'] ?? date('Y'))));

$schoolName    = getSetting('school_name')    ?? 'San Pablo City Central School';
$schoolAddress = getSetting('school_address') ?? '';
$schoolYear    = getSetting('school_year')    ?? '';
$monthLabel    = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth   = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// --- Count school days ---
$schoolDays  = 0;
$schoolDates = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $dow = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
    if (!in_array($dow, [6, 7]) && !isHolidayOrNoClass($ds)) {
        $schoolDays++;
        $schoolDates[] = $ds;
    }
}

// --- Cumulative window: SY starts June ---
$syStartYear    = ($month >= 6) ? $year : ($year - 1);
$cumulativeFrom = sprintf('%04d-06-01', $syStartYear);
$cumulativeTo   = sprintf('%04d-%02d-01', $year, $month);

// --- Fetch all sections ---
$allSections = $db->query("
    SELECT s.id, s.section_name, s.grade_level, s.schedule_type,
           u.full_name AS adviser_name
    FROM sections s
    LEFT JOIN users u ON s.adviser_id = u.id
    WHERE s.is_active = 1
    ORDER BY " . gradeLevelOrderSQL('s.grade_level') . ", s.section_name
")->fetchAll();

$sectionData = [];
$sectionIds  = array_column($allSections, 'id');

if (!empty($sectionIds)) {
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));

    // --- 1. Enrollment + gender counts ---
    $enrollStmt = $db->prepare("
        SELECT section_id,
               COUNT(*) AS total,
               SUM(gender = 'Male')   AS male,
               SUM(gender = 'Female') AS female
        FROM students
        WHERE is_active = 1 AND section_id IN ($placeholders)
        GROUP BY section_id
    ");
    $enrollStmt->execute($sectionIds);
    $enrollBySection = [];
    foreach ($enrollStmt->fetchAll() as $r) {
        $enrollBySection[$r['section_id']] = $r;
    }

    // --- 2. Current month attendance ---
    $nextMonth    = ($month == 12) ? ($year + 1) : $year;
    $nextMonthNum = ($month == 12) ? 1 : ($month + 1);
    $monthStart   = sprintf('%04d-%02d-01', $year, $month);
    $monthEndEx   = sprintf('%04d-%02d-01', $nextMonth, $nextMonthNum);

    $monthStmt = $db->prepare("
        SELECT
            s.section_id,
            s.gender,
            SUM(CASE
                WHEN a.attendance_type = 'full_day' THEN 1
                WHEN a.attendance_type = 'partial'
                     AND (a.am_status = 'present' OR a.pm_status = 'present') THEN 1
                ELSE 0 END) AS nlp,
            SUM(CASE
                WHEN a.am_status = 'late' OR a.pm_status = 'late' THEN 1
                ELSE 0 END) AS nl,
            SUM(CASE
                WHEN a.attendance_type = 'absent' THEN 1
                ELSE 0 END) AS p
        FROM students s
        INNER JOIN attendance a ON a.student_id = s.id
        WHERE s.is_active = 1
          AND s.section_id IN ($placeholders)
          AND a.date >= ? AND a.date < ?
        GROUP BY s.section_id, s.gender
    ");
    $monthStmt->execute(array_merge($sectionIds, [$monthStart, $monthEndEx]));
    $monthBySection = [];
    foreach ($monthStmt->fetchAll() as $r) {
        $sid = $r['section_id'];
        if (!isset($monthBySection[$sid])) {
            $monthBySection[$sid] = ['M' => ['nlp' => 0, 'nl' => 0, 'p' => 0], 'F' => ['nlp' => 0, 'nl' => 0, 'p' => 0]];
        }
        $g = ($r['gender'] === 'Male') ? 'M' : 'F';
        $monthBySection[$sid][$g]['nlp'] = (int)$r['nlp'];
        $monthBySection[$sid][$g]['nl']  = (int)$r['nl'];
        $monthBySection[$sid][$g]['p']   = (int)$r['p'];
    }

    // --- 3. Cumulative attendance ---
    $cumStmt = $db->prepare("
        SELECT
            s.section_id,
            s.gender,
            SUM(CASE
                WHEN a.attendance_type = 'full_day' THEN 1
                WHEN a.attendance_type = 'partial'
                     AND (a.am_status = 'present' OR a.pm_status = 'present') THEN 1
                ELSE 0 END) AS nlp,
            SUM(CASE
                WHEN a.am_status = 'late' OR a.pm_status = 'late' THEN 1
                ELSE 0 END) AS nl,
            SUM(CASE
                WHEN a.attendance_type = 'absent' THEN 1
                ELSE 0 END) AS p
        FROM students s
        INNER JOIN attendance a ON a.student_id = s.id
        WHERE s.is_active = 1
          AND s.section_id IN ($placeholders)
          AND a.date >= ? AND a.date < ?
        GROUP BY s.section_id, s.gender
    ");
    $cumStmt->execute(array_merge($sectionIds, [$cumulativeFrom, $cumulativeTo]));
    $cumBySection = [];
    foreach ($cumStmt->fetchAll() as $r) {
        $sid = $r['section_id'];
        if (!isset($cumBySection[$sid])) {
            $cumBySection[$sid] = ['M' => ['nlp' => 0, 'nl' => 0, 'p' => 0], 'F' => ['nlp' => 0, 'nl' => 0, 'p' => 0]];
        }
        $g = ($r['gender'] === 'Male') ? 'M' : 'F';
        $cumBySection[$sid][$g]['nlp'] = (int)$r['nlp'];
        $cumBySection[$sid][$g]['nl']  = (int)$r['nl'];
        $cumBySection[$sid][$g]['p']   = (int)$r['p'];
    }

    // --- 4. Assemble per-section data ---
    foreach ($allSections as $sec) {
        $sid = $sec['id'];
        $enr = $enrollBySection[$sid] ?? ['total' => 0, 'male' => 0, 'female' => 0];
        $mCur = $monthBySection[$sid]['M'] ?? ['nlp' => 0, 'nl' => 0, 'p' => 0];
        $fCur = $monthBySection[$sid]['F'] ?? ['nlp' => 0, 'nl' => 0, 'p' => 0];
        $mCum = $cumBySection[$sid]['M']   ?? ['nlp' => 0, 'nl' => 0, 'p' => 0];
        $fCum = $cumBySection[$sid]['F']   ?? ['nlp' => 0, 'nl' => 0, 'p' => 0];

        $totalEnrolled = (int)$enr['total'];
        $presentDays   = $mCur['nlp'] + $fCur['nlp'];
        $dailyAvg      = $schoolDays > 0 ? $presentDays / $schoolDays : 0;
        $possible      = $totalEnrolled * $schoolDays;
        $pct           = $possible > 0 ? round(($presentDays / $possible) * 100, 1) : 0;

        $sectionData[] = array_merge($sec, [
            'enrolled_total'  => $totalEnrolled,
            'enrolled_male'   => (int)$enr['male'],
            'enrolled_female' => (int)$enr['female'],

            'davg_male'   => $schoolDays > 0 ? round($mCur['nlp'] / $schoolDays, 1) : 0,
            'davg_female' => $schoolDays > 0 ? round($fCur['nlp'] / $schoolDays, 1) : 0,
            'davg_total'  => round($dailyAvg, 1),

            'pct' => $pct,

            'cum_m_nlp' => $mCum['nlp'],
            'cum_m_nl' => $mCum['nl'],
            'cum_m_p' => $mCum['p'],
            'cum_f_nlp' => $fCum['nlp'],
            'cum_f_nl' => $fCum['nl'],
            'cum_f_p' => $fCum['p'],
            'cum_t_nlp' => $mCum['nlp'] + $fCum['nlp'],
            'cum_t_nl'  => $mCum['nl']  + $fCum['nl'],
            'cum_t_p'   => $mCum['p']   + $fCum['p'],

            'm_m_nlp' => $mCur['nlp'],
            'm_m_nl' => $mCur['nl'],
            'm_m_p' => $mCur['p'],
            'm_f_nlp' => $fCur['nlp'],
            'm_f_nl' => $fCur['nl'],
            'm_f_p' => $fCur['p'],
            'm_t_nlp' => $mCur['nlp'] + $fCur['nlp'],
            'm_t_nl'  => $mCur['nl']  + $fCur['nl'],
            'm_t_p'   => $mCur['p']   + $fCur['p'],
        ]);
    }
}

// --- Grand totals ---
$grand = [
    'enrolled_total' => 0,
    'enrolled_male' => 0,
    'enrolled_female' => 0,
    'davg_male' => 0,
    'davg_female' => 0,
    'davg_total' => 0,
    'cum_m_nlp' => 0,
    'cum_m_nl' => 0,
    'cum_m_p' => 0,
    'cum_f_nlp' => 0,
    'cum_f_nl' => 0,
    'cum_f_p' => 0,
    'cum_t_nlp' => 0,
    'cum_t_nl' => 0,
    'cum_t_p' => 0,
    'm_m_nlp' => 0,
    'm_m_nl' => 0,
    'm_m_p' => 0,
    'm_f_nlp' => 0,
    'm_f_nl' => 0,
    'm_f_p' => 0,
    'm_t_nlp' => 0,
    'm_t_nl' => 0,
    'm_t_p' => 0,
];
foreach ($sectionData as $s) {
    foreach ($grand as $k => $_) $grand[$k] += $s[$k] ?? 0;
}
$grandPct = ($grand['enrolled_total'] * $schoolDays) > 0
    ? round(($grand['m_t_nlp'] / ($grand['enrolled_total'] * $schoolDays)) * 100, 1)
    : 0;

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- ============ PAGE HEADER ============ -->
<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>
            SF4 — Monthly Learner Movement and Attendance Report
        </h1>
        <p class="page-subtitle">DepEd Official Format</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-success btn-sm">
            <i class="bi bi-printer me-1"></i>Print / Save PDF
        </button>
        <a href="index_reports.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- ============ FILTERS ============ -->
<div class="card mb-3 no-print sf4-filter-card">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end" id="sf4FilterForm">
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
                <button type="submit" class="btn btn-primary btn-sm" id="genBtn">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="genSpin"></span>
                    <i class="bi bi-arrow-clockwise me-1"></i>Generate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ SF4 DOCUMENT ============ -->
<div class="card sf4-card">
    <div class="card-body p-3" id="sf4Document">

        <!-- Header -->
        <div class="sf4-header">
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                class="sf4-logo" alt="School Logo">
            <div class="sf4-header-text">
                <div class="sf4-republic">Republic of the Philippines</div>
                <div class="sf4-deped">Department of Education</div>
                <div class="sf4-school"><?= sanitize($schoolName) ?></div>
                <?php if ($schoolAddress): ?>
                    <div class="sf4-address"><?= sanitize($schoolAddress) ?></div>
                <?php endif; ?>
                <div class="sf4-sy">S.Y. <?= sanitize($schoolYear) ?></div>
            </div>
            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                class="sf4-logo sf4-logo-ghost" alt="DepEd Logo">
        </div>

        <div class="sf4-title-block">
            <div class="sf4-badge">
                <i class="bi bi-mortarboard-fill"></i>
                SCHOOL FORM 4 (SF4)
            </div>
            <div class="sf4-subtitle">
                MONTHLY LEARNER MOVEMENT AND ATTENDANCE REPORT
            </div>
            <div class="sf4-meta">
                Month: <strong><?= $monthLabel ?></strong>
                <span class="sf4-sep">|</span>
                S.Y.: <strong><?= sanitize($schoolYear) ?></strong>
                <span class="sf4-sep">|</span>
                No. of School Days: <strong><?= $schoolDays ?></strong>
            </div>
        </div>

        <!-- SF4 Table -->
        <div class="sf4-table-wrap">
            <table class="sf4-table">
                <thead>
                    <!-- Row 1 -->
                    <tr>
                        <th rowspan="3" class="col-grade">Grade/<br>Year Level</th>
                        <th rowspan="3" class="col-section">Section</th>
                        <th rowspan="3" class="col-adviser">Name of Adviser</th>
                        <th colspan="3" class="grp-enroll">REGISTERED LEARNERS<br>(As of End of the Month)</th>
                        <th colspan="3" class="grp-davg">DAILY AVERAGE<br>ATTENDANCE</th>
                        <th rowspan="3" class="col-pct">Percentage<br>for the<br>Month</th>
                        <th colspan="9" class="grp-cum">(A) CUMULATIVE AS OF<br>PREVIOUS MONTH</th>
                        <th colspan="9" class="grp-month">(B) FOR THE MONTH</th>
                    </tr>
                    <!-- Row 2 -->
                    <tr>
                        <th class="grp-enroll">M</th>
                        <th class="grp-enroll">F</th>
                        <th class="grp-enroll">T</th>
                        <th class="grp-davg">M</th>
                        <th class="grp-davg">F</th>
                        <th class="grp-davg">T</th>
                        <th colspan="3" class="grp-cum">NLP</th>
                        <th colspan="3" class="grp-cum">NL</th>
                        <th colspan="3" class="grp-cum">P</th>
                        <th colspan="3" class="grp-month">NLP</th>
                        <th colspan="3" class="grp-month">NL</th>
                        <th colspan="3" class="grp-month">P</th>
                    </tr>
                    <!-- Row 3 -->
                    <tr>
                        <?php for ($g = 0; $g < 3; $g++): ?>
                            <th class="grp-cum">M</th>
                            <th class="grp-cum">F</th>
                            <th class="grp-cum">T</th>
                        <?php endfor; ?>
                        <?php for ($g = 0; $g < 3; $g++): ?>
                            <th class="grp-month">M</th>
                            <th class="grp-month">F</th>
                            <th class="grp-month">T</th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $currentGrade = '';
                    $rowNum = 0;
                    foreach ($sectionData as $s):
                        if ($s['grade_level'] !== $currentGrade):
                            $currentGrade = $s['grade_level'];
                            $rowNum = 0;
                    ?>
                            <tr class="grade-row">
                                <td colspan="19">
                                    <i class="bi bi-bookmark-fill me-2"></i><?= sanitize($currentGrade) ?>
                                </td>
                            </tr>
                        <?php
                        endif;
                        $rowNum++;
                        ?>
                        <tr class="section-row">
                            <td class="c row-num-cell">
                                <span class="row-num"><?= $rowNum ?></span>
                            </td>
                            <td class="section-cell"><?= sanitize($s['section_name']) ?></td>
                            <td class="adviser-cell"><?= sanitize($s['adviser_name'] ?? '—') ?></td>

                            <!-- Registered Learners -->
                            <td class="c grp-enroll"><?= $s['enrolled_male'] ?></td>
                            <td class="c grp-enroll"><?= $s['enrolled_female'] ?></td>
                            <td class="c b grp-enroll"><?= $s['enrolled_total'] ?></td>

                            <!-- Daily Average Attendance -->
                            <td class="c grp-davg"><?= $s['davg_male'] ?></td>
                            <td class="c grp-davg"><?= $s['davg_female'] ?></td>
                            <td class="c b grp-davg"><?= $s['davg_total'] ?></td>

                            <!-- Percentage -->
                            <td class="c col-pct-cell">
                                <span class="rate-pill rate-<?= $s['pct'] >= 90 ? 'good' : ($s['pct'] >= 75 ? 'warn' : 'bad') ?>">
                                    <?= $s['pct'] ?>%
                                </span>
                            </td>

                            <!-- (A) Cumulative -->
                            <td class="c grp-cum"><?= $s['cum_m_nlp'] ?></td>
                            <td class="c grp-cum"><?= $s['cum_f_nlp'] ?></td>
                            <td class="c b grp-cum"><?= $s['cum_t_nlp'] ?></td>
                            <td class="c grp-cum"><?= $s['cum_m_nl'] ?></td>
                            <td class="c grp-cum"><?= $s['cum_f_nl'] ?></td>
                            <td class="c b grp-cum"><?= $s['cum_t_nl'] ?></td>
                            <td class="c grp-cum"><?= $s['cum_m_p'] ?></td>
                            <td class="c grp-cum"><?= $s['cum_f_p'] ?></td>
                            <td class="c b grp-cum"><?= $s['cum_t_p'] ?></td>

                            <!-- (B) For the Month -->
                            <td class="c grp-month"><?= $s['m_m_nlp'] ?></td>
                            <td class="c grp-month"><?= $s['m_f_nlp'] ?></td>
                            <td class="c b grp-month"><?= $s['m_t_nlp'] ?></td>
                            <td class="c grp-month"><?= $s['m_m_nl'] ?></td>
                            <td class="c grp-month"><?= $s['m_f_nl'] ?></td>
                            <td class="c b grp-month"><?= $s['m_t_nl'] ?></td>
                            <td class="c grp-month"><?= $s['m_m_p'] ?></td>
                            <td class="c grp-month"><?= $s['m_f_p'] ?></td>
                            <td class="c b grp-month"><?= $s['m_t_p'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="grand-row">
                        <td colspan="3" class="r b">GRAND TOTAL</td>
                        <td class="c b grp-enroll"><?= $grand['enrolled_male'] ?></td>
                        <td class="c b grp-enroll"><?= $grand['enrolled_female'] ?></td>
                        <td class="c b grp-enroll"><?= $grand['enrolled_total'] ?></td>
                        <td class="c b grp-davg"><?= round($grand['davg_male'], 1) ?></td>
                        <td class="c b grp-davg"><?= round($grand['davg_female'], 1) ?></td>
                        <td class="c b grp-davg"><?= round($grand['davg_total'], 1) ?></td>
                        <td class="c b col-pct-cell">
                            <span class="rate-pill rate-<?= $grandPct >= 90 ? 'good' : ($grandPct >= 75 ? 'warn' : 'bad') ?>">
                                <?= $grandPct ?>%
                            </span>
                        </td>

                        <td class="c b grp-cum"><?= $grand['cum_m_nlp'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_f_nlp'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_t_nlp'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_m_nl'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_f_nl'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_t_nl'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_m_p'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_f_p'] ?></td>
                        <td class="c b grp-cum"><?= $grand['cum_t_p'] ?></td>

                        <td class="c b grp-month"><?= $grand['m_m_nlp'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_f_nlp'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_t_nlp'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_m_nl'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_f_nl'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_t_nl'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_m_p'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_f_p'] ?></td>
                        <td class="c b grp-month"><?= $grand['m_t_p'] ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Legend -->
        <div class="sf4-legend">
            <span class="legend-chip"><strong>NLP</strong> = No. of Learners Present</span>
            <span class="legend-chip"><strong>NL</strong> = No. of Learners Late</span>
            <span class="legend-chip"><strong>P</strong> = No. of Learners Absent</span>
            <span class="legend-chip"><strong>M</strong> = Male</span>
            <span class="legend-chip"><strong>F</strong> = Female</span>
            <span class="legend-chip"><strong>T</strong> = Total</span>
        </div>

        <!-- Certification -->
        <div class="sf4-cert">
            I certify that the data presented herein are true and correct
            based on the attendance records of this school for the month of
            <strong><?= $monthLabel ?></strong>.
        </div>

        <!-- Signatures -->
        <div class="signature-block">
            <div class="sig-line">
                <div class="line"></div>
                <div class="name principal-name">KRISTEL IRIS ESTRELLADO IGOT</div>
                <div class="role">School Head / Principal</div>
                <div class="role muted">Date: _______________</div>
            </div>
            <div class="sig-line">
                <div class="line"></div>
                <div class="name">___________________________</div>
                <div class="role">Received by: District Supervisor</div>
                <div class="role muted">Date: _______________</div>
            </div>
        </div>

    </div>
</div>

<style>
    /* ============================================================
   SF4 — Enhanced Visual Design
   ============================================================ */

    /* ---------- Filter Card ---------- */
    .sf4-filter-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        background: #fff;
    }

    /* ---------- SF4 Document Card ---------- */
    .sf4-card {
        border: none;
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        background: #fff;
        overflow: hidden;
    }

    #sf4Document {
        padding: 22px !important;
    }

    /* ---------- Header ---------- */
    .sf4-header {
        display: flex;
        align-items: center;
        gap: 18px;
        padding-bottom: 12px;
        margin-bottom: 14px;
        border-bottom: 3px double #1e3a8a;
    }

    .sf4-logo {
        width: 80px;
        height: 80px;
        object-fit: contain;
        flex-shrink: 0;
    }

    .sf4-logo-ghost {
        opacity: 0.15;
    }

    .sf4-header-text {
        flex: 1;
        text-align: center;
        font-size: 0.82rem;
        line-height: 1.4;
    }

    .sf4-republic {
        font-size: 0.72rem;
        color: #555;
    }

    .sf4-deped {
        font-weight: 700;
        font-size: 0.9rem;
        color: #1e3a8a;
    }

    .sf4-school {
        font-weight: 800;
        font-size: 0.95rem;
    }

    .sf4-address {
        font-size: 0.75rem;
        color: #555;
    }

    .sf4-sy {
        font-size: 0.75rem;
        color: #555;
    }

    /* ---------- Title Block ---------- */
    .sf4-title-block {
        text-align: center;
        margin-bottom: 14px;
    }

    .sf4-badge {
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
        box-shadow: 0 3px 10px rgba(30, 58, 138, 0.30);
    }

    .sf4-subtitle {
        font-weight: 700;
        font-size: 0.85rem;
        margin-top: 8px;
        color: #1f2937;
        letter-spacing: 0.04em;
    }

    .sf4-meta {
        font-size: 0.78rem;
        margin-top: 6px;
        color: #374151;
    }

    .sf4-sep {
        margin: 0 8px;
        color: #9ca3af;
    }

    /* ---------- Table Wrapper ---------- */
    .sf4-table-wrap {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid #d1d5db;
    }

    /* ---------- SF4 Table ---------- */
    .sf4-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.65rem;
        table-layout: fixed;
    }

    .sf4-table th,
    .sf4-table td {
        border-right: 1px solid #cbd5e1;
        border-bottom: 1px solid #cbd5e1;
        padding: 5px 4px;
        vertical-align: middle;
        line-height: 1.35;
        word-wrap: break-word;
    }

    .sf4-table th:last-child,
    .sf4-table td:last-child {
        border-right: none;
    }

    .sf4-table tbody tr:last-child td {
        border-bottom: none;
    }

    .sf4-table thead th {
        background: #e8eef7;
        text-align: center;
        font-weight: 700;
        color: #1e293b;
        font-size: 0.62rem;
        border-bottom: 2px solid #1e3a8a;
    }

    .sf4-table thead tr:nth-child(2) th {
        background: #dde7f5;
    }

    /* ---------- Grade Header Row ---------- */
    .sf4-table .grade-row td {
        background: linear-gradient(90deg, #1e3a8a, #3b82f6);
        color: #fff;
        padding: 6px 12px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        font-size: 0.72rem;
        font-weight: 700;
    }

    /* ---------- Zebra Striping + Hover ---------- */
    .sf4-table tbody tr.section-row:nth-of-type(even) {
        background: #fafbfd;
    }

    .sf4-table tbody tr.section-row:hover {
        background: #fef9e7;
        transition: background 0.15s ease;
    }

    /* ---------- Column Group Colors ---------- */
    .sf4-table .grp-enroll {
        background: #eff6ff;
    }

    .sf4-table .grp-davg {
        background: #f0fdf4;
    }

    .sf4-table .grp-cum {
        background: #faf5ff;
    }

    .sf4-table .grp-month {
        background: #fef2f2;
    }

    /* Keep the header colors saturated */
    .sf4-table thead .grp-enroll {
        background: #dbeafe !important;
    }

    .sf4-table thead .grp-davg {
        background: #dcfce7 !important;
    }

    .sf4-table thead .grp-cum {
        background: #f3e8ff !important;
    }

    .sf4-table thead .grp-month {
        background: #fee2e2 !important;
    }

    /* Preserve group color on hover */
    .sf4-table tbody tr.section-row:hover .grp-enroll {
        background: #dbeafe;
    }

    .sf4-table tbody tr.section-row:hover .grp-davg {
        background: #dcfce7;
    }

    .sf4-table tbody tr.section-row:hover .grp-cum {
        background: #f3e8ff;
    }

    .sf4-table tbody tr.section-row:hover .grp-month {
        background: #fee2e2;
    }

    /* ---------- Cell Modifiers ---------- */
    .sf4-table .c {
        text-align: center;
    }

    .sf4-table .r {
        text-align: right;
    }

    .sf4-table .b {
        font-weight: 700;
    }

    .sf4-table .section-cell {
        font-weight: 700;
        color: #1e293b;
        padding-left: 8px;
    }

    .sf4-table .adviser-cell {
        color: #374151;
        padding-left: 8px;
    }

    .sf4-table .row-num-cell {
        padding: 3px;
    }

    /* ---------- Row Number Chip ---------- */
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

    /* ---------- Percentage Column ---------- */
    .col-pct-cell {
        padding: 3px !important;
    }

    .rate-pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-weight: 700;
        font-size: 0.62rem;
        white-space: nowrap;
    }

    .rate-good {
        background: #d1fae5;
        color: #065f46;
    }

    .rate-warn {
        background: #fef3c7;
        color: #92400e;
    }

    .rate-bad {
        background: #fee2e2;
        color: #991b1b;
    }

    /* ---------- Grand Total Row ---------- */
    .sf4-table tfoot .grand-row td {
        background: #e0e7ff !important;
        border-top: 2px solid #1e3a8a;
        font-weight: 800;
        color: #1e293b;
        padding: 6px 4px;
    }

    /* ---------- Column Widths (landscape A4 fit) ---------- */
    .sf4-table .col-grade {
        width: 62px;
    }

    .sf4-table .col-section {
        width: 110px;
    }

    .sf4-table .col-adviser {
        width: 130px;
    }

    .sf4-table .col-pct {
        width: 52px;
    }

    /* ---------- Legend ---------- */
    .sf4-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 6px 14px;
        font-size: 0.7rem;
        color: #374151;
        margin-top: 10px;
        padding: 8px 10px;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px dashed #d1d5db;
    }

    .legend-chip {
        white-space: nowrap;
    }

    /* ---------- Certification ---------- */
    .sf4-cert {
        font-size: 0.78rem;
        margin-top: 18px;
        color: #374151;
        line-height: 1.5;
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

    .sig-line .name {
        font-weight: 700;
        font-size: 0.78rem;
    }

    .sig-line .name.principal-name {
        font-weight: 800;
        font-size: 0.82rem;
        color: #1e293b;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .sig-line .role {
        font-size: 0.72rem;
        color: #555;
    }

    .sig-line .role.muted {
        color: #9ca3af;
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
        .sf4-filter-card {
            display: none !important;
        }

        .main-content,
        .content-area {
            margin: 0 !important;
            padding: 0 !important;
        }

        .card,
        .sf4-card {
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
        }

        #sf4Document {
            padding: 0 !important;
        }

        body {
            font-size: 8px;
        }

        /* Preserve ALL colors when printing */
        *,
        *::before,
        *::after {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Table: header repeat + row page-break control */
        .sf4-table {
            font-size: 0.58rem;
        }

        .sf4-table thead {
            display: table-header-group;
        }

        .sf4-table tfoot {
            display: table-footer-group;
        }

        .sf4-table tr {
            page-break-inside: avoid;
        }

        .sf4-table tbody tr.section-row:hover {
            background: inherit !important;
        }

        /* Flatten gradient for cleaner ink */
        .sf4-table .grade-row td {
            background: #1e3a8a !important;
            color: #fff !important;
        }

        .sf4-badge {
            background: #1e3a8a !important;
            box-shadow: none !important;
        }

        .signature-block {
            margin-top: 20px;
        }

        .sf4-legend {
            font-size: 0.65rem;
            padding: 4px 6px;
        }

        .sf4-cert {
            font-size: 0.7rem;
            margin-top: 10px;
        }
    }
</style>

<script>
    // Loading spinner on Generate
    document.getElementById('sf4FilterForm')?.addEventListener('submit', function() {
        document.getElementById('genSpin')?.classList.remove('d-none');
        document.getElementById('genBtn').disabled = true;
    });
</script>

<?php include '../includes/footer.php'; ?>