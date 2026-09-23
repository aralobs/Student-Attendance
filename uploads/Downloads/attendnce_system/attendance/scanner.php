<?php
/**
 * QR Scanner — uses html5-qrcode library
 * Proper implementation with error handling and camera selection
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'QR Scanner';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-qr-code-scan me-2 text-primary"></i>QR Scanner
        </h1>
        <p class="page-subtitle" id="liveClock"></p>
    </div>
    <span class="badge bg-success fs-6 py-2 px-3">
        <i class="bi bi-check-circle me-1"></i>
        <span id="scanCount">0</span> scanned today
    </span>
</div>

<div class="row g-3">

    <!-- Scanner Panel -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-camera-video me-2"></i>Camera</span>
                <div class="d-flex gap-2 align-items-center">
                    <!-- Camera selector (populated by JS) -->
                    <select id="cameraSelect" class="form-select form-select-sm d-none" style="width:auto; max-width:160px"></select>
                    <span class="badge bg-secondary" id="scannerStatus">Stopped</span>
                    <button class="btn btn-sm btn-primary" id="startBtn" onclick="startScanner()">
                        <i class="bi bi-play-fill me-1"></i>Start
                    </button>
                    <button class="btn btn-sm btn-danger d-none" id="stopBtn" onclick="stopScanner()">
                        <i class="bi bi-stop-fill me-1"></i>Stop
                    </button>
                </div>
            </div>
            <div class="card-body p-2">
                <!-- Scanner container — html5-qrcode renders here -->
                <div id="qr-reader" style="width:100%; border-radius:8px; overflow:hidden"></div>

                <!-- Manual fallback -->
                <div class="mt-3">
                    <label class="form-label small fw-600">Manual / Barcode Scanner Input</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light">
                            <i class="bi bi-upc-scan"></i>
                        </span>
                        <input type="text"
                               id="manualInput"
                               class="form-control"
                               placeholder="Scan barcode or type QR token..."
                               autocomplete="off"
                               autocorrect="off"
                               spellcheck="false">
                        <button class="btn btn-primary" onclick="submitManual()">
                            <i class="bi bi-check-lg me-1"></i>Record
                        </button>
                    </div>
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Works with USB barcode scanners (auto-submits on Enter)
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Result + Log Panel -->
    <div class="col-lg-6">

        <!-- Scan Result -->
        <div class="card mb-3">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Scan Result
            </div>
            <div class="card-body" id="resultArea">
                <div class="text-center text-muted py-3">
                    <i class="bi bi-qr-code-scan d-block mb-2" style="font-size:2.5rem; opacity:0.2"></i>
                    Start scanner and scan a student QR code
                </div>
            </div>
        </div>

        <!-- Today's Log -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-check me-2"></i>Today's Log</span>
                <button class="btn btn-sm btn-outline-secondary" onclick="refreshLog()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div style="height:320px; overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top bg-white">
                        <tr>
                            <th>Student</th>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="logBody">
                        <tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<'JSEOF'
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
// ── State ─────────────────────────────────────────────────────
let qrScanner   = null;
let scanCount   = 0;
let cooldown    = false;
let cooldownMs  = 3000;     // ms between accepted scans
let lastToken   = '';       // prevent same token twice in a row

// ── Scanner lifecycle ──────────────────────────────────────────

async function startScanner() {
    setStatus('Starting...', 'warning');

    // List available cameras
    try {
        const cameras = await Html5Qrcode.getCameras();

        if (!cameras || cameras.length === 0) {
            setStatus('No camera', 'danger');
            showResult('danger', '❌ No Camera Found',
                'No camera device detected. Use the manual input below, or check browser permissions.');
            return;
        }

        // Populate camera selector if multiple
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
            // Prefer back/environment camera
            const backCam = cameras.find(c =>
                c.label.toLowerCase().includes('back') ||
                c.label.toLowerCase().includes('rear') ||
                c.label.toLowerCase().includes('environment')
            );
            if (backCam) select.value = backCam.id;
        }

        // Initialize scanner
        qrScanner = new Html5Qrcode('qr-reader');

        const cameraId = select.value || cameras[cameras.length - 1].id;
        await startWithCamera(cameraId);

        // Camera switch handler
        select.onchange = async () => {
            await qrScanner.stop();
            await startWithCamera(select.value);
        };

    } catch (err) {
        setStatus('Error', 'danger');
        showResult('danger', '⚠️ Camera Error',
            `Could not start camera: ${err}<br><br>
            <strong>Possible fixes:</strong><br>
            • Allow camera permission in browser<br>
            • Use HTTPS (or localhost)<br>
            • Try a different browser (Chrome recommended)<br>
            • Use manual input below`);
    }
}

async function startWithCamera(cameraId) {
    const config = {
        fps: 15,
        qrbox: (w, h) => {
            const size = Math.min(w, h) * 0.7;
            return { width: size, height: size };
        },
        aspectRatio: 1.0,
        showTorchButtonIfSupported: true,
        showZoomSliderIfSupported: true,
        defaultZoomValueIfSupported: 1,
    };

    await qrScanner.start(
        cameraId,
        config,
        onScanSuccess,
        () => {}  // ignore per-frame decode errors
    );

    setStatus('Active', 'success');
    document.getElementById('startBtn').classList.add('d-none');
    document.getElementById('stopBtn').classList.remove('d-none');
}

async function stopScanner() {
    if (qrScanner) {
        try { await qrScanner.stop(); } catch (e) {}
        qrScanner = null;
    }
    setStatus('Stopped', 'secondary');
    document.getElementById('startBtn').classList.remove('d-none');
    document.getElementById('stopBtn').classList.add('d-none');
}

// ── Scan handlers ─────────────────────────────────────────────

function onScanSuccess(decodedText) {
    // Prevent duplicate / rapid scans
    if (cooldown || decodedText === lastToken) return;

    cooldown   = true;
    lastToken  = decodedText;

    setTimeout(() => {
        cooldown   = false;
        lastToken  = '';
    }, cooldownMs);

    processToken(decodedText, 'qr');
}

function submitManual() {
    const input = document.getElementById('manualInput');
    const token = input.value.trim();
    if (!token) { input.focus(); return; }
    input.value = '';
    processToken(token, 'manual');
}

// ── Core token processor ──────────────────────────────────────

async function processToken(token, source = 'qr') {
    showResult('info', '⏳ Processing...', `Reading token: <code>${token}</code>`);

    try {
        const res  = await fetch('scan_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `token=${encodeURIComponent(token)}&source=${source}`
        });

        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (data.success) {
            scanCount++;
            document.getElementById('scanCount').textContent = scanCount;

            const isLate    = data.status === 'late';
            const isTimeout = data.type   === 'timeout';
            const cardType  = isTimeout ? 'info' : (isLate ? 'warning' : 'success');
            const icon      = isTimeout ? '🚪' : (isLate ? '⏰' : '✅');
            const title     = isTimeout
                ? 'Time-Out Recorded'
                : (isLate ? 'Late Arrival' : 'Present — On Time');

            showResult(cardType, `${icon} ${title}`, `
                <div class="d-flex align-items-center gap-3 mt-2">
                    <div class="fs-1">${isTimeout ? '🚪' : '🧒'}</div>
                    <div class="text-start">
                        <div class="fw-800 fs-5">${data.student}</div>
                        <div class="text-muted small">Section: ${data.section}</div>
                        <div class="mt-1">
                            <span class="badge bg-${cardType === 'info' ? 'info' : cardType} fs-6">
                                ${data.time}
                            </span>
                        </div>
                        <div class="mt-2 small">
                            <i class="bi bi-chat-dots me-1"></i>
                            SMS: ${data.sms_sent
                                ? '<span class="text-success">Sent</span>'
                                : '<span class="text-muted">Sent</span>'}
                            &nbsp;|&nbsp;
                            <i class="bi bi-envelope me-1"></i>
                            Email: ${data.email_sent
                                ? '<span class="text-success">Sent</span>'
                                : '<span class="text-muted">Not sent</span>'}
                        </div>
                    </div>
                </div>
            `);

            beep(true);
            refreshLog();

        } else {
            showResult('danger', '❌ Scan Rejected', data.message);
            beep(false);
        }

    } catch (err) {
        showResult('danger', '⚠️ Network Error',
            `Could not reach server.<br><small>${err.message}</small>`);
        beep(false);
    }
}

// ── UI helpers ────────────────────────────────────────────────

function showResult(type, title, body) {
    const colors = {
        success: '#d1fae5',
        warning: '#fef3c7',
        danger:  '#fee2e2',
        info:    '#e0f2fe',
    };
    document.getElementById('resultArea').innerHTML = `
        <div style="background:${colors[type] ?? '#f1f5f9'};
                    border-radius:10px; padding:1rem;
                    animation: fadeIn .3s ease">
            <h6 class="fw-800 mb-2">${title}</h6>
            <div>${body}</div>
        </div>`;
}

function setStatus(text, color) {
    const el = document.getElementById('scannerStatus');
    el.textContent = text;
    el.className   = `badge bg-${color}`;
}

// ── Attendance log ────────────────────────────────────────────

async function refreshLog() {
    try {
        const res  = await fetch('get_today_log.php');
        const data = await res.json();
        const tbody = document.getElementById('logBody');

        if (!data.length) {
            tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">No records yet today</td></tr>`;
            return;
        }

        tbody.innerHTML = data.map(r => `
            <tr>
                <td class="fw-600 small">${r.name}</td>
                <td class="small">${r.time_in}</td>
                <td>
                    <span class="badge bg-${r.type === 'timeout' ? 'info' : 'success'} bg-opacity-75 small">
                        ${r.type === 'timeout' ? 'Out' : 'In'}
                    </span>
                </td>
                <td>
                    <span class="status-badge badge-${r.status}" style="font-size:0.65rem">
                        ${r.status}
                    </span>
                </td>
            </tr>
        `).join('');

    } catch (e) {
        console.warn('Log refresh failed:', e);
    }
}

// ── Audio feedback ────────────────────────────────────────────

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
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + (success ? 0.2 : 0.5));
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + (success ? 0.2 : 0.5));
    } catch (e) {}
}

// ── Init ──────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    // Load today's log on page open
    refreshLog();

    // Auto-refresh log every 30s
    setInterval(refreshLog, 30000);

    // Live clock
    const clock = document.querySelector('.page-subtitle');
    if (clock) {
        const updateClock = () => {
            clock.textContent = new Date().toLocaleString('en-PH', {
                weekday:'long', year:'numeric', month:'long', day:'numeric',
                hour:'2-digit', minute:'2-digit', second:'2-digit'
            });
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // Enter key on manual input
    document.getElementById('manualInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') submitManual();
    });

    // Fade-in animation
    const style = document.createElement('style');
    style.textContent = `@keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }`;
    document.head.appendChild(style);
});
</script>
JSEOF;

include '../includes/footer.php';
?>