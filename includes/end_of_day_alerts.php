<?php
require_once __DIR__ . '/attendance_finalizer.php';
require_once __DIR__ . '/security_audit.php';
require_once __DIR__ . '/notification_templates.php';

/** The scan window includes its closing second; review begins one second later. */
function endOfDayCutoff(array $section, string $date): ?DateTimeImmutable {
    $end = $section[getAttendanceScheduleType($section) === 'am_only' ? 'am_out_end' : 'pm_out_end'] ?? null;
    return $end ? (new DateTimeImmutable($date . ' ' . $end, new DateTimeZone('Asia/Manila')))->modify('+1 second') : null;
}

function endOfDayReason(array $record, array $section, string $date, DateTimeImmutable $now): ?string {
    $cutoff = endOfDayCutoff($section, $date);
    if (!$cutoff || $now < $cutoff || ($record['attendance_type'] ?? '') === 'holiday'
        || trim($record['remarks'] ?? '') !== '') return null;
    foreach (['student_created_at', 'section_created_at'] as $field) {
        if (!empty($record[$field]) && new DateTimeImmutable($record[$field], $now->getTimezone()) >= $cutoff) return null;
    }
    [$in, $out] = getAttendanceEvents($section);
    if (!empty($record[$in]) && empty($record[$out])) return 'incomplete';
    foreach (['am_in', 'am_out', 'pm_in', 'pm_out'] as $field) {
        if (!empty($record[$field])) return null;
    }
    return 'absent';
}

