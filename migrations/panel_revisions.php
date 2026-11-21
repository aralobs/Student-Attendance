<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';
require_once dirname(__DIR__).'/includes/database_backups.php';
require_once dirname(__DIR__).'/includes/adviser_workflows.php';
$db=getDB(true);
ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);ensureInterventionSchema($db);ensureBackupSchema($db);
ensureAdviserWorkflowSchema($db);
$db->exec("ALTER TABLE sms_logs MODIFY type ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence','missing_checkout','intervention') NOT NULL");
echo "Panel revisions schema installed. Attendance history preserved. Configure workers separately; intervention SMS remains disabled by default.\n";
