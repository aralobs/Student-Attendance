<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/enrollment_operations.php';

function requireAttendanceFinalizerWorker(): void {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Attendance finalization is restricted to the scheduled worker.');
}

/** Repair stale completion labels using only the section's required daily pair. */
function reconcileAttendanceCompletion(string $date, ?int $sectionId = null, ?PDO $db = null): int
{
    requireAttendanceFinalizerWorker();
    unset($GLOBALS['security_audit_actor'], $GLOBALS['security_audit_device']);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid attendance date');
    $db = $db ?? getDB();
    if (enrollmentModelEnabled($db)) return finalizeEnrollmentAttendanceDate($db,$date,$sectionId,
        new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')),true);
    $query = $db->prepare("SELECT a.id, a.am_in, a.am_out, a.pm_in, a.pm_out, a.attendance_type,
        sec.grade_level, sec.schedule_type
        FROM attendance a JOIN students s ON s.id = a.student_id
        JOIN sections sec ON sec.id = s.section_id
        WHERE a.date = ? AND a.attendance_type <> 'holiday'
        AND (a.am_in IS NOT NULL OR a.am_out IS NOT NULL OR a.pm_in IS NOT NULL OR a.pm_out IS NOT NULL)"
        . ($sectionId !== null ? ' AND sec.id = ?' : ''));
    $query->execute($sectionId === null ? [$date] : [$date, $sectionId]);
    // Snapshot guards prevent a refresh from overwriting a concurrent second scan.
    $save = $db->prepare("UPDATE attendance SET attendance_type = ? WHERE id = ?
        AND attendance_type <> 'holiday' AND attendance_type <> ?
        AND am_in <=> ? AND am_out <=> ? AND pm_in <=> ? AND pm_out <=> ?");
    $changed = 0;
    foreach ($query->fetchAll() as $record) {
        $type = computeAttendanceType($record, $record);
        // Leave legacy scans in unused session columns for manual review.
        if ($type === 'absent' || $record['attendance_type'] === $type) continue;
        $db->beginTransaction();
        try {
            $read = $db->prepare('SELECT * FROM attendance WHERE id = ? FOR UPDATE');
            $read->execute([$record['id']]); $beforeAudit = $read->fetch();
            $save->execute([$type, $record['id'], $type, $record['am_in'], $record['am_out'], $record['pm_in'], $record['pm_out']]);
            $affected = $save->rowCount();
            if ($affected) auditAttendanceAfterWrite($db, (int)$record['id'], $beforeAudit ?: null, 'attendance.reconcile');
            $db->commit(); $changed += $affected;
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }
    return $changed;
}

function closedSessionAbsences(array $record, array $section, string $date, DateTimeImmutable $now): array
{
    if (($record['attendance_type'] ?? '') === 'holiday' || trim($record['remarks'] ?? '') !== '') return $record;
    $schedule = getAttendanceScheduleType($section);
    $end = $section[$schedule === 'am_only' ? 'am_out_end' : 'pm_out_end'] ?? null;
    if (!$end) return $record;
    $cutoff = new DateTimeImmutable($date . ' ' . $end, $now->getTimezone());
    if ($now < $cutoff) return $record;
    foreach (['student_created_at', 'section_created_at'] as $field) {
        if (!empty($record[$field]) && new DateTimeImmutable($record[$field], $now->getTimezone()) > $cutoff) return $record;
    }
    // Missing lunch scans never mean absence.
    if (!empty($record['am_in']) || !empty($record['am_out']) || !empty($record['pm_in']) || !empty($record['pm_out'])) return $record;
    foreach ($schedule === 'am_only' ? ['am'] : ($schedule === 'pm_only' ? ['pm'] : ['am', 'pm']) as $session) {
        if (empty($record[$session . '_status'])) $record[$session . '_status'] = 'absent';
    }
    return $record;
}

function finalizeAttendanceAbsences(string $date, ?int $sectionId = null, ?DateTimeImmutable $now = null): int
{
    requireAttendanceFinalizerWorker();
    unset($GLOBALS['security_audit_actor'], $GLOBALS['security_audit_device']);
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid attendance date');
    if ($date > $now->format('Y-m-d')) return 0;
    $db=getDB();
    if (enrollmentModelEnabled($db)) return finalizeEnrollmentAttendanceDate($db,$date,$sectionId,$now);
    $completionChanged = reconcileAttendanceCompletion($date, $sectionId);
    if (isHolidayOrNoClass($date)) return $completionChanged;
    if ((int)date('N', strtotime($date)) > 5 && (getCalendarEntry($date)['type'] ?? '') !== 'school_day') return $completionChanged;
    $db = getDB();
    $query = $db->prepare("SELECT s.id AS student_id, s.created_at AS student_created_at,
        sec.created_at AS section_created_at, sec.grade_level, sec.schedule_type, sec.am_out_end, sec.pm_out_end,
        a.am_in, a.am_out, a.pm_in, a.pm_out, a.am_status, a.pm_status, a.attendance_type, a.remarks
        FROM students s JOIN sections sec ON sec.id = s.section_id
        LEFT JOIN attendance a ON a.student_id = s.id AND a.date = ?
        WHERE s.is_active = 1 AND s.enrollment_status = 'active' AND sec.is_active = 1
        AND s.created_at IS NOT NULL AND sec.created_at IS NOT NULL
        AND DATE(s.created_at) <= ? AND DATE(sec.created_at) <= ?
        AND TRIM(COALESCE(a.remarks, '')) = ''" . ($sectionId !== null ? ' AND sec.id = ?' : ''));
    $params = [$date, $date, $date];
    if ($sectionId !== null) $params[] = $sectionId;
    $query->execute($params);
    $save = $db->prepare("INSERT INTO attendance (student_id, date, am_status, pm_status, attendance_type)
        VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE
        am_status = IF(TRIM(COALESCE(remarks, '')) = '' AND am_status IS NULL AND am_in IS NULL AND am_out IS NULL AND pm_in IS NULL AND pm_out IS NULL, VALUES(am_status), am_status),
        pm_status = IF(TRIM(COALESCE(remarks, '')) = '' AND pm_status IS NULL AND am_in IS NULL AND am_out IS NULL AND pm_in IS NULL AND pm_out IS NULL, VALUES(pm_status), pm_status)");
    $type = $db->prepare("UPDATE attendance SET attendance_type = CASE
        WHEN ? = 'am_only' AND am_status = 'absent' THEN 'absent'
        WHEN ? = 'pm_only' AND pm_status = 'absent' THEN 'absent'
        WHEN ? = 'full_day' AND am_status = 'absent' AND pm_status = 'absent' THEN 'absent'
        ELSE attendance_type END WHERE student_id = ? AND date = ? AND attendance_type <> 'holiday'
        AND TRIM(COALESCE(remarks, '')) = ''");
    $changed = 0;
    foreach ($query->fetchAll() as $record) {
        $final = closedSessionAbsences($record, $record, $date, $now);
        if (($record['am_status'] ?? null) === ($final['am_status'] ?? null) && ($record['pm_status'] ?? null) === ($final['pm_status'] ?? null)) continue;
        $schedule = getAttendanceScheduleType($record);
        $absent = $schedule === 'am_only' ? ($final['am_status'] ?? '') === 'absent' :
            ($schedule === 'pm_only' ? ($final['pm_status'] ?? '') === 'absent' :
            (($final['am_status'] ?? '') === 'absent' && ($final['pm_status'] ?? '') === 'absent'));
        $db->beginTransaction();
        try {
            $read = $db->prepare('SELECT * FROM attendance WHERE student_id = ? AND date = ? FOR UPDATE');
            $read->execute([$record['student_id'], $date]); $beforeAudit = $read->fetch();
            $save->execute([$record['student_id'], $date, $final['am_status'] ?? null, $final['pm_status'] ?? null, $absent ? 'absent' : 'partial']);
            $type->execute([$schedule, $schedule, $schedule, $record['student_id'], $date]);
            $read->execute([$record['student_id'], $date]); $afterAudit = $read->fetch();
            auditAttendanceAfterWrite($db, (int)$afterAudit['id'], $beforeAudit ?: null, 'attendance.finalize');
            $db->commit(); $changed++;
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }
    return $changed + $completionChanged;
}

/** Managed worker implementation, separately injectable for disposable-DB tests.
 * Each student's transaction takes shared structural locks before profile and
 * attendance. Eligibility is re-read for this learning date, never from caches. */
function finalizeEnrollmentAttendanceDate(PDO $db, string $date, ?int $sectionId, DateTimeImmutable $now, bool $reconcileOnly = false): int
{
    requireAttendanceFinalizerWorker();
    unset($GLOBALS['security_audit_actor'],$GLOBALS['security_audit_device']);
    validatedSchoolYearDate($date);
    if ($date>$now->format('Y-m-d')) return 0;
    $calendar=$db->prepare('SELECT type FROM school_calendar WHERE date=?'); $calendar->execute([$date]);
    $calendarType=$calendar->fetchColumn();
    $noAbsences=$reconcileOnly || in_array($calendarType,['holiday','no_class'],true)
        || ((int)(new DateTimeImmutable($date))->format('N')>5 && $calendarType!=='school_day');
    $changed=0;
    foreach (enrollmentOperationalRows($db,$date,$sectionId) as $candidate) {
        $db->beginTransaction();
        try {
            $locked=lockAttendanceEnrollment($db,(int)$candidate['student_id'],$date,null,$sectionId);
            $ctx=$locked['context']; $section=$locked['section'];
            $read=$db->prepare('SELECT * FROM attendance WHERE student_id=? AND date=? FOR UPDATE');
            $read->execute([$ctx['student_id'],$date]); $before=$read->fetch(PDO::FETCH_ASSOC);
            if ($before) verifyAttendanceEnrollment($before,$ctx);
            $record=$before?:[];
            $hasScan=false;
            foreach (['am_in','am_out','pm_in','pm_out'] as $field) $hasScan=$hasScan || !empty($record[$field]);
            if ($hasScan && ($record['attendance_type']??'')!=='holiday') {
                $type=computeAttendanceType($record,$section);
                if ($type!=='absent' && $type!==$record['attendance_type']) {
                    $db->prepare('UPDATE attendance SET attendance_type=? WHERE id=?')->execute([$type,$record['id']]);
                    auditAttendanceAfterWrite($db,(int)$record['id'],$before,'attendance.reconcile'); $changed++;
                }
            } elseif (!$noAbsences) {
                // Verified annual dates are the entry boundary in managed mode.
                // Legacy profile/section creation timestamps are not eligibility.
                $final=closedSessionAbsences($record,$section,$date,$now);
                if (($record['am_status']??null)!==($final['am_status']??null) || ($record['pm_status']??null)!==($final['pm_status']??null)) {
                    if (!$before) {
                        $db->prepare("INSERT INTO attendance(student_id,date,enrollment_id,assignment_id,am_status,pm_status,attendance_type) VALUES(?,?,?,?,?,?,'absent')")
                            ->execute([$ctx['student_id'],$date,$ctx['enrollment_id'],$ctx['assignment_id'],$final['am_status']??null,$final['pm_status']??null]);
                        $id=(int)$db->lastInsertId();
                    } else {
                        $id=(int)$before['id'];
                        $schedule=getAttendanceScheduleType($section);
                        $absent=$schedule==='am_only'?($final['am_status']??'')==='absent':($schedule==='pm_only'?($final['pm_status']??'')==='absent':
                            (($final['am_status']??'')==='absent' && ($final['pm_status']??'')==='absent'));
                        $db->prepare('UPDATE attendance SET am_status=?,pm_status=?,attendance_type=? WHERE id=?')
                            ->execute([$final['am_status']??null,$final['pm_status']??null,$absent?'absent':$before['attendance_type'],$id]);
                    }
                    auditAttendanceAfterWrite($db,$id,$before?:null,'attendance.finalize'); $changed++;
                }
            }
            $db->commit();
        } catch (DomainException $error) { if ($db->inTransaction()) $db->rollBack(); /* Revoked, closed or unmapped: no ordinary write. */ }
        catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }
    return $changed;
}

function finalizeAttendanceRange(string $from, string $to, ?int $sectionId = null): int
{
    requireAttendanceFinalizerWorker();
    unset($GLOBALS['security_audit_actor'], $GLOBALS['security_audit_device']);
    foreach ([$from, $to] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid attendance date');
    }
    if ($from > $to) throw new InvalidArgumentException('Invalid attendance date range');
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $end = min($to, $now->format('Y-m-d'));
    $changed = 0;
    for ($day = new DateTimeImmutable($from, $now->getTimezone()); $day->format('Y-m-d') <= $end; $day = $day->modify('+1 day')) {
        $changed += finalizeAttendanceAbsences($day->format('Y-m-d'), $sectionId, $now);
    }
    return $changed;
}
