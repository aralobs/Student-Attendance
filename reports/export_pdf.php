<?php
// Preserve the legacy URL while using the maintained SF2 print/PDF view.
require_once __DIR__ . '/../includes/student_access.php';
requireStudentManagement();
$month = filter_var($_GET['month'] ?? date('n'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>12]]);
$year = filter_var($_GET['year'] ?? date('Y'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1900,'max_range'=>2100]]);
$sectionId = filter_var($_GET['section'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$month || !$year || !$sectionId) { http_response_code(400); exit('Invalid report details.'); }
if (enrollmentModelEnabled(getDB())) {
    require_once __DIR__ . '/../includes/enrollment_reports.php';
    $from=sprintf('%04d-%02d-01',$year,$month);
    $context=prepareEnrollmentReport(getDB(),$from,date('Y-m-t',strtotime($from)),(int)$sectionId);
    header('Location: ' . BASE_URL . 'reports/sf2.php?' . http_build_query(['month'=>$month,'year'=>$year,'section'=>$sectionId,'school_year_id'=>$context['year']['id']]));
    exit;
}
[$scope, $params] = studentSectionScope('sec');
$stmt = getDB()->prepare("SELECT sec.id FROM sections sec WHERE sec.id = ? AND sec.is_active = 1 AND ($scope)");
$stmt->execute(array_merge([$sectionId], $params));
if (!$stmt->fetch()) { http_response_code(403); exit('Section access denied.'); }
header('Location: ' . BASE_URL . 'reports/sf2.php?' . http_build_query(['month'=>$month,'year'=>$year,'section'=>$sectionId]));
exit;
