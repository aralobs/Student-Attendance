<?php
require_once dirname(__DIR__) . '/includes/attendance_finalizer.php';
function expectTwo($actual, $expected, string $message): void {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
}
foreach (getGradeLevels() as $grade) {
    $rule = getScheduleForGrade($grade);
    $amOnly = in_array($grade, ['Kinder', 'Grade 1', 'Grade 2'], true);
    $type = $amOnly ? 'am_only' : 'full_day';
    $section = array_merge($rule['am'], $rule['pm'] ?? [], ['grade_level' => $grade, 'schedule_type' => $rule['default_type']]);
    $out = $amOnly ? 'am_out' : 'pm_out';
    $outTime = $amOnly ? '11:30:00' : '15:30:00';
    expectTwo($rule['default_type'], $type, "$grade default");
    expectTwo($rule['allowed_types'], ['am_only', 'pm_only', 'full_day'], "$grade allowed types");
    expectTwo(getAttendanceEvents($section), ['am_in', $out], "$grade two events");
    expectTwo(getNextAttendanceEvent(null, $section, '07:59:59'), 'outside_window', "$grade early arrival");
    expectTwo(getNextAttendanceEvent(null, $section, '08:00:00'), 'am_in', "$grade arrival");
    expectTwo(getNextAttendanceEvent(null, $section, '08:30:00'), 'am_in', "$grade inclusive arrival close");
    expectTwo(getNextAttendanceEvent(null, $section, '08:30:01'), 'outside_window', "$grade late arrival window");
    expectTwo(getNextAttendanceEvent(null, $section, '15:30:00'), 'outside_window', "$grade cannot arrive in PM");
    $record = ['am_in' => '08:01:01', 'am_status' => 'late'];
    expectTwo(getSessionStatus($record['am_in'], 'am_late_threshold', $section), 'late', "$grade lateness");
    expectTwo(computeAttendanceType($record, $section), 'partial', "$grade awaiting departure");
    expectTwo(getNextAttendanceEvent($record, $section, '08:06:01'), 'outside_window', "$grade repeat cannot create lunch checkout");
    expectTwo(getNextAttendanceEvent($record, $section, $outTime), $out, "$grade departure");
    $record[$out] = $outTime;
    expectTwo(computeAttendanceType($record, $section), 'full_day', "$grade two scans complete");
    expectTwo(getNextAttendanceEvent($record, $section, '16:30:00'), 'complete', "$grade rejects third scan");
    $section['am_out_end'] = '12:00:00';
    $section['pm_out_end'] = '16:00:00';
    $end = new DateTimeImmutable('2026-10-09 ' . ($amOnly ? '12:00:00' : '16:00:00'));
    expectTwo(closedSessionAbsences($record, $section, '2026-10-09', $end), $record, "$grade complete never absent");
    $arrival = ['am_in' => '08:00:00', 'am_status' => 'present'];
    expectTwo(closedSessionAbsences($arrival, $section, '2026-10-09', $end), $arrival, "$grade missing checkout is not absence");
    expectTwo(closedSessionAbsences([], $section, '2026-10-09', $end->modify('-1 second')), [], "$grade not absent before day closes");
    $absent = $amOnly ? ['am_status' => 'absent'] : ['am_status' => 'absent', 'pm_status' => 'absent'];
    expectTwo(closedSessionAbsences([], $section, '2026-10-09', $end), $absent, "$grade daily absence");
    $holiday = ['attendance_type' => 'holiday'];
    expectTwo(closedSessionAbsences($holiday, $section, '2026-10-09', $end), $holiday, "$grade holiday");
    $manual = ['am_status' => 'present', 'pm_status' => 'present'];
    expectTwo(closedSessionAbsences($manual, $section, '2026-10-09', $end), $manual, "$grade manual status preserved");
    $newStudent = ['student_created_at' => '2026-10-09 17:00:00'];
    expectTwo(closedSessionAbsences($newStudent, $section, '2026-10-09', $end), $newStudent, "$grade creation cutoff");
}
// Explicit section choices override either grade default without adding scans.
foreach (getGradeLevels() as $grade) {
    foreach (['am_only', 'full_day'] as $chosen) {
        $section = array_merge(getScheduleForGrade($grade)['am'], getScheduleForGrade('Grade 3')['pm'],
            ['grade_level' => $grade, 'schedule_type' => $chosen]);
        $out = $chosen === 'am_only' ? 'am_out' : 'pm_out';
        expectTwo(getAttendanceScheduleType($section), $chosen, "$grade chosen schedule honored");
        expectTwo(getAttendanceEvents($section), ['am_in', $out], "$grade override has two events");
        $record = ['am_in' => '08:00:00'];
        $time = $chosen === 'am_only' ? '11:30:00' : '15:30:00';
        expectTwo(getNextAttendanceEvent($record, $section, $time), $out, "$grade override departure");
        $record[$out] = $time;
        expectTwo(computeAttendanceType($record, $section), 'full_day', "$grade override complete");
        expectTwo(getNextAttendanceEvent($record, $section, '17:00:00'), 'complete', "$grade override rejects third scan");
        $end = new DateTimeImmutable('2026-10-09 12:00:00');
        $expected = $chosen === 'am_only' ? ['am_status' => 'absent'] : [];
        expectTwo(closedSessionAbsences([], $section, '2026-10-09', $end), $expected, "$grade override absence cutoff");
    }
}
foreach (getGradeLevels() as $grade) {
    $section = array_merge(getScheduleForGrade($grade)['am'], getScheduleForGrade('Grade 3')['pm'],
        ['grade_level' => $grade, 'schedule_type' => 'pm_only']);
    expectTwo(getAttendanceEvents($section), ['pm_in', 'pm_out'], "$grade PM pair");
    expectTwo(getNextAttendanceEvent(null, $section, '08:00:00'), 'outside_window', "$grade no morning PM-only arrival");
    expectTwo(getNextAttendanceEvent(null, $section, '12:59:59'), 'outside_window', "$grade PM arrival opens");
    expectTwo(getNextAttendanceEvent(null, $section, '13:00:00'), 'pm_in', "$grade PM arrival accepted");
    expectTwo(getNextAttendanceEvent(null, $section, '13:30:00'), 'pm_in', "$grade PM arrival inclusive close");
    expectTwo(getNextAttendanceEvent(null, $section, '13:30:01'), 'outside_window', "$grade PM arrival closed");
    expectTwo(getSessionStatus('13:01:01', 'pm_late_threshold', $section), 'late', "$grade PM lateness");
    $record = ['pm_in' => '13:01:01', 'pm_status' => 'late'];
    expectTwo(computeAttendanceType($record, $section), 'partial', "$grade PM arrival partial");
    expectTwo(getNextAttendanceEvent($record, $section, '13:06:01'), 'outside_window', "$grade no premature PM checkout");
    expectTwo(getNextAttendanceEvent($record, $section, '15:30:00'), 'pm_out', "$grade PM checkout");
    $record['pm_out'] = '15:30:00';
    expectTwo(computeAttendanceType($record, $section), 'full_day', "$grade two PM scans complete");
    expectTwo(getNextAttendanceEvent($record, $section, '16:30:00'), 'complete', "$grade no third PM scan");
    expectTwo(closedSessionAbsences([], $section, '2026-10-09', new DateTimeImmutable('2026-10-09 15:59:59')), [], "$grade PM pending before close");
    expectTwo(closedSessionAbsences([], $section, '2026-10-09', new DateTimeImmutable('2026-10-09 16:00:00')), ['pm_status' => 'absent'], "$grade PM absence only");
    expectTwo(closedSessionAbsences($record, $section, '2026-10-09', new DateTimeImmutable('2026-10-09 16:00:00')), $record, "$grade PM completion not absent");
}
// Session presence or scans in unused columns cannot complete the daily pair.
foreach (['am_only', 'full_day', 'pm_only'] as $schedule) {
    $section = ['schedule_type' => $schedule];
    [$in, $out] = getAttendanceEvents($section);
    $record = ['am_status' => 'present', 'pm_status' => 'present', 'attendance_type' => 'full_day'];
    expectTwo(computeAttendanceType($record, $section), 'absent', "$schedule statuses are not scans");
    $record[$in] = '08:00:00';
    expectTwo(computeAttendanceType($record, $section), 'partial', "$schedule one required scan overrides stale full-day label");
    $record[$out] = '15:30:00';
    $record['attendance_type'] = 'partial';
    expectTwo(computeAttendanceType($record, $section), 'full_day', "$schedule two required scans override stale partial label");
}
echo "Two-scan attendance checks passed for Kinder and Grades 1-6.\n";
