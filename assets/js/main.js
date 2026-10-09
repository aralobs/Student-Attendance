/**
 * Main JavaScript — Automated Attendance System
 * Global utilities: sidebar toggle, tooltips, AJAX helpers
 */

document.addEventListener('DOMContentLoaded', function () {

    // ── Sidebar Toggle ─────────────────────────────────────────

    const sidebar       = document.getElementById('sidebar');
    const toggleBtn     = document.getElementById('sidebarToggle');
    const closeBtn      = document.getElementById('sidebarClose');
    const overlay       = document.createElement('div');
    overlay.id          = 'sidebarOverlay';
    overlay.style.cssText = `
        display:none; position:fixed; inset:0;
        background:rgba(0,0,0,0.5); z-index:1045;
    `;
    document.body.appendChild(overlay);

    function openSidebar() {
        sidebar?.classList.add('show');
        overlay.style.display = 'block';
    }

    function closeSidebar() {
        sidebar?.classList.remove('show');
        overlay.style.display = 'none';
    }

    toggleBtn?.addEventListener('click', () => {
        sidebar?.classList.contains('show') ? closeSidebar() : openSidebar();
    });
    closeBtn?.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    // Auto-close sidebar on large screens is handled by CSS
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 992) closeSidebar();
    });

    // ── Bootstrap Tooltips ─────────────────────────────────────

    const tooltips = document.querySelectorAll('[title]');
    tooltips.forEach(el => {
        new bootstrap.Tooltip(el, { trigger: 'hover', placement: 'top' });
    });

    // ── Auto-dismiss alerts ────────────────────────────────────

    const alerts = document.querySelectorAll('.alert.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert?.close();
        }, 5000);
    });

    // ── Active nav highlight (fallback) ───────────────────────

    const navLinks = document.querySelectorAll('.sidebar .nav-link');
    const current  = window.location.pathname;
    navLinks.forEach(link => {
        if (link.getAttribute('href') && current.includes(link.getAttribute('href').split('/').pop())) {
            link.classList.add('active');
        }
    });


    // ── Form validation styling ────────────────────────────────

    const forms = document.querySelectorAll('form[novalidate]');
    forms.forEach(form => {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    // ── Clock display (if element exists) ─────────────────────

    const clockEl = document.getElementById('liveClock');
    if (clockEl) {
        const updateClock = () => {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString('en-PH', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // ── Number formatter for stat cards ──────────────────────

    document.querySelectorAll('.stat-number[data-value]').forEach(el => {
        const val = parseInt(el.dataset.value);
        if (!isNaN(val)) el.textContent = val.toLocaleString();
    });

});


// Escape, backdrop, close, and Cancel all leave an action unconfirmed.
const actionDialogQueue = [];
let actionDialogOpen = false;

function showActionDialog(message, options = {}, messageOnly = false) {
    return new Promise(resolve => {
        actionDialogQueue.push({ message, options, messageOnly, resolve, trigger: document.activeElement });
        openNextActionDialog();
    });
}

function openNextActionDialog() {
    if (actionDialogOpen || actionDialogQueue.length === 0) return;
    actionDialogOpen = true;
    const { message, options, messageOnly, resolve, trigger } = actionDialogQueue.shift();
    const element = document.getElementById('actionModal');
    const confirmButton = document.getElementById('actionModalConfirm');
    const cancelButton = document.getElementById('actionModalCancel');
    const modal = bootstrap.Modal.getOrCreateInstance(element);
    const tone = ['primary', 'warning', 'danger', 'success'].includes(options.tone) ? options.tone : 'primary';
    let confirmed = false;

    document.getElementById('actionModalTitle').textContent = options.title || (messageOnly ? 'Notice' : 'Confirm action');
    document.getElementById('actionModalMessage').textContent = message;
    confirmButton.textContent = messageOnly ? 'OK' : (options.confirmLabel || 'Confirm');
    confirmButton.className = 'btn btn-' + tone;
    cancelButton.hidden = messageOnly;
    confirmButton.disabled = false;

    function accept() {
        confirmed = true;
        confirmButton.disabled = true;
        modal.hide();
    }
    confirmButton.addEventListener('click', accept);
    element.addEventListener('shown.bs.modal', () => {
        (messageOnly ? confirmButton : cancelButton).focus();
    }, { once: true });
    element.addEventListener('hidden.bs.modal', () => {
        confirmButton.removeEventListener('click', accept);
        if (trigger?.isConnected) trigger.focus();
        actionDialogOpen = false;
        resolve(confirmed);
        openNextActionDialog();
    }, { once: true });
    modal.show();
}

function showConfirm(message, options = {}) {
    return showActionDialog(message, options);
}

function showMessage(message, options = {}) {
    return showActionDialog(message, options, true);
}

async function confirmAction(message, url, options = {}) {
    if (await showConfirm(message, options)) window.location.href = url;
}

document.addEventListener('click', async event => {
    const link = event.target.closest('a[data-confirm]');
    if (!link || event.defaultPrevented) return;
    event.preventDefault();
    if (link.dataset.confirmPending) return;
    link.dataset.confirmPending = 'true';
    try {
        await confirmAction(link.dataset.confirm, link.href, {
            title: link.dataset.confirmTitle,
            confirmLabel: link.dataset.confirmLabel,
            tone: link.dataset.confirmTone
        });
    } finally {
        delete link.dataset.confirmPending;
    }
});

async function ajaxPost(url, data) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        });
        if (!response.ok) throw new Error('Network response was not OK');
        return await response.json();
    } catch (error) {
        console.error('AJAX error:', error);
        return { success: false, message: 'Network error occurred.' };
    }
}

// ── Date/time utilities ────────────────────────────────────────

function formatTime(timeStr) {
    if (!timeStr) return '—';
    const [h, m] = timeStr.split(':');
    const hour = parseInt(h);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const h12  = hour % 12 || 12;
    return `${h12}:${m} ${ampm}`;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('en-PH', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
}
