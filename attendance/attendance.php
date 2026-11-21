<?php
/**
 * Attendance Management — v2
 * Daily table with AM/PM 4-event display
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireStaff();

$pageTitle = 'Attendance';
$db        = getDB();
require_once '../includes/enrollment_operations.php';
if (enrollmentModelEnabled($db)) {
    require_once '../includes/delivery_modes.php';
    if (panelTableAvailable($db,'attendance_decisions')) {
        $panelManual=false;
        require '../includes/attendance_sheet_page.php';
        exit;
    }
}
$today     = date('Y-m-d');

// Filters
$date       = $_GET['date']    ?? $today;
$gradeLevel = $_GET['grade']   ?? '';
$sectionId  = (int)($_GET['section'] ?? 0);
$typeFilter = $_GET['type']    ?? '';
$search = trim((string)($_GET['search'] ?? ''));
require_once '../includes/list_view.php';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

// Allowed sections
$allowedSections = getAllowedSections();
$allowedIds      = array_column($allowedSections, 'id');
if (empty($allowedIds)) $allowedIds = [0];

$grades   = getGradeLevels();
$allSections = $allowedSections;
$sections = array_values(array_filter($allowedSections, static fn($s) => $gradeLevel === '' || $s['grade_level'] === $gradeLevel));
if ($sectionId && in_array($sectionId, $allowedIds) && !in_array($sectionId, array_column($sections, 'id'))) $sectionId = 0;

// Calendar check
$calEntry  = getCalendarEntry($date);
$isHolDate = isHolidayOrNoClass($date);

// ── Build WHERE ───────────────────────────────────────────────
$where  = ['a.date = ?', 's.is_active = 1'];
$params = [$date];

if (!isAdmin()) {
    $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
    $where[]      = "s.section_id IN ({$placeholders})";
    $params       = array_merge($params, $allowedIds);
}
if ($gradeLevel) {
    $where[]  = 'sec.grade_level = ?';
    $params[] = $gradeLevel;
}
if ($sectionId > 0 && (isAdmin() || in_array($sectionId, $allowedIds))) {
    $where[]  = 's.section_id = ?';
    $params[] = $sectionId;
}
if ($typeFilter) {
    if ($typeFilter === 'late') {
        $where[] = "(a.am_status = 'late' OR a.pm_status = 'late')";
    } else {
        $where[]  = 'a.attendance_type = ?';
        $params[] = $typeFilter;
    }
}

if ($search !== '') {
    $where[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR sec.section_name LIKE ?)';
    $params = array_merge($params, array_fill(0, 4, '%' . $search . '%'));
}
$whereSQL = implode(' AND ', $where);

// ── Count ─────────────────────────────────────────────────────
$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$whereSQL}
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $perPage)));
$offset = ($page - 1) * $perPage;

// ── Records ───────────────────────────────────────────────────
$orderSQL = gradeLevelOrderSQL('sec.grade_level');
$stmt = $db->prepare("
    SELECT a.*,
           s.first_name, s.last_name, s.photo, s.lrn,
           sec.section_name, sec.grade_level, sec.schedule_type
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$whereSQL}
    ORDER BY {$orderSQL}, sec.section_name, s.last_name, s.first_name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$records = $stmt->fetchAll();

// ── Day stats ─────────────────────────────────────────────────
$statsWhere  = ['a.date = ?', 's.is_active = 1'];
$statsParams = [$date];

if (!isAdmin()) {
    $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
    $statsWhere[] = "s.section_id IN ({$placeholders})";
    $statsParams  = array_merge($statsParams, $allowedIds);
}
if ($gradeLevel) {
    $statsWhere[]  = 'sec.grade_level = ?';
    $statsParams[] = $gradeLevel;
}
if ($sectionId > 0) {
    $statsWhere[]  = 's.section_id = ?';
    $statsParams[] = $sectionId;
}

$statsSQL  = implode(' AND ', $statsWhere);
$dayStats  = $db->prepare("
    SELECT
        COUNT(*)                              AS total,
        SUM(a.attendance_type = 'full_day')  AS full_day,
        SUM(a.attendance_type = 'partial')   AS partial,
        SUM(a.attendance_type = 'absent')    AS absent,
        SUM(a.am_status = 'late')            AS am_late,
        SUM(a.pm_status = 'late')            AS pm_late
        ,SUM(a.am_status = 'late' OR a.pm_status = 'late') AS late
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$statsSQL}
");
$dayStats->execute($statsParams);
$ds = $dayStats->fetch();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="attendance-page">
<?php showFlash(); ?>

<?php if ($isHolDate && $calEntry): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-calendar-x fs-4"></i>
    <div>
        <strong><?= ucfirst(str_replace('_',' ',$calEntry['type'])) ?>:</strong>
        <?= sanitize($calEntry['title']) ?>
        — attendance data for this date may be empty.
    </div>
</div>
<?php endif; ?>

<div class="card attendance-card mb-4"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h1 class="mb-2">
            <i class="bi bi-calendar3 me-2 text-primary"></i>Attendance
        </h1>
        <p class="month-badge mb-0">
            Daily records — <?= date('F j, Y', strtotime($date)) ?>
        </p>
    </div>
    <div class="attendance-actions d-flex gap-2">
        <a href="manual.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-pencil-square me-1"></i>Manual Entry
        </a>
        <a href="scanner.php" class="btn btn-sm btn-primary">
            <i class="bi bi-qr-code-scan me-1"></i>Scanner
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card-body">
        <form method="GET" class="row g-2 align-items-end"><input type="hidden" name="search" value="<?= sanitize($search) ?>">
            <div class="col-6 col-md-2">
                <label class="form-label mb-1 small fw-600">Date</label>
                <input type="date" name="date"
                       class="form-control form-control-sm"
                       value="<?= htmlspecialchars($date) ?>">
            </div>
            <?php if (isAdmin()): ?>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1 small fw-600">Grade</label>
                <select id="attendanceGrade" name="grade" class="form-select form-select-sm">
                    <option value="">All Grades</option>
                    <?php foreach ($grades as $g): ?>
                    <option value="<?= $g ?>"
                            <?= $gradeLevel === $g ? 'selected' : '' ?>>
                        <?= $g ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-3">
                <label class="form-label mb-1 small fw-600">Section</label>
                <select id="attendanceSection" name="section" class="form-select form-select-sm">
                    <option value="0">All Sections</option>
                    <?php foreach ($sections as $sec): ?>
                    <option value="<?= $sec['id'] ?>"
                            <?= $sectionId == $sec['id'] ? 'selected' : '' ?>>
                        <?= sanitize($sec['grade_level'].' — '.$sec['section_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label mb-1 small fw-600">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="full_day"
                            <?= $typeFilter === 'full_day' ? 'selected' : '' ?>>Full Day</option>
                    <option value="partial"
                            <?= $typeFilter === 'partial'  ? 'selected' : '' ?>>Partial</option>
                    <option value="absent"
                            <?= $typeFilter === 'absent'   ? 'selected' : '' ?>>Absent</option>
                    <option value="remarked" <?= $typeFilter === 'remarked' ? 'selected' : '' ?>>Manual remark</option>
                    <option value="late" <?= $typeFilter === 'late' ? 'selected' : '' ?>>Late</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-filter me-1"></i>Filter
                </button>
                <a href="attendance.php" class="btn btn-outline-secondary btn-sm">
                    Reset
                </a>
            </div>
        </form>
    </div>
</div>

<?php renderListSearch($search, 'Search student name, LRN or section...'); ?>
<div class="card attendance-card mb-4"><div class="card-body">
<div class="section-divider"><i class="bi bi-calendar-check"></i>Daily overview</div>
<!-- Day stats -->
<div class="row g-2 mb-3">
    <?php foreach ([
        ['Total',    'total',    'blue',   'people-fill'],
        ['Full Day', 'full_day', 'green',  'check-circle-fill'],
        ['Partial',  'partial',  'orange', 'clock-history'],
        ['Absent',   'absent',   'red',    'x-circle-fill'],
        ['Late',     'late',     'orange', 'clock'],
    ] as [$label,$key,$color,$icon]): ?>
    <div class="col-6 col-md">
        <div class="metric-tile p-3 text-center">
            <div class="metric-label"><?= $label ?></div>
            <div class="metric-value text-<?= ['blue'=>'primary','green'=>'success','orange'=>'warning','red'=>'danger'][$color] ?>"><?= $ds[$key] ?? 0 ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

</div></div>
<!-- Table -->
<div class="card attendance-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-table me-1"></i>
            Records
            <span class="badge bg-primary ms-1"><?= $total ?></span>
        </span>
        <?php if (isAdmin()): ?>
        <a href="../reports/daily.php?date=<?= urlencode($date) ?>&grade=<?= urlencode($gradeLevel) ?>&section=<?= $sectionId ?>"
           class="btn btn-sm btn-outline-success">
            <i class="bi bi-file-earmark-text me-1"></i>Export
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table data-list-title="Attendance List" data-list-native="1" data-list-total="<?= (int)$total ?>" class="table table-hover mb-0 attendance-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Grade / Section</th>
                        <th class="text-center" style="background:#f0fdf4">Time-In</th>
                        <th class="text-center" style="background:#f0fdf4">Time-Out</th>
                        <th class="text-center" style="background:#f0fdf4">AM</th>
                        <th class="text-center" style="background:#eff6ff">PM</th>
                        <th>Type</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-25"></i>
                            No attendance records found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?= BASE_URL ?>student_photo.php?id=<?= (int)$r['id'] ?>"
                                     class="student-photo-sm">
                                <div>
                                    <div class="fw-600">
                                        <?= sanitize($r['last_name'].', '.$r['first_name']) ?>
                                    </div>
                                    <div class="text-muted" style="font-size:0.7rem">
                                        <?= sanitize($r['lrn']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="small">
                            <div class="fw-600"><?= sanitize($r['grade_level'] ?? '—') ?></div>
                            <div class="text-muted"><?= sanitize($r['section_name'] ?? '—') ?></div>
                        </td>
                        <?php $out = $r[getAttendanceEvents($r)[1]]; ?>
                        <td class="text-center small"><?= $r[getAttendanceEvents($r)[0]] ? date('h:i A', strtotime($r[getAttendanceEvents($r)[0]])) : '-' ?></td>
                        <td class="text-center small"><?= $out ? date('h:i A', strtotime($out)) : '-' ?></td>
                        <td class="text-center"><?= getAttendanceScheduleType($r) === 'pm_only' ? 'N/A' : sessionStatusBadge($r['am_status']) ?></td>
                        <td class="text-center"><?= getAttendanceScheduleType($r) === 'am_only' ? 'N/A' : sessionStatusBadge($r['pm_status']) ?></td>
                        <td><?= attendanceTypeBadge($r['attendance_type']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-warning"
                                    onclick="openOverride(<?= htmlspecialchars(json_encode([
                                        'id'            => $r['id'],
                                        'first_name'    => $r['first_name'],
                                        'last_name'     => $r['last_name'],
                                        'grade_level'   => $r['grade_level'],
                                        'section_name'  => $r['section_name'],
                                        'schedule_type' => $r['schedule_type'],
                                        'am_in'         => $r['am_in'],
                                        'am_out'        => $r['am_out'],
                                        'am_status'     => $r['am_status'],
                                        'pm_in'         => $r['pm_in'],
                                        'pm_out'        => $r['pm_out'],
                                        'pm_status'     => $r['pm_status'],
                                        'remarks'       => $r['remarks'],
                                    ]), ENT_QUOTES) ?>)"
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
        <small class="text-muted">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?>
            of <?= $total ?>
        </small>
        <?= paginate($total, $perPage, $page,
            'attendance.php?date=' . urlencode($date) .
            '&grade=' . urlencode($gradeLevel) .
            '&section=' . $sectionId .
            '&type=' . urlencode($typeFilter) . '&search=' . urlencode($search)) ?>
    </div>
    <?php endif; ?>
</div>

</div>
<!-- Override Modal -->
<script id="attendanceSectionData" type="application/json"><?= json_encode(array_map(static fn($s)=>['id'=>(int)$s['id'],'grade'=>$s['grade_level'],'name'=>$s['section_name']],$allSections),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php if (isAdmin()): ?><script src="<?= BASE_URL ?>assets/js/attendance_filters.js" defer></script><?php endif; ?>
<div class="modal fade" id="overrideModal" tabattendance="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-700">
                    <i class="bi bi-pencil-square me-2"></i>Override Attendance
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="override.php">
<?= csrfField() ?>
                <div class="modal-body">
                    <input type="hidden" name="attendance_id"  id="ovId">
                    <input type="hidden" name="redirect_date"  value="<?= htmlspecialchars($date) ?>">
                    <input type="hidden" name="schedule_type"  id="ovScheduleType">

                    <p class="mb-3 fw-600 small" id="ovStudentName"></p>

                    <div class="row g-3">
                        <!-- AM -->
                        <div class="col-md-6" id="ovAmSection">
                            <div class="card border-success">
                                <div class="card-header py-2" style="background:#f0fdf4">
                                    <i class="bi bi-sun me-1 text-success"></i>
                                    <strong>AM Session</strong>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label small">Time-In</label>
                                            <input type="time" name="am_in"
                                                   id="ovAmIn"
                                                   class="form-control form-control-sm">
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small">Time-Out</label>
                                            <input type="time" name="am_out"
                                                   id="ovAmOut"
                                                   class="form-control form-control-sm">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small">AM Status</label>
                                            <select name="am_status" id="ovAmStatus"
                                                    class="form-select form-select-sm">
                                                <option value="">— Not set —</option>
                                                <option value="present">Present</option>
                                                <option value="late">Late</option>
                                                <option value="absent">Absent</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- PM -->
                        <div class="col-md-6" id="ovPmSection">
                            <div class="card border-primary">
                                <div class="card-header py-2" style="background:#eff6ff">
                                    <i class="bi bi-moon me-1 text-primary"></i>
                                    <strong>PM Session</strong>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-6"><label class="form-label small">Time-In</label><input type="time" name="pm_in" id="ovPmIn" class="form-control form-control-sm"></div>
                                        <div class="col-6">
                                            <label class="form-label small">Time-Out</label>
                                            <input type="time" name="pm_out"
                                                   id="ovPmOut"
                                                   class="form-control form-control-sm">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small">PM Status</label>
                                            <select name="pm_status" id="ovPmStatus"
                                                    class="form-select form-select-sm">
                                                <option value="">— Not set —</option>
                                                <option value="present">Present</option>
                                                <option value="late">Late</option>
                                                <option value="absent">Absent</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small" for="overrideReason">Correction reason</label>
                            <input name="correction_reason" id="overrideReason" class="form-control form-control-sm mb-2" maxlength="2000" required>
                            <label class="form-label small" for="ovRemarks">Remarks</label>
                            <textarea name="remarks" id="ovRemarks"
                                      class="form-control form-control-sm"
                                      rows="2"
                                      placeholder="Optional note..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-check me-1"></i>Save Override
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
function openOverride(r) {
    document.getElementById('ovId').value          = r.id;
    document.getElementById('ovScheduleType').value = r.schedule_type || 'full_day';
    document.getElementById('ovStudentName').textContent =
        (r.last_name || '') + ', ' + (r.first_name || '') +
        ' — ' + (r.grade_level || '') + ' / ' + (r.section_name || '');

    // Time values — strip seconds if present
    const t = v => v ? v.slice(0,5) : '';
    document.getElementById('ovAmIn').value     = t(r.am_in);
    document.getElementById('ovAmOut').value    = t(r.am_out);
    document.getElementById('ovAmStatus').value = r.am_status || '';
    document.getElementById('ovPmOut').value    = t(r.pm_out);
    document.getElementById('ovPmStatus').value = r.pm_status || '';
    document.getElementById('ovRemarks').value  = r.remarks  || '';

    document.getElementById('ovPmIn').value = t(r.pm_in);
    const pmOnly = r.schedule_type === 'pm_only';
    document.getElementById('ovAmSection').style.display = pmOnly ? 'none' : '';
    for (const id of ['ovAmIn', 'ovAmStatus']) document.getElementById(id).disabled = pmOnly;
    const pmIn = document.getElementById('ovPmIn');
    pmIn.parentElement.style.display = pmOnly ? '' : 'none';
    pmIn.disabled = !pmOnly;
    const amOnly = r.schedule_type === 'am_only';
    const amOut = document.getElementById('ovAmOut');
    amOut.parentElement.style.display = amOnly ? '' : 'none';
    amOut.disabled = !amOnly;
    document.getElementById('ovPmOut').disabled = amOnly;
    // Hide PM for am_only sections
    document.getElementById('ovPmSection').style.display =
        r.schedule_type === 'am_only' ? 'none' : '';

    new bootstrap.Modal(document.getElementById('overrideModal')).show();
}
</script>
JS;
include '../includes/footer.php';
?>
