<?php
/** Dedicated SF4 print view. Included after the screen report. */
if (!isset($sectionData, $db, $schoolDates)) return;

function sf4PrintNumber($value) {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}
function sf4PrintGrade($grade) {
    return ['Kinder'=>'KINDER', 'Grade 1'=>'ONE', 'Grade 2'=>'TWO', 'Grade 3'=>'THREE',
        'Grade 4'=>'FOUR', 'Grade 5'=>'FIVE', 'Grade 6'=>'SIX'][$grade] ?? strtoupper($grade);
}

// Match SF2: count present/late sessions as day equivalents, excluding non-school dates.
$sf4Attendance = [];
if ($sectionIds && $schoolDates) {
    $datePlaceholders = implode(',', array_fill(0, count($schoolDates), '?'));
    $stmt = $db->prepare("SELECT s.section_id, s.gender, sec.schedule_type,
        SUM(a.am_status IN ('present', 'late')) AS am_present,
        SUM(a.pm_status IN ('present', 'late')) AS pm_present
        FROM attendance a JOIN students s ON s.id = a.student_id
        JOIN sections sec ON sec.id = s.section_id
        WHERE s.is_active = 1 AND s.section_id IN ($placeholders)
        AND a.date IN ($datePlaceholders)
        GROUP BY s.section_id, s.gender, sec.schedule_type");
    $stmt->execute(array_merge($sectionIds, $schoolDates));
    foreach ($stmt->fetchAll() as $record) {
        $gender = $record['gender'] === 'Male' ? 'male' : ($record['gender'] === 'Female' ? 'female' : null);
        if (!$gender) continue;
        $present = $record['schedule_type'] === 'am_only' ? $record['am_present']
            : ($record['schedule_type'] === 'pm_only' ? $record['pm_present']
            : ($record['am_present'] + $record['pm_present']) / 2);
        $sf4Attendance[$record['section_id']][$gender] = (float)$present;
    }
}
$sf4Rows = [];
$sf4Grades = [];
$sf4Totals = ['enrolled_male'=>0, 'enrolled_female'=>0, 'enrolled_total'=>0,
    'present_male'=>0, 'present_female'=>0, 'present_total'=>0];
// Follow the reference roster order; additional database sections remain in their grade.
$sf4Sections = $sectionData;
$sf4Order = []; $sf4OrderGrade = null;
$sf4RosterPath = __DIR__ . '/SF4_Sections_and_Advisers_SY_2026-2027.md';
if (is_readable($sf4RosterPath)) {
    foreach (file($sf4RosterPath, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, '## Differences') === 0) break;
        if (preg_match('/^## (Kinder|Grade [1-6]) \(/', $line, $match)) $sf4OrderGrade = $match[1];
        if ($sf4OrderGrade && preg_match('/^\| ([^|]+) \| ([^|]+) \|$/u', $line, $match)
            && !in_array(trim($match[1]), ['Section', '---'], true)) {
            $sf4Order[$sf4OrderGrade . '|' . strtoupper(trim($match[1]))] = count($sf4Order);
        }
    }
}
$sf4GradeOrder = array_flip(['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6']);
usort($sf4Sections, static function ($a, $b) use ($sf4Order, $sf4GradeOrder) {
    $gradeCompare = ($sf4GradeOrder[$a['grade_level']] ?? 99) <=> ($sf4GradeOrder[$b['grade_level']] ?? 99);
    if ($gradeCompare) return $gradeCompare;
    $aName = strtoupper(trim(preg_replace('/^(Kinder|Grade [1-6])\s*-\s*/i', '', $a['section_name'])));
    $bName = strtoupper(trim(preg_replace('/^(Kinder|Grade [1-6])\s*-\s*/i', '', $b['section_name'])));
    return (($sf4Order[$a['grade_level'] . '|' . $aName] ?? PHP_INT_MAX)
        <=> ($sf4Order[$b['grade_level'] . '|' . $bName] ?? PHP_INT_MAX)) ?: strnatcasecmp($aName, $bName);
});
foreach ($sf4Sections as $section) {
    $row = $section;
    $row['present_male'] = $sf4Attendance[$section['id']]['male'] ?? 0;
    $row['present_female'] = $sf4Attendance[$section['id']]['female'] ?? 0;
    $row['present_total'] = $row['present_male'] + $row['present_female'];
    $row['label'] = sf4PrintGrade($section['grade_level']);
    $sf4Rows[] = $row;
    $grade = $section['grade_level'];
    if (!isset($sf4Grades[$grade])) $sf4Grades[$grade] = $sf4Totals;
    foreach (array_keys($sf4Totals) as $key) {
        $sf4Grades[$grade][$key] += $row[$key];
    }
}
// Sum grade aggregates only after all grades are assembled.
foreach ($sf4Grades as $values) foreach ($values as $key => $value) $sf4Totals[$key] += $value;
if (!$sf4Rows) $sf4Rows[] = ['empty'=>true];
$sf4Rows[] = ['band'=>true];
foreach ($sf4Grades as $grade => $values) {
    $sf4Rows[] = array_merge($values, ['summary'=>true,
        'label'=>($grade === 'Kinder' ? 'KINDER' : ($grade === 'Grade 6' ? 'GRADE 6' : 'GRADE ' . sf4PrintGrade($grade)))]);
}
$sf4Rows[] = array_merge($sf4Totals, ['summary'=>true, 'total'=>true, 'label'=>'TOTAL']);
// Reference rows are 2.43 mm high; reserve room for the signature on the final page.
$sf4Pages = array_chunk($sf4Rows, 68);
$last = count($sf4Pages) - 1;
if (count($sf4Pages[$last]) > 58) {
    $sf4Pages[] = array_splice($sf4Pages[$last], 58);
}
// Keep the grade summary together if a page boundary would split it.
$summaryCount = count($sf4Grades) + 2;
$last = count($sf4Pages) - 1;
if ($last > 0 && count($sf4Pages[$last]) < $summaryCount) {
    $needed = $summaryCount - count($sf4Pages[$last]);
    $sf4Pages[$last] = array_merge(array_splice($sf4Pages[$last - 1], -$needed), $sf4Pages[$last]);
}
$sf4SchoolId = getSetting('school_id') ?? '';
$sf4Region = getSetting('school_region') ?: 'REGION IV-A CALABARZON';
$sf4Division = getSetting('school_division') ?: 'SAN PABLO CITY';
$sf4SchoolHead = $schoolHead;
// Column widths measured in points from the reference PDF (806.298 pt form width).
$sf4ColumnWidths = [32.478,71.214,75.982,19.070,17.580,17.282,12.813,13.409,12.515,
    20.560,16.388,20.262,20.560,16.090,14.004,15.494,12.217,19.070,14.302,17.282,21.156,
    15.792,12.813,17.282,16.090,13.409,20.262,15.792,17.878,17.878,16.984,18.772,20.262,
    16.090,14.898,20.560,21.156,20.560,30.095];
