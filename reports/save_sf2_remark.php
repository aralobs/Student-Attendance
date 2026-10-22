<?php
require_once __DIR__ . '/../includes/sf2_remarks.php';
requireLogin();
header('Content-Type: application/json; charset=UTF-8');
requireCsrfPost(true);
if (!isAdmin() && !isTeacher()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Report access denied.']); exit; }
try {
    $values = [];
    foreach (['student_id','section_id','month','year','version'] as $field) {
        $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value === null) throw new InvalidArgumentException('Invalid report details.');
        $values[$field] = $value;
    }
    if (!is_string($_POST['remark'] ?? null)) throw new InvalidArgumentException('Invalid remark.');
    $db = getDB(true);
    enforceAbuseLimits($db, [abuseBucket('sf2.save.user', (string)currentUser()['id'], 120, 60), abuseBucket('sf2.save.ip', abuseClientIP(), 600, 60)]);
    $version = saveSF2Remark($db, $values['student_id'], $values['section_id'], $values['month'], $values['year'], $_POST['remark'], $values['version']);
    echo json_encode(['success'=>true,'version'=>$version]);
} catch (InvalidArgumentException $error) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>$error->getMessage()]);
} catch (DomainException $error) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Report access denied.']);
} catch (SF2RemarkConflict $error) {
    http_response_code(409); echo json_encode(['success'=>false,'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    http_response_code(503); error_log('SF2 remark save unavailable.');
    echo json_encode(['success'=>false,'message'=>'Remarks could not be saved. Please try again later.']);
}
