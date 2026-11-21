<?php
require_once __DIR__.'/support/enrollment_fixture.php';
$service=dirname(__DIR__).'/includes/database_backups.php';if(is_file($service))require_once $service;
checkEnrollment(function_exists('createDatabaseBackup'),'Backup creation must exist');
$previousDirectory=getcwd();try{chdir(sys_get_temp_dir());expectEnrollmentError(DomainException::class,fn()=>backupRoot(''));}finally{chdir($previousDirectory);}
withEnrollmentFixture(function(array $f):void {
 $db=$f['db'];ensureBackupSchema($db);$root=sys_get_temp_dir().'/attendance-backup-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
 try {
  $now=new DateTimeImmutable('2026-10-25 00:30:00',new DateTimeZone('Asia/Manila'));
  $runner=function(string $path):void {file_put_contents($path,"CREATE TABLE synthetic (id INT);\nINSERT INTO synthetic VALUES(1);\n".str_repeat('-- synthetic backup fixture'."\n",60));};
  $backup=createDatabaseBackup($db,['root'=>$root,'kind'=>'daily','now'=>$now],$runner);
  checkEnrollment($backup['status']==='completed' && str_starts_with($backup['relative_path'],'2026/10/25/'),'Manila date groups a complete backup');
  $file=resolveBackupFile($root,$backup);checkEnrollment(hash_file('sha256',$file)===$backup['sha256'],'Checksum recorded');
  $download=authorizedBackupDownload($db,$f['admin'],$backup['id'],$root);checkEnrollment($download['path']===$file,'Administrator resolves only the intended completed backup');
  $original=file_get_contents($file);file_put_contents($file,'synthetic corruption');expectEnrollmentError(DomainException::class,fn()=>authorizedBackupDownload($db,$f['admin'],$backup['id'],$root));file_put_contents($file,$original);
  $same=createDatabaseBackup($db,['root'=>$root,'kind'=>'daily','now'=>$now],$runner);checkEnrollment($same['id']===$backup['id'],'Daily job idempotent');
  $bad=createDatabaseBackup($db,['root'=>$root,'kind'=>'manual','now'=>$now],fn($path)=>file_put_contents($path,''));
  checkEnrollment($bad['status']==='failed','Empty dump is visibly failed');
  $forged=$backup;$forged['relative_path']='../outside.sql.gz';expectEnrollmentError(DomainException::class,fn()=>resolveBackupFile($root,$forged));
  $r=runBackupRetention($db,$now->modify('+31 days'),$root);checkEnrollment(is_file($file),'Monthly snapshot survives daily rotation');
  $r=runBackupRetention($db,$now->modify('+13 months'),$root);checkEnrollment(is_file($file),'Last verified copy retained when no replacement exists');
  $replacement=createDatabaseBackup($db,['root'=>$root,'kind'=>'manual','now'=>$now->modify('+13 months')],$runner);
  runBackupRetention($db,$now->modify('+13 months +1 day'),$root);checkEnrollment(!is_file($file) && is_file(resolveBackupFile($root,$replacement)),'Expired monthly snapshot rotates after a replacement exists');
 } finally {
  $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
 }
});
echo "Database backup tests passed (synthetic dump runner).\n";
