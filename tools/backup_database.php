<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';require_once dirname(__DIR__).'/includes/database_backups.php';
$db=getDB(true);$root=backupRoot();$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
if(in_array('--dry-run',$argv,true)){echo json_encode(['queued'=>(int)$db->query("SELECT COUNT(*) FROM database_backups WHERE status='queued'")->fetchColumn(),'time_zone'=>'Asia/Manila'])."\n";exit;}
$runner=static function(string $path)use($root):void {dumpDatabaseToFile($path,['host'=>DB_HOST,'port'=>DB_PORT,'database'=>DB_NAME,'user'=>DB_USER,'password'=>DB_PASS,'tls_ca'=>DB_TLS_CA],getenv('BACKUP_DUMP_BINARY')?:'',$root);};
$failed=0;
foreach($db->query("SELECT id FROM database_backups WHERE status='queued' ORDER BY created_at LIMIT 5")->fetchAll(PDO::FETCH_COLUMN) as $id){$r=createDatabaseBackup($db,['root'=>$root,'kind'=>'manual','id'=>$id,'now'=>$now],$runner);$failed+=(int)($r['status']!=='completed');}
if(!in_array('--queued-only',$argv,true)){$r=createDatabaseBackup($db,['root'=>$root,'kind'=>'daily','now'=>$now],$runner);$failed+=(int)($r['status']!=='completed');}
runBackupRetention($db,$now,$root);echo json_encode(['failed'=>$failed])."\n";exit($failed?1:0);
