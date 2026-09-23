<?php
/**
 * Archived Students List
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Archived Students';
$db        = getDB();

// Filters
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = ['s.is_active = 0'];
$params = [];

if ($search !== '') {
    $where[]  = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM students s {$whereSQL}");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

// Data
$stmt = $db->prepare("
    SELECT s.*, sec.section_name
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    {$whereSQL}
    ORDER BY s.last_name, s.first_name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-archive-fill me-2 text-warning"></i>Archived Students
        </h1>
        <p class="page-subtitle">Previously archived students (can be restored)</p>
    </div>
    <a href="students.php" class="btn btn-outline-primary">
        <i class="bi bi-arrow-left me-1"></i>Back to Active Students
    </a>
</div>

<!-- Search -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search name or LRN..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="archived.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Archived Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-table me-1"></i>
            Archived List
            <span class="badge bg-warning text-dark ms-1"><?= $total ?></span>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>LRN</th>
                        <th>Full Name</th>
                        <th>Gender</th>
                        <th>Section</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-archive fs-2 d-block mb-2 text-muted"></i>
                            No archived students.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td><code><?= sanitize($s['lrn']) ?></code></td>
                        <td class="fw-600">
                            <?= sanitize($s['last_name'] . ', ' . $s['first_name']) ?>
                        </td>
                        <td><?= sanitize($s['gender']) ?></td>
                        <td><?= sanitize($s['section_name'] ?? '—') ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if (isAdmin()): ?>
                                <button class="btn btn-sm btn-outline-success"
                                        onclick="confirmRestore(<?= $s['id'] ?>, '<?= sanitize($s['first_name'].' '.$s['last_name']) ?>')"
                                        title="Restore">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </div>
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
        <?= paginate($total, $perPage, $page, 'archived.php?search=' . urlencode($search)) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Restore Confirmation Modal -->
<div class="modal fade" id="restoreModal" tabstudents="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-success">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Restore Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Restore <strong id="restoreStudentName"></strong> to active students?</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="restoreConfirmBtn" class="btn btn-success btn-sm">Restore</a>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<JS
<script>
function confirmRestore(id, name) {
    document.getElementById('restoreStudentName').textContent = name;
    document.getElementById('restoreConfirmBtn').href = 'restore.php?id=' + id;
    new bootstrap.Modal(document.getElementById('restoreModal')).show();
}
</script>
JS;
include '../includes/footer.php';
?>