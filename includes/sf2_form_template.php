<div id="sf2PrintRoot" aria-hidden="true">
<?php foreach ($sf2PrintPages as $sf2PageIndex => $sf2PageItems):
    $labelGroupSizes=array_count_values(array_column($sf2PageItems,'gender'));
    $labelRowCount=$labelGroupSizes?min($labelGroupSizes):0;
    $labelTotalRows=count($sf2PageItems);
    $labelRow=0;
    $spanDayLabels=[];
    foreach($sf2PrintDays as $column) {
        $dayLabel=$column['label']??'';
        $length=mb_strlen($dayLabel,'UTF-8');
        $spanDayLabels[]=$length>$labelRowCount;
        $labelTotalRows=max($labelTotalRows,$length);
    }
?>
<section class="sheet">
  <h1>School Form 2 (SF2) Daily Attendance Report of Learners</h1>
  <div class="subtitle">(This replaces Form 1, Form 2 &amp; STS Form 4 - Absenteeism and Dropout Profile)</div>

  <div class="meta">
    <label class="id-label">School ID</label><span class="id-value print-value"><?= sf2FormEscape($schoolId) ?></span>
    <label class="year-label">School Year</label><span class="year-value print-value"><?= sf2FormEscape(preg_replace('/\s*-\s*/', ' - ', $schoolYear)) ?></span>
    <label class="month-label">Report for the Month of</label><span class="month-value print-value"><?= sf2FormEscape($monthUpper) ?></span>
    <label class="school-label">Name of School</label><span class="school-value print-value"><?= sf2FormEscape(strtoupper($schoolName)) ?></span>
    <label class="grade-label">Grade Level</label><span class="grade-value print-value"><?= sf2FormEscape($section['grade_level'] ?? '') ?></span>
    <label class="section-label">Section</label><span class="section-value print-value"><?= sf2FormEscape($section['section_name'] ?? '') ?></span>
  </div>

  <table class="attendance">
    <colgroup><col width="2.8%" style="width:2.8%"><col width="16.5%" style="width:16.5%">
    <?php foreach ($sf2PrintDays as $column): ?><col width="<?= 51 / count($sf2PrintDays) ?>% " style="width:<?= 51 / count($sf2PrintDays) ?>% "><?php endforeach; ?>
    <col width="6.1%" style="width:6.1%"><col width="6.1%" style="width:6.1%"><col width="17%" style="width:17%"></colgroup>
    <thead>
      <tr class="column-widths" aria-hidden="true"><th style="width:2.8%"></th><th style="width:16.5%"></th>
      <?php foreach ($sf2PrintDays as $column): ?><th style="width:<?= 51 / count($sf2PrintDays) ?>%"></th><?php endforeach; ?>
      <th style="width:6.1%"></th><th style="width:6.1%"></th><th style="width:17%"></th></tr>
      <tr><th width="2.8%" style="width:2.8%" rowspan="3">No.</th><th width="16.5%" style="width:16.5%" rowspan="3">NAME<br><span class="learner-name-format">(Last Name, First Name, Middle Name)</span></th><th width="51%" style="width:51%" class="date-caption" colspan="<?= count($sf2PrintDays) ?>">(1st row for date)</th><th width="12.2%" style="width:12.2%" class="month-total-header" colspan="2" rowspan="2">Total for the<br>Month</th><th width="17%" style="width:17%" class="remarks-header" rowspan="3"><b>REMARKS</b> (If DROPPED OUT, state reason, please refer to legend number 2. If TRANSFERRED IN/OUT, write the name of School.)</th></tr>
      <tr><?php foreach ($sf2PrintDays as $column): ?><th title="<?= sf2FormEscape($column['label'] ?? '') ?>" width="<?= 51 / count($sf2PrintDays) ?>%" style="width:<?= 51 / count($sf2PrintDays) ?>%"><?= $column['day'] ?? '' ?></th><?php endforeach; ?></tr>
      <tr><?php foreach ($sf2PrintDays as $column): ?><th><?= $column['weekday'] ?></th><?php endforeach; ?><th width="6.1%" style="width:6.1%">ABSENT</th><th width="6.1%" style="width:6.1%">PRESENT</th></tr>
    </thead>
    <tbody>
    <?php foreach (['M' => 'MALE', 'F' => 'FEMALE', 'U' => 'UNSPECIFIED'] as $gender => $label):
        $pageGroup = array_values(array_filter($sf2PageItems, static function ($item) use ($gender) { return $item['gender'] === $gender; }));
        if (!$pageGroup) continue;
        foreach ($pageGroup as $groupRow => $item): $student = $item['student']; ?>
      <tr><td><?= $item['number'] ?>.</td><td class="name"><?= sf2FormEscape(strtoupper($student['last_name'] . ', ' . $student['first_name'] . (!empty($student['middle_name']) ? ' ' . $student['middle_name'] : ''))) ?></td>
      <?php foreach ($item['cells'] as $columnIndex => $cell):
          $dayLabel=$sf2PrintDays[$columnIndex]['label']??'';
          $labelHtml=sf2DayLabelCell($dayLabel,$spanDayLabels[$columnIndex]?$labelRow:$groupRow,$spanDayLabels[$columnIndex]?$labelTotalRows:count($pageGroup)); ?>
        <td class="att-cell<?= $labelHtml!=='' ? ' labelled-day' : '' ?>"<?= $dayLabel!=='' ? ' data-day-label="'.sf2FormEscape($dayLabel).'"' : '' ?>><?= $labelHtml ?><?= $labelHtml!=='' && $cell['html']!=='' ? '<span class="attendance-mark">'.$cell['html'].'</span>' : $cell['html'] ?></td>
      <?php endforeach; ?>
      <td><?= $item['recorded'] > 0 ? sf2PrintNumber($item['absent']) : '' ?></td><td><?= $item['recorded'] > 0 ? sf2PrintNumber($item['present']) : '' ?></td><td class="remarks" data-print-student-id="<?= (int)($student['id'] ?? 0) ?>"><?= sf2FormEscape(is_array($sf2Remarks[$student['id'] ?? 0] ?? null) ? ($sf2Remarks[$student['id']]['text'] ?? '') : ($sf2Remarks[$student['id'] ?? 0] ?? '')) ?></td></tr>
    <?php $labelRow++; endforeach;
        echo sf2PrintTotalRow($pageGroup, count($pageGroup) . '. <=== ' . $label . ' | TOTAL Per Day ===>', $sf2PrintDays);
    endforeach;
    // Extra blank rows keep the entire label visible for a small roster; they are never counted as learners.
    for(;$labelRow<$labelTotalRows;$labelRow++): ?>
      <tr class="label-padding-row"><td></td><td></td>
      <?php foreach($sf2PrintDays as $column): $dayLabel=$column['label']??''; ?>
        <td class="att-cell"<?= $dayLabel!=='' ? ' data-day-label="'.sf2FormEscape($dayLabel).'"' : '' ?>><?= sf2DayLabelCell($dayLabel,$labelRow,$labelTotalRows) ?></td>
      <?php endforeach; ?><td></td><td></td><td></td></tr>
    <?php endfor;
    echo sf2PrintTotalRow($sf2PageItems, count($sf2PageItems) . '. Combined TOTAL Per Day' . (count($sf2PrintPages) > 1 ? ' (this page)' : ''), $sf2PrintDays); ?>
    </tbody>
  </table>

  <table class="bottom"><tr>
    <td width="50%" class="box guidelines">
      <h3>GUIDELINES:</h3>
      <p>1. The attendance shall be accomplished daily. Refer to the codes for checking learners' attendance.</p>
      <p>2. Dates shall be written in the columns after Learner's Name.</p>
      <p>3. To compute the following:</p>

      <table class="formula"><tr><td width="39%">a. Percentage of Enrolment =</td><td width="50%" class="fraction"><span>Registered Learners as of end of the month</span><span>Enrolment as of 1st Friday of the school year</span></td><td width="11%">&times; 100</td></tr></table>
      <table class="formula"><tr><td width="39%">b. Average Daily Attendance =</td><td width="50%" class="fraction"><span>Total Daily Attendance</span><span>Number of School Days in reporting month</span></td><td width="11%"></td></tr></table>
      <table class="formula"><tr><td width="39%">c. Percentage of Attendance for the month =</td><td width="50%" class="fraction"><span>Average daily attendance</span><span>Registered Learners as of end of the month</span></td><td width="11%">&times; 100</td></tr></table>
      <p>4. Every end of the month, the class adviser will submit this form to the office of the principal for recording of summary table into School Form 4. Once signed by the principal, this form should be returned to the adviser.</p>
      <p>5. The adviser will provide necessary interventions including but not limited to home visitation to learners who were absent for 5 consecutive days and/or those at risk of dropping out.</p>
      <p>6. Attendance performance of learners will be reflected in Form 137 and Form 138 every grading period.</p>
      <p class="cutoff">*Beginning of School Year cut-off report is every 1st Friday of the School Year</p>
    </td>
    <td width="20%" class="legend-column"><div class="box legend">
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
    </div><div class="generated"><b><?= sf2FormEscape($generatedBy) ?></b><span>Generated thru LIS</span></div></td>
    <td width="30%" class="summary-column">
      <div class="box summary">
        <table>
          <colgroup><col width="62%" style="width:62%"><col width="10%" style="width:10%"><col width="10%" style="width:10%"><col width="18%" style="width:18%"></colgroup>
          <tr><th rowspan="2" class="summary-month"><span>Month : <?= sf2FormEscape($monthUpper) ?></span><span>No. of Days of<br>Classes: <?= $schoolDays ?></span></th><th colspan="3">Summary</th></tr>
          <tr><th>M</th><th>F</th><th>TOTAL</th></tr>
          <?php foreach ($sf2PrintSummaryRows as $summaryRow): ?>
          <tr><td><?= sf2FormEscape($summaryRow['label']) ?></td>
          <?php foreach (['M','F','TOTAL'] as $gender): ?><td><?= sf2PrintSummaryValue($summaryRow['key'], $gender, $sf2PrintSummary, $schoolDays, $sf2SummaryOverrides ?? []) ?></td><?php endforeach; ?>
          </tr><?php endforeach; ?>
        </table>
      </div>
      <p class="certify">I certify that this is a true and correct report.</p>
      <div class="signatures">
        <div><div class="signature-space"></div><div class="sigline"><span class="signature-name"><?= sf2FormEscape($section['adviser_name'] ?? '') ?></span><small>(Signature of Adviser over Printed Name)</small></div></div>
        <div><p class="attested">Attested by:</p><div class="signature-space"></div><div class="sigline"><b class="signature-name"><?= sf2FormEscape($schoolHead) ?></b><small>(Signature of School Head over Printed Name)</small></div></div>
      </div>
    </td>
  </tr></table>
<?php if (count($sf2PrintPages) > 1): ?><p class="page-count">Page <?= $sf2PageIndex + 1 ?> of <?= count($sf2PrintPages) ?> &middot; Daily totals on this page; summary for the entire class.</p><?php endif; ?>
</section>
<?php endforeach; ?>
</div>

