<?php
require_once __DIR__ . '/includes/functions.php';
requireAdmin();
// Explicit denial protects this endpoint even when the shared redirect guard changes.
if (!isAdmin()) { http_response_code(403); exit('Access denied.'); }
$db = getDB(); $history = null;
$eventId = $_GET['event'] ?? '';
if (!is_string($eventId) || ($eventId !== '' && !preg_match('/^[a-f0-9]{32}$/D',$eventId))) { http_response_code(400); exit('Invalid event.'); }
if ($eventId !== '') {
    $read = $db->prepare('SELECT change_ciphertext FROM security_change_history WHERE event_id = ?'); $read->execute([$eventId]);
    $ciphertext = $read->fetchColumn();
    if (!$ciphertext) { http_response_code(404); exit('Change history not found.'); }
    try { $history = json_decode(decryptPII($ciphertext,'security-change:' . $eventId),true,512,JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { http_response_code(503); exit('Change history is unavailable.'); }
    securityAuditEvent($db,'audit.history_read');
}
$alertsOnly = ($_GET['alerts'] ?? '') === '1';
$page = filter_var($_GET['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
if ($page === false) { http_response_code(400); exit('Invalid page.'); }
$offset = ($page-1)*100;
$events = $db->query('SELECT e.*, h.event_id AS history_id FROM security_events e LEFT JOIN security_change_history h ON h.event_id=e.event_id '
    . ($alertsOnly ? 'WHERE e.alert=1 ' : '') . 'ORDER BY e.occurred_at DESC,e.event_id DESC LIMIT 100 OFFSET ' . $offset)->fetchAll();
$recentFailures = $db->query("SELECT action,COUNT(*) AS failures FROM security_events WHERE occurred_at >= NOW() - INTERVAL 15 MINUTE
    AND action IN ('auth.login','scanner.request') AND outcome IN ('failure','denied','limited') GROUP BY action HAVING COUNT(*) >= 10")->fetchAll();
$pageTitle = 'Security Audit';
include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php';
function auditEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<div class="page-header"><div><h1 class="page-title">Security Audit</h1><p class="page-subtitle">Security events and protected change history. Times are Asia/Manila.</p></div></div>
<?php foreach ($recentFailures as $failure): ?>
<div class="alert alert-danger" role="alert"><?= auditEscape($failure['action']) ?>: <?= (int)$failure['failures'] ?> unsuccessful requests in the last 15 minutes. Investigate repeated failures.</div>
<?php endforeach; ?>
<p><a class="btn btn-outline-primary" href="?alerts=1">Alerts</a> <a class="btn btn-outline-secondary" href="security_audit.php">All events</a></p>
<?php if ($history !== null): ?><div class="card mb-3"><div class="card-body"><h2 class="h5">Protected change history</h2><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= auditEscape(json_encode($history,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?></pre></div></div><?php endif; ?>
<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Time</th><th>Actor ID</th><th>Action</th><th>Outcome</th><th>Target</th><th>Request ID</th><th>History</th></tr></thead><tbody>
<?php foreach ($events as $event): ?><tr>
<td><?= auditEscape($event['occurred_at']) ?></td><td><?= auditEscape($event['actor_id'] ?? 'Anonymous / worker') ?></td>
<td><?= auditEscape($event['route_name']) ?><br><?= auditEscape($event['action']) ?><?= $event['alert'] ? ' ⚑' : '' ?></td><td><?= auditEscape($event['outcome']) ?></td>
<td><?= auditEscape($event['target_type']) ?> <?= auditEscape($event['target_id']) ?></td><td><code><?= auditEscape($event['request_id']) ?></code></td>
<td><?php if ($event['history_id']): ?><a href="?event=<?= auditEscape($event['event_id']) ?>">View change</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p><?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&amp;alerts=<?= (int)$alertsOnly ?>">Previous</a> · <?php endif; ?>Page <?= $page ?><?php if (count($events)===100 && $page<10000): ?> · <a href="?page=<?= $page+1 ?>&amp;alerts=<?= (int)$alertsOnly ?>">Next</a><?php endif; ?></p>
<?php include __DIR__ . '/includes/footer.php'; ?>
