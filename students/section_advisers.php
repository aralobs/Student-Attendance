<?php
/**
 * Assign Advisers to Sections
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
requireAdmin();

$pageTitle = 'Assign Advisers';
$db        = getDB();

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignments = $_POST['adviser'] ?? []; // [section_id => user_id]
    try {
        $db->beginTransaction();
        $upd = $db->prepare("UPDATE sections SET adviser_id = ? WHERE id = ?");
        foreach ($assignments as $sectionId => $adviserId) {
            $sectionId = (int)$sectionId;
            $adviserId = $adviserId === '' ? null : (int)$adviserId;
            $upd->execute([$adviserId, $sectionId]);
        }
        $db->commit();
        setFlash('success', 'Advisers updated successfully.');
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Failed to update advisers: ' . $e->getMessage());
    }
    header('Location: section_advisers.php?sy=' . urlencode($_POST['sy'] ?? ''));
    exit;
}

// Filter by school year
$sy = trim($_GET['sy'] ?? '');
if ($sy === '') {
    $latest = $db->query("
        SELECT school_year FROM sections
        WHERE school_year IS NOT NULL AND school_year != ''
        ORDER BY school_year DESC LIMIT 1
    ")->fetchColumn();
    $sy = $latest ?: '2026-2027';
}

// Fetch sections
$stmt = $db->prepare("
    SELECT * FROM sections
    WHERE school_year = ?
    ORDER BY 
      FIELD(grade_level,'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'),
      section_name
");
$stmt->execute([$sy]);
$sections = $stmt->fetchAll();

// Fetch all teachers/advisers (role: admin or teacher)
$advisers = $db->query("
    SELECT id, full_name 
    FROM users 
    WHERE role IN ('teacher','admin') AND is_active = 1
    ORDER BY full_name
")->fetchAll();

// Distinct school years
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
            <i class="bi bi-person-badge me-2 text-primary"></i>Assign Advisers
        </h1>
        <p class="page-subtitle">Pick the adviser for each section — one selection per section</p>
    </div>
    <div class="d-flex gap-2">
        <a href="students.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Students
        </a>
        <a href="master_list.php?sy=<?= urlencode($sy) ?>" class="btn btn-outline-primary">
            <i class="bi bi-list-columns-reverse me-1"></i>Master List
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="sy" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($schoolYears as $y): ?>
                    <option value="<?= sanitize($y) ?>" <?= $sy === $y ? 'selected' : '' ?>>
                        SY <?= sanitize($y) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<form method="POST">
<input type="hidden" name="sy" value="<?= sanitize($sy) ?>">

<div class="card">
    <div class="card-header">
        <i class="bi bi-table me-1"></i>Sections for SY <?= sanitize($sy) ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Section</th>
                        <th>Grade Level</th>
                        <th>Schedule</th>
                        <th style="width:300px;">Adviser</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sections)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-5">
                            No sections for this school year.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($sections as $sec): ?>
                    <tr>
                        <td class="fw-600"><?= sanitize($sec['section_name']) ?></td>
                        <td><span class="badge bg-secondary"><?= sanitize($sec['grade_level']) ?></span></td>
                        <td><?= sanitize(str_replace('_', ' ', $sec['schedule_type'])) ?></td>
                        <td>
                            <select name="adviser[<?= $sec['id'] ?>]" class="form-select form-select-sm">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($advisers as $a): ?>
                                <option value="<?= $a['id'] ?>"
                                    <?= $sec['adviser_id'] == $a['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($a['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!empty($sections)): ?>
    <div class="card-footer d-flex justify-content-end">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2-circle me-1"></i>Save Advisers
        </button>
    </div>
    <?php endif; ?>
</div>
</form>

<?php
include '../includes/footer.php';
?>