<?php
/**
 * Attendance Management — Daily Table with Filters
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Attendance Management';
$db        = getDB();

// Filters
$date      = $_GET['date']    ?? date('Y-m-d');
$sectionId = (int)($_GET['section'] ?? 0);
$status    = $_GET['status']  ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

$sections  = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();

// Build WHERE
$where  = ["a.date = ?"];
$params = [$date];

if ($sectionId > 0) {
    $where[]  = "s.section_id = ?";
    $params[] = $sectionId;
}
if (!empty($status)) {
    $where[]  = "a.status = ?";
    $params[] = $status;
}

$whereSQL = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM attendance a JOIN students s ON a.student_id = s.id WHERE {$whereSQL}");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();

// Records
$stmt = $db->prepare("
    SELECT a.*, s.first_name, s.last_name, s.photo, s.lrn,
           sec.section_name
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$whereSQL}
    ORDER BY a.time_in ASC, s.last_name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-calendar3 me-2 text-primary"></i>Attendance</h1>
        <p class="page-subtitle">Daily attendance records management</p>
    </div>
    <div class="d-flex gap-2">
        <a href="manual.php" class="btn btn-outline-primary">
            <i class="bi bi-pencil-square me-1"></i>Manual Entry
        </a>
        <a href="scanner.php" class="btn btn-primary">
            <i class="bi bi-qr-code-scan me-1"></i>Scanner
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Date</label>
                <input type="date" name="date" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select name="section" class="form-select form-select-sm">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>" <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                        <?= sanitize($sec['section_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label mb-1 small fw-600">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="present"  <?= $status === 'present'  ? 'selected' : '' ?>>Present</option>
                    <option value="absent"   <?= $status === 'absent'   ? 'selected' : '' ?>>Absent</option>
                    <option value="late"     <?= $status === 'late'     ? 'selected' : '' ?>>Late</option>
                    <option value="excused"  <?= $status === 'excused'  ? 'selected' : '' ?>>Excused</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-filter me-1"></i>Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Stats for selected date -->
<?php
$dayStats = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'present') AS present,
        SUM(status = 'late')    AS late,
        SUM(status = 'absent')  AS absent,
        SUM(status = 'excused') AS excused
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    WHERE a.date = ?
    " . ($sectionId > 0 ? "AND s.section_id = {$sectionId}" : "")
);
$dayStats->execute([$date]);
$ds = $dayStats->fetch();
?>
<div class="row g-2 mb-3">
    <?php foreach ([
        ['Total','total','blue','people-fill'],
        ['Present','present','green','check-circle-fill'],
        ['Late','late','orange','clock-fill'],
        ['Absent','absent','red','x-circle-fill'],
    ] as [$label,$key,$color,$icon]): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card <?= $color ?> py-2">
            <div class="stat-icon <?= $color ?>" style="width:40px;height:40px;font-size:1.1rem">
                <i class="bi bi-<?= $icon ?>"></i>
            </div>
            <div>
                <div class="stat-number" style="font-size:1.4rem"><?= $ds[$key] ?? 0 ?></div>
                <div class="stat-label"><?= $label ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <span>
            <i class="bi bi-table me-1"></i>
            Records for <?= date('F j, Y', strtotime($date)) ?>
            <span class="badge bg-primary ms-1"><?= $total ?></span>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>LRN</th>
                        <th>Section</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            No attendance records for this filter.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($r['photo']) ?>"
                                     class="student-photo-sm"
                                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($r['first_name'].' '.$r['last_name']) ?>&size=40&background=1a56db&color=fff'">
                                <span class="fw-600">
                                    <?= sanitize($r['last_name'] . ', ' . $r['first_name']) ?>
                                </span>
                            </div>
                        </td>
                        <td><code class="small"><?= sanitize($r['lrn']) ?></code></td>
                        <td><?= sanitize($r['section_name'] ?? '—') ?></td>
                        <td><?= $r['time_in']  ? date('h:i A', strtotime($r['time_in']))  : '—' ?></td>
                        <td><?= $r['time_out'] ? date('h:i A', strtotime($r['time_out'])) : '—' ?></td>
                        <td>
                            <span class="status-badge badge-<?= $r['status'] ?>">
                                <?= ucfirst($r['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $r['scan_type'] === 'qr' ? 'info' : 'secondary' ?> bg-opacity-25 text-dark small">
                                <?= strtoupper($r['scan_type']) ?>
                            </span>
                        </td>
                        <td>
                            <small class="text-muted"><?= sanitize($r['remarks'] ?? '—') ?></small>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-warning"
                                    onclick="openOverride(<?= $r['id'] ?>, '<?= sanitize($r['first_name'].' '.$r['last_name']) ?>', '<?= $r['status'] ?>', '<?= htmlspecialchars($r['remarks']) ?>')"
                                    title="Override">
                                <i class="bi bi-pencil"></i>
                            </button>
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
        <small class="text-muted">Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?></small>
        <?= paginate($total, $perPage, $page,
            "index.php?date={$date}&section={$sectionId}&status=" . urlencode($status)) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Override Modal -->
<div class="modal fade" id="overrideModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Override Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="override.php">
                <div class="modal-body">
                    <input type="hidden" name="attendance_id" id="overrideId">
                    <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($date) ?>">

                    <p class="mb-3">Student: <strong id="overrideStudentName"></strong></p>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" id="overrideStatus" class="form-select">
                            <option value="present">Present</option>
                            <option value="late">Late</option>
                            <option value="absent">Absent</option>
                            <option value="excused">Excused</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" id="overrideRemarks" class="form-control" rows="3"
                                  placeholder="Optional note..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-check me-1"></i>Save Override
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraJS = <<<JS
<script>
function openOverride(id, name, status, remarks) {
    document.getElementById('overrideId').value          = id;
    document.getElementById('overrideStudentName').textContent = name;
    document.getElementById('overrideStatus').value      = status;
    document.getElementById('overrideRemarks').value     = remarks;
    new bootstrap.Modal(document.getElementById('overrideModal')).show();
}
</script>
JS;
include '../includes/footer.php';
?>