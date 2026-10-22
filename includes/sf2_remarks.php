<?php
require_once __DIR__ . '/student_access.php';
require_once __DIR__ . '/pii_security.php';
class SF2RemarkConflict extends RuntimeException {}
function sf2RemarkContext(int $studentId, int $sectionId, int $month, int $year): string {
    return "sf2:$studentId:$sectionId:$month:$year";
}
function loadSF2Remarks(PDO $db, int $sectionId, int $month, int $year): array {
    [$scope, $params] = studentSectionScope('sec');
    $stmt = $db->prepare("SELECT r.student_id, r.remark_ciphertext, r.version FROM sf2_remarks r
        JOIN students s ON s.id = r.student_id AND s.section_id = r.section_id AND s.is_active = 1
        JOIN sections sec ON sec.id = r.section_id AND sec.is_active = 1
        WHERE r.section_id = ? AND r.report_month = ? AND r.report_year = ? AND ($scope)");
    $stmt->execute(array_merge([$sectionId, $month, $year], $params));
    $remarks = [];
    foreach ($stmt->fetchAll() as $row) $remarks[(int)$row['student_id']] = [
        'text' => decryptPII($row['remark_ciphertext'], sf2RemarkContext((int)$row['student_id'], $sectionId, $month, $year)),
        'version' => (int)$row['version']];
    return $remarks;
}
function saveSF2Remark(PDO $db, int $studentId, int $sectionId, int $month, int $year, string $remark, int $expectedVersion): int {
    if ($studentId < 1 || $sectionId < 1 || $month < 1 || $month > 12 || $year < 1900 || $year > 2100 || $expectedVersion < 0
        || strlen($remark) > 4000 || !mb_check_encoding($remark, 'UTF-8') || mb_strlen($remark, 'UTF-8') > 1000 || str_contains($remark, "\0")) {
        throw new InvalidArgumentException('Invalid report details. Remarks must contain at most 1,000 characters.');
    }
    if (!isAdmin() && !isTeacher()) throw new DomainException('Report access denied.');
    [$scope, $params] = studentSectionScope('sec');
    $db->beginTransaction();
    try {
        $check = $db->prepare("SELECT s.id FROM students s JOIN sections sec ON sec.id = s.section_id
            WHERE s.id = ? AND s.section_id = ? AND s.is_active = 1 AND sec.is_active = 1 AND ($scope) FOR UPDATE");
        $check->execute(array_merge([$studentId, $sectionId], $params));
        if (!$check->fetch()) throw new DomainException('Report access denied.');
        $select = $db->prepare('SELECT remark_ciphertext, version FROM sf2_remarks WHERE student_id = ? AND section_id = ? AND report_month = ? AND report_year = ? FOR UPDATE');
        $select->execute([$studentId, $sectionId, $month, $year]);
        $previous = $select->fetch();
        $version = $previous ? (int)$previous['version'] : 0;
        if ($version !== $expectedVersion) throw new SF2RemarkConflict('This remark changed in another tab. Reload the report before editing it.');
        $context = sf2RemarkContext($studentId, $sectionId, $month, $year);
        $before = $previous ? decryptPII($previous['remark_ciphertext'], $context) : '';
        if ($before === $remark) { $db->commit(); return $version; }
        $ciphertext = encryptPII($remark, $context);
        $newVersion = $version + 1;
        $save = $db->prepare('INSERT INTO sf2_remarks (student_id,section_id,report_month,report_year,remark_ciphertext,version,updated_by)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE remark_ciphertext=VALUES(remark_ciphertext),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=NOW()');
        $save->execute([$studentId,$sectionId,$month,$year,$ciphertext,$newVersion,(int)currentUser()['id']]);
        $eventId = bin2hex(random_bytes(16));
        $audit = encryptPII(json_encode(['before'=>$before,'after'=>$remark], JSON_THROW_ON_ERROR), 'sf2-audit:' . $eventId);
        $db->prepare('INSERT INTO sf2_remark_history (event_id,student_id,section_id,report_month,report_year,actor_id,version,change_ciphertext) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$eventId,$studentId,$sectionId,$month,$year,(int)currentUser()['id'],$newVersion,$audit]);
        $db->commit(); return $newVersion;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
