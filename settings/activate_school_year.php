<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/school_years.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('School-year activation requires an administrator.'); }
requireCsrfPost();
$db=getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment must already be enabled. Initial cutover is a separate operation.'); }
try {
    $target=filter_var($_POST['target_year_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($target===false || !is_string($_POST['expected_fingerprint'] ?? null)) throw new DomainException('Review activation readiness before confirming.');
    activateSchoolYear($db,currentUser(),$target,$_POST['expected_fingerprint']);
    setFlash('success','School year activated. Review unassigned students for follow-up.');
    header('Location: school_years.php'); exit;
} catch (DomainException|InvalidArgumentException $error) {
    http_response_code(409);
    echo sanitize($error->getMessage()).' <a href="school_years.php">Review school-year readiness</a>'; exit;
} catch (Throwable $error) {
    http_response_code(500); exit('Activation was not completed. Review readiness and try again.');
}
