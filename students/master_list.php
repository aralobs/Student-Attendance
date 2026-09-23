<?php
/**
 * Master List — Sections with Advisers and their Students
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Master List';
$db        = getDB();

// ---------------------------------------------------------------
// Handle inline adviser update (AJAX POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_adviser') {
    header('Content-Type: application/json');
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Admin only.']);
        exit;
    }
    $sectionId = (int)($_POST['section_id'] ?? 0);
    $adviserId = ($_POST['adviser_id'] === '' || $_POST['adviser_id'] === null)
                    ? null
                    : (int)$_POST['adviser_id'];

    if ($sectionId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid section.']);
        exit;
    }
    try {
        $stmt = $db->prepare("UPDATE sections SET adviser_id = ? WHERE id = ?");
        $stmt->execute([$adviserId, $sectionId]);

        // Fetch the new adviser name for the response
        $adviserName = null;
        if ($adviserId) {
            $n = $db->prepare("SELECT full_name FROM users WHERE id = ?");
            $n->execute([$adviserId]);
            $adviserName = $n->fetchColumn() ?: null;
        }
        echo json_encode(['success' => true, 'adviser_name' => $adviserName]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------
// Filters
// ---------------------------------------------------------------
$sy    = trim($_GET['sy']    ?? '');
$grade = trim($_GET['grade'] ?? '');

if ($sy === '') {
    $latest = $db->query("
        SELECT school_year FROM sections
        WHERE school_year IS NOT NULL AND school_year != ''
        ORDER BY school_year DESC LIMIT 1
    ")->fetchColumn();
    $sy = $latest ?: '2026-2027';
}

$gradeLevels = ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'];

// ---------------------------------------------------------------
// Fetch sections
// ---------------------------------------------------------------
$where  = ['sec.school_year = ?'];
$params = [$sy];

if ($grade !== '') {
    $where[]  = 'sec.grade_level = ?';
    $params[] = $grade;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT sec.*, 
           u.full_name AS adviser_name,
           (SELECT COUNT(*) FROM students st 
            WHERE st.section_id = sec.id AND st.is_active = 1) AS student_count
    FROM sections sec
    LEFT JOIN users u ON u.id = sec.adviser_id
    {$whereSQL}
    ORDER BY 
      FIELD(sec.grade_level,'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'),
      sec.section_name
");
$stmt->execute($params);
$sections = $stmt->fetchAll();

// ---------------------------------------------------------------
// Fetch students grouped by section
// ---------------------------------------------------------------
$studentsBySection = [];
if ($sections) {
    $ids = array_column($sections, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $sStmt = $db->prepare("
        SELECT id, lrn, first_name, middle_name, last_name, gender, section_id,
               parent_name, parent_contact
        FROM students
        WHERE is_active = 1 AND section_id IN ($ph)
        ORDER BY last_name, first_name
    ");
    $sStmt->execute($ids);
    foreach ($sStmt->fetchAll() as $row) {
        $studentsBySection[$row['section_id']][] = $row;
    }
}

// ---------------------------------------------------------------
// Advisers dropdown list
// ---------------------------------------------------------------
$advisers = $db->query("
    SELECT id, full_name 
    FROM users 
    WHERE role IN ('teacher','admin') AND is_active = 1
    ORDER BY full_name
")->fetchAll();

// Distinct school years for filter
$schoolYears = $db->query("
    SELECT DISTINCT school_year FROM sections 
    WHERE school_year IS NOT NULL AND school_year != ''
    ORDER BY school_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-list-columns-reverse me-2 text-primary"></i>Master List
        </h1>
        <p class="page-subtitle">Sections, advisers, and students for the selected school year</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="students.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Students
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <?php if (isAdmin()): ?>
        <a href="section_advisers.php?sy=<?= urlencode($sy) ?>" class="btn btn-outline-primary">
            <i class="bi bi-person-badge me-1"></i>Bulk Assign Advisers
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="sy" class="form-select form-select-sm">
                    <?php foreach ($schoolYears as $y): ?>
                    <option value="<?= sanitize($y) ?>" <?= $sy === $y ? 'selected' : '' ?>>
                        SY <?= sanitize($y) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="grade" class="form-select form-select-sm">
                    <option value="">All Grade Levels</option>
                    <?php foreach ($gradeLevels as $g): ?>
                    <option value="<?= $g ?>" <?= $grade === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="master_list.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($sections)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-inbox fs-2 d-block mb-2"></i>
        No sections found for SY <?= sanitize($sy) ?>.
    </div>
</div>
<?php else: ?>

<?php
// Group sections by grade level
$byGrade = [];
foreach ($sections as $sec) {
    $byGrade[$sec['grade_level']][] = $sec;
}
?>

<?php foreach ($byGrade as $gradeName => $gradeSections): ?>
<div class="mb-4">
    <h5 class="text-primary border-bottom pb-2 mb-3">
        <i class="bi bi-bookmark-fill me-1"></i><?= sanitize($gradeName) ?>
        <span class="badge bg-secondary ms-2"><?= count($gradeSections) ?> section(s)</span>
    </h5>

    <?php foreach ($gradeSections as $sec): ?>
    <?php $students = $studentsBySection[$sec['id']] ?? []; ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong><?= sanitize($sec['section_name']) ?></strong>
                <span class="ms-3 text-muted small">
                    <i class="bi bi-person-badge me-1"></i>
                    Adviser:
                    <span class="adviser-label fw-600"
                          id="adviser-label-<?= $sec['id'] ?>">
                        <?php if ($sec['adviser_name']): ?>
                            <span class="text-dark"><?= sanitize($sec['adviser_name']) ?></span>
                        <?php else: ?>
                            <span class="text-danger">Unassigned</span>
                        <?php endif; ?>
                    </span>
                </span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if (isAdmin()): ?>
                <select class="form-select form-select-sm adviser-select d-print-none"
                        style="width:200px;"
                        data-section-id="<?= $sec['id'] ?>"
                        onchange="updateAdviser(this)">
                    <option value="">— Assign adviser —</option>
                    <?php foreach ($advisers as $a): ?>
                    <option value="<?= $a['id'] ?>"
                        <?= $sec['adviser_id'] == $a['id'] ? 'selected' : '' ?>>
                        <?= sanitize($a['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <span class="badge bg-info">
                    <?= count($students) ?> student(s)
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($students)): ?>
            <p class="text-muted text-center py-3 mb-0">No students assigned to this section.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>LRN</th>
                            <th>Name</th>
                            <th>Gender</th>
                            <th>Parent / Guardian</th>
                            <th>Contact</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $st): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><code><?= sanitize($st['lrn']) ?></code></td>
                            <td class="fw-600">
                                <?= sanitize($st['last_name'] . ', ' . $st['first_name']) ?>
                                <?= $st['middle_name'] ? sanitize(substr($st['middle_name'], 0, 1)) . '.' : '' ?>
                            </td>
                            <td>
                                <i class="bi bi-<?= $st['gender'] === 'Male' ? 'gender-male text-primary' : 'gender-female text-danger' ?>"></i>
                                <?= $st['gender'] ?>
                            </td>
                            <td><?= sanitize($st['parent_name'] ?? '—') ?></td>
                            <td><?= sanitize($st['parent_contact'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<style>
@media print {
    .sidebar, .navbar, .page-header .btn, .d-print-none { display: none !important; }
    .card { break-inside: avoid; }
    body { background: #fff !important; }
}
</style>

<?php
$extraJS = <<<JS
<script>
function updateAdviser(selectEl) {
    const sectionId = selectEl.dataset.sectionId;
    const adviserId = selectEl.value;
    const label     = document.getElementById('adviser-label-' + sectionId);

    // Optimistic UI
    selectEl.disabled = true;
    label.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> Saving...</span>';

    const formData = new FormData();
    formData.append('action', 'update_adviser');
    formData.append('section_id', sectionId);
    formData.append('adviser_id', adviserId);

    fetch('master_list.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        selectEl.disabled = false;
        if (data.success) {
            if (data.adviser_name) {
                label.innerHTML = '<span class="text-dark">' + data.adviser_name + '</span>';
            } else {
                label.innerHTML = '<span class="text-danger">Unassigned</span>';
            }
            showToast('Adviser updated.', 'success');
        } else {
            label.innerHTML = '<span class="text-danger">Error</span>';
            showToast(data.error || 'Failed to update.', 'danger');
        }
    })
    .catch(err => {
        selectEl.disabled = false;
        label.innerHTML = '<span class="text-danger">Network error</span>';
        showToast('Network error.', 'danger');
    });
}

// Small toast helper
function showToast(message, type) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }
    const bg = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : 'bg-secondary');
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-white ' + bg + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex">' +
                     '<div class="toast-body">' + message + '</div>' +
                     '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
                   '</div>';
    container.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 2000 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
</script>
JS;
include '../includes/footer.php';
?>