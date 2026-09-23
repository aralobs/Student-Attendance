<?php
/**
 * School Calendar
 * Full calendar UI with holiday and no-class day management
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'School Calendar';
$db        = getDB();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));

// Clamp month
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfMonth = date('N', mktime(0, 0, 0, $month, 1, $year)); // 1=Mon 7=Sun
$monthLabel   = date('F Y', mktime(0, 0, 0, $month, 1, $year));

// Get calendar entries for this month
$calendarEntries = getCalendarMonth($month, $year);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    requireAdmin();

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $date  = $_POST['date']        ?? '';
        $title = trim($_POST['title']  ?? '');
        $type  = $_POST['type']        ?? 'holiday';
        $desc  = trim($_POST['description'] ?? '');
        $allowedTypes = ['holiday','no_class','special_event','school_day'];

        if (empty($date) || empty($title) || !in_array($type, $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid data.']);
            exit;
        }

        try {
            $db->prepare("INSERT INTO school_calendar (date, title, type, description, created_by)
                          VALUES (?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE title=?, type=?, description=?")
               ->execute([$date, $title, $type, $desc, currentUser()['id'],
                           $title, $type, $desc]);
            echo json_encode(['success' => true, 'message' => 'Calendar entry saved.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete') {
        $date = $_POST['date'] ?? '';
        if (empty($date)) {
            echo json_encode(['success' => false, 'message' => 'Date required.']);
            exit;
        }
        $db->prepare("DELETE FROM school_calendar WHERE date = ?")->execute([$date]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get') {
        $date = $_POST['date'] ?? '';
        $entry = getCalendarEntry($date);
        echo json_encode(['success' => true, 'entry' => $entry]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// Upcoming holidays/no-class (next 30 days)
$upcoming = $db->query("
    SELECT * FROM school_calendar
    WHERE date >= CURDATE()
    AND type IN ('holiday','no_class')
    ORDER BY date
    LIMIT 8
")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-calendar3 me-2 text-primary"></i>School Calendar
        </h1>
        <p class="page-subtitle">Manage holidays, no-class days, and special events</p>
    </div>
    <?php if (isAdmin()): ?>
    <button class="btn btn-primary" onclick="openAddModal(null)">
        <i class="bi bi-plus-circle me-1"></i>Add Entry
    </button>
    <?php endif; ?>
</div>

<div class="row g-4">

    <!-- Calendar -->
    <div class="col-lg-8">
        <div class="card">
            <!-- Month navigation -->
            <div class="card-header d-flex justify-content-between align-items-center">
                <a href="?month=<?= $month - 1 ?>&year=<?= $year ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <h5 class="mb-0 fw-800"><?= $monthLabel ?></h5>
                <a href="?month=<?= $month + 1 ?>&year=<?= $year ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <div class="card-body p-2">

                <!-- Day headers -->
                <div class="calendar-grid mb-1">
                    <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?>
                    <div class="calendar-day-header text-center fw-700 small text-muted py-2">
                        <?= $day ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar days -->
                <div class="calendar-grid">
                    <?php
                    // Empty cells before first day (Monday-based)
                    for ($i = 1; $i < $firstDayOfMonth; $i++):
                    ?>
                    <div class="calendar-cell empty"></div>
                    <?php endfor; ?>

                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $dateStr   = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $dayOfWeek = date('N', mktime(0, 0, 0, $month, $d, $year));
                        $isWeekend = in_array($dayOfWeek, [6, 7]);
                        $isToday   = $dateStr === date('Y-m-d');
                        $entry     = $calendarEntries[$dateStr] ?? null;

                        $cellClass = 'calendar-cell';
                        if ($isToday)   $cellClass .= ' today';
                        if ($isWeekend) $cellClass .= ' weekend';
                        if ($entry) {
                            $cellClass .= ' has-entry entry-' . $entry['type'];
                        }
                    ?>
                    <div class="<?= $cellClass ?>"
                         onclick="<?= isAdmin() ? "openAddModal('{$dateStr}')" : "showEntry('{$dateStr}')" ?>"
                         data-date="<?= $dateStr ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="day-number fw-700"><?= $d ?></span>
                            <?php if ($entry): ?>
                            <span class="entry-dot bg-<?= entryColor($entry['type']) ?>"></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($entry): ?>
                        <div class="entry-label" title="<?= sanitize($entry['title']) ?>">
                            <?= sanitize(substr($entry['title'], 0, 14)) ?>
                            <?= strlen($entry['title']) > 14 ? '…' : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Legend -->
                <div class="d-flex gap-3 mt-3 flex-wrap px-2" style="font-size:0.78rem">
                    <?php foreach ([
                        ['holiday',       'danger',   '🎉 Holiday'],
                        ['no_class',      'warning',  '📢 No Class'],
                        ['special_event', 'info',     '⭐ Special Event'],
                        ['school_day',    'success',  '📚 Marked School Day'],
                    ] as [$type, $color, $label]): ?>
                    <div class="d-flex align-items-center gap-1">
                        <span class="rounded-circle d-inline-block bg-<?= $color ?>"
                              style="width:10px;height:10px"></span>
                        <?= $label ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="d-flex align-items-center gap-1">
                        <span class="rounded-circle d-inline-block bg-secondary"
                              style="width:10px;height:10px"></span>
                        Weekend
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar: Upcoming + Month entries -->
    <div class="col-lg-4">

        <!-- Today status -->
        <?php
        $todayEntry = getCalendarEntry(date('Y-m-d'));
        $todayIsHoliday = isHolidayOrNoClass(date('Y-m-d'));
        ?>
        <div class="card mb-3">
            <div class="card-body py-3">
                <div class="fw-700 mb-1">
                    <i class="bi bi-calendar-check me-2 text-primary"></i>Today
                </div>
                <div class="small text-muted mb-2"><?= date('l, F j, Y') ?></div>
                <?php if ($todayIsHoliday && $todayEntry): ?>
                <div class="alert alert-<?= entryColor($todayEntry['type']) === 'warning' ? 'warning' : 'danger' ?> py-2 mb-0">
                    <strong><?= ucfirst(str_replace('_',' ',$todayEntry['type'])) ?>:</strong>
                    <?= sanitize($todayEntry['title']) ?>
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mb-0">
                    <i class="bi bi-check-circle me-1"></i>Regular school day
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming holidays -->
        <div class="card mb-3">
            <div class="card-header fw-700">
                <i class="bi bi-calendar-event me-2 text-primary"></i>Upcoming
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcoming)): ?>
                <div class="text-center text-muted py-3 small">No upcoming holidays</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($upcoming as $u): ?>
                    <li class="list-group-item py-2 px-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-600 small"><?= sanitize($u['title']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem">
                                    <?= date('l, F j, Y', strtotime($u['date'])) ?>
                                </div>
                            </div>
                            <span class="badge bg-<?= entryColor($u['type']) ?> ms-2">
                                <?= ucfirst(str_replace('_',' ',$u['type'])) ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- This month's entries -->
        <div class="card">
            <div class="card-header fw-700">
                <i class="bi bi-list-ul me-2 text-primary"></i>
                <?= date('F', mktime(0,0,0,$month,1,$year)) ?> Entries
            </div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                <?php if (empty($calendarEntries)): ?>
                <div class="text-center text-muted py-3 small">No entries this month</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($calendarEntries as $date => $entry): ?>
                    <li class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600 small"><?= sanitize($entry['title']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem">
                                <?= date('l, j', strtotime($date)) ?>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <span class="badge bg-<?= entryColor($entry['type']) ?>">
                                <?= ucfirst(str_replace('_',' ',$entry['type'])) ?>
                            </span>
                            <?php if (isAdmin()): ?>
                            <button class="btn btn-sm btn-outline-danger p-0 px-1"
                                    onclick="deleteEntry('<?= $date ?>','<?= sanitize($entry['title']) ?>')"
                                    style="font-size:0.7rem">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="calendarModal" tabcalendar="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-700">
                    <i class="bi bi-calendar-plus me-2"></i>
                    <span id="modalTitle">Add Calendar Entry</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Date</label>
                    <input type="date" id="entryDate" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Title <span class="text-danger">*</span></label>
                    <input type="text" id="entryTitle" class="form-control"
                           placeholder="e.g. Christmas Day, No Class - Storm Signal">
                </div>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select id="entryType" class="form-select">
                        <option value="holiday">🎉 Holiday</option>
                        <option value="no_class">📢 No Class / School Closure</option>
                        <option value="special_event">⭐ Special Event</option>
                        <option value="school_day">📚 Regular School Day</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description (optional)</label>
                    <textarea id="entryDescription" class="form-control" rows="2"
                              placeholder="Additional details..."></textarea>
                </div>
                <div id="modalMsg"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm"
                        data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm"
                        onclick="saveEntry()">
                    <i class="bi bi-save me-1"></i>Save Entry
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extraJS = <<<'JS'
<script>
let calendarModal = null;

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('calendarModal');
    if (el) calendarModal = new bootstrap.Modal(el);
});

function openAddModal(date) {
    document.getElementById('entryDate').value        = date || '';
    document.getElementById('entryTitle').value       = '';
    document.getElementById('entryType').value        = 'holiday';
    document.getElementById('entryDescription').value = '';
    document.getElementById('modalMsg').innerHTML     = '';
    document.getElementById('modalTitle').textContent =
        date ? `Add Entry — ${date}` : 'Add Calendar Entry';

    // If date has existing entry, load it
    if (date) {
        fetch('calendar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax=1&action=get&date=${encodeURIComponent(date)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.entry) {
                document.getElementById('entryTitle').value       = data.entry.title;
                document.getElementById('entryType').value        = data.entry.type;
                document.getElementById('entryDescription').value = data.entry.description || '';
                document.getElementById('modalTitle').textContent = `Edit Entry — ${date}`;
            }
        });
    }

    calendarModal?.show();
}

function showEntry(date) {
    // For non-admins — just highlight
    const cells = document.querySelectorAll('.calendar-cell');
    cells.forEach(c => c.style.outline = '');
    const cell = document.querySelector(`[data-date="${date}"]`);
    if (cell) cell.style.outline = '3px solid #1a56db';
}

async function saveEntry() {
    const date  = document.getElementById('entryDate').value;
    const title = document.getElementById('entryTitle').value.trim();
    const type  = document.getElementById('entryType').value;
    const desc  = document.getElementById('entryDescription').value.trim();
    const msg   = document.getElementById('modalMsg');

    if (!date || !title) {
        msg.innerHTML = '<div class="alert alert-danger py-2">Date and title are required.</div>';
        return;
    }

    try {
        const res  = await fetch('calendar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax=1&action=add&date=${encodeURIComponent(date)}&title=${encodeURIComponent(title)}&type=${encodeURIComponent(type)}&description=${encodeURIComponent(desc)}`
        });
        const data = await res.json();

        if (data.success) {
            calendarModal?.hide();
            // Reload page to reflect changes
            const url = new URL(window.location);
            const d   = new Date(date);
            url.searchParams.set('month', d.getMonth() + 1);
            url.searchParams.set('year',  d.getFullYear());
            window.location = url.toString();
        } else {
            msg.innerHTML = `<div class="alert alert-danger py-2">${data.message}</div>`;
        }
    } catch (e) {
        msg.innerHTML = '<div class="alert alert-danger py-2">Network error.</div>';
    }
}

async function deleteEntry(date, title) {
    if (!confirm(`Delete entry: "${title}" on ${date}?`)) return;

    try {
        const res  = await fetch('calendar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax=1&action=delete&date=${encodeURIComponent(date)}`
        });
        const data = await res.json();
        if (data.success) window.location.reload();
        else alert('Failed to delete entry.');
    } catch(e) {
        alert('Network error.');
    }
}
</script>
JS;
include '../includes/footer.php';
?>