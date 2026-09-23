<?php
/**
 * Sections Management
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/schedule_rules.php';
requireAdmin();

$pageTitle = 'Sections';
$db        = getDB();
$grades    = getGradeLevels();

// ── Active / Archived view ────────────────────────────────────
$view      = $_GET['view'] ?? 'active';
$isArchive = $view === 'archived';
$activeVal = $isArchive ? 0 : 1;

$stmt = $db->prepare("
    SELECT s.*, u.full_name AS adviser_name
    FROM sections s
    LEFT JOIN users u ON s.adviser_id = u.id
    WHERE s.is_active = ?
    ORDER BY " . gradeLevelOrderSQL('s.grade_level') . ", s.section_name
");
$stmt->execute([$activeVal]);
$sections = $stmt->fetchAll();

$teachers = $db->query("
    SELECT id, full_name
    FROM users
    WHERE role = 'teacher' AND is_active = 1
    ORDER BY full_name
")->fetchAll();

// Build JS-friendly grade defaults map (for pre-filling the form)
$gradeDefaults = [];
foreach ($grades as $g) {
    $rule = getScheduleForGrade($g);
    if ($rule) $gradeDefaults[$g] = $rule;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-diagram-3 me-2 text-primary"></i>Sections
        </h1>
        <p class="page-subtitle">Manage grade levels and class sections</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <a href="?view=active"
           class="btn btn-sm <?= !$isArchive ? 'btn-primary' : 'btn-outline-primary' ?>">
            <i class="bi bi-list-ul me-1"></i>Active
        </a>
        <a href="?view=archived"
           class="btn btn-sm <?= $isArchive ? 'btn-primary' : 'btn-outline-primary' ?>">
            <i class="bi bi-archive me-1"></i>Archived
        </a>
        <button class="btn btn-primary btn-sm" onclick="openModal(null)">
            <i class="bi bi-plus-circle me-1"></i>Add Section
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:0.85rem">
                <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Section Name</th>
                        <th>Schedule</th>
                        <th>Adviser</th>
                        <th>School Year</th>
                        <th>AM Window</th>
                        <th>PM Window</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sections)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <?= $isArchive
                                ? 'No archived sections.'
                                : 'No sections found. Add one to get started.' ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($sections as $s): ?>
                    <?php
                        $usesAM = in_array($s['schedule_type'], ['full_day', 'am_only'], true);
                        $usesPM = in_array($s['schedule_type'], ['full_day', 'pm_only'], true);
                    ?>
                    <tr>
                        <td>
                            <span class="badge bg-primary bg-opacity-75">
                                <?= sanitize($s['grade_level']) ?>
                            </span>
                        </td>
                        <td class="fw-600"><?= sanitize($s['section_name']) ?></td>
                        <td>
                            <span class="badge bg-<?= $s['schedule_type']==='full_day'
                                ? 'info'
                                : ($s['schedule_type']==='am_only' ? 'success' : 'warning') ?>">
                                <?= ucfirst(str_replace('_',' ',$s['schedule_type'])) ?>
                            </span>
                        </td>
                        <td><?= sanitize($s['adviser_name'] ?? '—') ?></td>
                        <td><?= sanitize($s['school_year']) ?></td>
                        <td class="small text-muted">
                            <?= $usesAM
                                ? date('h:i A', strtotime($s['am_in_start'])) . ' – ' .
                                  date('h:i A', strtotime($s['am_out_end']))
                                : 'N/A' ?>
                        </td>
                        <td class="small text-muted">
                            <?= $usesPM
                                ? date('h:i A', strtotime($s['pm_in_start'])) . ' – ' .
                                  date('h:i A', strtotime($s['pm_out_end']))
                                : 'N/A' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($isArchive): ?>
                                    <a href="restore.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-success"
                                       title="Restore"
                                       onclick="return confirm('Restore section \'<?= sanitize($s['section_name']) ?>\'?')">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary edit-section-btn"
                                            data-section='<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>'
                                            title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <a href="archive.php?id=<?= $s['id'] ?>"
                                       class="btn btn-sm btn-outline-warning"
                                       title="Archive"
                                       onclick="return confirm('Archive section \'<?= sanitize($s['section_name']) ?>\'?')">
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

<!-- Add / Edit Modal -->
<div class="modal fade" id="sectionModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-700" id="sectionModalTitle">
                    <i class="bi bi-diagram-3 me-2"></i>Add Section
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" action="save.php" id="sectionForm">
                <input type="hidden" name="id" id="secId" value="">

                <div class="modal-body">
                    <div class="row g-3">

                        <!-- Basic Info -->
                        <div class="col-md-4">
                            <label class="form-label">
                                Grade Level <span class="text-danger">*</span>
                            </label>
                            <select name="grade_level" id="secGrade"
                                    class="form-select" required
                                    onchange="applyGradeDefaults()">
                                <?php foreach ($grades as $g): ?>
                                <option value="<?= $g ?>"><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Section Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="section_name" id="secName"
                                   class="form-control" required
                                   placeholder="e.g. Sampaguita">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Schedule Type <span class="text-danger">*</span>
                            </label>
                            <select name="schedule_type" id="secSchedule"
                                    class="form-select"
                                    onchange="toggleScheduleFields(this.value)">
                                <option value="full_day">🔄 Full Day (AM + PM)</option>
                                <option value="am_only">☀️ AM Only (Half Day)</option>
                                <option value="pm_only">🌙 PM Only (Half Day)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Class Adviser</label>
                            <select name="adviser_id" id="secAdviser" class="form-select">
                                <option value="">— No Adviser —</option>
                                <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['id'] ?>">
                                    <?= sanitize($t['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">School Year</label>
                            <input type="text" name="school_year" id="secYear"
                                   class="form-control"
                                   placeholder="2026-2027"
                                   value="2026-2027">
                        </div>

                        <!-- AM Schedule -->
                        <div class="col-12" id="amScheduleBlock">
                            <hr class="my-1">
                            <p class="fw-700 mb-2 text-success">
                                ☀️ AM Schedule
                                <small class="text-muted fw-normal ms-2">
                                    (defaults from grade — edit as needed)
                                </small>
                            </p>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label small">Time-In Opens</label>
                                    <input type="time" name="am_in_start"
                                           id="secAmInStart"
                                           class="form-control form-control-sm"
                                           value="08:00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Time-In Closes</label>
                                    <input type="time" name="am_in_end"
                                           id="secAmInEnd"
                                           class="form-control form-control-sm"
                                           value="08:30">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">
                                        Late Threshold
                                        <span class="badge bg-warning text-dark">After = Late</span>
                                    </label>
                                    <input type="time" name="am_late_threshold"
                                           id="secAmLate"
                                           class="form-control form-control-sm"
                                           value="08:01">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Time-Out Opens</label>
                                    <input type="time" name="am_out_start"
                                           id="secAmOutStart"
                                           class="form-control form-control-sm"
                                           value="11:30">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Time-Out Closes</label>
                                    <input type="time" name="am_out_end"
                                           id="secAmOutEnd"
                                           class="form-control form-control-sm"
                                           value="12:00">
                                </div>
                            </div>
                        </div>

                        <!-- PM Schedule -->
                        <div class="col-12" id="pmScheduleBlock">
                            <hr class="my-1">
                            <p class="fw-700 mb-2 text-primary">
                                🌙 PM Schedule
                                <small class="text-muted fw-normal ms-2">
                                    (defaults from grade — edit as needed)
                                </small>
                            </p>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label small">Time-In Opens</label>
                                    <input type="time" name="pm_in_start"
                                           id="secPmInStart"
                                           class="form-control form-control-sm"
                                           value="13:00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Time-In Closes</label>
                                    <input type="time" name="pm_in_end"
                                           id="secPmInEnd"
                                           class="form-control form-control-sm"
                                           value="13:30">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">
                                        Late Threshold
                                        <span class="badge bg-warning text-dark">After = Late</span>
                                    </label>
                                    <input type="time" name="pm_late_threshold"
                                           id="secPmLate"
                                           class="form-control form-control-sm"
                                           value="13:01">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Time-Out Opens</label>
                                    <input type="time" name="pm_out_start"
                                           id="secPmOutStart"
                                           class="form-control form-control-sm"
                                           value="15:30">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Time-Out Closes</label>
                                    <input type="time" name="pm_out_end"
                                           id="secPmOutEnd"
                                           class="form-control form-control-sm"
                                           value="16:00">
                                </div>
                            </div>
                        </div>

                    </div>
                </div><!-- /.modal-body -->

                <div class="modal-footer">
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-save me-1"></i>
                        <span id="saveButtonText">Save Section</span>
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<?php
$gradeDefaultsJson = json_encode($gradeDefaults, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// NOWDOC — safe from PHP interpolation so JS template literals work.
$jsCode = <<<'HTML'
<script>
let sectionModal = null;

document.addEventListener('DOMContentLoaded', () => {
    sectionModal = new bootstrap.Modal(document.getElementById('sectionModal'));

    document.querySelectorAll('.edit-section-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const data = JSON.parse(btn.dataset.section);
            openModal(data);
        });
    });
});

function openModal(sec) {
    const isEdit = sec !== null && sec !== undefined;

    document.getElementById('sectionModalTitle').innerHTML = isEdit
        ? '<i class="bi bi-pencil-square me-2"></i>Edit Section'
        : '<i class="bi bi-plus-circle me-2"></i>Add Section';
    document.getElementById('saveButtonText').textContent =
        isEdit ? 'Update Section' : 'Save Section';

    document.getElementById('secId').value      = sec?.id           ?? '';
    document.getElementById('secGrade').value   = sec?.grade_level  ?? Object.keys(GRADE_DEFAULTS)[0];
    document.getElementById('secName').value    = sec?.section_name ?? '';
    document.getElementById('secAdviser').value = sec?.adviser_id   ?? '';
    document.getElementById('secYear').value    = sec?.school_year  ?? '2026-2027';

    // Populate times
    const t = v => (v ?? '').slice(0, 5);

    // If editing, use saved times; otherwise, use grade defaults
    if (isEdit) {
        document.getElementById('secAmInStart').value  = t(sec.am_in_start);
        document.getElementById('secAmInEnd').value    = t(sec.am_in_end);
        document.getElementById('secAmLate').value     = t(sec.am_late_threshold);
        document.getElementById('secAmOutStart').value = t(sec.am_out_start);
        document.getElementById('secAmOutEnd').value   = t(sec.am_out_end);
        document.getElementById('secPmInStart').value  = t(sec.pm_in_start);
        document.getElementById('secPmInEnd').value    = t(sec.pm_in_end);
        document.getElementById('secPmLate').value     = t(sec.pm_late_threshold);
        document.getElementById('secPmOutStart').value = t(sec.pm_out_start);
        document.getElementById('secPmOutEnd').value   = t(sec.pm_out_end);
        document.getElementById('secSchedule').value   = sec.schedule_type ?? 'full_day';
    } else {
        applyGradeDefaults();
    }

    toggleScheduleFields(document.getElementById('secSchedule').value);

    sectionModal.show();
}

/**
 * Pre-fill time inputs from the selected grade's defaults.
 * The admin can still overwrite any value afterwards.
 */
