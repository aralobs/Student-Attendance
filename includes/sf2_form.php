<?php
declare(strict_types=1);

// The original SF2 print form is shared by browser printing and PDF downloads.
function sf2PrintNumber($value) {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}
function sf2PrintSummaryValue($key, $gender, $summary, $schoolDays, $overrides) {
    if (isset($overrides[$key][$gender])) return sf2PrintNumber($overrides[$key][$gender]);
    $data = $summary[$gender] ?? ['count'=>0, 'recorded'=>0, 'five'=>0, 'present'=>0];
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

function sf2FormEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Calendar closures override delivery modes; a section setting overrides a school-wide setting. */
function sf2DayLabels(array $calendar, array $deliveryRows, int $sectionId): array {
    $modes=[]; $priorities=[];
    foreach($deliveryRows as $row) {
        $scope=(int)$row['scope_section_id'];
        if($scope!==0 && $scope!==$sectionId)continue;
        $date=$row['learning_date']; $priority=$scope===0?0:1;
        if(($priorities[$date]??-1)>$priority)continue;
        $priorities[$date]=$priority;
        $modes[$date]=$row['mode'];
    }
    $labels=[];
    foreach($modes as $date=>$mode) {
        if(in_array($mode,['modular','online'],true))$labels[$date]=strtoupper($mode);
    }
    foreach($calendar as $date=>$entry) {
        if(!in_array($entry['type'],['holiday','no_class'],true))continue;
        $title=trim((string)($entry['title']??''));
        $labels[$date]=sf2LabelText($title!==''?$title:($entry['type']==='holiday'?'Holiday':'No classes'));
    }
    return $labels;
}

function sf2LabelText(string $label): string {
    $label=preg_replace('/[^\p{L}\p{N}\s]/u','',$label);
    return mb_strtoupper(trim(preg_replace('/\s+/u',' ',$label)),'UTF-8');
}

/** One label character per learner cell; long labels continue across gender groups. */
function sf2DayLabelCell(string $label,int $row,int $rowCount): string {
    $label=sf2LabelText($label);
    if($label==='' || $row<0 || $row>=$rowCount || $row>=mb_strlen($label,'UTF-8'))return '';
    return '<span class="day-label">'.sf2FormEscape(mb_substr($label,$row,1,'UTF-8')).'</span>';
}

function renderSf2Form(array $data): string {
    $data += ['schoolId'=>'', 'schoolName'=>'', 'schoolYear'=>'', 'schoolHead'=>'',
        'generatedBy'=>'', 'section'=>[], 'schoolDays'=>0, 'sf2PrintSummary'=>[],
        'sf2PrintSummaryRows'=>[], 'sf2SummaryOverrides'=>[], 'sf2Remarks'=>[]];
    $data['monthUpper'] ??= strtoupper(explode(' ', $data['monthLabel'] ?? '')[0]);
    foreach($data['sf2PrintDays'] as &$column)$column['label']=sf2LabelText($column['label']??'');
    unset($column);
    extract($data, EXTR_SKIP);
    ob_start();
    try {
        require __DIR__.'/sf2_form_template.php';
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
