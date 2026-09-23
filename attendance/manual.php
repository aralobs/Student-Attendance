<?php
/**
 * Manual Attendance Entry — v2 Fixed
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Manual Attendance';
$db        = getDB();
$today     = date('Y-m-d');

$allowedSections = getAllowedSections();

if (empty($allowedSections)) {
    setFlash('warning', 'You have no sections assigned.');
    header('Location: attendance.php');
    exit;
}

// Get section ID — check POST first, then GET, then default
$sectionId = (int)($_POST['section_id'] ?? $_GET['section'] ?? $allowedSections[0]['id']);
$date      = trim($_POST['attendance_date'] ?? $_GET['date'] ?? $today);

// Validate section access
if (!canAccessSection($sectionId)) {
    setFlash('danger', 'Access denied to this section.');
    header('Location: attendance.php');
    exit;
}

// Fetch section BEFORE POST handler — needed by computeAttendanceType()
$section = getSection($sectionId);

if (!$section) {
    setFlash('danger', 'Section not found.');
    header('Location: attendance.php');
    exit;
}

// ── Handle form submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedDate = trim($_POST['attendance_date'] ?? $today);
    $entries       = $_POST['entries'] ?? [];

    if (empty($entries)) {
        setFlash('warning', 'No attendance entries submitted.');
        header('Location: manual.php?section=' . $sectionId . '&date=' . urlencode($submittedDate));
        exit;
    }

    foreach ($entries as $studentId => $data) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) continue;

        $amIn     = !empty($data['am_in'])     ? $data['am_in']    : null;
        $amOut    = !empty($data['am_out'])     ? $data['am_out']   : null;
        $amStatus = !empty($data['am_status'])  ? $data['am_status']: null;
        $pmIn     = !empty($data['pm_in'])      ? $data['pm_in']    : null;
        $pmOut    = !empty($data['pm_out'])      ? $data['pm_out']   : null;
        $pmStatus = !empty($data['pm_status'])  ? $data['pm_status']: null;
        $remarks  = trim($data['remarks']       ?? '');

        // Validate status values
        $validStatuses = ['present', 'late', 'absent', null];
        if (!in_array($amStatus, $validStatuses)) $amStatus = null;
        if (!in_array($pmStatus, $validStatuses)) $pmStatus = null;

        // Compute attendance type based on section schedule
        $mockRecord = ['am_in' => $amIn, 'pm_in' => $pmIn];
        $attendType = computeAttendanceType($mockRecord, $section);

        // Check for existing record
        $check = $db->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
        $check->execute([$studentId, $submittedDate]);
        $existing = $check->fetch();

        if ($existing) {
            $db->prepare("
                UPDATE attendance SET
                    am_in           = ?,
                    am_out          = ?,
                    am_status       = ?,
                    pm_in           = ?,
                    pm_out          = ?,
                    pm_status       = ?,
                    attendance_type = ?,
                    remarks         = ?,
                    recorded_by     = ?
                WHERE id = ?
            ")->execute([
                $amIn, $amOut, $amStatus,
                $pmIn, $pmOut, $pmStatus,
                $attendType, $remarks,
                currentUser()['id'],
                $existing['id']
            ]);
        } else {
            $db->prepare("
                INSERT INTO attendance (
                    student_id, date,
                    am_in, am_out, am_status,
                    pm_in, pm_out, pm_status,
                    attendance_type, remarks, recorded_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $studentId, $submittedDate,
                $amIn, $amOut, $amStatus,
                $pmIn, $pmOut, $pmStatus,
                $attendType, $remarks,
                currentUser()['id']
            ]);
        }

        // Send absence SMS if fully absent
        if ($attendType === 'absent') {
            $stuStmt = $db->prepare("SELECT * FROM students WHERE id = ?");
            $stuStmt->execute([$studentId]);
            $stu = $stuStmt->fetch();
            if ($stu && !empty($stu['parent_contact'])) {
                $msg = buildSMSMessage('sms_absence_template', $stu);
                sendSMS($stu['parent_contact'], $msg, $stu['id'], 'absence');
            }
        }
    }

    setFlash('success', 'Attendance saved for ' . date('F j, Y', strtotime($submittedDate)));
    header('Location: manual.php?section=' . $sectionId . '&date=' . urlencode($submittedDate));
    exit;
}

// ── Load students with existing attendance for the date ───────
$students = $db->prepare("
    SELECT s.*,
           a.id              AS att_id,
           a.am_in,
           a.am_out,
           a.am_status,
           a.pm_in,
           a.pm_out,
           a.pm_status,
           a.attendance_type,
           a.remarks
    FROM students s
    LEFT JOIN attendance a ON a.student_id = s.id AND a.date = ?
    WHERE s.section_id = ? AND s.is_active = 1
    ORDER BY s.last_name, s.first_name
");
$students->execute([$date, $sectionId]);
$students = $students->fetchAll();

$isAmOnly = $section['schedule_type'] === 'am_only';
$isPmOnly = $section['schedule_type'] === 'pm_only';

// Calendar check
$calEntry  = getCalendarEntry($date);
$isHolDate = isHolidayOrNoClass($date);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<?php if ($isHolDate && $calEntry): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-calendar-x fs-4"></i>
    <div>
        <strong><?= ucfirst(str_replace('_',' ',$calEntry['type'])) ?>:</strong>
        <?= sanitize($calEntry['title']) ?>
        — This date is a holiday/no-class day.
    </div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-pencil-square me-2 text-primary"></i>Manual Attendance
        </h1>
        <p class="page-subtitle">
            <?= sanitize($section['grade_level']) ?> —
            <?= sanitize($section['section_name']) ?>
            <span class="badge bg-<?= $isAmOnly ? 'success' : ($isPmOnly ? 'primary' : 'info') ?> ms-1">
                <?= $isAmOnly ? '☀️ AM Only' : ($isPmOnly ? '🌙 PM Only' : '🔄 Full Day') ?>
            </span>
        </p>
    </div>
    <a href="attendance.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Attendance
    </a>
</div>

<form method="POST" id="manualAttendanceForm">
    <!-- Hidden fields -->
    <input type="hidden" name="section_id" value="<?= $sectionId ?>">

    <!-- Controls -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label mb-1 small fw-600">Date</label>
                    <input type="date"
                           name="attendance_date"
                           class="form-control form-control-sm"
                           value="<?= htmlspecialchars($date) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1 small fw-600">Section</label>
                    <select class="form-select form-select-sm"
                            onchange="window.location='manual.php?section='+this.value+'&date=<?= urlencode($date) ?>'">
                        <?php foreach ($allowedSections as $sec): ?>
                        <option value="<?= $sec['id'] ?>"
                                <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                            <?= sanitize($sec['grade_level'].' — '.$sec['section_name']) ?>
                            (<?= ucfirst(str_replace('_',' ',$sec['schedule_type'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1 flex-wrap">
                    <?php if (!$isPmOnly): ?>
                    <button type="button"
                            class="btn btn-sm btn-outline-success"
                            onclick="markAll('am_status','present')">
                        ☀️ All AM Present
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            onclick="markAll('am_status','absent')">
                        ☀️ All AM Absent
                    </button>
                    <?php endif; ?>
                    <?php if (!$isAmOnly): ?>
                    <button type="button"
                            class="btn btn-sm btn-outline-primary"
                            onclick="markAll('pm_status','present')">
                        🌙 All PM Present
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            onclick="markAll('pm_status','absent')">
                        🌙 All PM Absent
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-people me-2"></i>
                Students
                <span class="badge bg-primary ms-1"><?= count($students) ?></span>
            </span>
            <span class="small text-muted">
                <?= date('l, F j, Y', strtotime($date)) ?>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0"
                       style="font-size:0.82rem">
                    <thead>
                        <tr>
                            <th style="width:30px">#</th>
                            <th>Student</th>
                            <?php if (!$isPmOnly): ?>
                            <th class="text-center"
                                colspan="3"
                                style="background:#f0fdf4;border-left:2px solid #10b981">
                                ☀️ AM Session
                            </th>
                            <?php endif; ?>
                            <?php if (!$isAmOnly): ?>
                            <th class="text-center"
                                colspan="3"
                                style="background:#eff6ff;border-left:2px solid #3b82f6">
                                🌙 PM Session
                            </th>
                            <?php endif; ?>
                            <th style="min-width:120px">Remarks</th>
                        </tr>
                        <tr style="font-size:0.75rem">
                            <th colspan="2"></th>
                            <?php if (!$isPmOnly): ?>
                            <th class="text-center" style="background:#f0fdf4">AM In</th>
                            <th class="text-center" style="background:#f0fdf4">AM Out</th>
                            <th class="text-center" style="background:#f0fdf4">AM Status</th>
                            <?php endif; ?>
                            <?php if (!$isAmOnly): ?>
                            <th class="text-center" style="background:#eff6ff">PM In</th>
                            <th class="text-center" style="background:#eff6ff">PM Out</th>
                            <th class="text-center" style="background:#eff6ff">PM Status</th>
                            <?php endif; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="<?= 2 + (!$isPmOnly ? 3 : 0) + (!$isAmOnly ? 3 : 0) + 1 ?>"
                                class="text-center text-muted py-4">
                                <i class="bi bi-people fs-3 d-block mb-2 opacity-25"></i>
                                No students found in this section.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($s['photo']) ?>"
                                         class="student-photo-sm"
                                         onerror="this.onerror=null;this.src='https://ui-avatars.com/api/?name=<?= urlencode($s['first_name'].' '.$s['last_name']) ?>&size=40&background=1a56db&color=fff'">
                                    <div>
                                        <div class="fw-600">
                                            <?= sanitize($s['last_name'].', '.$s['first_name']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:0.7rem">
                                            <?= sanitize($s['lrn']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <?php if (!$isPmOnly): ?>
                            <!-- AM In -->
                            <td style="background:#fafffe">
                                <input type="time"
                                       name="entries[<?= $s['id'] ?>][am_in]"
                                       class="form-control form-control-sm"
                                       value="<?= $s['am_in'] ? substr($s['am_in'],0,5) : '' ?>"
                                       style="width:110px">
                            </td>
                            <!-- AM Out -->
                            <td style="background:#fafffe">
                                <input type="time"
                                       name="entries[<?= $s['id'] ?>][am_out]"
                                       class="form-control form-control-sm"
                                       value="<?= $s['am_out'] ? substr($s['am_out'],0,5) : '' ?>"
                                       style="width:110px">
                            </td>
                            <!-- AM Status -->
                            <td style="background:#fafffe">
                                <select name="entries[<?= $s['id'] ?>][am_status]"
                                        class="form-select form-select-sm am_status"
                                        style="width:105px">
                                    <option value="">— —</option>
                                    <option value="present"
                                        <?= ($s['am_status'] ?? '') === 'present' ? 'selected' : '' ?>>
                                        ✅ Present
                                    </option>
                                    <option value="late"
                                        <?= ($s['am_status'] ?? '') === 'late' ? 'selected' : '' ?>>
                                        ⏰ Late
                                    </option>
                                    <option value="absent"
                                        <?= ($s['am_status'] ?? '') === 'absent' ? 'selected' : '' ?>>
                                        ❌ Absent
                                    </option>
                                </select>
                            </td>
                            <?php endif; ?>

                            <?php if (!$isAmOnly): ?>
                            <!-- PM In -->
                            <td style="background:#f5f8ff">
                                <input type="time"
                                       name="entries[<?= $s['id'] ?>][pm_in]"
                                       class="form-control form-control-sm"
                                       value="<?= $s['pm_in'] ? substr($s['pm_in'],0,5) : '' ?>"
                                       style="width:110px">
                            </td>
                            <!-- PM Out -->
                            <td style="background:#f5f8ff">
                                <input type="time"
                                       name="entries[<?= $s['id'] ?>][pm_out]"
                                       class="form-control form-control-sm"
                                       value="<?= $s['pm_out'] ? substr($s['pm_out'],0,5) : '' ?>"
                                       style="width:110px">
                            </td>
                            <!-- PM Status -->
                            <td style="background:#f5f8ff">
                                <select name="entries[<?= $s['id'] ?>][pm_status]"
                                        class="form-select form-select-sm pm_status"
                                        style="width:105px">
                                    <option value="">— —</option>
                                    <option value="present"
                                        <?= ($s['pm_status'] ?? '') === 'present' ? 'selected' : '' ?>>
                                        ✅ Present
                                    </option>
                                    <option value="late"
                                        <?= ($s['pm_status'] ?? '') === 'late' ? 'selected' : '' ?>>
                                        ⏰ Late
                                    </option>
                                    <option value="absent"
                                        <?= ($s['pm_status'] ?? '') === 'absent' ? 'selected' : '' ?>>
                                        ❌ Absent
                                    </option>
                                </select>
                            </td>
                            <?php endif; ?>

                            <!-- Remarks -->
                            <td>
                                <input type="text"
                                       name="entries[<?= $s['id'] ?>][remarks]"
                                       class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($s['remarks'] ?? '') ?>"
                                       placeholder="Optional...">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($students)): ?>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Attendance
            </button>
            <a href="attendance.php?date=<?= urlencode($date) ?>"
               class="btn btn-outline-secondary">
                Cancel
            </a>
            <span class="ms-auto text-muted small align-self-center">
                <?= count($students) ?> student(s) —
                <?= date('F j, Y', strtotime($date)) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php
$extraJS = <<<'JS'
<script>
/**
 * Mark all AM or PM status dropdowns at once
 * @param {string} fieldName - 'am_status' or 'pm_status'
 * @param {string} value     - 'present', 'late', or 'absent'
 */
function markAll(fieldName, value) {
    document.querySelectorAll('.' + fieldName).forEach(sel => {
        sel.value = value;
    });
}

// Confirm before leaving with unsaved changes
let formChanged = false;
document.getElementById('manualAttendanceForm')
    .addEventListener('change', () => { formChanged = true; });
document.getElementById('manualAttendanceForm')
    .addEventListener('submit', () => { formChanged = false; });

window.addEventListener('beforeunload', e => {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});
</script>
JS;
include '../includes/footer.php';
?>