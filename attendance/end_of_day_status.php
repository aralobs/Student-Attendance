<?php
require_once __DIR__ . '/../includes/end_of_day_alerts.php';
header('Content-Type: application/json');
sendNoCacheHeaders();
requireLogin(false);
if (!isTeacher()) { http_response_code(403); echo json_encode(['error' => 'Assigned teachers only.']); exit; }
try {
    $now = new DateTimeImmutable('now'); $date = $now->format('Y-m-d');
    $rows = endOfDayRows($date, currentUser(), $now);
    $next = 30000;
    foreach (getAllowedSections() as $section) {
        $cutoff = endOfDayCutoff($section, $date);
        if ($cutoff && $cutoff > $now) $next = min($next, max(1000, ($cutoff->getTimestamp() - $now->getTimestamp()) * 1000));
    }
    echo json_encode(['count' => count($rows), 'date' => $date, 'next_check_ms' => $next]);
} catch (Throwable $e) {
    error_log('End-of-day status: ' . $e->getMessage());
    http_response_code(503); echo json_encode(['error' => 'End-of-day review unavailable.']);
}
