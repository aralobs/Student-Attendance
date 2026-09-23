<?php
/**
 * Archived Users — view, restore, or permanently delete
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Archived Users';
$db        = getDB();

$users = $db->query("
    SELECT * FROM users
    WHERE is_active = 2
    ORDER BY full_name
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-archive me-2 text-primary"></i>Archived Users
        </h1>
        <p class="page-subtitle">Users hidden from the main list. Restore or permanently delete them.</p>
    </div>
    <a href="users.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Users
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox me-1"></i>No archived users.
                            </td>
                        </tr>
                    <?php else: foreach ($users as $i => $u): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-600"><?= sanitize($u['full_name']) ?></td>
                        <td><code><?= sanitize($u['username']) ?></code></td>
                        <td><?= sanitize($u['email'] ?? '—') ?></td>
                        <td>
                            <span class="badge bg-<?= $u['role'] === 'admin' ? 'warning text-dark' : 'info' ?>">
                                <?= ucfirst($u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="restore.php?id=<?= $u['id'] ?>"
                                   class="btn btn-sm btn-outline-success"
                                   onclick="return confirm('Restore this user? They will reappear in the active list.')">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                </a>
                                <a href="delete.php?id=<?= $u['id'] ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('PERMANENTLY delete this user? This cannot be undone.')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>