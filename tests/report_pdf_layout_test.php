<?php
// Synthetic layout cases; no database, provider, school records or private configuration.
require __DIR__.'/report_pdf_download_test.php';
$directory=dirname(__DIR__).'/.qa/pdf';$model['sf2PrintDays']=[];
for($i=1;$i<=25;$i++)$model['sf2PrintDays'][]=['day'=>$i,'weekday'=>'M','off'=>false];
$items=[];
for($i=1;$i<=24;$i++)$items[]=['student'=>['id'=>$i,'first_name'=>'Synthetic learner with a deliberately long first name','last_name'=>'Synthetic surname with a deliberately long family name'],
    'number'=>$i,'gender'=>$i<=12?'M':'F','cells'=>array_fill(0,25,['html'=>'X','present'=>0,'recorded'=>true]),'recorded'=>25,'absent'=>25,'present'=>0];
$normal=$model;
$normal['sf2PrintPages']=[array_map(static function($item){$item['student']['first_name']='Synthetic';$item['student']['last_name']='Learner '.$item['number'];return $item;},array_slice($items,0,23))];
$normal['sf2Remarks']=[1=>['text'=>'Synthetic saved remark <check>']];
$normal['sf2PrintSummaryRows']=array_map(fn($label)=>['key'=>'sample','label'=>$label],['Enrolment as of 1st Friday of June','Late enrolment during the month','Registered Learners as of end of the month','Percentage of Enrolment','Average Daily Attendance','Percentage of Attendance for the month','Number of students absent for 5 consecutive days','Dropped out','Transferred out','Transferred in']);
$normal['sf2PrintSummary']=['M'=>['count'=>12],'F'=>['count'=>11],'TOTAL'=>['count'=>23]];
file_put_contents($directory.'/normal-sf2.pdf',buildSchoolFormPdf('sf2',$normal));
file_put_contents($directory.'/normal-sf2.html','<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4 landscape;margin:0}body{margin:0;padding:0}'.file_get_contents(dirname(__DIR__).'/assets/css/sf2_form.css').'</style></head><body>'.renderSf2Form($normal).'</body></html>');
$labelled=$normal;
$labelled['monthLabel']='August 2026';$labelled['monthUpper']='AUGUST';$labelled['schoolDays']=19;
$labelled['sf2PrintDays']=[];
for($day=1;$day<=31;$day++){
    $weekday=(int)date('N',strtotime('2026-08-'.sprintf('%02d',$day)));
    if($weekday>5)continue;
    $labelled['sf2PrintDays'][]=['day'=>$day,'weekday'=>['M','T','W','TH','F'][$weekday-1],'off'=>in_array($day,[21,31]),'label'=>[10=>'MODULAR',21=>'NINOY AQUINO DAY',31=>'NATIONAL HEROES DAY'][$day]??''];
}
while(count($labelled['sf2PrintDays'])<25)$labelled['sf2PrintDays'][]=['day'=>null,'weekday'=>'','off'=>true,'label'=>''];
foreach($labelled['sf2PrintPages'][0] as $i=>&$item){
    $item['cells']=[];
    foreach($labelled['sf2PrintDays'] as $day){
        $absent=$i===0&&$day['day']===10;
        $item['cells'][]=['html'=>$absent?'X':'','present'=>$day['off']||$absent?0:1,'absent'=>$absent?1:0,'recorded'=>!$day['off']];
    }
    $item['recorded']=19;$item['absent']=$i===0?1:0;$item['present']=19-$item['absent'];
}unset($item);
$labelledHtml=renderSf2Form($labelled);
$dom=new DOMDocument();@$dom->loadHTML($labelledHtml);$xpath=new DOMXPath($dom);
foreach(['MODULAR','NINOY AQUINO DAY','NATIONAL HEROES DAY'] as $label){
    $text='';foreach($xpath->query('//td[@data-day-label="'.$label.'"]/span[@class="day-label"]') as $span)$text.=$span->textContent;
    $expected=$label==='MODULAR'?'MODULARMODULAR':$label;
    if($text!==$expected)throw new RuntimeException('Vertical label must use one character per cell: '.$label);
}
foreach($xpath->query('//span[@class="day-label"]') as $span)if(mb_strlen($span->textContent,'UTF-8')>1)throw new RuntimeException('Multiple label characters in one date cell');
if($xpath->query('//td[@data-day-label="MODULAR"]/span[@class="attendance-mark" and text()="X"]')->length!==1)throw new RuntimeException('Modular annotation must preserve actual absence marks');
file_put_contents($directory.'/labelled-sf2.pdf',buildSchoolFormPdf('sf2',$labelled));
file_put_contents($directory.'/labelled-sf2.html','<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A4 landscape;margin:0}body{margin:0;padding:0}'.file_get_contents(dirname(__DIR__).'/assets/css/sf2_form.css').'</style></head><body>'.$labelledHtml.'</body></html>');
$small=$labelled;
$small['sf2PrintPages']=[array_slice($labelled['sf2PrintPages'][0],0,3)];
$small['sf2PrintDays'][0]['label']="Teacher`s Day!";
$smallHtml=renderSf2Form($small);
@$dom->loadHTML($smallHtml);$xpath=new DOMXPath($dom);
foreach(['TEACHERS DAY','NINOY AQUINO DAY','NATIONAL HEROES DAY'] as $label){
    $text='';foreach($xpath->query('//td[@data-day-label="'.$label.'"]/span[@class="day-label"]') as $span){
        if(mb_strlen($span->textContent,'UTF-8')!==1)throw new RuntimeException('Small rosters must retain one character per cell');
        $text.=$span->textContent;
    }
    if($text!==$label)throw new RuntimeException('Small roster must retain the whole label: '.$label);
}
if($xpath->query('//tr[@class="label-padding-row"]')->length!==16)throw new RuntimeException('Short rosters need enough blank cells for complete labels');
if($xpath->query('//td[@data-print-student-id]')->length!==3)throw new RuntimeException('Label padding must not add learners');
file_put_contents($directory.'/small-labelled-sf2.pdf',buildSchoolFormPdf('sf2',$small));
$model['sf2PrintPages']=array_chunk($items,23);$model['sf2Remarks']=[1=>['text'=>str_repeat('Synthetic report remark. ',35)]];
$model['sf2PrintSummaryRows']=array_map(fn($label)=>['key'=>'sample','label'=>$label],['Enrolment at opening','Late enrolment','Registered learners at end of month','Percentage of enrolment','Average daily attendance','Attendance percentage','Five consecutive absence days','Dropped out','Transferred out','Transferred in']);
foreach(['stress-sf2'=>['sf2',$model], 'empty-sf2'=>['sf2',array_replace($model,['sf2PrintPages'=>[[]]])], 'empty-sf4'=>['sf4',array_replace($model,['sf4Pages'=>[[['empty'=>true]]]])]] as $name=>[$form,$case]){
    $bytes=buildSchoolFormPdf($form,$case);if(!str_starts_with($bytes,'%PDF-'))throw new RuntimeException('Layout case did not generate a PDF: '.$name);
    file_put_contents($directory.'/'.$name.'.pdf',$bytes);
}
echo "Long-name, long-remark, multipage and empty-roster PDFs generated.\n";
