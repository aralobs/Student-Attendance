<?php
/**
 * School Calendar
 * Full calendar UI with holiday and no-class day management
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/calendarific.php';
require_once '../includes/delivery_modes.php';
requireStaff();

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
    if (($_POST['action'] ?? '') === 'delivery_delete') {
        requireCsrfPost(true);
        try {
            echo json_encode(['success'=>true]+deleteDeliveryMode($db,currentUser(),['id'=>$_POST['id']??null,'confirm_token'=>$_POST['confirm_token']??'']));
        } catch (DomainException|InvalidArgumentException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(503);
            echo json_encode(['success'=>false,'message'=>'Unable to delete the delivery entry. Reload the calendar and try again.']);
        }
        exit;
    }
    if (($_POST['action'] ?? '') === 'delivery') {
        requireCsrfPost(true);
        try {
            requirePanelFeatures($db);
            $activeYear = getActiveSchoolYear($db);
            if (!$activeYear) throw new DomainException('No active school year is available.');
            $deadline = $_POST['deadline_at'] ?? '';
            if (!is_string($deadline)) throw new InvalidArgumentException('Enter a valid modular deadline.');
            $result = saveDeliveryModes($db, currentUser(), [
                'year_id' => (int)$activeYear['id'],
                'section_id' => (int)($_POST['section_id'] ?? 0),
                'from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '',
                'mode' => $_POST['mode'] ?? '',
                'deadline_at' => $deadline !== '' ? str_replace('T', ' ', $deadline) . ':00' : null,
                'confirm_token' => $_POST['confirm_token'] ?? '',
            ]);
            echo json_encode(['success' => true] + $result);
        } catch (DomainException|InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Unable to save the delivery schedule. Please try again.']);
        }
        exit;
    }
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
                    require_once '../includes/calendar_protection.php';
                    $counts = importCalendarificHolidays($db, $holidays, (int)currentUser()['id'], 'protectedCalendarImportGuard');
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
            require_once '../includes/calendar_protection.php';
            saveProtectedCalendarEntry($db,currentUser(),['date'=>$date,'title'=>$title,'type'=>$type,'description'=>$desc]);
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
        try {
            require_once '../includes/calendar_protection.php';
            saveProtectedCalendarEntry($db,currentUser(),['date'=>$date,'delete'=>true]);
            echo json_encode(['success'=>true]);
        } catch (DomainException|InvalidArgumentException $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        catch (Throwable $e) { http_response_code(503); echo json_encode(['success'=>false,'message'=>'Unable to change the calendar.']); }
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

$deliveryEntries = $savedDeliveryEntries = $todayDelivery = [];
$deliveryAvailable = false;
$deliveryLoadError = false;
$calendarDeliveryYear = null;
$calendarDeliverySections = [];
try {
    $deliveryAvailable = enrollmentModelEnabled($db) && panelTableAvailable($db, 'delivery_modes');
    if ($deliveryAvailable) {
        $calendarDeliveryYear = getActiveSchoolYear($db);
        $calendarDeliverySections = getAllowedSections();
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        $savedDeliveryEntries = getDeliveryCalendarRange($db, currentUser(), $monthStart, $monthEnd, true);
        foreach ($savedDeliveryEntries as $deliveryDate => $schedules) {
            foreach ($schedules as $schedule) {
                if ($schedule['eligible']) $deliveryEntries[$deliveryDate][] = $schedule;
            }
        }
        $today = date('Y-m-d');
        $todayDelivery = ($today >= $monthStart && $today <= $monthEnd)
            ? ($deliveryEntries[$today] ?? [])
            : (getDeliveryCalendarRange($db, currentUser(), $today, $today)[$today] ?? []);
    }
} catch (Throwable $e) {
    $deliveryLoadError = true;
    error_log('Unable to load calendar delivery schedules.');
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header analytics-template">
    <div>
        <h1 class="page-title">
            <i class="bi bi-calendar3 me-2 text-primary"></i>School Calendar
        </h1>
        <p class="page-subtitle">School days, delivery schedules, holidays, and special events</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-primary" href="delivery_modes.php">View scheduled dates</a>
    <?php if (isAdmin()): ?>
    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#calendarificModal">
        <i class="bi bi-cloud-arrow-down me-1"></i>Calendarific API
    </button>
    <?php endif; ?>
    <?php if (isAdmin() || ($calendarDeliveryYear && $calendarDeliverySections && !$deliveryLoadError)): ?>
    <button class="btn btn-primary" onclick="openAddModal(null)">
        <i class="bi bi-plus-circle me-1"></i>Add Entry
    </button>
    <?php endif; ?>
    </div>
</div>

<?php if ($deliveryLoadError): ?>
<div class="alert alert-warning">Delivery schedules could not be loaded. Please reload the calendar.</div>
<?php endif; ?>

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
                         onclick="<?= isAdmin() || ($calendarDeliveryYear && $calendarDeliverySections && !$deliveryLoadError) ? "openAddModal('{$dateStr}')" : "showEntry('{$dateStr}')" ?>"
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
                        <?php foreach ($deliveryEntries[$dateStr] ?? [] as $delivery): ?>
                        <div class="entry-label delivery-label text-primary" title="<?= sanitize(($delivery['section_name'] ?? 'Whole school') . ': ' . deliveryLabels()[$delivery['mode']] . ($delivery['deadline_at'] ? ' — Due ' . $delivery['deadline_at'] . ' (Manila time)' : '')) ?>">
                            <?= sanitize(deliveryLabels()[$delivery['mode']]) ?>
                            <span class="d-block text-muted"><?= sanitize($delivery['section_name'] ?? 'Whole school') ?></span>
                        </div>
                        <?php endforeach; ?>
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
                <?php elseif ($deliveryLoadError): ?>
                <div class="alert alert-warning py-2 mb-0">Delivery schedule unavailable</div>
                <?php elseif (!deliveryCalendarEligible($db, date('Y-m-d'))): ?>
                <div class="alert alert-secondary py-2 mb-0">No scheduled classes (weekend)</div>
                <?php elseif ($todayDelivery): ?>
                <div class="alert alert-info py-2 mb-0">
                    <?php foreach ($todayDelivery as $delivery): ?>
                    <div class="mb-2">
                        <strong><?= sanitize(deliveryLabels()[$delivery['mode']]) ?></strong>
                        <span class="d-block small"><?= sanitize($delivery['section_name'] ?? 'Whole school') ?><?= (int)$delivery['scope_section_id'] !== 0 ? ' (section override)' : '' ?></span>
                        <?php if ($delivery['deadline_at']): ?>
                        <span class="d-block small">Deadline: <?= sanitize($delivery['deadline_at']) ?> (Manila time)</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="small">Section overrides take priority. Other sections follow the whole-school schedule or default to face-to-face.</div>
                </div>
                <?php else: ?>
                <div class="alert alert-success py-2 mb-0">
                    <i class="bi bi-check-circle me-1"></i><?= $deliveryAvailable ? 'Face-to-face (default)' : 'Regular school day' ?>
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

        <!-- All saved entries for the selected month -->
        <?php include '../includes/calendar_month_entries.php'; ?>

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
<?php endif; ?>
<?php include '../includes/calendar_entry_modal.php'; ?>

<?php
$extraJS = <<<'JS'
<script>
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

function showEntry(date) {
    // For non-admins — just highlight
    const cells = document.querySelectorAll('.calendar-cell');
    cells.forEach(c => c.style.outline = '');
    const cell = document.querySelector(`[data-date="${date}"]`);
    if (cell) cell.style.outline = '3px solid #1a56db';
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
$extraJS .= '<script src="' . BASE_URL . 'assets/js/calendar_entry.js"></script>';
$extraJS .= '<script src="' . BASE_URL . 'assets/js/delivery_delete.js"></script>';
include '../includes/footer.php';
?>
