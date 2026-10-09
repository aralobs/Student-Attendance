<?php
/**
 * School Calendar
 * Full calendar UI with holiday and no-class day management
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/calendarific.php';
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

    if (!validSettingsCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please reload School Calendar and try again.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['calendarific_save', 'calendarific_test', 'calendarific_import'], true)) {
        try {
            if ($action === 'calendarific_save') {
                $key = $_POST['api_key'] ?? '';
                if (!is_string($key) || strlen($key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
                    throw new InvalidArgumentException('Enter a valid Calendarific API key.');
                }
                $key = trim($key);
                if ($key === '') $key = getSetting('calendarific_api_key') ?? '';
                if ($key === '') {
                    throw new InvalidArgumentException('Enter your Calendarific API key.');
                }
                // Preserve blank replacements while encrypting credentials saved by older versions.
                updateSetting('calendarific_api_key', $key);
                echo json_encode(['success' => true, 'message' => 'API key saved. Test the connection to verify it.']);
            } else {
                $importYear = calendarificYear($_POST['year'] ?? '');
                $holidays = fetchCalendarificHolidays(getSetting('calendarific_api_key') ?? '', $importYear);
                if ($action === 'calendarific_test') {
                    echo json_encode(['success' => true, 'message' => 'Connection successful. Found ' . count($holidays) . ' Philippine national holiday dates for ' . $importYear . '.']);
                } else {
                    $counts = importCalendarificHolidays($db, $holidays, (int)currentUser()['id']);
                    $message = "Imported {$counts['added']} holiday dates for {$importYear}; preserved {$counts['skipped']} existing entries.";
                    setFlash('success', $message);
                    echo json_encode(['success' => true, 'message' => $message]);
                }
            }
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Never log request URLs or credentials, or expose raw provider/DB errors.
            error_log('Calendarific action failed.');
            echo json_encode(['success' => false, 'message' => 'Calendarific action could not be completed. Please try again.']);
        }
        exit;
    }

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
    <div class="d-flex gap-2 flex-wrap">
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#calendarificModal">
        <i class="bi bi-cloud-arrow-down me-1"></i>Calendarific API
    </button>
    <button class="btn btn-primary" onclick="openAddModal(null)">
        <i class="bi bi-plus-circle me-1"></i>Add Entry
    </button>
    </div>
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
                                    data-entry-date="<?= sanitize($date) ?>" data-entry-title="<?= sanitize($entry['title']) ?>"
                                    onclick="deleteEntry(this.dataset.entryDate, this.dataset.entryTitle)"
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
<?php
$keyStatus = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
$keyStatus->execute(['calendarific_api_key']);
$calendarificConfigured = (bool)$keyStatus->fetchColumn();
?>
<input type="hidden" id="calendarCsrf" value="<?= htmlspecialchars(settingsCsrfToken()) ?>">
<div class="modal fade" id="calendarificModal" tabindex="-1" aria-labelledby="calendarificModalTitle" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="calendarificModalTitle">Calendarific API</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <p id="calendarificStatus" class="small text-muted"><?= $calendarificConfigured ? 'API key configured. Leave the field blank to keep your saved key.' : 'Paste your Calendarific API key below and save it.' ?></p>
            <label for="calendarificKey" class="form-label">Calendarific API key</label>
            <input type="password" id="calendarificKey" class="form-control" maxlength="512" autocomplete="new-password" placeholder="<?= $calendarificConfigured ? 'Enter a replacement key' : 'Enter your API key' ?>">
            <div class="form-text mb-3">Saved keys are encrypted and are never displayed here. <a href="https://calendarific.com/" target="_blank" rel="noopener noreferrer">Get an API key</a></div>
            <button type="button" class="btn btn-primary btn-sm mb-3" onclick="calendarificAction('calendarific_save')">Save API Key</button>
            <hr>
            <label for="calendarificYear" class="form-label">Holiday year</label>
            <input type="number" id="calendarificYear" class="form-control mb-2" min="2000" max="2049" value="<?= max(2000, min(2049, $year)) ?>">
            <p class="small text-muted">Import Philippine national holidays for one calendar year. Existing entries are preserved. For a school year spanning two years, import each year separately. School breaks and local suspensions can be added with Add Entry.</p>
            <p class="small text-muted">Import runs only when you click Import Holidays. Test and import use the saved key; save a replacement before testing.</p>
            <div id="calendarificMsg" role="status" aria-live="polite"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="calendarificAction('calendarific_test')">Test Connection</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="calendarificAction('calendarific_import')">Import Holidays</button>
        </div>
    </div></div>
</div>
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
function calendarCsrf() {
    return encodeURIComponent(document.getElementById('calendarCsrf')?.value || '');
}

async function calendarificAction(action) {
    const modal = document.getElementById('calendarificModal');
    const msg = document.getElementById('calendarificMsg');
    const buttons = modal.querySelectorAll('button');
    const year = document.getElementById('calendarificYear').value;
    if (action !== 'calendarific_save' && !document.getElementById('calendarificYear').checkValidity()) {
        document.getElementById('calendarificYear').reportValidity();
        return;
    }
    if (action === 'calendarific_import' && !await showConfirm(`Import Philippine national holidays for ${year}? Existing calendar entries will be preserved.`, {title: 'Import holidays', confirmLabel: 'Import'})) return;
    buttons.forEach(button => button.disabled = true);
    msg.className = 'alert alert-info py-2';
    msg.textContent = 'Please wait...';
    try {
        const params = new URLSearchParams({ajax: '1', action, year,
            csrf_token: document.getElementById('calendarCsrf').value});
        if (action === 'calendarific_save') params.set('api_key', document.getElementById('calendarificKey').value);
        const response = await fetch('calendar.php', {method: 'POST', body: params});
        const data = await response.json();
        msg.className = `alert alert-${data.success ? 'success' : 'danger'} py-2`;
        msg.textContent = data.message;
        if (data.success && action === 'calendarific_save') {
            document.getElementById('calendarificKey').value = '';
            document.getElementById('calendarificKey').placeholder = 'Enter a replacement key';
            document.getElementById('calendarificStatus').textContent = 'API key configured. Leave the field blank to keep your saved key.';
        }
        if (data.success && action === 'calendarific_import') {
            const url = new URL(window.location.href);
            url.searchParams.set('year', year);
            window.location.assign(url.toString());
        }
    } catch (error) {
        msg.className = 'alert alert-danger py-2';
        msg.textContent = 'Could not complete the request. Please try again.';
    } finally {
        buttons.forEach(button => button.disabled = false);
    }
}

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
            body: `ajax=1&action=get&csrf_token=${calendarCsrf()}&date=${encodeURIComponent(date)}`
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
            body: `ajax=1&action=add&csrf_token=${calendarCsrf()}&date=${encodeURIComponent(date)}&title=${encodeURIComponent(title)}&type=${encodeURIComponent(type)}&description=${encodeURIComponent(desc)}`
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
    if (!await showConfirm(`Delete entry: "${title}" on ${date}?`, { title: 'Delete calendar entry', confirmLabel: 'Delete', tone: 'danger' })) return;

    try {
        const res  = await fetch('calendar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `ajax=1&action=delete&csrf_token=${calendarCsrf()}&date=${encodeURIComponent(date)}`
        });
        const data = await res.json();
        if (data.success) window.location.reload();
        else showMessage('Failed to delete entry.', { title: 'Delete failed' });
    } catch(e) {
        showMessage('Network error. Please try again.', { title: 'Connection error' });
    }
}
</script>
JS;
include '../includes/footer.php';
?>
