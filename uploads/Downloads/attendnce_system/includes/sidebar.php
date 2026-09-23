<?php
/**
 * Sidebar Navigation
 */
$currentFile = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

function navItem($href, $icon, $label, $dir = '') {
    global $currentDir;
    $active = ($currentDir === $dir || strpos($_SERVER['PHP_SELF'], $dir) !== false) ? 'active' : '';
    return "<li class='nav-item'>
              <a href='{$href}' class='nav-link {$active}'>
                <i class='bi bi-{$icon} me-2'></i>{$label}
              </a>
            </li>";
}
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
        <?= navItem(BASE_URL . 'dashboard.php', 'speedometer2', 'Dashboard', 'dashboard') ?>
        <?= navItem(BASE_URL . 'attendance/scanner.php', 'qr-code-scan', 'QR Scanner', 'scanner') ?>
        <?= navItem(BASE_URL . 'attendance/index.php', 'calendar3', 'Attendance', 'attendance') ?>

        <li class="nav-section-label mt-2">MANAGE</li>
        <?= navItem(BASE_URL . 'students/index.php', 'people-fill', 'Students', 'students') ?>

        <?php if (isAdmin()): ?>
        <?= navItem(BASE_URL . 'reports/sf2.php', 'file-earmark-text-fill', 'SF2 Reports', 'reports') ?>
        <?= navItem(BASE_URL . 'analytics/index.php', 'bar-chart-fill', 'Analytics', 'analytics') ?>

        <li class="nav-section-label mt-2">ADMIN</li>
        <?= navItem(BASE_URL . 'users/index.php', 'person-gear', 'User Management', 'users') ?>
        <?= navItem(BASE_URL . 'sms/logs.php', 'chat-dots-fill', 'SMS Logs', 'sms') ?>
        <?= navItem(BASE_URL . 'settings/index.php', 'gear-fill', 'Settings', 'settings') ?>
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