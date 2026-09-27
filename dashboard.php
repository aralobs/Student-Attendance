<?php
/**
 * Dashboard — Elementary School
 * Focused on TODAY + recent activity.
 * Yearly/aggregate analytics live in analytics.php
 */
require_once 'config/database.php';
require_once 'includes/functions.php';
requireLogin();

$pageTitle = 'Dashboard';
$db        = getDB();
$today     = date('Y-m-d');
$user      = currentUser();

$endpointUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
             . '/attendance/get_today_log.php';

// ── Calendar: single fetch for today + upcoming events ─────
$calendarEntry  = getCalendarEntry($today);
$isHoliday      = isHolidayOrNoClass($today);

$upcomingEvents = $db->query("
    SELECT date, title, type
    FROM school_calendar
    WHERE date >= CURDATE()
      AND date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY date ASC
    LIMIT 5
")->fetchAll();

// ── Today's snapshot ───────────────────────────────────────
$stats = getDashboardStats();

// ── Scope filter (for the 7-day mini trend) ────────────────
$scopeFilter = '';
$scopeParams = [];
if (!isAdmin()) {
    $scopeFilter = 'AND sec.adviser_id = ?';
    $scopeParams[] = $user['id'];
}

// ── 7-day trend: single GROUP BY query (no N+1) ────────────
$trend = [];
$startDate = date('Y-m-d', strtotime('-6 days'));

$stmt = $db->prepare("
    SELECT
        a.date,
        SUM(a.attendance_type = 'full_day') AS full_day,
        SUM(a.attendance_type = 'partial')  AS partial,
        SUM(a.attendance_type = 'absent')   AS absent
    FROM attendance a
    JOIN students s      ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.date BETWEEN ? AND ? {$scopeFilter}
    GROUP BY a.date
");
$stmt->execute(array_merge([$startDate, $today], $scopeParams));
$rowsByDate = [];
foreach ($stmt->fetchAll() as $r) {
    $rowsByDate[$r['date']] = $r;
}

// Fill missing days (holidays / no-scan days)
for ($i = 6; $i >= 0; $i--) {
    $d     = date('Y-m-d', strtotime("-{$i} days"));
    $isHol = isHolidayOrNoClass($d);
    $r     = $rowsByDate[$d] ?? null;

    $trend[] = [
        'date'     => date('D', strtotime($d)),   // Mon, Tue...
        'full_day' => $isHol ? 0 : (int)($r['full_day'] ?? 0),
        'partial'  => $isHol ? 0 : (int)($r['partial']  ?? 0),
        'absent'   => $isHol ? 0 : (int)($r['absent']   ?? 0),
        'holiday'  => $isHol ? 1 : 0,
    ];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
/* Compact stat strip for 7-day trend */
.mini-trend-row {
    display: flex;
    justify-content: space-between;
    gap: .5rem;
}
.mini-trend-day {
    flex: 1;
    text-align: center;
    font-size: .72rem;
    color: #64748b;
}
.mini-trend-day .label { font-weight: 600; margin-bottom: .25rem; }
.mini-trend-bar {
    height: 60px;
    display: flex;
    flex-direction: column-reverse;
    border-radius: 4px;
    overflow: hidden;
    background: #f1f5f9;
    margin-bottom: .25rem;
}
.mini-trend-bar span { display: block; width: 100%; }
.mini-trend-bar .seg-full    { background: #0e9f6e; }
.mini-trend-bar .seg-partial { background: #f59e0b; }
.mini-trend-bar .seg-absent  { background: #e02424; }
.mini-trend-day .totals { font-size: .68rem; color: #94a3b8; }

/* Quick action tiles */
.quick-tile {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .85rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #fff;
    text-decoration: none;
    color: #1e293b;
    transition: all .15s ease;
    height: 100%;
}
.quick-tile:hover {
    border-color: #93c5fd;
    box-shadow: 0 4px 12px rgba(15, 23, 42, .06);
    color: #1e293b;
    transform: translateY(-1px);
}
.quick-tile i { font-size: 1.4rem; }
.quick-tile .qt-title { font-weight: 600; font-size: .88rem; }
.quick-tile .qt-sub   { font-size: .72rem; color: #64748b; }
</style>

<?php showFlash(); ?>

<?php if ($isHoliday && $calendarEntry): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-calendar-x fs-3"></i>
    <div>
        <strong>
            <?= $calendarEntry['type'] === 'holiday' ? '🎉 Holiday' : '📢 No Class Today' ?>:
            <?= sanitize($calendarEntry['title']) ?>
        </strong>
        <?php if ($calendarEntry['description']): ?>
        — <?= sanitize($calendarEntry['description']) ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard
        </h1>
        <p class="page-subtitle"><?= date('l, F j, Y') ?> — Today at a glance</p>
    </div>
    <a href="attendance/scanner.php" class="btn btn-primary">
        <i class="bi bi-qr-code-scan me-1"></i>Open Scanner
    </a>
</div>

<!-- ============================================================
     STAT CARDS — today's snapshot
     ============================================================ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card blue">
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['total_students'] ?></div>
                <div class="stat-label">Total Students</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card green">
            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['present_today'] ?></div>
                <div class="stat-label">Present Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card orange">
            <div class="stat-icon orange"><i class="bi bi-clock-history"></i></div>
            <div>
                <div class="stat-number"><?= $stats['partial_today'] ?></div>
                <div class="stat-label">Partial Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card red">
            <div class="stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="stat-number"><?= $stats['absent_today'] ?></div>
                <div class="stat-label">Absent Today</div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     TODAY'S LOG — live absent/partial list
     ============================================================ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-person-x me-2 text-danger"></i>
                    Today's Log — <span id="todayLogDate"><?= date('F j, Y') ?></span>
                    <span id="todayLogCount" class="badge bg-danger ms-2">—</span>
                </span>
                <div class="d-flex gap-2 align-items-center">
                    <select id="todayLogGrade" class="form-select form-select-sm" style="width:auto">
                        <option value="">All Grade Levels</option>
                        <?php foreach (getGradeLevels() as $grade): ?>
                            <option value="<?= sanitize($grade) ?>"><?= sanitize($grade) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="todayLogType" class="form-select form-select-sm" style="width:auto">
                        <option value="">All (Absent + Partial)</option>
                        <option value="absent">Absent only</option>
                        <option value="partial">Partial only</option>
                    </select>
                    <input type="text"
                           id="todayLogSearch"
                           class="form-control form-control-sm"
                           placeholder="Search name / LRN / section..."
                           style="width:220px">
                    <button id="todayLogRefresh" class="btn btn-sm btn-outline-primary" title="Refresh">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0" style="max-height:420px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0" id="todayLogTable">
                    <thead class="sticky-top bg-white">
                        <tr>
                            <th>Student</th>
                            <th>Grade / Section</th>
                            <th class="text-center">AM In</th>
                            <th class="text-center">AM Out</th>
                            <th class="text-center">PM In</th>
                            <th class="text-center">PM Out</th>
                            <th class="text-center">Type</th>
                        </tr>
                    </thead>
                    <tbody id="todayLogBody">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <span class="spinner-border spinner-border-sm me-2"></span>
                                Loading today's log...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-muted small d-flex justify-content-between">
                <span id="todayLogUpdated">—</span>
                <span>Only students flagged absent or partial appear here.</span>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     MINI 7-DAY TREND + UPCOMING EVENTS
     (compact; full analytics moved to analytics.php)
     ============================================================ -->
<div class="row g-3 mb-4">

    <!-- Mini trend -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-graph-up me-2 text-primary"></i>Last 7 Days
                </span>
                <a href="analytics/analytics.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-bar-chart-fill me-1"></i>Full Analytics
                </a>
            </div>
            <div class="card-body">
                <div class="mini-trend-row">
                    <?php foreach ($trend as $t): ?>
                        <?php
                            $total = $t['full_day'] + $t['partial'] + $t['absent'];
                            $hFull = $total > 0 ? ($t['full_day'] / max($total,1)) * 100 : 0;
                            $hPart = $total > 0 ? ($t['partial']  / max($total,1)) * 100 : 0;
                            $hAbs  = $total > 0 ? ($t['absent']   / max($total,1)) * 100 : 0;
                        ?>
                        <div class="mini-trend-day">
                            <div class="label"><?= sanitize($t['date']) ?></div>
                            <div class="mini-trend-bar" title="<?= $t['holiday'] ? 'Holiday' : "Full: {$t['full_day']} · Partial: {$t['partial']} · Absent: {$t['absent']}" ?>">
                                <?php if ($t['holiday']): ?>
                                    <span class="seg-full" style="height:100%;background:#cbd5e1"></span>
                                <?php elseif ($total === 0): ?>
                                    <span style="height:100%;background:#e2e8f0"></span>
                                <?php else: ?>
                                    <span class="seg-full"    style="height:<?= $hFull ?>%"></span>
                                    <span class="seg-partial" style="height:<?= $hPart ?>%"></span>
                                    <span class="seg-absent"  style="height:<?= $hAbs  ?>%"></span>
                                <?php endif; ?>
                            </div>
                            <div class="totals"><?= $t['holiday'] ? '—' : $total ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-3 mt-3 small text-muted">
                    <span><i class="bi bi-square-fill text-success"></i> Full Day</span>
                    <span><i class="bi bi-square-fill text-warning"></i> Partial</span>
                    <span><i class="bi bi-square-fill text-danger"></i> Absent</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Upcoming events -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-calendar-event me-2 text-primary"></i>Upcoming Events
                <small class="text-muted ms-1">(30 days)</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($upcomingEvents)): ?>
                    <div class="text-muted small p-3">No upcoming events.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($upcomingEvents as $ev): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="me-2">
                                    <div class="fw-600 small"><?= sanitize($ev['title']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem">
                                        <?= ucfirst(sanitize($ev['type'])) ?>
                                    </div>
                                </div>
                                <span class="badge bg-light text-dark">
                                    <?= date('M j', strtotime($ev['date'])) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     QUICK ACTIONS
     ============================================================ -->
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-lightning-charge me-2 text-primary"></i>Quick Actions
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <a href="attendance/scanner.php" class="quick-tile">
                            <i class="bi bi-qr-code-scan text-primary"></i>
                            <div>
                                <div class="qt-title">Scanner</div>
                                <div class="qt-sub">Record attendance</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="students/students.php" class="quick-tile">
                            <i class="bi bi-people text-success"></i>
                            <div>
                                <div class="qt-title">Students</div>
                                <div class="qt-sub">Manage records</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="analytics/analytics.php" class="quick-tile">
                            <i class="bi bi-bar-chart text-warning"></i>
                            <div>
                                <div class="qt-title">Analytics</div>
                                <div class="qt-sub">Reports & trends</div>
                            </div>
                        </a>
                    </div>
                    <?php if (isAdmin()): ?>
                    <div class="col-6 col-md-3">
                        <a href="sms/sms.php" class="quick-tile">
                            <i class="bi bi-chat-dots text-danger"></i>
                            <div>
                                <div class="qt-title">SMS</div>
                                <div class="qt-sub">Send notifications</div>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// JSON data for the Today's Log JS
$extraJS = <<<JS
<script>
// ── Today's Log ──────────────────────────────────────────────
(function () {
    const tbody      = document.getElementById('todayLogBody');
    const searchBox  = document.getElementById('todayLogSearch');
    const gradeSel   = document.getElementById('todayLogGrade');
    const typeSel    = document.getElementById('todayLogType');
    const refreshBtn = document.getElementById('todayLogRefresh');
    const updatedEl  = document.getElementById('todayLogUpdated');
    const countEl    = document.getElementById('todayLogCount');

    if (!tbody) return;

    const ENDPOINT = '{$endpointUrl}';
    let rows = [];

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => (
            { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]
        ));
    }
    function timeCell(t) {
        if (t) return '<span class="text-muted">' + esc(t) + '</span>';
        return '<span class="text-danger" title="No scan">—</span>';
    }
    function typeBadge(type) {
        const map = { absent: 'danger', partial: 'warning text-dark', pending: 'secondary' };
        const cls = map[type] || 'secondary';
        return '<span class="badge bg-' + cls + '">' + esc(type || '—') + '</span>';
    }

    function render() {
        const term  = (searchBox.value || '').toLowerCase().trim();
        const grade = gradeSel.value;
        const type  = typeSel.value;

        const filtered = rows.filter(r => {
            const matchesTerm =
                term === '' ||
                r.name.toLowerCase().includes(term) ||
                r.lrn.toLowerCase().includes(term) ||
                r.section.toLowerCase().includes(term);
            const matchesGrade = grade === '' || r.grade === grade;
            const matchesType  = type  === '' || r.attendance_type === type;
            return matchesTerm && matchesGrade && matchesType;
        });

        countEl.textContent = filtered.length + ' student' + (filtered.length === 1 ? '' : 's');

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-success py-4">' +
                '<i class="bi bi-check-circle me-1"></i>No absences match your filter.</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(r => (
            '<tr>' +
              '<td class="fw-600 small">' + esc(r.name) +
                  '<div class="text-muted" style="font-size:.75rem">' + esc(r.lrn) + '</div></td>' +
              '<td class="small text-muted">' + esc(r.grade) + ' / ' + esc(r.section) + '</td>' +
              '<td class="text-center small">' + timeCell(r.am_in)  + '</td>' +
              '<td class="text-center small">' + timeCell(r.am_out) + '</td>' +
              '<td class="text-center small">' + timeCell(r.pm_in)  + '</td>' +
              '<td class="text-center small">' + timeCell(r.pm_out) + '</td>' +
              '<td class="text-center">' + typeBadge(r.attendance_type) + '</td>' +
            '</tr>'
        )).join('');
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">' +
            '<span class="spinner-border spinner-border-sm me-2"></span>Loading today\\'s log...</td></tr>';
        try {
            const res = await fetch(ENDPOINT, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const all = await res.json();
            if (!Array.isArray(all)) throw new Error('Invalid response');
            rows = all.filter(r =>
                r.attendance_type === 'absent' || r.attendance_type === 'partial'
            );
            render();
            updatedEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">' +
                'Failed to load today\\'s log: ' + esc(err.message) + '</td></tr>';
            countEl.textContent = '—';
        }
    }

    searchBox.addEventListener('input', render);
    gradeSel.addEventListener('change', render);
    typeSel.addEventListener('change', render);
    refreshBtn.addEventListener('click', load);

    load();
    setInterval(load, 60000);
})();
</script>
JS;
include 'includes/footer.php';
?>