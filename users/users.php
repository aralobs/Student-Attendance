<?php
/**
 * User Management - Listing
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'User Management';
$db        = getDB();

// Main list: exclude archived users (is_active = 2)
$users = $db->query("
    SELECT * FROM users
    WHERE is_active != 2
    ORDER BY role, full_name
")->fetchAll();

// Count archived users for the tab badge
$archivedCount = (int)$db->query("
    SELECT COUNT(*) FROM users WHERE is_active = 2
")->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-person-gear me-2 text-primary"></i>User Management</h1>
        <p class="page-subtitle">Manage admin and teacher accounts</p>
    </div>
    <div class="d-flex gap-2">
        <a href="archived.php" class="btn btn-outline-secondary">
            <i class="bi bi-archive me-1"></i>Archived
            <?php if ($archivedCount): ?>
                <span class="badge bg-secondary ms-1"><?= $archivedCount ?></span>
            <?php endif; ?>
        </a>
        <a href="add.php" class="btn btn-primary">
            <i class="bi bi-person-plus me-1"></i>Add User
        </a>
    </div>
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
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $i => $u): ?>
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
                                <?php if ((int)$u['is_active'] === 1): ?>
                                    <span class="status-badge badge-present">Hired</span>
                                <?php elseif ((int)$u['is_active'] === 2): ?>
                                    <span class="status-badge badge-absent">Archived</span>
                                <?php else: ?>
                                    <span class="status-badge badge-absent">Resigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="edit.php?id=<?= (int)$u['id'] ?>"
                                       class="btn btn-sm btn-outline-primary"
                                       title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="toggle.php?id=<?= (int)$u['id'] ?>"
                                       class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'danger' : 'success' ?>"
                                       title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"
                                       onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                                        <i class="bi bi-<?= $u['is_active'] ? 'person-x' : 'person-check' ?>"></i>
                                    </a>
                                    <?php if ((int)$u['id'] !== (int)currentUser()['id']): ?>
                                    <a href="archive.php?id=<?= (int)$u['id'] ?>"
                                       class="btn btn-sm btn-outline-secondary"
                                       title="Archive"
                                       onclick="return confirm('Archive this user? They will be hidden from the list but kept in the database.')">
                                        <i class="bi bi-archive"></i>
                                    </a>
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
</div>

<?php include '../includes/footer.php'; ?>