<?php
/**
 * Manual Attendance Entry
 * Mark attendance for students without QR scan
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Manual Attendance';
$db        = getDB();
$today     = date('Y-m-d');
$date      = $_GET['date'] ?? $today;

$sections  = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();
$sectionId = (int)($_GET['section'] ?? ($sections[0]['id'] ?? 0));

// Handle bulk POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedDate = $_POST['attendance_date'] ?? $today;
    $entries       = $_POST['entries'] ?? [];

    foreach ($entries as $studentId => $data) {
        $status  = $data['status'] ?? 'absent';
        $timeIn  = !empty($data['time_in']) ? $data['time_in'] : null;
        $remarks = trim($data['remarks'] ?? '');

        // Upsert attendance
        $check = $db->prepare("SELECT id FROM attendance WHERE student_id = ? AND date = ?");
        $check->execute([$studentId, $submittedDate]);
        $existing = $check->fetch();

        if ($existing) {
            $db->prepare("UPDATE attendance SET status=?, time_in=?, remarks=?, scan_type='manual', recorded_by=? WHERE id=?")
               ->execute([$status, $timeIn, $remarks, currentUser()['id'], $existing['id']]);
        } else {
            $db->prepare("INSERT INTO attendance (student_id, date, time_in, status, remarks, scan_type, recorded_by) VALUES (?,?,?,?,?,'manual',?)")
               ->execute([$studentId, $submittedDate, $timeIn, $status, $remarks, currentUser()['id']]);
        }

        // Send absence SMS if absent
        if ($status === 'absent') {
            $stuStmt = $db->prepare("SELECT * FROM students WHERE id = ?");
            $stuStmt->execute([$studentId]);
            $stu = $stuStmt->fetch();
            if ($stu && !empty($stu['parent_contact'])) {
                $msg = buildSMSMessage('sms_absence_template', $stu);
                sendSMS($stu['parent_contact'], $msg, $stu['id'], 'absence');
            }
        }
    }

    setFlash('success', 'Attendance saved successfully for ' . date('F j, Y', strtotime($submittedDate)));
    header('Location: manual.php?date=' . urlencode($submittedDate) . '&section=' . $sectionId);
    exit;
}

// Load students for section
$students = $db->prepare("
    SELECT s.*,
           (SELECT id FROM attendance WHERE student_id = s.id AND date = ?) AS att_id,
           (SELECT status FROM attendance WHERE student_id = s.id AND date = ?) AS att_status,
           (SELECT time_in FROM attendance WHERE student_id = s.id AND date = ?) AS att_time_in,
           (SELECT remarks FROM attendance WHERE student_id = s.id AND date = ?) AS att_remarks
    FROM students s
    WHERE s.section_id = ? AND s.is_active = 1
    ORDER BY s.last_name, s.first_name
");
$students->execute([$date, $date, $date, $date, $sectionId]);
$students = $students->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Manual Attendance</h1>
        <p class="page-subtitle">Mark attendance manually by section</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>View Attendance
    </a>
</div>

<form method="POST">
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1 small fw-600">Date</label>
                    <input type="date" name="attendance_date" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1 small fw-600">Section</label>
                    <select name="section_filter" class="form-select form-select-sm"
                            onchange="this.form.action='manual.php?section='+this.value+'&date=<?= urlencode($date) ?>';this.form.submit()">
                        <?php foreach ($sections as $sec): ?>
                        <option value="<?= $sec['id'] ?>" <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                            <?= sanitize($sec['section_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="markAll('present')">
                        <i class="bi bi-check-all me-1"></i>All Present
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger ms-1"
                            onclick="markAll('absent')">
                        <i class="bi bi-x-lg me-1"></i>All Absent
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="bi bi-list-check me-2"></i>Students — <?= sanitize($sections[array_search($sectionId, array_column($sections,'id'))]['section_name'] ?? '') ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Time In</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No students in this section.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($students as $i => $s): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($s['photo']) ?>"
                                         class="student-photo-sm"
                                         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($s['first_name'].' '.$s['last_name']) ?>&size=40&background=1a56db&color=fff'">
                                    <div>
                                        <div class="fw-600"><?= sanitize($s['last_name'] . ', ' . $s['first_name']) ?></div>
                                        <small class="text-muted"><?= sanitize($s['lrn']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <select name="entries[<?= $s['id'] ?>][status]"
                                        class="form-select form-select-sm status-select"
                                        style="min-width:110px">
                                    <?php foreach (['present','late','absent','excused'] as $opt): ?>
                                    <option value="<?= $opt ?>"
                                            <?= ($s['att_status'] ?? 'present') === $opt ? 'selected' : '' ?>>
                                        <?= ucfirst($opt) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="time"
                                       name="entries[<?= $s['id'] ?>][time_in]"
                                       class="form-control form-control-sm"
                                       value="<?= $s['att_time_in'] ?? '' ?>"
                                       style="width:130px">
                            </td>
                            <td>
                                <input type="text"
                                       name="entries[<?= $s['id'] ?>][remarks]"
                                       class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($s['att_remarks'] ?? '') ?>"
                                       placeholder="Optional remark"
                                       style="min-width:150px">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (!empty($students)): ?>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Attendance
            </button>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php
$extraJS = <<<JS
<script>
function markAll(status) {
    document.querySelectorAll('.status-select').forEach(sel => sel.value = status);
}
</script>
JS;
include '../includes/footer.php';
?>