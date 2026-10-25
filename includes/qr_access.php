<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/enrollment_operations.php';

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
    if (enrollmentModelEnabled(getDB())) {
        $q=getDB()->prepare("SELECT sec.id FROM sections sec JOIN school_years y ON y.id=sec.school_year_id
            WHERE sec.id=? AND sec.is_active=1 AND y.state='active' AND CURDATE() BETWEEN y.starts_on AND y.ends_on");
        $q->execute([$sectionId]);
        if (!$q->fetchColumn() || (!isAdmin() && !canAccessSection($sectionId))) {
            http_response_code(403); exit('Access denied to this section.');
        }
        return;
    }
    if (!isAdmin() && ($sectionId <= 0 || !canAccessSection($sectionId))) {
        http_response_code(403);
        exit('Access denied to this section.');
    }
}

/** Stable QR identity, current eligible annual placement, current staff scope. */
function requireQRStudentAccess(int $id, array $student): array {
    if (enrollmentModelEnabled(getDB())) {
        $student=enrollmentOperationalStudent(getDB(),$id,date('Y-m-d'),currentUser());
        if (!$student) { http_response_code(403); exit('No eligible current enrollment.'); }
    }
    requireQRSectionAccess((int)($student['section_id']??0));
    return $student;
}
