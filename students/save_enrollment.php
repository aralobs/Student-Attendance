<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/student_access.php';
require_once '../includes/enrollment_commands.php';
requireStudentManagement();
requireCsrfPost();
$db = getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
$yearId = (int)($_POST['school_year_id'] ?? 0);
try {
    if (!is_string($_POST['lrn'] ?? null) || trim($_POST['lrn']) === '') throw new InvalidArgumentException('Enter the existing student LRN.');
    // An identity lookup never loads names, parent contacts, photos, or QR secrets.
    $q = $db->prepare('SELECT id FROM students WHERE lrn=? AND is_active=1'); $q->execute([trim($_POST['lrn'])]);
    $studentId = (int)$q->fetchColumn();
    if (!$studentId) throw new DomainException('Student is unavailable for enrollment. Contact an administrator.');
    createAnnualEnrollment($db,currentUser(),$studentId,$yearId,(int)($_POST['section_id'] ?? 0),(string)($_POST['starts_on'] ?? ''),(string)($_POST['ends_on'] ?? ''));
    setFlash('success','Annual enrollment created. The existing student QR identity is retained.');
} catch (DomainException|InvalidArgumentException $error) { setFlash('danger',$error->getMessage()); }
catch (Throwable $error) { setFlash('danger','Unable to create the enrollment. Please try again.'); }
header('Location: enroll.php?school_year_id=' . $yearId);
exit;
