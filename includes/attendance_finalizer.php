<?php
require_once __DIR__ . '/functions.php';

function closedSessionAbsences(array $record, array $section, string $date, DateTimeImmutable $now): array
{
    if (($record['attendance_type'] ?? '') === 'holiday') return $record;
    $schedule = $section['schedule_type'];
    $sessions = $schedule === 'am_only' ? ['am'] : ($schedule === 'pm_only' ? ['pm'] : ['am', 'pm']);
    foreach ($sessions as $session) {
        $end = $section[$session . '_out_end'] ?? null;
        if (!$end) continue;
        $cutoff = new DateTimeImmutable($date . ' ' . $end, $now->getTimezone());
        if ($now < $cutoff) continue;
        foreach (['student_created_at', 'section_created_at'] as $field) {
            if (!empty($record[$field]) && new DateTimeImmutable($record[$field], $now->getTimezone()) > $cutoff) continue 2;
        }
        if (empty($record[$session . '_status']) && empty($record[$session . '_in']) && empty($record[$session . '_out'])) {
            $record[$session . '_status'] = 'absent';
        }
    }
    return $record;
}

function finalizeAttendanceAbsences(string $date, ?int $sectionId = null, ?DateTimeImmutable $now = null): int
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid attendance date');
    if ($date > $now->format('Y-m-d') || isHolidayOrNoClass($date)) return 0;
    if ((int)date('N', strtotime($date)) > 5 && (getCalendarEntry($date)['type'] ?? '') !== 'school_day') return 0;
    $db = getDB();
    $query = $db->prepare("SELECT s.id AS student_id, s.created_at AS student_created_at,
        sec.created_at AS section_created_at, sec.schedule_type, sec.am_out_end, sec.pm_out_end,
        a.am_in, a.am_out, a.pm_in, a.pm_out, a.am_status, a.pm_status, a.attendance_type
        FROM students s JOIN sections sec ON sec.id = s.section_id
        LEFT JOIN attendance a ON a.student_id = s.id AND a.date = ?
        WHERE s.is_active = 1 AND s.enrollment_status = 'active' AND sec.is_active = 1
        AND s.created_at IS NOT NULL AND sec.created_at IS NOT NULL
        AND DATE(s.created_at) <= ? AND DATE(sec.created_at) <= ?" . ($sectionId !== null ? ' AND sec.id = ?' : ''));
    $params = [$date, $date, $date];
    if ($sectionId !== null) $params[] = $sectionId;
    $query->execute($params);
    $save = $db->prepare("INSERT INTO attendance (student_id, date, am_status, pm_status, attendance_type)
        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
        am_status = IF(am_status IS NULL AND am_in IS NULL AND am_out IS NULL, VALUES(am_status), am_status),
        pm_status = IF(pm_status IS NULL AND pm_in IS NULL AND pm_out IS NULL, VALUES(pm_status), pm_status)");
    $type = $db->prepare("UPDATE attendance SET attendance_type = CASE
        WHEN ? = 'am_only' AND am_status = 'absent' THEN 'absent'
        WHEN ? = 'pm_only' AND pm_status = 'absent' THEN 'absent'
        WHEN ? = 'full_day' AND am_status = 'absent' AND pm_status = 'absent' THEN 'absent'
        ELSE attendance_type END WHERE student_id = ? AND date = ? AND attendance_type <> 'holiday'");
    $changed = 0;
    foreach ($query->fetchAll() as $record) {
        $final = closedSessionAbsences($record, $record, $date, $now);
        if (($record['am_status'] ?? null) === ($final['am_status'] ?? null) && ($record['pm_status'] ?? null) === ($final['pm_status'] ?? null)) continue;
        $schedule = $record['schedule_type'];
        $absent = $schedule === 'am_only' ? ($final['am_status'] ?? '') === 'absent' :
            ($schedule === 'pm_only' ? ($final['pm_status'] ?? '') === 'absent' :
            (($final['am_status'] ?? '') === 'absent' && ($final['pm_status'] ?? '') === 'absent'));
        $save->execute([$record['student_id'], $date, $final['am_status'] ?? null, $final['pm_status'] ?? null, $absent ? 'absent' : 'partial']);
        $type->execute([$schedule, $schedule, $schedule, $record['student_id'], $date]);
        $changed++;
    }
    return $changed;
}

function finalizeAttendanceRange(string $from, string $to, ?int $sectionId = null): int
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $end = min($to, $now->format('Y-m-d'));
    $changed = 0;
    for ($day = new DateTimeImmutable($from, $now->getTimezone()); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) {
        $changed += finalizeAttendanceAbsences($day->format('Y-m-d'), $sectionId, $now);
    }
    return $changed;
}
