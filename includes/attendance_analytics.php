<?php
declare(strict_types=1);
require_once __DIR__.'/attendance_decisions.php';
function summarizeAttendanceTimeline(array $days):array {
    $s=['eligible_days'=>0,'present_units'=>0.0,'absent_units'=>0.0,'unresolved_days'=>0,'learners'=>0,'counts'=>array_fill_keys(array_keys(attendanceDecisionLabels()),0),'attendance_rate'=>null,'coverage'=>null]; $learners=[];
    foreach($days as $d) {
        if(!$d['eligible'] || in_array($d['status'],['future','not_eligible'],true)) continue;
        $s['eligible_days']++; $s['present_units']+=$d['present_units']; $s['absent_units']+=$d['absent_units'];
        $s['unresolved_days']+=(int)$d['unresolved']; $s['counts'][$d['status']]++; $learners[$d['enrollment_id']]=true;
    }
    $s['learners']=count($learners);
    if($s['eligible_days']) { $s['attendance_rate']=round($s['present_units']/$s['eligible_days']*100,1); $s['coverage']=round(($s['eligible_days']-$s['unresolved_days'])/$s['eligible_days']*100,1); }
    return $s;
}
function loadMonthlyAttendanceAnalytics(PDO $db,array $actor,int $yearId,int $calendarYear,int $month,?int $sectionId,DateTimeImmutable $now):array {
    if($month<1 || $month>12 || $calendarYear<1900 || $calendarYear>2100) throw new InvalidArgumentException('Select a valid month and year.');
    $from=sprintf('%04d-%02d-01',$calendarYear,$month); $to=(new DateTimeImmutable($from))->format('Y-m-t');
    $year=resolveEnrollmentReportYear($db,$yearId,$from,$to);
    if($to<$year['starts_on'] || $from>$year['ends_on']) throw new DomainException('The month is outside the selected school year.');
    $timeline=loadAttendanceTimeline($db,$actor,$yearId,$sectionId,$from,$to,$now); $days=$timeline['days'];
    $daily=[]; $byMode=[]; $bySection=[];
    for($day=new DateTimeImmutable($from);$day->format('Y-m-d')<=$to;$day=$day->modify('+1 day')) {
        $date=$day->format('Y-m-d'); $daily[$date]=summarizeAttendanceTimeline(array_filter($days,fn($d)=>$d['date']===$date));
        $daily[$date]['future']=$date>$now->format('Y-m-d');
    }
    foreach(deliveryLabels() as $mode=>$label) $byMode[$mode]=summarizeAttendanceTimeline(array_filter($days,fn($d)=>$d['mode']===$mode));
    foreach($timeline['report']['sections'] as $section) $bySection[(int)$section['id']]=['name'=>$section['section_name'],'summary'=>summarizeAttendanceTimeline(array_filter($days,fn($d)=>$d['section_id']===(int)$section['id']))];
    return ['summary'=>summarizeAttendanceTimeline($days),'daily'=>$daily,'by_mode'=>$byMode,'by_section'=>$bySection,'period'=>['from'=>$from,'to'=>$to],'year'=>$timeline['report']['year']];
}
