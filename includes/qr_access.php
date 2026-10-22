<?php
require_once __DIR__ . '/functions.php';

/** QR cards expose reusable attendance credentials; only authorized staff may issue them. */
function requireQRManagement(): void {
    requireLogin();
    sendNoCacheHeaders();
    if (!isAdmin() && !isTeacher()) {
        http_response_code(403);
        exit('QR cards are restricted to administrators and assigned teachers.');
    }
}

function requireQRSectionAccess(int $sectionId): void {
    if (!isAdmin() && ($sectionId <= 0 || !canAccessSection($sectionId))) {
        http_response_code(403);
        exit('Access denied to this section.');
    }
}
