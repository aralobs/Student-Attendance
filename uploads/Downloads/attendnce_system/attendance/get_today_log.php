<?php
/**
 * AJAX — return today's attendance as JSON
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$db    = getDB();
$today = date('Y-m-d');

$rows = $db->query("
    SELECT s.first_name, s.last_name, a.time_in, a.status
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.date = '{$today}'
    ORDER BY a.created_at DESC
    LIMIT 50
")->fetchAll();

$result = array_map(fn($r) => [
    'name'    => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
    'time_in' => $r['time_in'] ? date('h:i A', strtotime($r['time_in'])) : '—',
    'status'  => $r['status'],
], $rows);

echo json_encode($result);