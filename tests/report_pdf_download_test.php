<?php
$path=dirname(__DIR__).'/includes/report_pdf.php';if(is_file($path))require_once $path;
if(!function_exists('buildSchoolFormPdf'))throw new RuntimeException('Actual PDF renderer must exist');
$model=['schoolName'=>'Synthetic School','schoolYear'=>'2026-2027','schoolId'=>'000001','monthLabel'=>'October 2026','schoolHead'=>'Synthetic Head',
 'section'=>['section_name'=>'Synthetic Section','grade_level'=>'Grade 3','adviser_name'=>'Synthetic Adviser'],
 'sf2PrintDays'=>[['day'=>20,'weekday'=>'T','off'=>false]],'sf2PrintPages'=>[[['student'=>['id'=>1,'first_name'=>'Synthetic','last_name'=>'Learner'],'number'=>1,'gender'=>'M','cells'=>[['html'=>'X','present'=>0,'recorded'=>true]],'recorded'=>1,'absent'=>1,'present'=>0]]],
 'sf2PrintSummaryRows'=>[],'sf2PrintSummary'=>[],'schoolDays'=>1,'sf2Remarks'=>[]];
$pdf=buildSchoolFormPdf('sf2',$model);if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)<1000)throw new RuntimeException('Expected PDF bytes, not HTML redirect');
$dir=dirname(__DIR__).'/.qa/pdf';if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($dir.'/synthetic-sf2.pdf',$pdf);
echo "PDF renderer passed (synthetic SF2).\n";
