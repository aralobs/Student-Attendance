<?php
declare(strict_types=1);
function pdfEscape($text):string{return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function buildSchoolFormPdf(string $form,array $data):string {
    require_once dirname(__DIR__).'/vendor/autoload.php';
    if(!in_array($form,['sf2','sf4'],true))throw new InvalidArgumentException('Unsupported school form.');
    $options=new Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);
    $options->set('chroot',realpath(dirname(__DIR__).'/assets/img'));$options->set('defaultFont','DejaVu Sans');
    $pdf=new Dompdf\Dompdf($options);$pdf->setPaper('A4','landscape');
    $e='pdfEscape';$logo=dirname(__DIR__).'/assets/img/school_logo.png';
    $heading='<img class="logo" src="data:image/png;base64,'.base64_encode((string)file_get_contents($logo)).'"><h1>'.($form==='sf2'?'School Form 2 (SF2) Daily Attendance Report of Learners':"School Form 4 (SF4) Monthly Learner’s Movement and Attendance").'</h1><p class="meta">School ID: '.$e($data['schoolId']??$data['sf4SchoolId']??'').' · School: '.$e($data['schoolName']??'').' · School year: '.$e($data['schoolYear']??'').' · Month: '.$e($data['monthLabel']??'').'</p>';
    $html='<html><head><meta charset="utf-8"><style>@page{margin:9mm}body{font-family:DejaVu Sans;font-size:7pt;color:#111}h1{text-align:center;font-size:12pt;margin:0 0 3mm}.meta{text-align:center;margin-bottom:3mm}.logo{position:absolute;left:0;top:0;width:12mm;height:12mm}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:.5pt solid #333;padding:2px;text-align:center;word-wrap:break-word}th{font-size:6pt;background:#f1f3f5}.name{text-align:left}.sheet{page-break-after:always}.sheet:last-child{page-break-after:auto}.bottom{margin-top:3mm;font-size:6pt}.bottom td{vertical-align:top;text-align:left;padding:4px}.half-mark{display:block;height:5px;line-height:5px;font-size:5pt}.late-mark{background:#888}.sig{margin-top:6mm;text-align:center}.summary td{font-size:5.5pt}.summary th{font-size:5pt}.small{font-size:5.5pt}</style></head><body>';
    if($form==='sf2'){
        require_once __DIR__.'/sf2_form.php';
        if(count($data['sf2PrintPages']??[])>100)throw new DomainException('Report is too large.');
        $css=file_get_contents(dirname(__DIR__).'/assets/css/sf2_form.css');
        $html='<html><head><meta charset="utf-8"><style>@page{margin:0}body{margin:0;padding:0}'.$css.'</style></head><body class="sf2-pdf">'.renderSf2Form($data);
    }else{
        $pages=$data['sf4Pages']??[];if(count($pages)>100)throw new DomainException('Report is too large.');
        foreach($pages as $index=>$rows){$html.='<div class="sheet">'.$heading.'<table><colgroup><col width="5%" style="width:5%"><col width="9%" style="width:9%"><col width="12%" style="width:12%">';for($i=0;$i<36;$i++)$html.='<col width="'.(74/36).'%" style="width:'.(74/36).'%">';$html.='</colgroup><thead><tr><th width="5%" rowspan="2">Grade</th><th width="9%" rowspan="2">Section</th><th width="12%" rowspan="2">Adviser</th>';
            foreach(['Registered learners','Average daily attendance','Attendance (%)'] as $title)$html.='<th colspan="3" rowspan="2">'.$e($title).'</th>';
            foreach(['NLPA','Transferred out','Transferred in'] as $title)$html.='<th colspan="9">'.$e($title).'</th>';
            $html.='</tr><tr>';for($group=0;$group<3;$group++)foreach(['Previous cumulative','For the month','Cumulative total'] as $title)$html.='<th colspan="3">'.$title.'</th>';
            $html.='</tr><tr><th></th><th></th><th></th>';for($group=0;$group<12;$group++)foreach(['M','F','T'] as $g)$html.='<th>'.$g.'</th>';$html.='</tr></thead><tbody>';
            foreach($rows as $r){if(isset($r['band'])||isset($r['empty']))continue;$html.='<tr><td>'.$e($r['label']??'').'</td><td>'.$e($r['section_name']??'').'</td><td>'.$e($r['adviser_name']??'').'</td>';
                foreach(['male','female','total'] as $g)$html.='<td>'.(int)($r['enrolled_'.$g]??0).'</td>';
                foreach(['male','female','total'] as $g)$html.='<td style="font-size:5pt;white-space:nowrap">'.($data['schoolDays']>0?round(($r['present_'.$g]??0)/$data['schoolDays'],2):'').'</td>';
                foreach(['male','female','total'] as $g)$html.='<td>'.(($r['possible_'.$g]??0)>0?round($r['present_'.$g]/$r['possible_'.$g]*100).'%':'').'</td>';
                for($i=0;$i<27;$i++)$html.='<td></td>';$html.='</tr>';
            }
            $html.='</tbody></table><p class="small">Movement values remain blank where dated movement data is unavailable; absences are not dropout events.</p><div class="sig">Prepared and Submitted by: '.$e($data['schoolHead']??'').'<br>(Signature of School Head over Printed Name)</div></div>';
        }
    }
    $pdf->loadHtml($html.'</body></html>','UTF-8');$pdf->render();
    $canvas=$pdf->getCanvas();$font=$pdf->getFontMetrics()->getFont('DejaVu Sans','normal');
    $canvas->page_text(26,$canvas->get_height()-15,'Page {PAGE_NUM} of {PAGE_COUNT}',$font,6);
    $runningHeader=strtoupper($form).' | '.($data['schoolYear']??'').' | '.($data['monthLabel']??'');
    if($form==='sf4')$canvas->page_script(static function($number,$count,$canvas,$metrics)use($font,$runningHeader):void{if($number>1)$canvas->text(26,10,$runningHeader,$font,6);});
    return $pdf->output();
}
function streamSchoolFormPdf(string $form,array $data):never {
    $bytes=buildSchoolFormPdf($form,$data);$filename=$form.'-'.(int)($data['sectionId']??0).'-'.(int)$data['year'].'-'.sprintf('%02d',(int)$data['month']).'.pdf';
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');echo $bytes;exit;
}
