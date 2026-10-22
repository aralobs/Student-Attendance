<?php
// Intentionally explicit: rotation invalidates printed cards; do not run on deployment.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/abuse_security.php';
$options = getopt('', ['legacy', 'student:', 'confirm']);
$studentId = isset($options['student']) ? filter_var($options['student'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if (!isset($options['confirm']) || (isset($options['legacy']) === isset($options['student'])) || $studentId === false) {
    fwrite(STDERR, "Usage: php migrations/rotate_student_qr_tokens.php --legacy --confirm\n   or: php migrations/rotate_student_qr_tokens.php --student=ID --confirm\nOld printed cards stop working. Reprint the affected students' cards afterward.\n");
    exit(1);
}
$db = getDB();
$db->beginTransaction();
try {
    $sql = 'SELECT id, lrn, qr_token FROM students';
    if ($studentId !== null) $sql .= ' WHERE id = ?';
    $sql .= ' FOR UPDATE';
    $stmt = $db->prepare($sql);
    $stmt->execute($studentId !== null ? [$studentId] : []);
    $update = $db->prepare('UPDATE students SET qr_token = ?, qr_code = NULL WHERE id = ?');
    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
        if (isset($options['legacy']) && preg_match('/^STU-' . preg_quote($student['lrn'], '/') . '-[A-F0-9]{64}$/D', $student['qr_token'])) continue;
        $update->execute([newStudentQRToken($student['lrn']), $student['id']]);
        $count++;
    }
    $db->commit();
    echo "Rotated {$count} student QR tokens. Reprint those attendance cards.\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "QR rotation failed; changes rolled back.\n");
    exit(1);
}
