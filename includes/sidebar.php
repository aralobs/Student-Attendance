<?php
/**
 * Sidebar Navigation — static active detection
 * Includes its own embedded sidebar styles.
 * Logout stays pinned to bottom via margin-top:auto (no rigid column split).
 *
 * Roles:
 *   - admin   : full access (Main, Manage, Reports, Admin)
 *   - teacher : Main, Manage, Reports (SF2 + SF4 only)
 *   - user    : Scanner only
 */

// Current script + folder, resolved ONCE
$currentFile = basename($_SERVER['PHP_SELF']);                 // e.g. attendance.php
$currentPath = str_replace('\\', '/', $_SERVER['PHP_SELF']);   // normalize slashes
?>

<!-- ══════════════════════════════════════════════════════════
     Sidebar Styles (embedded)
     ══════════════════════════════════════════════════════════ -->
<style>
    :root {
        --sidebar-bg: #1e3a5f;
        --sidebar-w:  260px;
        --sidebar-primary: #1a56db;
        --sidebar-radius: 0.5rem;
    }

    /* ── Sidebar container ─────────────────────── */
    #sidebar.sidebar {
        width: var(--sidebar-w);
        height: 100vh;                 /* locked to viewport */
        background: var(--sidebar-bg);
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1050;
        display: flex;
        flex-direction: column;        /* natural vertical flow */
        overflow-y: auto;              /* whole sidebar scrolls as one unit */
        overflow-x: hidden;
        transition: transform 0.3s ease;
        box-shadow: 2px 0 12px rgba(0, 0, 0, 0.08);
        scrollbar-width: thin;
        scrollbar-color: rgba(255,255,255,0.15) transparent;
    }

    /* ── Brand ─────────────────────────────────── */
    #sidebar .sidebar-brand {
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    #sidebar .brand-logo {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* ── User info ─────────────────────────────── */
    #sidebar .sidebar-user {
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
    }

    #sidebar .avatar-sm {
        width: 38px;
        height: 38px;
        min-width: 38px;
        font-size: 0.9rem;
        background: var(--sidebar-primary) !important;
    }

    /* ── Nav links ─────────────────────────────── */
    #sidebar .nav-link {
        color: rgba(255, 255, 255, 0.75);
        padding: 0.55rem 0.85rem;
        border-radius: var(--sidebar-radius);
        margin-bottom: 2px;
        font-size: 0.875rem;
        font-weight: 500;
        display: flex;
        align-items: center;
        transition: background 0.2s ease, color 0.2s ease, transform 0.15s ease;
    }

    #sidebar .nav-link i {
        width: 20px;
        text-align: center;
        font-size: 1rem;
    }

    #sidebar .nav-link:hover {
        background: rgba(255, 255, 255, 0.10);
        color: #fff;
        transform: translateX(2px);
    }

    #sidebar .nav-link.active {
        background: var(--sidebar-primary);
        color: #fff !important;
        font-weight: 600;
        box-shadow: 0 2px 8px rgba(26, 86, 219, 0.4);
    }

    #sidebar .nav-link.active:hover {
        transform: none;
    }

    /* ── Section labels ────────────────────────── */
    #sidebar .nav-section-label {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: rgba(255, 255, 255, 0.35);
        padding: 0.65rem 0.85rem 0.3rem;
        list-style: none;
        user-select: none;
    }

    /* ── Divider ───────────────────────────────── */
    #sidebar hr {
        border-color: rgba(255, 255, 255, 0.08);
        opacity: 1;
    }

    /* ── Logout wrapper — pushes to bottom ─────── */
    #sidebar .sidebar-logout {
        margin-top: auto;              /* pushes it down in flex column */
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        background: var(--sidebar-bg); /* covers content scrolling behind */
    }

    /* ── Logout button ─────────────────────────── */
    #sidebar .btn-outline-danger {
        border-color: rgba(224, 36, 36, 0.6);
        color: #fca5a5;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    #sidebar .btn-outline-danger:hover {
        background: #e02424;
        border-color: #e02424;
        color: #fff;
    }

    /* ── Scrollbar ─────────────────────────────── */
    #sidebar.sidebar::-webkit-scrollbar {
        width: 6px;
    }
    #sidebar.sidebar::-webkit-scrollbar-track {
        background: transparent;
    }
    #sidebar.sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.15);
        border-radius: 3px;
    }
    #sidebar.sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.25);
    }

    /* ── Main content offset ───────────────────── */
    .main-content {
        margin-left: var(--sidebar-w);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        transition: margin 0.3s ease;
    }

    /* ── Top navbar ────────────────────────────── */
    .top-navbar {
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        position: sticky;
        top: 0;
        z-index: 1040;
        height: 56px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
    }

    /* ── Responsive ────────────────────────────── */
    @media (max-width: 991.98px) {
        #sidebar.sidebar {
            transform: translateX(-100%);
        }
        #sidebar.sidebar.show {
            transform: translateX(0);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.3);
        }
        .main-content {
            margin-left: 0 !important;
        }
    }

    /* ── Print ─────────────────────────────────── */
    @media print {
        #sidebar.sidebar,
        .top-navbar,
        .no-print {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
        }
    }
</style>

<!-- ══════════════════════════════════════════════════════════
     Sidebar
     ══════════════════════════════════════════════════════════ -->
