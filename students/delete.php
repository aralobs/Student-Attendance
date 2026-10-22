<?php
/**
 * Delete Student (soft delete)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();
requireCsrfPost();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    setFlash('danger', 'Invalid student ID.');
    header('Location: students.php');
    exit;
}

$db   = getDB();
require_once '../includes/enrollment_operations.php';
if (enrollmentModelEnabled($db)) {
    try {
        $found=setEnrollmentEntityActive($db,currentUser(),'student',$id,false);
        setFlash($found?'success':'danger',$found?'Record visibility updated.':'Record not found.');
    } catch (DomainException $error) { setFlash('danger',$error->getMessage()); }
    catch (Throwable $error) { setFlash('danger','Unable to update the record. Please try again.'); }
    header('Location: students.php'); exit;
}
$stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found.');
} else {
    $db->prepare("UPDATE students SET is_active = 0 WHERE id = ?")
       ->execute([$id]);
    setFlash('success', "Student {$student['first_name']} {$student['last_name']} has been removed.");
}

header('Location: students.php');
exit;