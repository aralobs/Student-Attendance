<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();
requireCsrfPost();

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    setFlash('danger', 'Invalid section ID.');
    header('Location: index.php');
    exit;
}

$db   = getDB();
require_once '../includes/enrollment_operations.php';
if (enrollmentModelEnabled($db)) {
    try {
        $found=setEnrollmentEntityActive($db,currentUser(),'section',$id,false);
        setFlash($found?'success':'danger',$found?'Record visibility updated.':'Record not found.');
    } catch (DomainException $error) { setFlash('danger',$error->getMessage()); }
    catch (Throwable $error) { setFlash('danger','Unable to update the record. Please try again.'); }
    header('Location: index.php'); exit;
}
$stmt = $db->prepare("SELECT * FROM sections WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$section = $stmt->fetch();

if (!$section) {
    setFlash('danger', 'Section not found.');
} else {
    // Check if section has students
    $stuCount = $db->prepare("SELECT COUNT(*) FROM students WHERE section_id = ? AND is_active = 1");
    $stuCount->execute([$id]);
    $count = $stuCount->fetchColumn();

    if ($count > 0) {
        setFlash('danger', "Cannot delete — this section has {$count} active student(s). Reassign them first.");
    } else {
        $db->prepare("UPDATE sections SET is_active = 0 WHERE id = ?")
           ->execute([$id]);
        setFlash('success', "Section '{$section['section_name']}' deleted.");
    }
}

header('Location: index.php');
exit;