<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/attendance_decisions.php';
ensureDeliverySchema(getDB(true)); ensureAttendanceDecisionSchema(getDB(true));
echo "Attendance decision schema installed; existing scans preserved.\n";
