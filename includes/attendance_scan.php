<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security_audit.php';
require_once __DIR__ . '/enrollment_operations.php';

/**
 * Save one scan while holding the student/date row lock. A successful return
 * owns the new event and is the only request allowed to notify its parent.
 * Requires InnoDB and the unique attendance (student_id, date) key.
 */
function saveAttendanceScan(PDO $db, int $studentId, string $date, array $section, int $recordedBy, ?DateTimeImmutable $scanTime = null): array
{
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $db->beginTransaction();
        try {
            $ctx=null;
            if (enrollmentModelEnabled($db)) {
                // Resolve annual context before the attendance upsert. The passed
                // schedule is not authority and cannot forge section ownership.
                $sectionId=(int)($section['section_id']??$section['id']??0);
                $locked=lockAttendanceEnrollment($db,$studentId,$date,null,$sectionId);
                $ctx=$locked['context']; $section=$locked['section'];
                $actorRead=$db->prepare('SELECT id,role FROM users WHERE id=? AND is_active=1 LOCK IN SHARE MODE');
                $actorRead->execute([$recordedBy]); $actor=$actorRead->fetch(PDO::FETCH_ASSOC);
                if (!$actor || !in_array($actor['role'],['admin','teacher','scanner_operator'],true)
                    || ($actor['role']==='teacher' && (int)$section['adviser_id']!==$recordedBy)) throw new DomainException('Scan access denied.');
            }
            // Upsert first: SELECT FOR UPDATE alone cannot lock a missing row safely.
            // The unique key serializes competing first scans as well as later scans.
            $upsert = $db->prepare($ctx ? "INSERT INTO attendance (student_id,date,attendance_type,recorded_by,enrollment_id,assignment_id)
                VALUES (?,?,'absent',?,?,?) ON DUPLICATE KEY UPDATE id=id" : "INSERT INTO attendance (student_id, date, attendance_type, recorded_by)
                VALUES (?, ?, 'absent', ?) ON DUPLICATE KEY UPDATE id = id");
            $upsert->execute($ctx ? [$studentId,$date,$recordedBy,$ctx['enrollment_id'],$ctx['assignment_id']] : [$studentId, $date, $recordedBy]);
            $newAttendance = $upsert->rowCount() === 1;
            $read = $db->prepare('SELECT * FROM attendance WHERE student_id = ? AND date = ? FOR UPDATE');
            $read->execute([$studentId, $date]);
            $existing = $read->fetch(PDO::FETCH_ASSOC);
            $beforeAudit = $newAttendance ? null : $existing;
            if (!$existing) throw new RuntimeException('Attendance row missing after upsert');
            if ($ctx) verifyAttendanceEnrollment($existing,$ctx);

            // Take the time after acquiring the lock, since another kiosk may hold it.
            $eventTime = $scanTime ?? new DateTimeImmutable('now');
            $now = $eventTime->format('H:i:s');
            foreach (['am_in', 'am_out', 'pm_in', 'pm_out'] as $event) {
                if (!empty($existing[$event])) {
                    $lastScan = new DateTimeImmutable($date . ' ' . $existing[$event], $eventTime->getTimezone());
                    if ($eventTime->getTimestamp() - $lastScan->getTimestamp() < 300) {
                        $db->rollBack();
                        return ['success' => false, 'message' => "You're already scanned"];
                    }
                }
            }

            $event = getNextAttendanceEvent($existing, $section, $now);
            if ($event === 'complete') {
                $db->rollBack();
                return ['success' => false, 'complete' => true];
            }
            if ($event === 'outside_window') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Scan outside the scheduled Time-In or Time-Out window.'];
            }
            // Only these fixed column names may be interpolated into SQL.
            if (!in_array($event, ['am_in', 'am_out', 'pm_in', 'pm_out'], true)) {
                throw new RuntimeException('Invalid attendance event');
            }
            $params = [$now];
            $set = "$event = ?";
            if (substr($event, -3) === '_in') {
                $session = substr($event, 0, 2);
                $status = getSessionStatus($now, $session . '_late_threshold', $section);
                $set .= ", {$session}_status = ?";
                $params[] = $status;
                $existing[$session . '_status'] = $status;
            }
            // Full-day attendance has no separate afternoon arrival.
            if (getAttendanceScheduleType($section) === 'full_day' && ($event === 'am_in' || $event === 'pm_out')) {
                $set .= ', pm_status = ?';
                $params[] = 'present';
                $existing['pm_status'] = 'present';
            }
            $params[] = $existing['id'];
            $save = $db->prepare("UPDATE attendance SET $set WHERE id = ? AND $event IS NULL");
            $save->execute($params);
            if ($save->rowCount() !== 1) throw new RuntimeException('Attendance event already saved');
            $existing[$event] = $now;
            $type = computeAttendanceType($existing, $section);
            $db->prepare('UPDATE attendance SET attendance_type = ? WHERE id = ?')
                ->execute([$type, $existing['id']]);
            $existing['attendance_type'] = $type;
            securityAuditContext($recordedBy);
            auditAttendanceAfterWrite($db, (int)$existing['id'], $beforeAudit, 'attendance.scan');
            $db->commit();
            return ['success' => true, 'event' => $event, 'time' => $now, 'record' => $existing];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Retry only database contention, after rolling back the whole attempt.
            // No external notification has happened at this point.
            $driverCode = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
            if ($attempt < 2 && in_array($driverCode, [1213, 1205], true)) {
                usleep(random_int(10000, 50000));
                continue;
            }
            throw $e;
        }
    }
}
