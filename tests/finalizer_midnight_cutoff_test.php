<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/attendance_finalizer.php';
$day='2026-10-27';$early=new DateTimeImmutable($day.' 03:00:00',new DateTimeZone('Asia/Manila'));
$section=['schedule_type'=>'full_day','am_in_start'=>'08:00:00','am_out_end'=>'12:00:00','pm_in_start'=>'13:00:00','pm_out_end'=>'00:00:00'];
checkEnrollment(closedSessionAbsences([],$section,$day,$early)===[],'A zero/missing full-day cutoff must not create absences at midnight');
$section['pm_out_end']='16:00:00';
checkEnrollment(closedSessionAbsences([],$section,$day,$early)===[],'A valid afternoon cutoff cannot finalize before class');
$after=closedSessionAbsences([],$section,$day,new DateTimeImmutable($day.' 16:01:00',new DateTimeZone('Asia/Manila')));
checkEnrollment($after['am_status']==='absent' && $after['pm_status']==='absent','Valid full-day finalization still runs after cutoff');
$section['schedule_type']='am_only';$section['pm_out_end']='00:00:00';
$after=closedSessionAbsences([],$section,$day,new DateTimeImmutable($day.' 12:01:00',new DateTimeZone('Asia/Manila')));
checkEnrollment(($after['am_status']??null)==='absent' && !isset($after['pm_status']),'Unused PM zero does not invalidate an AM-only schedule');
echo "Finalizer rejects midnight placeholders and respects valid AM/full-day cutoffs.\n";
