<?php
require_once __DIR__.'/support/enrollment_fixture.php';
$path=dirname(__DIR__).'/includes/absence_interventions.php'; if(is_file($path)) require_once $path;
checkEnrollment(function_exists('evaluateAbsenceStreak'),'Streak evaluator must exist');
function streakDays(array $statuses):array { $rows=[]; foreach($statuses as $i=>$status) $rows[]=['date'=>sprintf('2026-10-%02d',$i+1),'status'=>$status]; return $rows; }
$r=evaluateAbsenceStreak(streakDays(['absent_unexcused','absent_excused','absent_unexcused','not_eligible','absent_unexcused']));
checkEnrollment($r['count']===3 && $r['state']==='warning','Excused and holidays do not reset/count');
$r=evaluateAbsenceStreak(streakDays(array_fill(0,5,'absent_unexcused')));
checkEnrollment($r['state']==='intervention_required' && $r['threshold_date']==='2026-10-05','Five qualifies intervention');
foreach(['present','partial','late'] as $reset) checkEnrollment(evaluateAbsenceStreak(streakDays(['absent_unexcused',$reset]))['count']===0,'Confirmed attendance resets');
$r=evaluateAbsenceStreak(streakDays(['absent_unexcused','not_recorded','absent_unexcused','absent_unexcused','absent_unexcused','absent_unexcused']));
checkEnrollment($r['state']==='needs_review' && $r['count']===1,'Unresolved intervening date blocks threshold');
$r=evaluateAbsenceStreak([
    ['date'=>'2026-06-08','status'=>'not_recorded'],
    ['date'=>'2026-09-30','status'=>'absent_unexcused'],
    ['date'=>'2026-10-01','status'=>'absent_unexcused'],
]);
checkEnrollment($r['state']==='needs_review' && $r['count']===0 && $r['blocked_on']==='2026-06-08','Earlier unresolved attendance explains a zero-count review episode');
$r=evaluateAbsenceStreak(array_merge(streakDays(array_fill(0,15,'absent_unexcused')),[['date'=>'2026-10-26','status'=>'not_recorded']]));
checkEnrollment($r['state']==='needs_review' && $r['count']===15 && $r['blocked_on']==='2026-10-26','Unresolved date after counted absences retains the count but blocks intervention');
echo "Absence streak tests passed.\n";
