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
$sy        = trim($_GET['sy']       ?? '');
$grade     = trim($_GET['grade']    ?? '');
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

if ($sy !== '') {
    $where[]  = "s.school_year = ?";
    $params[] = $sy;
}

if ($grade !== '') {
    $where[]  = "s.grade_level = ?";
    $params[] = $grade;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM students s {$whereSQL}");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

// Data
$stmt = $db->prepare("
    SELECT s.*, sec.section_name,
           (SELECT attendance_type FROM attendance WHERE student_id = s.id AND date = CURDATE() LIMIT 1) AS today_status
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    {$whereSQL}
    ORDER BY s.grade_level, s.last_name, s.first_name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Sections dropdown
$sections = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();

// School years dropdown (distinct)
$schoolYears = $db->query("
    SELECT DISTINCT school_year FROM students 
    WHERE school_year IS NOT NULL AND school_year != '' 
    ORDER BY school_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

// Grade levels for filter
$gradeLevels = ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-people-fill me-2 text-primary"></i>Students</h1>
        <p class="page-subtitle">Manage kindergarten students</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="archived.php" class="btn btn-outline-warning">
            <i class="bi bi-archive me-1"></i>Archived
        </a>
        <a href="master_list.php" class="btn btn-outline-info">
            <i class="bi bi-list-columns-reverse me-1"></i>Master List
        </a>
        <?php if (isAdmin()): ?>
        <a href="section_advisers.php" class="btn btn-outline-secondary">
            <i class="bi bi-person-badge me-1"></i>Assign Advisers
        </a>
        <?php endif; ?>
        <a href="add.php" class="btn btn-primary">
            <i class="bi bi-person-plus-fill me-1"></i>Add Student
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Search name or LRN..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-2">
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
            <div class="col-md-2">
                <select name="grade" class="form-select form-select-sm">
                    <option value="">All Grades</option>
                    <?php foreach ($gradeLevels as $g): ?>
                    <option value="<?= $g ?>" <?= $grade === $g ? 'selected' : '' ?>>
                        <?= $g ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="sy" class="form-select form-select-sm">
                    <option value="">All School Years</option>
                    <?php foreach ($schoolYears as $y): ?>
                    <option value="<?= sanitize($y) ?>" <?= $sy === $y ? 'selected' : '' ?>>
                        <?= sanitize($y) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="students.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>

        <?php if ($sectionId > 0): ?>
        <div class="mt-2 small">
            <a href="master_list.php?sy=<?= urlencode($sy) ?>&grade=<?= urlencode($grade) ?>"
               class="text-decoration-none">
                <i class="bi bi-box-arrow-up-right me-1"></i>
                View this section in Master List
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Bulk action bar (shown only when rows are selected) -->
<div class="card mb-3 d-none" id="bulkBar">
    <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
        <span class="me-2"><strong id="bulkCount">0</strong> selected</span>
        <select class="form-select form-select-sm w-auto" id="bulkSection">
            <option value="">— Assign to section —</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['id'] ?>">
                <?= sanitize($sec['section_name']) ?> (<?= sanitize($sec['grade_level']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-success" onclick="bulkAssignSection()">
            <i class="bi bi-check2 me-1"></i>Apply
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">
            Clear
        </button>
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
        <form id="bulkForm" method="POST" action="bulk_assign_section.php">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:36px;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th>#</th>
                        <th>Photo</th>
                        <th>LRN</th>
                        <th>Full Name</th>
                        <th>Gender</th>
                        <th>Grade</th>
                        <th>Section</th>
                        <th>Parent Contact</th>
                        <th>Today</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-5">
                            <i class="bi bi-people fs-2 d-block mb-2 text-muted"></i>
                            No students found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input row-check"
                                   name="student_ids[]" value="<?= $s['id'] ?>">
                        </td>
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
                        <td>
                            <?php if ($s['grade_level']): ?>
                                <span class="badge bg-secondary"><?= sanitize($s['grade_level']) ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
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
                                <?php if (!empty($s['grade_level'])): ?>
                                <a href="master_list.php?grade=<?= urlencode($s['grade_level']) ?>&sy=<?= urlencode($s['school_year'] ?? '') ?>"
                                   class="btn btn-sm btn-outline-secondary" title="View in Master List">
                                    <i class="bi bi-list-columns"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (isAdmin()): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning"
                                        onclick="confirmArchive(<?= $s['id'] ?>, '<?= sanitize($s['first_name'].' '.$s['last_name']) ?>')"
                                        title="Archive">
                                    <i class="bi bi-archive"></i>
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
        </form>
    </div>
    <?php if ($total > $perPage): ?>
    <div class="card-footer d-flex justify-content-between align-items-center py-2">
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?>
        </small>
        <?= paginate($total, $perPage, $page, 'students.php?search=' . urlencode($search) . '&section=' . $sectionId . '&grade=' . urlencode($grade) . '&sy=' . urlencode($sy)) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title text-warning">
                    <i class="bi bi-archive me-2"></i>Archive Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to archive <strong id="archiveStudentName"></strong>?
                They will be moved to the archived list and can be restored later.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="archiveConfirmBtn" class="btn btn-warning btn-sm">Archive</a>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<JS
<script>
function confirmArchive(id, name) {
    document.getElementById('archiveStudentName').textContent = name;
    document.getElementById('archiveConfirmBtn').href = 'archive.php?id=' + id;
    new bootstrap.Modal(document.getElementById('archiveModal')).show();
}

// ---- Bulk selection ----
const selectAll  = document.getElementById('selectAll');
const bulkBar    = document.getElementById('bulkBar');
const bulkCount  = document.getElementById('bulkCount');
const rowChecks  = document.querySelectorAll('.row-check');

function updateBulkBar() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    bulkCount.textContent = checked;
    bulkBar.classList.toggle('d-none', checked === 0);
}

selectAll?.addEventListener('change', () => {
    rowChecks.forEach(c => c.checked = selectAll.checked);
    updateBulkBar();
});

rowChecks.forEach(c => c.addEventListener('change', updateBulkBar));

function clearSelection() {
    rowChecks.forEach(c => c.checked = false);
    if (selectAll) selectAll.checked = false;
    updateBulkBar();
}

function bulkAssignSection() {
    const sectionId = document.getElementById('bulkSection').value;
    if (!sectionId) { alert('Please choose a section first.'); return; }
    const checked = document.querySelectorAll('.row-check:checked').length;
    if (checked === 0) { alert('No students selected.'); return; }
    if (!confirm('Assign ' + checked + ' student(s) to the selected section?')) return;

    // Inject section id and submit
    const form = document.getElementById('bulkForm');
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'section_id';
    hidden.value = sectionId;
    form.appendChild(hidden);
    form.submit();
}
</script>
JS;
include '../includes/footer.php';
?>