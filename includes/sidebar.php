<?php
/**
 * Sidebar Navigation — static active detection
 */

// Current script + folder, resolved ONCE
$currentFile = basename($_SERVER['PHP_SELF']);                 // e.g. attendance.php
$currentPath = str_replace('\\', '/', $_SERVER['PHP_SELF']);   // normalize slashes
?>

<!-- Sidebar -->
<nav id="sidebar" class="sidebar d-flex flex-column">
    <!-- Brand -->
    <div class="sidebar-brand d-flex align-items-center px-3 py-3">
        <div class="brand-logo me-2">
            <i class="bi bi-mortarboard-fill fs-4 text-warning"></i>
        </div>
        <div class="brand-text">
            <div class="fw-bold text-white lh-1" style="font-size:0.9rem">SPCCS</div>
            <div class="text-white-50" style="font-size:0.7rem">Kinder Attendance</div>
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
                <span class="badge bg-<?= isAdmin() ? 'warning' : 'info' ?> text-dark" style="font-size:0.65rem">
                    <?= ucfirst($currentUser['role']) ?>
                </span>
            </div>
        </div>
    </div>

    <hr class="border-secondary mx-3 my-0">

    <!-- Navigation -->
    <ul class="nav flex-column px-2 py-2 flex-grow-1">

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

        <?php if (isAdmin()): ?>
            <li class="nav-section-label mt-2">REPORTS</li>

            <li class="nav-item">
                <a href="<?= BASE_URL ?>reports/index_reports.php"
                   class="nav-link <?= $currentFile === 'index_reports.php' ? 'active' : '' ?>">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>Reports
                </a>
            </li>

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

    </ul>

    <!-- Logout -->
    <div class="px-3 py-3 mt-auto">
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