<?php
require_once dirname(__DIR__).'/includes/sf2_form.php';
function checkDayLabel(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
checkDayLabel(function_exists('sf2DayLabels'),'SF2 must display calendar and delivery labels instead of blank date columns');
$labels=sf2DayLabels([
 '2026-08-21'=>['type'=>'holiday','title'=>'Ninoy Aquino Day'],
 '2026-08-31'=>['type'=>'holiday','title'=>'National Heroes Day'],
 '2026-08-24'=>['type'=>'no_class','title'=>'Local suspension'],
 '2026-08-25'=>['type'=>'special_event','title'=>'School program'],
], [
 ['learning_date'=>'2026-08-10','scope_section_id'=>10,'mode'=>'modular'],
 ['learning_date'=>'2026-08-10','scope_section_id'=>0,'mode'=>'online'],
 ['learning_date'=>'2026-08-11','scope_section_id'=>0,'mode'=>'modular'],
 ['learning_date'=>'2026-08-11','scope_section_id'=>10,'mode'=>'face_to_face'],
 ['learning_date'=>'2026-08-21','scope_section_id'=>10,'mode'=>'modular'],
 ['learning_date'=>'2026-08-12','scope_section_id'=>20,'mode'=>'modular'],
],10);
checkDayLabel(($labels['2026-08-10']??'')==='MODULAR','Section delivery override must win regardless of input order');
checkDayLabel(!isset($labels['2026-08-11']),'Face-to-face override removes school-wide modular label');
checkDayLabel(($labels['2026-08-21']??'')==='NINOY AQUINO DAY','Holiday name takes precedence over a delivery schedule');
checkDayLabel(($labels['2026-08-31']??'')==='NATIONAL HEROES DAY','Holiday names must come from calendar entries');
checkDayLabel(($labels['2026-08-24']??'')==='LOCAL SUSPENSION','No-class reasons must be visible');
checkDayLabel(!isset($labels['2026-08-25'])&&!isset($labels['2026-08-12']),'Events and other sections must not label this class');
$letters='';
for($row=0;$row<12;$row++)$letters.=strip_tags(sf2DayLabelCell('MODULAR',$row,12));
checkDayLabel($letters==='MODULAR','Modular label must read vertically across learner rows');
$letters='';
for($row=0;$row<3;$row++){
    $letter=strip_tags(sf2DayLabelCell('NINOY AQUINO DAY',$row,3));
    checkDayLabel(mb_strlen($letter,'UTF-8')<=1,'Each date cell must contain at most one label character, even for small groups');
    $letters.=$letter;
}
checkDayLabel($letters==='NIN','Short groups must not squeeze the remaining letters into cells');
checkDayLabel(!str_contains(sf2DayLabelCell('<script>',0,1),'<script>'),'Calendar labels must be HTML escaped');
$clean='';
for($row=0;$row<20;$row++)$clean.=strip_tags(sf2DayLabelCell("Teacher`s Day!",$row,20));
checkDayLabel($clean==='TEACHERS DAY','Date labels must remove punctuation including backticks');
echo "SF2 calendar names, delivery precedence and vertical labels passed.\n";
