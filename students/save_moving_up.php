<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/moving_up.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit('Moving up requires an administrator.'); }
requireCsrfPost();
$db=getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
try {
    $input=$_POST;
    if (($input['select_all'] ?? '')==='1') $input['student_ids']=array_column(listMovingUpSourceRoster($db,currentUser(),movingUpId($input['source_year_id'] ?? null),movingUpId($input['source_section_id'] ?? null)),'student_id');
    if (!is_string($input['request_key'] ?? null) || !is_string($input['expected_fingerprint'] ?? null)) throw new DomainException('Preview the batch before confirming.');
    $saved=prepareMovingUp($db,currentUser(),$input,$input['request_key'],$input['expected_fingerprint']);
    setFlash('success',count($saved['decision_ids']).' moving-up decisions prepared'.($saved['replayed']?' (existing request).':'.'));
} catch(DomainException|InvalidArgumentException $error) { setFlash('danger',$error->getMessage()); }
catch(Throwable $error) { setFlash('danger','Unable to prepare this batch. Preview the roster and try again.'); }
header('Location: moving_up.php?source_year_id='.(int)($_POST['source_year_id'] ?? 0).'&source_section_id='.(int)($_POST['source_section_id'] ?? 0).'&target_year_id='.(int)($_POST['target_year_id'] ?? 0));
exit;