?>
<p class="no-print small text-muted mt-2">SF4 printing follows the reference form. NLPA and transfer cells are blank because dated learner movement records are unavailable.</p>
<div id="sf4PrintRoot">
<?php foreach ($sf4Pages as $pageIndex => $rows): ?>
<section class="sf4-sheet">
    <h1>School Form 4 (SF4) Monthly Learner's Movement and Attendance</h1>
    <p class="replacement">(This replaces Form 3 &amp; STS Form 4-Absenteeism and Dropout Profile)</p>
    <div class="meta first-meta">
        <span class="id-label">School ID</span><strong class="id-value"><?= sanitize($sf4SchoolId) ?></strong>
        <strong class="region-value"><?= sanitize($sf4Region) ?></strong><span class="division-label">Division</span>
        <strong class="division-value"><?= sanitize($sf4Division) ?></strong>
    </div>
    <div class="meta second-meta">
        <span class="school-label">School Name</span><strong class="school-value"><?= sanitize(strtoupper($schoolName)) ?></strong>
        <span class="year-label">School Year</span><strong class="year-value"><?= sanitize($schoolYear) ?></strong>
        <span class="month-label">Report for the Month of</span><strong class="month-value"><?= strtoupper(date('F', mktime(0, 0, 0, $month, 1, $year))) ?></strong>
    </div>
    <table class="movement">
        <colgroup><?php foreach ($sf4ColumnWidths as $width): ?><col style="width:<?= number_format($width / 806.298 * 100, 6, '.', '') ?>%"><?php endforeach; ?></colgroup>
        <thead>
        <tr><th rowspan="3">GRADE/<br>YEAR LEVEL</th><th rowspan="3">SECTION</th><th rowspan="3">NAME OF ADVISER</th>
            <th colspan="3" rowspan="2">REGISTERED<br>LEARNERS (As of End<br>of the Month)</th>
            <th colspan="6">ATTENDANCE</th><th colspan="9">NLPA</th><th colspan="9">TRANSFERRED OUT</th><th colspan="9">TRANSFERRED IN</th></tr>
        <tr><th colspan="3">Daily Average</th><th colspan="3">Percentage for the<br>Month</th>
        <?php for ($group=0; $group<3; $group++): ?>
            <th colspan="3"><?= $group === 1 ? '(A) Cumulative as<br>of Previous Month' : '(A) Cumulative as of<br>Previous Month' ?></th><th colspan="3">(B) For the Month</th>
            <th colspan="3"><?= $group === 2 ? '(A+B) Cumulative as of End of<br>the Month' : '(A+B) Cumulative as<br>of End of the Month' ?></th>
        <?php endfor; ?></tr>
        <tr><?php for ($group=0; $group<12; $group++): ?><th>M</th><th>F</th><th>T</th><?php endfor; ?></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php if (isset($row['empty'])): ?><tr><td colspan="39">No active sections available for this report.</td></tr>
            <?php elseif (isset($row['band'])): ?><tr class="summary-band"><td colspan="3">ELEMENTARY</td><td colspan="36"></td></tr>
            <?php else: ?>
            <tr class="<?= isset($row['total']) ? 'total' : '' ?>">
                <?php if (isset($row['summary'])): ?><td colspan="3"><?= sanitize($row['label']) ?></td>
                <?php else: ?><td><?= sanitize($row['label']) ?></td><td><?= sanitize(strtoupper($row['section_name'])) ?></td><td><?= sanitize(strtoupper($row['adviser_name'] ?? '')) ?></td><?php endif; ?>
                <?php foreach (['male','female','total'] as $gender): ?><td><?= (int)$row['enrolled_' . $gender] ?></td><?php endforeach; ?>
                <?php foreach (['male','female','total'] as $gender): ?><td><?= $schoolDays > 0 ? sf4PrintNumber($row['present_' . $gender] / $schoolDays) : '' ?></td><?php endforeach; ?>
                <?php foreach (['male','female','total'] as $gender): ?><td><?= $schoolDays > 0 && $row['enrolled_' . $gender] > 0 ? round($row['present_' . $gender] / ($schoolDays * $row['enrolled_' . $gender]) * 100) . '%' : '' ?></td><?php endforeach; ?>
                <?php /* Absences and lateness are not learner movement events. */ for ($i=0; $i<27; $i++): ?><td></td><?php endfor; ?>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pageIndex === count($sf4Pages) - 1): ?>
    <div class="sf4-print-footer">
        <table class="cumulative-labels"><tr><th>Previous Month/s</th><td></td><th>Cummulative as of End of Month</th><td></td></tr></table>
        <div class="prepared"><b>Prepared and Submitted by:</b><div class="signature-name"><?= sanitize(strtoupper($sf4SchoolHead)) ?></div><b>(Signature of School Head over Printed Name)</b></div>
    </div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
