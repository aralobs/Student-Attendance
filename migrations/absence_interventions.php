<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';
ensureInterventionSchema(getDB(true));
getDB(true)->exec("ALTER TABLE sms_logs MODIFY type ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence','missing_checkout','intervention') NOT NULL");
echo "Intervention schema installed. Sending remains disabled without explicit enable time.\n";
