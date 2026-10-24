<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/enrollment_corrections.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Enrollment corrections require an administrator.'); }
requireCsrfPost();
$db=getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
try {
    if (($_POST['action'] ?? '')==='cancel_graduation') {
        if (!is_string($_POST['reason'] ?? null)) throw new DomainException('Enter a reason.');
        revisePreparedGraduationDecision($db,currentUser(),movingUpId($_POST['decision_id'] ?? null),$_POST['reason'],movingUpId($_POST['expected_version'] ?? null));
    } else {
        if (!is_string($_POST['expected_fingerprint'] ?? null)) throw new DomainException('Preview before confirming.');
        applyEnrollmentCorrection($db,currentUser(),movingUpId($_POST['correction_id'] ?? null),$_POST['expected_fingerprint']);
    }
    setFlash('success','Enrollment correction saved.');
    header('Location: enrollment_corrections.php');
} catch (DomainException|InvalidArgumentException $error) {
    http_response_code(409); echo sanitize($error->getMessage()).' <a href="enrollment_corrections.php">Preview again</a>';
} catch (Throwable $error) {
    http_response_code(500); echo 'Unable to apply correction. Reload and preview again.';
}
exit;
