<?php
/**
 * Student List
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Students';
$db        = getDB();

// Filters
$search    = trim($_GET['search']   ?? '');
$sectionId = (int)($_GET['section'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 15;
$offset    = ($page - 1) * $perPage;

// Build query
$where  = ['s.is_active = 1'];
$params = [];

if ($search !== '') {
    $where[]  = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if ($sectionId > 0) {
    $where[]  = "s.section_id = ?";
    $params[] = $sectionId;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM students s {$whereSQL}");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

// Data
$stmt = $db->prepare("
    SELECT s.*, sec.section_name,
           (SELECT status FROM attendance WHERE student_id = s.id AND date = CURDATE() LIMIT 1) AS today_status
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    {$whereSQL}
    ORDER BY s.last_name, s.first_name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Sections dropdown
$sections = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-people-fill me-2 text-primary"></i>Students</h1>
        <p class="page-subtitle">Manage kindergarten students</p>
    </div>
    <a href="add.php" class="btn btn-primary">
        <i class="bi bi-person-plus-fill me-1"></i>Add Student
    </a>
</div>

<!-- Filters -->
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
            <div class="col-md-3">
                <select name="section" class="form-select form-select-sm">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>"
                            <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                        <?= sanitize($sec['section_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Student Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-table me-1"></i>
            Student List
            <span class="badge bg-primary ms-1"><?= $total ?></span>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Photo</th>
                        <th>LRN</th>
                        <th>Full Name</th>
                        <th>Gender</th>
                        <th>Section</th>
                        <th>Parent Contact</th>
                        <th>Today</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="bi bi-people fs-2 d-block mb-2 text-muted"></i>
                            No students found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td class="text-muted"><?= $offset + $i + 1 ?></td>
                        <td>
                            <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($s['photo']) ?>"
                                 class="student-photo-sm"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($s['first_name'].' '.$s['last_name']) ?>&size=40&background=1a56db&color=fff'">
                        </td>
                        <td><code><?= sanitize($s['lrn']) ?></code></td>
                        <td class="fw-600">
                            <?= sanitize($s['last_name'] . ', ' . $s['first_name']) ?>
                            <?= $s['middle_name'] ? sanitize(substr($s['middle_name'], 0, 1)) . '.' : '' ?>
                        </td>
                        <td>
                            <i class="bi bi-<?= $s['gender'] === 'Male' ? 'gender-male text-primary' : 'gender-female text-danger' ?>"></i>
                            <?= $s['gender'] ?>
                        </td>
                        <td><?= sanitize($s['section_name'] ?? '—') ?></td>
                        <td><?= sanitize($s['parent_contact'] ?? '—') ?></td>
                        <td>
                            <?php if ($s['today_status']): ?>
                            <span class="status-badge badge-<?= $s['today_status'] ?>">
                                <?= ucfirst($s['today_status']) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="view.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-info" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="edit.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="generate_qr.php?id=<?= $s['id'] ?>"
                                   class="btn btn-sm btn-outline-success" title="QR Code">
                                    <i class="bi bi-qr-code"></i>
                                </a>
                                <?php if (isAdmin()): ?>
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="confirmDelete(<?= $s['id'] ?>, '<?= sanitize($s['first_name'].' '.$s['last_name']) ?>')"
                                        title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
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
        <?= paginate($total, $perPage, $page, 'index.php?search=' . urlencode($search) . '&section=' . $sectionId) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to delete <strong id="deleteStudentName"></strong>?
                This will also remove all their attendance records.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger btn-sm">Delete</a>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<JS
<script>
function confirmDelete(id, name) {
    document.getElementById('deleteStudentName').textContent = name;
    document.getElementById('deleteConfirmBtn').href = 'delete.php?id=' + id;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
JS;
include '../includes/footer.php';
?>