function applyGradeDefaults() {
    const grade = document.getElementById('secGrade').value;
    const rule  = GRADE_DEFAULTS[grade];
    if (!rule) return;

    const typeSel = document.getElementById('secSchedule');

    // Only auto-set schedule_type when adding a new section
    // (i.e., secId is empty). When editing, we respect the saved value.
    if (!document.getElementById('secId').value) {
        typeSel.value = rule.default_type || 'full_day';
    }

    // AM defaults
    if (rule.am) {
        document.getElementById('secAmInStart').value = rule.am.am_in_start.slice(0,5);
        document.getElementById('secAmInEnd').value   = rule.am.am_in_end.slice(0,5);
        document.getElementById('secAmLate').value    = rule.am.am_late_threshold.slice(0,5);
        document.getElementById('secAmOutStart').value= rule.am.am_out_start.slice(0,5);
        document.getElementById('secAmOutEnd').value  = rule.am.am_out_end.slice(0,5);
    }

    // PM defaults
    if (rule.pm) {
        document.getElementById('secPmInStart').value = rule.pm.pm_in_start.slice(0,5);
        document.getElementById('secPmInEnd').value   = rule.pm.pm_in_end.slice(0,5);
        document.getElementById('secPmLate').value    = rule.pm.pm_late_threshold.slice(0,5);
        document.getElementById('secPmOutStart').value= rule.pm.pm_out_start.slice(0,5);
        document.getElementById('secPmOutEnd').value  = rule.pm.pm_out_end.slice(0,5);
    }

    toggleScheduleFields(typeSel.value);
}

function toggleScheduleFields(scheduleType) {
    const amBlock = document.getElementById('amScheduleBlock');
    const pmBlock = document.getElementById('pmScheduleBlock');

    amBlock.style.display = scheduleType === 'pm_only' ? 'none' : '';
    pmBlock.style.display = scheduleType === 'am_only' ? 'none' : '';
}
</script>
HTML;

$extraJS = '<script>const GRADE_DEFAULTS = ' . $gradeDefaultsJson . ';</script>' . "\n" . $jsCode;

include '../includes/footer.php';
?>