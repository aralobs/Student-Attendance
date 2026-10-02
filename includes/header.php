<?php
/**
 * Global HTML header + Bootstrap + CSS includes
 */

// ─── Anti-cache headers ─────────────────────────────────────
// Prevent the browser from serving a stale authenticated page
// after the session has been invalidated by another browser login.
// Must run BEFORE any HTML output — that's why this file starts
// with <?php on byte 0 (no whitespace, no BOM).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    header('Vary: Cookie');
}

requireLogin();
$currentUser = currentUser();
$flash = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Attendance System' ?> — SPCCS Kinder</title>

    <!-- Anti-cache meta tags (fallback for older browsers) -->
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">

    <script>
    // Force a server round-trip when the page is restored from bfcache
    // (Back/Forward cache). Otherwise a kicked browser could press Back
    // and see a stale dashboard without ever hitting the server.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });
    </script>
</head>
<body>
<div class="wrapper d-flex">