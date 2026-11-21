<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/adviser_workflows.php';
ensureAdviserWorkflowSchema(getDB(true));
echo "Adviser requests and intervention follow-ups installed. Existing records preserved.\n";
