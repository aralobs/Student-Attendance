<?php

/**
 * QR Scanner - two daily scans
 * AM IN → AM OUT → PM IN → PM OUT
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
requireScannerPermission();

$pageTitle = 'QR Scanner';

$today         = date('Y-m-d');
$calendarEntry = getCalendarEntry($today);
$isHoliday     = isHolidayOrNoClass($today);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php if ($isHoliday): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-calendar-x fs-4"></i>
        <div>
            <strong>
                <?= $calendarEntry['type'] === 'holiday' ? '🎉 Holiday' : '📢 No Class Today' ?>:
                <?= sanitize($calendarEntry['title']) ?>
            </strong>
            <?php if ($calendarEntry['description']): ?>
                — <?= sanitize($calendarEntry['description']) ?>
            <?php endif; ?>
            <div class="small">Attendance scanning is disabled on this day.</div>
        </div>
    </div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-qr-code-scan me-2 text-primary"></i>QR Scanner
        </h1>
        <p class="page-subtitle" id="liveClock"></p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge bg-success fs-6 py-2 px-3">
            <i class="bi bi-check-circle me-1"></i>
            <span id="scanCount">0</span> scanned
        </span>
    </div>
</div>

<div class="row g-3">

    <!-- Scanner -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-camera-video me-2"></i>Camera</span>
                <div class="d-flex gap-2 align-items-center">
                    <select id="cameraSelect" class="form-select form-select-sm d-none"
                        style="width:auto;max-width:160px"></select>
                    <span class="badge bg-secondary" id="scannerStatus">Stopped</span>
                    <button class="btn btn-sm btn-primary" id="startBtn" onclick="startScanner()"
                        <?= $isHoliday ? 'disabled' : '' ?>>
                        <i class="bi bi-play-fill me-1"></i>Start
                    </button>
                    <button class="btn btn-sm btn-danger d-none" id="stopBtn" onclick="stopScanner()">
                        <i class="bi bi-stop-fill me-1"></i>Stop
                    </button>
                </div>
            </div>
            <div class="card-body p-2">
                <div id="qr-reader" style="width:100%;border-radius:8px;overflow:hidden"></div>

                <div class="mt-3">
                    <label class="form-label small fw-600">Manual / Barcode Scanner Input</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="bi bi-upc-scan"></i>
                        </span>
                        <input type="text" id="manualInput" class="form-control"
                            placeholder="Scan barcode or type QR token..."
                            autocomplete="off" autocorrect="off" spellcheck="false"
                            <?= $isHoliday ? 'disabled' : '' ?>>
                        <button class="btn btn-primary" onclick="submitManual()"
                            <?= $isHoliday ? 'disabled' : '' ?>>
                            <i class="bi bi-check-lg me-1"></i>Record
                        </button>
                    </div>
                    <small class="text-muted">Enter to submit. Works with USB barcode scanners.</small>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body py-2">
                <div class="row g-2 text-center small">
                    <div class="col-6">
                        <div id="timeInGuide" class="p-3 rounded" style="background:#fef3c7;border:2px solid transparent">
                            <i class="bi bi-box-arrow-in-right d-block mb-1"></i>
                            <strong>Time-In</strong>
                            <div class="mt-1"><span class="badge bg-warning text-dark">Partial</span></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div id="timeOutGuide" class="p-3 rounded" style="background:#d1fae5;border:2px solid transparent">
                            <i class="bi bi-box-arrow-right d-block mb-1"></i>
                            <strong>Time-Out</strong>
                            <div class="mt-1"><span class="badge bg-success">Full Day</span></div>
                        </div>
                    </div>
                </div>
                <div id="scanGuideStatus" class="small text-muted mt-2" role="status" aria-live="polite">The matching step highlights after a successful scan.</div>
            </div>
        </div>
    </div>

    <!-- Result + Log -->
    <div class="col-lg-6">
        <div id="scanFeedback" class="mb-3" role="status" aria-live="polite"></div>

        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Last Scan Result
            </div>
            <div class="card-body" id="resultArea">
                <div class="text-center text-muted py-3">
                    <i class="bi bi-qr-code-scan d-block mb-2"
                        style="font-size:2.5rem;opacity:0.2"></i>
                    Start scanner and scan a student QR code
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-check me-2"></i>Today's Log</span>
                <button class="btn btn-sm btn-outline-secondary" onclick="refreshLog()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div style="height:350px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-white">
                        <tr>
                            <th>Student</th>
                            <th class="text-center">Time-In</th>
                            <th class="text-center">Time-Out</th>
                            <th>Last Event</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody id="logBody">
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">
                                Loading...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = '<script>const scannerContext = ' . json_encode(['csrf_token' => csrfToken(), 'user_id' => (int)currentUser()['id'], 'date' => $today, 'last_scan' => $_SESSION['scanner_last_result'] ?? null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script><script src="' . BASE_URL . 'assets/js/scanner_render.js"></script>'
    . '<script src="' . BASE_URL . 'assets/vendor/html5-qrcode-2.3.8.min.js" integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+" crossorigin="anonymous"></script>' . <<<'JSEOF'
<script>
let qrScanner  = null;
let scanCount  = 0;
let cooldown   = false;
let lastToken  = '';
let lastScannedId = null;
function rememberLastScan(data) {
    // The scan endpoint already saved this result in the current PHP session.
    if (data.success) scannerContext.last_scan = data;
}

function restoreLastScan() {
    const saved = scannerContext.last_scan;
    if (saved?.success && saved.student_id && ['am_in', 'am_out', 'pm_in', 'pm_out'].includes(saved.event)) {
        renderScanResult(saved);
    }
}

// ── Scanner lifecycle ──────────────────────────────────────

async function startScanner() {
    setStatus('Starting...', 'warning');
    try {
        const cameras = await Html5Qrcode.getCameras();
        if (!cameras || cameras.length === 0) {
            setStatus('No camera', 'danger');
            showResult('danger', '❌ No Camera Found',
                'No camera detected. Use manual input below.');
            return;
        }

        const select = document.getElementById('cameraSelect');
        select.innerHTML = '';
        cameras.forEach((cam, i) => {
            const opt = document.createElement('option');
            opt.value = cam.id;
            opt.textContent = cam.label || `Camera ${i + 1}`;
            select.appendChild(opt);
        });
        if (cameras.length > 1) {
            select.classList.remove('d-none');
            const back = cameras.find(c =>
                c.label.toLowerCase().includes('back') ||
                c.label.toLowerCase().includes('rear') ||
                c.label.toLowerCase().includes('environment')
            );
            if (back) select.value = back.id;
        }

        qrScanner = new Html5Qrcode('qr-reader');
        const cameraId = select.value || cameras[cameras.length - 1].id;
        await startWithCamera(cameraId);

        select.onchange = async () => {
            await qrScanner.stop();
            await startWithCamera(select.value);
        };
    } catch (err) {
        setStatus('Error', 'danger');
        showResult('danger', '⚠️ Camera Error',
            `Could not start camera: ${err}
Use manual input below.`);
    }
}

async function startWithCamera(cameraId) {
    const config = {
        fps: 15,
        qrbox: (w, h) => {
            const s = Math.min(w, h) * 0.7;
            return { width: s, height: s };
        },
        aspectRatio: 1.0,
        showTorchButtonIfSupported: true,
    };
    await qrScanner.start(cameraId, config, onScanSuccess, () => {});
    setStatus('Active', 'success');
    document.getElementById('startBtn').classList.add('d-none');
    document.getElementById('stopBtn').classList.remove('d-none');
}

async function stopScanner() {
    if (qrScanner) {
        try { await qrScanner.stop(); } catch(e) {}
        qrScanner = null;
    }
    setStatus('Stopped', 'secondary');
    document.getElementById('startBtn').classList.remove('d-none');
    document.getElementById('stopBtn').classList.add('d-none');
}

function onScanSuccess(decodedText) {
    if (cooldown || decodedText === lastToken) return;
    cooldown  = true;
    lastToken = decodedText;
    setTimeout(() => { cooldown = false; lastToken = ''; }, 3000);
    processToken(decodedText);
}

function submitManual() {
    const input = document.getElementById('manualInput');
    const token = input.value.trim();
    if (!token) { input.focus(); return; }
    input.value = '';
    processToken(token);
}

// ── Core processor ────────────────────────────────────────

function updateScanGuide(data) {
    if (!data.success) return;
    const isArrival = ['am_in', 'pm_in'].includes(data.event);
    const isDeparture = ['am_out', 'pm_out'].includes(data.event);
    if (!isArrival && !isDeparture) return;
    const partial = isArrival && data.attendance_type === 'partial';
    const complete = isDeparture && data.attendance_type === 'full_day';
    for (const [id, active, color] of [
        ['timeInGuide', partial, '#d97706'],
        ['timeOutGuide', complete, '#059669']
    ]) {
        const guide = document.getElementById(id);
        guide.style.borderColor = active ? color : 'transparent';
        guide.style.boxShadow = active ? `0 0 0 2px ${color}22` : 'none';
        if (active) guide.setAttribute('aria-current', 'step');
        else guide.removeAttribute('aria-current');
    }
    const status = complete ? 'Full Day' : (partial ? 'Partial' : 'Recorded');
    document.getElementById('scanGuideStatus').textContent =
        `Last successful scan: ${data.student || 'Student'} - ${isArrival ? 'Time-In' : 'Time-Out'} - ${status}`;
}

async function processToken(token) {
    showResult('info', 'Processing...', '');
    try {
        const res = await fetch('scan_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({token, csrf_token: scannerContext.csrf_token}).toString()
        });
        const raw = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (parseError) {
            // Web-server/PHP failures can return an empty or HTML body.
            // Never display that body: it may contain private diagnostics.
        }
        if (!data || typeof data !== 'object' || Array.isArray(data)
            || (typeof data.success !== 'boolean' && data.error !== true)) {
            const reference = res.headers.get('X-Request-ID');
            showResult('danger', 'Scan unavailable',
                (res.redirected ? 'Your session may have expired. Reload the page and sign in again.'
                    : `The server returned an unreadable scan response (HTTP ${res.status}). Check today's log before attempting another scan.`)
                + (reference && /^[a-f0-9]{24}$/.test(reference) ? ` Reference: ${reference}` : ''));
            beep(false);
            return;
        }
        if (!res.ok) {
            showResult(res.status === 429 ? 'warning' : 'danger',
                res.status === 429 ? 'Please wait before scanning again' : 'Scan unavailable',
                data.message || `HTTP ${res.status}`);
            beep(false);
            return;
        }
        if (data.success) {
            scanCount++;
            document.getElementById('scanCount').textContent = scanCount;
            beep(true);
            if (data.student_id) lastScannedId = data.student_id;
            renderScanResult(data);
            rememberLastScan(data);
            refreshLog();
        } else {
            // Soft "already scanned" handling — no duration disclosed
            const msg = (data.message || '').trim();
            const isAlreadyScanned = /already scanned/i.test(msg);

            if (isAlreadyScanned) {
                showResult('warning', '↩️ Already Scanned',
                    `You're already scanned
Please wait a moment before scanning again.`);
                beep(false);
            } else {
                showResult('danger', '❌ Scan Rejected', msg || 'Unknown error');
                beep(false);
            }
        }
    } catch (err) {
        showResult('danger', '⚠️ Network Error',
            `Could not reach server.
${err.message}`);
        beep(false);
    }
}

// ── Log ──────────────────────────────────────────────────

async function refreshLog() {
    try {
        const res = await fetch('get_today_log.php?scans_only=1');

        if (!res.ok) {
            throw new Error(`HTTP ${res.status} ${res.statusText}`);
        }

        const raw = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (parseErr) {
            console.error('get_today_log.php returned non-JSON:', raw.slice(0, 500));
            throw new Error('Server did not return JSON (check console)');
        }

        if (!Array.isArray(data)) {
            throw new Error('Unexpected response shape');
        }

        renderScannerLog(data, lastScannedId);

        if (lastScannedId != null) {
            setTimeout(() => { lastScannedId = null; }, 2500);
        }

    } catch (e) {
        console.error('refreshLog failed:', e);
        scannerLogMessage(`Failed to load log: ${e.message}`, true);
    }
}

// ── Helpers ──────────────────────────────────────────────

function setStatus(text, color) {
    const el = document.getElementById('scannerStatus');
    el.textContent = text;
    el.className   = `badge bg-${color}`;
}

function beep(success) {
    try {
        const ctx  = new (window.AudioContext || window.webkitAudioContext)();
        const osc  = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.type            = 'sine';
        osc.frequency.value = success ? 1000 : 300;
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
        osc.start();
        osc.stop(ctx.currentTime + 0.25);
    } catch(e) {}
}

// ── Init ─────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    restoreLastScan();
    const manualInput = document.getElementById('manualInput');
    if (manualInput) {
        manualInput.focus();
        setTimeout(() => manualInput.focus(), 500);
    }

    refreshLog();
    setInterval(refreshLog, 30000);

    const clock = document.querySelector('.page-subtitle');
    if (clock) {
        const tick = () => {
            clock.textContent = new Date().toLocaleString('en-PH', {
                weekday:'long', year:'numeric', month:'long', day:'numeric',
                hour:'2-digit', minute:'2-digit', second:'2-digit'
            });
        };
        tick();
        setInterval(tick, 1000);
    }

    document.getElementById('manualInput')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') submitManual();
    });

    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn { from { opacity:0; transform:translateY(8px);} to { opacity:1; transform:none;} }
        @keyframes pulse  { 0%,100% { background:#fff7ed; } 50% { background:#fed7aa; } }
    `;
    document.head.appendChild(style);
});
</script>
JSEOF;

include '../includes/footer.php';
?>