function endOfDayRows(string $date, array $user, ?DateTimeImmutable $now = null): array {
    if (($user['role'] ?? '') !== 'teacher' || (int)($user['id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('End of Day Review is restricted to assigned teachers.');
    }
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    if ($date > $now->format('Y-m-d') || isHolidayOrNoClass($date)
        || ((int)date('N', strtotime($date)) > 5 && (getCalendarEntry($date)['type'] ?? '') !== 'school_day')) return [];
    $db = getDB();
    // No fallback to the whole school when a teacher has no assigned sections.
    $stmt = $db->prepare("SELECT s.id AS student_id, s.first_name, s.last_name, s.parent_contact,
        s.created_at AS student_created_at, sec.created_at AS section_created_at,
        sec.id AS section_id, sec.section_name, sec.grade_level, sec.schedule_type, sec.am_out_end, sec.pm_out_end,
        a.id AS attendance_id, a.am_in, a.am_out, a.pm_in, a.pm_out, a.attendance_type, a.remarks,
        n.status AS notification_status
        FROM students s JOIN sections sec ON sec.id = s.section_id
        LEFT JOIN attendance a ON a.student_id = s.id AND a.date = ?
        LEFT JOIN end_of_day_parent_alerts n ON n.student_id = s.id AND n.date = ?
        WHERE s.is_active = 1 AND s.enrollment_status = 'active' AND sec.is_active = 1"
        . ' AND sec.adviser_id = ? ORDER BY sec.section_name, s.last_name, s.first_name');
    $stmt->execute([$date, $date, $user['id']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $reason = endOfDayReason($row, $row, $date, $now);
        if ($reason) { $row['reason'] = $reason; $rows[] = $row; }
    }
    return $rows;
}

/** Validate the entire batch before writing or contacting any parents. */
function submitEndOfDayReview(string $date, array $entries, array $user, ?callable $sender = null): array {
    $sender ??= static function ($phone, $message, $id, $type): string {
        sendSMS($phone, $message, $id, $type, $status);
        return $status;
    };
    $rows = array_column(endOfDayRows($date, $user), null, 'student_id');
    foreach ($entries as $id => $entry) {
        if (!ctype_digit((string)$id) || !is_array($entry) || !isset($rows[$id])) throw new InvalidArgumentException('The review list has changed. Reload it before submitting.');
        if (!is_string($entry['remarks'] ?? '') || strlen($entry['remarks'] ?? '') > 4000) throw new InvalidArgumentException('Remarks must contain at most 4,000 bytes.');
        if (!empty($entry['notify']) && ($rows[$id]['reason'] !== 'incomplete' || empty($rows[$id]['parent_contact']))) throw new InvalidArgumentException('Parent alerts are only available for missing Time-Out scans with a parent phone number.');
    }
    $db = getDB(); $deliveries = []; $saved = 0;
    $db->beginTransaction();
    try {
        foreach ($entries as $id => $entry) {
            $remark = trim($entry['remarks'] ?? '');
            if ($remark === '' && empty($entry['notify'])) continue;
            $row = $rows[$id];
            $scope = $db->prepare("SELECT sec.*, s.created_at AS student_created_at, sec.created_at AS section_created_at,
                s.parent_contact, s.first_name, s.last_name FROM students s JOIN sections sec ON sec.id = s.section_id
                WHERE s.id = ? AND s.is_active = 1 AND s.enrollment_status = 'active' AND sec.is_active = 1"
                . ' AND sec.adviser_id = ? FOR UPDATE');
            $scope->execute([$id, $user['id']]);
            $section = $scope->fetch();
            if (!$section) throw new InvalidArgumentException('A section assignment changed. Reload the review list.');
            $row = array_merge($row, $section);
            if (!empty($entry['notify']) && empty($row['parent_contact'])) throw new InvalidArgumentException('The parent phone number was removed. Reload the review list.');
            $db->prepare("INSERT INTO attendance (student_id, date, attendance_type) VALUES (?, ?, 'absent') ON DUPLICATE KEY UPDATE id = id")->execute([$id, $date]);
            $read = $db->prepare('SELECT * FROM attendance WHERE student_id = ? AND date = ? FOR UPDATE');
            $read->execute([$id, $date]); $current = $read->fetch();
            // A simultaneous manual edit or checkout invalidates this review item.
            $reason = endOfDayReason(array_merge($row, $current), $row, $date, new DateTimeImmutable('now'));
            if (!$reason) throw new InvalidArgumentException('An attendance record changed. Reload the review list before submitting.');
            if (!empty($entry['notify']) && $reason !== 'incomplete') throw new InvalidArgumentException('A scan changed. Reload the review list before notifying a parent.');
            if ($remark !== '') {
                $beforeAudit = $current;
                $current['remarks'] = $remark;
                $type = computeAttendanceType($current, $row);
                $db->prepare("UPDATE attendance SET remarks = ?, attendance_type = ?,
                    am_status = IF(? = 'remarked', NULL, am_status), pm_status = IF(? = 'remarked', NULL, pm_status), recorded_by = ? WHERE id = ?")
                    ->execute([$remark, $type, $type, $type, $user['id'], $current['id']]);
                securityAuditContext((int)$user['id']);
                auditAttendanceAfterWrite($db, (int)$current['id'], $beforeAudit, 'attendance.review');
                $saved++;
            }
            if (!empty($entry['notify'])) {
                // Reserve once per student/day before provider I/O, even across tabs.
                $claim = $db->prepare("INSERT IGNORE INTO end_of_day_parent_alerts (student_id, date, requested_by) VALUES (?, ?, ?)");
                $claim->execute([$id, $date, $user['id']]);
                if ($claim->rowCount() === 1) $deliveries[] = $row;
            }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $sent = 0; $pending = 0; $failed = 0; $failedStudents = [];
    foreach ($deliveries as $row) {
        $message = renderNotificationText(notificationTemplate('sms_missing_checkout_template'), $row, $date);
        $status = 'unknown';
        try {
            $result = $sender($row['parent_contact'], $message, (int)$row['student_id'], 'missing_checkout');
            // Preserve boolean callbacks used by existing integrations/tests.
            $status = $result === true ? 'sent' : ($result === false ? 'failed'
                : (in_array($result, ['sent', 'pending', 'failed', 'unknown'], true) ? $result : 'unknown'));
        }
        catch (Throwable $e) { error_log('Missing checkout notification: ' . $e->getMessage()); }
        $db->prepare('UPDATE end_of_day_parent_alerts SET status = ? WHERE student_id = ? AND date = ?')->execute([$status, $row['student_id'], $date]);
        if ($status === 'sent') $sent++;
        elseif ($status === 'pending') $pending++;
        else { $failed++; $failedStudents[] = $row['first_name'] . ' ' . $row['last_name']; }
    }
    return ['saved' => $saved, 'sent' => $sent, 'pending' => $pending, 'failed' => $failed, 'failed_students' => $failedStudents];
}
