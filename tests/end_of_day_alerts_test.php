<?php
require_once dirname(__DIR__) . '/includes/end_of_day_alerts.php';
function eodExpect($actual, $expected, string $message): void {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
}
$date = '2026-10-09';
foreach (['am_only', 'pm_only', 'full_day'] as $schedule) {
    $section = ['grade_level' => 'Grade 3', 'schedule_type' => $schedule, 'am_out_end' => '12:00:00', 'pm_out_end' => '16:00:00'];
    [$in, $out] = getAttendanceEvents($section);
    $close = new DateTimeImmutable($date . ($schedule === 'am_only' ? ' 12:00:00' : ' 16:00:00'));
    $after = $close->modify('+1 second');
    eodExpect(endOfDayReason([], $section, $date, $close), null, 'Inclusive closing second');
    eodExpect(endOfDayReason([], $section, $date, $after), 'absent', 'No scans after close');
    eodExpect(endOfDayReason([$in => '08:00:00'], $section, $date, $after), 'incomplete', 'Missing departure');
    eodExpect(endOfDayReason([$in => '08:00:00', $out => '11:30:00'], $section, $date, $after), null, 'Complete scans');
    eodExpect(endOfDayReason([$out => '11:30:00'], $section, $date, $after), null, 'Departure only is not absence');
    foreach (['Excused', 'Sent Home Sick'] as $remark) {
        eodExpect(endOfDayReason(['remarks' => $remark], $section, $date, $after), null, 'Existing remark excludes absence');
        eodExpect(endOfDayReason([$in => '08:00:00', 'remarks' => $remark], $section, $date, $after), null, 'Existing remark excludes missed checkout');
        eodExpect(computeAttendanceType(['remarks' => $remark], $section), 'remarked', 'Remark overrides default absence');
        $record = ['remarks' => $remark, 'attendance_type' => 'remarked'];
        eodExpect(closedSessionAbsences($record, $section, $date, $after), $record, 'Finalizer preserves remark');
    }
    eodExpect(endOfDayReason(['attendance_type' => 'holiday'], $section, $date, $after), null, 'Holiday');
    eodExpect(endOfDayReason(['student_created_at' => $after->format('Y-m-d H:i:s')], $section, $date, $after), null, 'New student after closing');
    eodExpect(endOfDayReason([], array_merge($section, ['am_out_end' => null, 'pm_out_end' => null]), $date, $after), null, 'No configured cutoff');
}
echo "End-of-day classification checks passed.\n";
