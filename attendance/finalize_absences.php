<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/attendance_finalizer.php';
$lockDirectory = __DIR__ . '/../storage/private';
if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0700, true) && !is_dir($lockDirectory)) { fwrite(STDERR, "Finalizer storage is unavailable.\n"); exit(1); }
$lock = fopen($lockDirectory . '/attendance-finalizer.lock', 'c');
if (!$lock) { fwrite(STDERR, "Finalizer lock is unavailable.\n"); exit(1); }
if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); echo "Finalizer already running.\n"; exit; }
$exitCode = 0;
try {
    $from = enrollmentModelEnabled(getDB())
        ? getDB()->query("SELECT MIN(GREATEST(y.starts_on,e.starts_on,es.starts_on)) FROM student_enrollments e JOIN school_years y ON y.id=e.school_year_id JOIN enrollment_sections es ON es.enrollment_id=e.id JOIN sections sec ON sec.id=es.section_id JOIN students s ON s.id=e.student_id WHERE y.state='active' AND e.state='active' AND s.is_active=1 AND sec.is_active=1 AND sec.school_year_id=y.id")->fetchColumn()
        : getDB()->query("SELECT MIN(GREATEST(DATE(s.created_at), DATE(sec.created_at))) FROM students s JOIN sections sec ON sec.id = s.section_id WHERE s.is_active = 1 AND s.enrollment_status = 'active' AND sec.is_active = 1")->fetchColumn();
    $lastDate = getSetting('attendance_absence_last_date');
    if ($from && $lastDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastDate) && $lastDate <= date('Y-m-d')) {
        $from = max($from, $lastDate);
    }
    // Bound each catch-up batch; the cursor continues at the next scheduled run.
    $end = $from ? min(date('Y-m-d'), (new DateTimeImmutable($from))->modify('+30 days')->format('Y-m-d')) : date('Y-m-d');
    $count = $from && $from <= $end ? finalizeAttendanceRange($from, $end) : 0;
    updateSetting('attendance_absence_last_date', $end);
    echo 'Finalized ' . $count . ' attendance records.' . PHP_EOL;
} catch (Throwable $e) {
    $reference = bin2hex(random_bytes(12));
    error_log('Attendance finalizer failed request_id=' . $reference);
    fwrite(STDERR, 'Finalization failed. Reference: ' . $reference . PHP_EOL);
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN); fclose($lock);
}
exit($exitCode);
