<?php
require_once '../config/database.php';require_once '../includes/functions.php';require_once '../includes/database_backups.php';
requireAdmin();
try{$download=authorizedBackupDownload(getDB(),currentUser(),(string)($_GET['id']??''));}
catch(Throwable $e){http_response_code(404);exit('Completed backup not available.');}
header('Content-Type: application/gzip');header('Content-Disposition: attachment; filename="attendance-'.str_replace('-','',substr($download['backup']['created_at'],0,10)).'-'.$download['backup']['id'].'.sql.gz"');
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($download['path']));
readfile($download['path']);exit;
