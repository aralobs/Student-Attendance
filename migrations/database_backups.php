<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';require_once dirname(__DIR__).'/includes/database_backups.php';
ensureBackupSchema(getDB(true));echo "Backup metadata schema installed. No database export was created.\n";