<nav id="sidebar" class="sidebar d-flex flex-column">

    <!-- Brand -->
    <div class="sidebar-brand d-flex align-items-center px-3 py-3">
        <div class="brand-logo me-2">
            <i class="bi bi-mortarboard-fill fs-4 text-warning"></i>
        </div>
        <div class="brand-text">
            <div class="fw-bold text-white lh-1" style="font-size:0.9rem">SPCCS</div>
            <div class="text-white-50" style="font-size:0.7rem">SPCC Attendance Monitoring</div>
        </div>
        <button class="btn btn-link ms-auto text-white d-lg-none p-0" id="sidebarClose">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <hr class="border-secondary mx-3 my-0">

    <!-- User info -->
    <div class="sidebar-user px-3 py-2">
        <div class="d-flex align-items-center gap-2">
            <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center text-white fw-bold">
                <?= strtoupper(substr($currentUser['full_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="text-white fw-semibold" style="font-size:0.8rem; line-height:1.2">
                    <?= sanitize($currentUser['full_name']) ?>
                </div>
                <span class="badge bg-<?= isAdmin() ? 'warning' : (isUser() ? 'success' : 'info') ?> text-dark" style="font-size:0.65rem">
                    <?= ucfirst($currentUser['role']) ?>
                </span>
            </div>
        </div>
    </div>

    <hr class="border-secondary mx-3 my-0">

    <!-- Navigation -->
    <ul class="nav flex-column px-2 py-2">

        <?php if (isUser()): ?>
            <?php /* ─── USER (Scanner-only) MENU ─── */ ?>

            <li class="nav-section-label">SCANNER</li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>attendance/scanner.php"
                   class="nav-link <?= $currentFile === 'scanner.php' ? 'active' : '' ?>">
                    <i class="bi bi-qr-code-scan me-2"></i>QR Scanner
                </a>
            </li>

        <?php else: ?>
            <?php /* ─── STAFF (admin + teacher) MENU ─── */ ?>

            <li class="nav-section-label">MAIN</li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>dashboard.php"
                   class="nav-link <?= $currentFile === 'dashboard.php' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>attendance/scanner.php"
                   class="nav-link <?= $currentFile === 'scanner.php' ? 'active' : '' ?>">
                    <i class="bi bi-qr-code-scan me-2"></i>QR Scanner
                </a>
            </li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>attendance/attendance.php"
                   class="nav-link <?= $currentFile === 'attendance.php' ? 'active' : '' ?>">
                    <i class="bi bi-calendar3 me-2"></i>Attendance
                </a>
            </li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>calendar/calendar.php"
                   class="nav-link <?= $currentFile === 'calendar.php' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-event me-2"></i>School Calendar
                </a>
            </li>

            <li class="nav-section-label mt-2">MANAGE</li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>students/students.php"
                   class="nav-link <?= $currentFile === 'students.php' ? 'active' : '' ?>">
                    <i class="bi bi-people-fill me-2"></i>Students
                </a>
            </li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>attendance/manual.php"
                   class="nav-link <?= $currentFile === 'manual.php' ? 'active' : '' ?>">
                    <i class="bi bi-pencil-square me-2"></i>Manual Entry
                </a>
            </li>

            <?php /* ─── REPORTS (admin + teacher) ─── */ ?>
            <li class="nav-section-label mt-2">REPORTS</li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>reports/sf2.php"
                   class="nav-link <?= $currentFile === 'sf2.php' ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-ruled me-2"></i>SF2 Report
                </a>
            </li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>reports/sf4.php"
                   class="nav-link <?= $currentFile === 'sf4.php' ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>SF4 Report
                </a>
            </li>

            <?php if (isAdmin()): ?>
                <?php /* ─── ADMIN-ONLY REPORTS ─── */ ?>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>reports/index_reports.php"
                       class="nav-link <?= $currentFile === 'index_reports.php' ? 'active' : '' ?>">
                        <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>analytics/analytics.php"
                       class="nav-link <?= $currentFile === 'analytics.php' ? 'active' : '' ?>">
                        <i class="bi bi-bar-chart-fill me-2"></i>Analytics
                    </a>
                </li>

                <li class="nav-section-label mt-2">ADMIN</li>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>users/users.php"
                       class="nav-link <?= $currentFile === 'users.php' ? 'active' : '' ?>">
                        <i class="bi bi-person-gear me-2"></i>Users
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>sections/sections.php"
                       class="nav-link <?= $currentFile === 'sections.php' ? 'active' : '' ?>">
                        <i class="bi bi-diagram-3 me-2"></i>Sections
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>sms/logs.php"
                       class="nav-link <?= $currentFile === 'logs.php' ? 'active' : '' ?>">
                        <i class="bi bi-chat-dots-fill me-2"></i>SMS Logs
                    </a>
                </li>

                <li class="nav-item">
                    <a href="<?= BASE_URL ?>settings/settings.php"
                       class="nav-link <?= $currentFile === 'settings.php' ? 'active' : '' ?>">
                        <i class="bi bi-gear-fill me-2"></i>Settings
                    </a>
                </li>
            <?php endif; ?>

        <?php endif; ?>

    </ul>

    <!-- Logout (pinned to bottom via margin-top:auto) -->
    <div class="sidebar-logout px-3 py-3">
        <a href="<?= BASE_URL ?>logout.php"
            class="btn btn-outline-danger btn-sm w-100"
            onclick="return confirm('Are you sure you want to logout?')">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
        </a>
    </div>
</nav>

<!-- Main content wrapper -->
<div class="main-content flex-grow-1">
    <!-- Top navbar -->
    <nav class="top-navbar navbar navbar-expand px-3 py-2">
        <button class="btn btn-link text-dark p-0 me-3" id="sidebarToggle">
            <i class="bi bi-list fs-5"></i>
        </button>
        <span class="text-muted small">
            <i class="bi bi-calendar3 me-1"></i>
            <?= date('l, F j, Y') ?>
        </span>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="badge bg-success">
                <i class="bi bi-circle-fill me-1" style="font-size:0.5rem"></i>Online
            </span>
        </div>
    </nav>

    <!-- Page content -->
    <div class="content-area p-3 p-lg-4">