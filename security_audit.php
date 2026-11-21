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
require_once __DIR__ . '/includes/list_view.php';
$search = trim((string)($_GET['search'] ?? ''));
$perPage = 15;
$where = $alertsOnly ? ['e.alert=1'] : [];
$params = [];
if ($search !== '') {
    $where[] = '(e.action LIKE ? OR e.route_name LIKE ? OR e.outcome LIKE ? OR e.target_type LIKE ? OR e.target_id LIKE ? OR e.request_id LIKE ? OR e.actor_id LIKE ?)';
    $params = array_fill(0, 7, '%' . $search . '%');
}
$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$count = $db->prepare('SELECT COUNT(*) FROM security_events e' . $whereSQL);
$count->execute($params); $total = (int)$count->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $perPage)));
$offset = ($page-1)*$perPage;
$read = $db->prepare('SELECT e.*, h.event_id AS history_id FROM security_events e LEFT JOIN security_change_history h ON h.event_id=e.event_id'
    . $whereSQL . ' ORDER BY e.occurred_at DESC,e.event_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$read->execute($params); $events = $read->fetchAll();
$recentFailures = $db->query("SELECT action,COUNT(*) AS failures FROM security_events WHERE occurred_at >= NOW() - INTERVAL 15 MINUTE
    AND action IN ('auth.login','scanner.request') AND outcome IN ('failure','denied','limited') GROUP BY action HAVING COUNT(*) >= 10")->fetchAll();
$pageTitle = 'Logs';
include __DIR__ . '/includes/header.php'; include __DIR__ . '/includes/sidebar.php';
function auditEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<div class="page-header analytics-template"><div><h1 class="page-title">Logs</h1><p class="page-subtitle">Security events and protected change history. Times are Asia/Manila.</p></div></div>
<nav class="nav nav-tabs mb-3" aria-label="Security">
    <a class="nav-link active" href="<?= BASE_URL ?>security_audit.php" aria-current="page"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Logs</a>
    <a class="nav-link" href="<?= BASE_URL ?>backups/index.php"><i class="bi bi-database me-1" aria-hidden="true"></i>Database Backups</a>
</nav>
<?php foreach ($recentFailures as $failure): ?>
<div class="alert alert-danger" role="alert"><?= auditEscape($failure['action']) ?>: <?= (int)$failure['failures'] ?> unsuccessful requests in the last 15 minutes. Investigate repeated failures.</div>
<?php endforeach; ?>
<p><a class="btn btn-outline-primary" href="?alerts=1">Alerts</a> <a class="btn btn-outline-secondary" href="security_audit.php">All events</a></p>
<?php if ($history !== null): ?><div class="card mb-3"><div class="card-body"><h2 class="h5">Protected change history</h2><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= auditEscape(json_encode($history,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)) ?></pre></div></div><?php endif; ?>
<?php renderListSearch($search, 'Search actor ID, action, outcome, target or request ID...'); ?>
<div class="card"><div class="table-responsive"><table data-list-title="Security Logs" data-list-native="1" data-list-total="<?= $total ?>" class="table table-hover mb-0"><thead><tr><th>Time</th><th>Actor ID</th><th>Action</th><th>Outcome</th><th>Target</th><th>Request ID</th><th>History</th></tr></thead><tbody>
<?php foreach ($events as $event): ?><tr>
<td><?= auditEscape($event['occurred_at']) ?></td><td><?= auditEscape($event['actor_id'] ?? 'Anonymous / worker') ?></td>
<td><?= auditEscape($event['route_name']) ?><br><?= auditEscape($event['action']) ?><?= $event['alert'] ? ' ⚑' : '' ?></td><td><?= auditEscape($event['outcome']) ?></td>
<td><?= auditEscape($event['target_type']) ?> <?= auditEscape($event['target_id']) ?></td><td><code><?= auditEscape($event['request_id']) ?></code></td>
<td><?php if ($event['history_id']): ?><a href="?event=<?= auditEscape($event['event_id']) ?>">View change</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$events): ?><tr><td colspan="7" class="text-center text-muted py-4">No security events found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($total > $perPage): ?><div class="card-footer d-flex justify-content-between align-items-center py-2 flex-wrap gap-2"><small class="text-muted">Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></small><?= paginate($total,$perPage,$page,'security_audit.php?alerts='.(int)$alertsOnly.'&search='.urlencode($search).($eventId!==''?'&event='.urlencode($eventId):'')) ?></div><?php endif; ?></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