</div>
<style>
#sf4PrintRoot{display:none}
@media print{
    @page{size:A4 landscape;margin:0}
    html,body{margin:0!important;padding:0!important;background:#fff!important;width:297mm!important;min-width:0!important;height:auto!important}
    body> :not(#sf4PrintRoot){display:none!important}
    #sf4PrintRoot{display:block!important;margin:0!important;padding:0!important}
    #sf4PrintRoot,#sf4PrintRoot *{box-sizing:border-box;color:#000;print-color-adjust:exact;-webkit-print-color-adjust:exact}
    #sf4PrintRoot .sf4-sheet{width:297mm;padding:35.702pt 0 30pt 17.851pt;font-family:Arial,Helvetica,sans-serif;font-size:4.6483pt;line-height:1.15;break-after:page}
    #sf4PrintRoot .sf4-sheet:last-child{break-after:auto}
    #sf4PrintRoot h1{width:806.298pt;font-size:4.6483pt;font-weight:700;text-align:center;margin:0;height:6.8532pt;line-height:6.8532pt;letter-spacing:normal}
    #sf4PrintRoot .replacement{width:806.298pt;font-size:4.6483pt;font-weight:700;font-style:italic;text-align:center;margin:0;height:6.8532pt;line-height:6.8532pt}
    #sf4PrintRoot .meta{width:806.298pt;position:relative;height:6.8532pt;font-size:4.6483pt}
    #sf4PrintRoot .meta>*{position:absolute;top:0;height:6.8532pt;display:flex;align-items:center;justify-content:center;font-size:4.6483pt;line-height:1.15;white-space:nowrap}
    #sf4PrintRoot .meta strong{border:.298pt solid #000;font-weight:400}
    #sf4PrintRoot .id-label{left:124.7pt;width:54.974pt}
    #sf4PrintRoot .id-value{left:179.674pt;width:53.932pt}
    #sf4PrintRoot .region-value{left:246.336pt;width:119.782pt;font-weight:700!important}
    #sf4PrintRoot .division-label{left:366.118pt;width:60.785pt;font-weight:700}
    #sf4PrintRoot .division-value{left:426.903pt;width:181.760pt}
    #sf4PrintRoot .school-label{left:0;width:179.674pt;font-weight:700}
    #sf4PrintRoot .school-value{left:179.674pt;width:186.444pt;font-size:4.6483pt}
    #sf4PrintRoot .year-label{left:366.118pt;width:60.785pt;font-weight:700}
    #sf4PrintRoot .year-value{left:426.903pt;width:95.946pt}
    #sf4PrintRoot .month-label{left:522.849pt;width:140.304pt;font-weight:700}
    #sf4PrintRoot .month-value{left:663.153pt;width:143.145pt}
    #sf4PrintRoot table{border-collapse:collapse;margin:0;color:#000}
    #sf4PrintRoot .movement{width:806.298pt;table-layout:fixed;font-size:4.6483pt}
    #sf4PrintRoot .movement th,#sf4PrintRoot .movement td{border:.298pt solid #777;padding:0 .25pt;text-align:center;vertical-align:middle;height:6.8532pt;line-height:1.15;overflow-wrap:normal;font-weight:400}
    #sf4PrintRoot .movement th{font-weight:700;font-size:4.6483pt}
    #sf4PrintRoot .movement thead tr:nth-child(2){height:12.2166pt}
    #sf4PrintRoot .movement thead th{vertical-align:bottom}
    #sf4PrintRoot .movement thead{display:table-header-group}
    #sf4PrintRoot .movement tr{break-inside:avoid}
    #sf4PrintRoot .movement .summary-band td{height:6.8532pt;font-weight:700}
    #sf4PrintRoot .movement .total td{font-weight:700}
    #sf4PrintRoot .sf4-print-footer{width:806.298pt;display:flex;align-items:flex-end;gap:20mm;margin-top:6.8532pt;break-inside:avoid}
    #sf4PrintRoot .cumulative-labels{width:112mm;margin:0 0 0 11.5mm;table-layout:fixed;font-size:4.6483pt}
    #sf4PrintRoot .cumulative-labels th,#sf4PrintRoot .cumulative-labels td{border:.298pt solid #000;height:13.7064pt;text-align:center;padding:0}
    #sf4PrintRoot .cumulative-labels th:first-child{width:25mm}
    #sf4PrintRoot .cumulative-labels td:nth-child(2){width:27mm}
    #sf4PrintRoot .cumulative-labels th:nth-child(3){width:34mm}
    #sf4PrintRoot .prepared{width:71mm;text-align:center;font-size:4.6483pt}
    #sf4PrintRoot .signature-name{border-bottom:.298pt solid #000;padding-top:13.7064pt;font-weight:700;min-height:20.5596pt}
}
</style>
<script>
// Isolate the printed form from application containers, as in SF2.
document.body.appendChild(document.getElementById('sf4PrintRoot'));
</script>
