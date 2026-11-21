<?php
/**
 * SMS Logs
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'SMS Logs';
$db        = getDB();
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 15;
$offset    = ($page - 1) * $perPage;
$type      = $_GET['type'] ?? '';
$search = trim((string)($_GET['search'] ?? ''));
require_once '../includes/list_view.php';

$where  = ['1=1'];
$params = [];
if ($type) { $where[] = 'l.type = ?'; $params[] = $type; }
if ($search !== '') {
    $where[] = '(l.recipient_number LIKE ? OR l.message LIKE ? OR l.status LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)';
    $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
}
$whereSQL = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM sms_logs l LEFT JOIN students s ON l.student_id=s.id WHERE {$whereSQL}");
$total->execute($params);
$total = (int)$total->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $perPage)));
$offset = ($page - 1) * $perPage;

$logs = $db->prepare("
    SELECT l.*, s.first_name, s.last_name
    FROM sms_logs l
    LEFT JOIN students s ON l.student_id = s.id
    WHERE {$whereSQL}
    ORDER BY l.sent_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$logs->execute($params);
$logs = $logs->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header analytics-template">
    <div>
        <h1 class="page-title">
            <i class="bi bi-chat-dots-fill me-2 text-primary"></i>SMS Logs
        </h1>
        <p class="page-subtitle">All outbound SMS notifications</p>
    </div>
</div>

<?php renderListSearch($search, 'Search student name, recipient number, message or status...'); ?>
<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center"><input type="hidden" name="search" value="<?= sanitize($search) ?>">
            <div class="col-md-3">
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="arrival"   <?= $type === 'arrival'   ? 'selected' : '' ?>>Arrival</option>
                    <option value="departure" <?= $type === 'departure' ? 'selected' : '' ?>>Departure</option>
                    <option value="absence"   <?= $type === 'absence'   ? 'selected' : '' ?>>Absence</option>
                    <option value="missing_checkout" <?= $type === 'missing_checkout' ? 'selected' : '' ?>>Missing Time-Out</option>
                    <option value="intervention" <?= $type === 'intervention' ? 'selected' : '' ?>>Attendance intervention</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="logs.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i> SMS Log
        <span class="badge bg-primary ms-1"><?= $total ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table data-list-title="SMS Logs" data-list-native="1" data-list-total="<?= (int)$total ?>" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Student</th>
                        <th>Recipient</th>
                        <th>Type</th>
                        <th>Delivery status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            No SMS logs found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="small text-muted">
                            <?= date('M j, Y g:i A', strtotime($log['sent_at'])) ?>
                        </td>
                        <td class="fw-600">
                            <?= $log['first_name']
                                ? sanitize($log['first_name'] . ' ' . $log['last_name'])
                                : '—' ?>
                        </td>
                        <td><code><?= sanitize($log['recipient_number']) ?></code></td>
                        <td>
                            <span class="badge bg-<?= $log['type'] === 'arrival'
                                ? 'success'
                                : ($log['type'] === 'departure' ? 'info' : 'danger') ?> bg-opacity-75">
                                <?= $log['type'] === 'missing_checkout' ? 'Missing Time-Out' : ucfirst($log['type']) ?>
                            </span>
                        </td>
                        <td><?= sanitize(ucfirst($log['status'])) ?></td>
                        <td>
                            <small class="text-muted"
                                   title="<?= htmlspecialchars($log['message']) ?>">
                                <?= htmlspecialchars(substr($log['message'], 0, 80)) ?>
                                <?= strlen($log['message']) > 80 ? '...' : '' ?>
                            </small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total > $perPage): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
        </small>
        <?= paginate($total, $perPage, $page, 'logs.php?type=' . urlencode($type) . '&search=' . urlencode($search)) ?>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
