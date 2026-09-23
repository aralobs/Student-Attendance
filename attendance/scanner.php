<?php

/**
 * QR Scanner — 4-event attendance system
 * AM IN → AM OUT → PM IN → PM OUT
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

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
                <div class="row g-2 text-center" style="font-size:0.8rem">
                    <div class="col-3">
                        <div class="p-2 rounded" style="background:#d1fae5">
                            <i class="bi bi-sun d-block mb-1"></i>
                            <strong>AM In</strong>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 rounded" style="background:#fef3c7">
                            <i class="bi bi-box-arrow-right d-block mb-1"></i>
                            <strong>AM Out</strong>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 rounded" style="background:#dbeafe">
                            <i class="bi bi-moon d-block mb-1"></i>
                            <strong>PM In</strong>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 rounded" style="background:#f3e8ff">
                            <i class="bi bi-door-closed d-block mb-1"></i>
                            <strong>PM Out</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Result + Log -->
    <div class="col-lg-6">

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
                            <th class="text-center">AM In</th>
                            <th class="text-center">AM Out</th>
                            <th class="text-center">PM In</th>
                            <th class="text-center">PM Out</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody id="logBody">
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">
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
$extraJS = <<<'JSEOF'
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let qrScanner  = null;
let scanCount  = 0;
let cooldown   = false;
let lastToken  = '';

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
            `Could not start camera: ${err}<br>Use manual input below.`);
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

async function processToken(token) {
    showResult('info', '⏳ Processing...', `<code>${token}</code>`);

    try {
        const res  = await fetch('scan_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `token=${encodeURIComponent(token)}`
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (data.success) {
            scanCount++;
            document.getElementById('scanCount').textContent = scanCount;
            beep(true);

            const colors = {
                am_in:  { bg: '#d1fae5', border: '#10b981', icon: '☀️', label: 'AM In'  },
                am_out: { bg: '#fef3c7', border: '#f59e0b', icon: '🌤️', label: 'AM Out' },
                pm_in:  { bg: '#dbeafe', border: '#3b82f6', icon: '🌙', label: 'PM In'  },
                pm_out: { bg: '#f3e8ff', border: '#8b5cf6', icon: '🚪', label: 'PM Out' },
            };
            const c = colors[data.event] || colors.am_in;

            const timeGrid = `
                <div class="row g-1 mt-2 text-center" style="font-size:0.75rem">
                    <div class="col-3">
                        <div class="p-1 rounded" style="background:#f1f5f9">
                            <div class="text-muted">AM In</div>
                            <strong>${data.am_in || '—'}</strong>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-1 rounded" style="background:#f1f5f9">
                            <div class="text-muted">AM Out</div>
                            <strong>${data.am_out || '—'}</strong>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-1 rounded" style="background:#f1f5f9">
                            <div class="text-muted">PM In</div>
                            <strong>${data.pm_in || '—'}</strong>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-1 rounded" style="background:#f1f5f9">
                            <div class="text-muted">PM Out</div>
                            <strong>${data.pm_out || '—'}</strong>
                        </div>
                    </div>
                </div>`;

            const remainingHtml = data.remaining.length > 0
                ? `<div class="mt-2 small text-muted">
                       Next: <strong>${data.remaining.join(' → ')}</strong>
                   </div>`
                : `<div class="mt-2 small text-success fw-bold">
                       ✅ All events completed for today
                   </div>`;

            document.getElementById('resultArea').innerHTML = `
                <div style="background:${c.bg};border:2px solid ${c.border};
                            border-radius:10px;padding:1rem;
                            animation:fadeIn .3s ease">
                    <div class="d-flex align-items-start gap-3">
                        <div style="font-size:2rem">${c.icon}</div>
                        <div class="flex-grow-1">
                            <div class="fw-800 fs-5">${data.student}</div>
                            <div class="text-muted small">
                                ${data.grade} — ${data.section}
                            </div>
                            <div class="mt-1">
                                <span class="badge bg-dark">${c.label}</span>
                                <span class="badge bg-secondary ms-1">${data.time}</span>
                                <span class="badge bg-${data.attendance_type === 'full_day' ? 'success' : (data.attendance_type === 'partial' ? 'warning text-dark' : 'danger')} ms-1">
                                    ${data.attendance_type.replace('_',' ').toUpperCase()}
                                </span>
                            </div>
                            ${timeGrid}
                            ${remainingHtml}
                            <div class="mt-2 small">
                                <i class="bi bi-chat-dots me-1"></i>
                                SMS: ${data.sms_sent ? '<span class="text-success">Sent</span>' : '<span class="text-muted">—</span>'}
                                &nbsp;|&nbsp;
                                <i class="bi bi-envelope me-1"></i>
                                Email: ${data.email_sent ? '<span class="text-success">Sent</span>' : '<span class="text-muted">—</span>'}
                            </div>
                        </div>
                    </div>
                </div>`;

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

// ── Log ──────────────────────────────────────────────────

async function refreshLog() {
    try {
        const res  = await fetch('get_today_log.php');
        const data = await res.json();
        const tbody = document.getElementById('logBody');

        if (!data.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="text-center text-muted py-3">No students</td></tr>`;
            return;
        }

        tbody.innerHTML = data.map(r => {
            const rowClass =
                r.attendance_type === 'absent'  ? 'table-danger' :
                r.attendance_type === 'pending' ? 'table-light'  : '';

            const badgeClass =
                r.attendance_type === 'full_day' ? 'success' :
                r.attendance_type === 'partial'  ? 'warning text-dark' :
                r.attendance_type === 'absent'   ? 'danger' :
                r.attendance_type === 'pending'  ? 'secondary' :
                                                    'secondary';

            const fmtCell = (time, status) => {
                if (!time) {
                    return status === 'absent'
                        ? '<span class="badge bg-danger" style="font-size:0.6rem">Absent</span>'
                        : '<span class="text-muted">—</span>';
                }
                const late = status === 'late'
                    ? ' <span class="badge bg-warning text-dark" style="font-size:0.6rem">Late</span>'
                    : '';
                return time + late;
            };

            return `
                <tr class="${rowClass}">
                    <td>
                        <div class="fw-600 small">${r.name}</div>
                        <div class="text-muted" style="font-size:0.7rem">${r.grade} — ${r.section}</div>
                    </td>
                    <td class="text-center small">${fmtCell(r.am_in,  r.am_status)}</td>
                    <td class="text-center small">${fmtCell(r.am_out, null)}</td>
                    <td class="text-center small">${fmtCell(r.pm_in,  r.pm_status)}</td>
                    <td class="text-center small">${fmtCell(r.pm_out, null)}</td>
                    <td>
                        <span class="badge bg-${badgeClass}" style="font-size:0.65rem">
                            ${r.attendance_type.replace('_',' ')}
                        </span>
                    </td>
                </tr>`;
        }).join('');
    } catch(e) {
        console.warn('Log refresh failed:', e);
    }
}

// ── Helpers ──────────────────────────────────────────────

function showResult(type, title, body) {
    const colors = {
        success:'#d1fae5', warning:'#fef3c7',
        danger:'#fee2e2',  info:'#e0f2fe'
    };
    document.getElementById('resultArea').innerHTML = `
        <div style="background:${colors[type]||'#f1f5f9'};
                    border-radius:10px;padding:1rem;
                    animation:fadeIn .3s ease">
            <h6 class="fw-800 mb-2">${title}</h6>
            <div>${body}</div>
        </div>`;
}

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
    const manualInput = document.getElementById('manualInput');
    manualInput.focus();
    setTimeout(() => manualInput.focus(), 500);

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
    style.textContent = `@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}`;
    document.head.appendChild(style);
});
</script>
JSEOF;

include '../includes/footer.php';
?>