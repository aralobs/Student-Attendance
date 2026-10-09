<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../includes/attendance_finalizer.php';
try {
    $from = getDB()->query("SELECT MIN(GREATEST(DATE(s.created_at), DATE(sec.created_at))) FROM students s JOIN sections sec ON sec.id = s.section_id WHERE s.is_active = 1 AND s.enrollment_status = 'active' AND sec.is_active = 1")->fetchColumn();
    $lastDate = getSetting('attendance_absence_last_date');
    if ($from && $lastDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastDate) && $lastDate <= date('Y-m-d')) {
        $from = max($from, $lastDate);
    }
    $count = $from ? finalizeAttendanceRange($from, date('Y-m-d')) : 0;
    updateSetting('attendance_absence_last_date', date('Y-m-d'));
    echo 'Finalized ' . $count . ' attendance records.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
