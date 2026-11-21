<?php
declare(strict_types=1);
require_once __DIR__.'/security_audit.php';
require_once __DIR__.'/pdo_database_dump.php';

function backupSchemaSQL():string {
    return "CREATE TABLE IF NOT EXISTS database_backups (
      id CHAR(32) PRIMARY KEY,kind ENUM('daily','manual') NOT NULL,daily_date DATE NULL,
      status ENUM('queued','running','completed','failed','expired') NOT NULL DEFAULT 'queued',
      created_at DATETIME NOT NULL,completed_at DATETIME NULL,created_by INT NULL,
      relative_path VARCHAR(160) NULL,bytes BIGINT UNSIGNED NULL,sha256 CHAR(64) NULL,
      monthly_until DATETIME NULL,retained_until DATETIME NULL,
      UNIQUE KEY daily_backup(daily_date),KEY backup_rotation(status,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}
function ensureBackupSchema(PDO $db):void {$db->exec(backupSchemaSQL());}
function backupRoot(?string $configured=null):string {
    $configuredPath=$configured??(getenv('BACKUP_DIR')?:'');
    if($configuredPath==='')throw new DomainException('Configure a private writable backup directory first.');
    $root=realpath($configuredPath);
    if(!$root || !is_dir($root) || !is_writable($root)) throw new DomainException('Configure a private writable backup directory first.');
    $normalized=strtolower(str_replace('\\','/',$root)); $app=strtolower(str_replace('\\','/',realpath(dirname(__DIR__))));
    $documentRoot=$_SERVER['DOCUMENT_ROOT']??'';
    $document=$documentRoot!==''?realpath($documentRoot):false;
    foreach(array_filter([$app,$document?strtolower(str_replace('\\','/',$document)):null]) as $public) {
        if($normalized===$public || str_starts_with($normalized,$public.'/')) throw new DomainException('Backup storage must be outside the website and checkout.');
    }
    return $root;
}
function backupActor(PDO $db,array $actor):int {
    if(($actor['role']??'')!=='admin')throw new DomainException('Administrator access required.');
    $q=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1");$q->execute([(int)($actor['id']??0)]);
    $id=$q->fetchColumn();if(!$id)throw new DomainException('Administrator access required.');return (int)$id;
}
function queueManualBackup(PDO $db,array $actor):string {
    $id=backupActor($db,$actor);backupRoot();$job=bin2hex(random_bytes(16));
    $db->beginTransaction();try {
        $q=$db->query("SELECT COUNT(*) FROM database_backups WHERE status IN('queued','running')");if((int)$q->fetchColumn()>=5)throw new DomainException('Backup jobs are already pending. Wait for completion.');
        $db->prepare("INSERT INTO database_backups(id,kind,created_at,created_by) VALUES(?,'manual',?,?)")->execute([$job,date('Y-m-d H:i:s'),$id]);
        securityAuditContext($id);securityAuditEvent($db,'backup.queued','success');$db->commit();return $job;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function resolveBackupFile(string $root,array $backup):string {
    if(($backup['status']??'')!=='completed' || !preg_match('#^\d{4}/\d{2}/\d{2}/[a-f0-9]{32}\.sql\.gz$#D',(string)($backup['relative_path']??'')))throw new DomainException('Completed backup not available.');
    $root=backupRoot($root);$path=realpath($root.'/'.$backup['relative_path']);
    if(!$path || !is_file($path) || !str_starts_with(strtolower(str_replace('\\','/',$path)),strtolower(str_replace('\\','/',$root)).'/'))throw new DomainException('Backup file not available.');
    return $path;
}
function authorizedBackupDownload(PDO $db,array $actor,string $id,?string $root=null):array {
    backupActor($db,$actor);
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Backup not available.');
    $q=$db->prepare('SELECT * FROM database_backups WHERE id=?');$q->execute([$id]);$backup=$q->fetch(PDO::FETCH_ASSOC);
    if(!$backup)throw new DomainException('Backup not available.');
    $path=resolveBackupFile(backupRoot($root),$backup);
    if(!hash_equals((string)$backup['sha256'],(string)hash_file('sha256',$path)))throw new DomainException('Backup integrity check failed. Contact the administrator.');
    securityAuditContext((int)$actor['id']);securityAuditEvent($db,'backup.download','success');return ['backup'=>$backup,'path'=>$path];
}
function createDatabaseBackup(PDO $db,array $options,callable $dumpRunner):array {
    $root=backupRoot($options['root']??null);$now=($options['now']??new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Manila'));
    $kind=$options['kind']??'manual';if(!in_array($kind,['daily','manual'],true))throw new InvalidArgumentException('Invalid backup kind.');
    $lock='attendance_backup_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
    $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new DomainException('Another backup is running.');
    $id=$options['id']??bin2hex(random_bytes(16));$sql=null;$temp=null;
    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid backup job.');
    try {
        if($kind==='daily'){
            $q=$db->prepare('SELECT * FROM database_backups WHERE daily_date=?');$q->execute([$now->format('Y-m-d')]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if($prior && $prior['status']==='completed')return $prior;
            if($prior)$id=$prior['id'];
        }
        $q=$db->prepare('SELECT * FROM database_backups WHERE id=?');$q->execute([$id]);$job=$q->fetch(PDO::FETCH_ASSOC);
        if($job && $job['status']==='completed')return $job;
        if(!$job)$db->prepare('INSERT INTO database_backups(id,kind,daily_date,status,created_at) VALUES(?,?,?,\'running\',?)')->execute([$id,$kind,$kind==='daily'?$now->format('Y-m-d'):null,$now->format('Y-m-d H:i:s')]);
        else $db->prepare("UPDATE database_backups SET status='running' WHERE id=?")->execute([$id]);
        $relative=$now->format('Y/m/d').'/'.$id.'.sql.gz';$directory=$root.'/'.$now->format('Y/m/d');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Backup directory creation failed.');
        $canonical=realpath($directory);
        if(!$canonical||!str_starts_with(strtolower(str_replace('\\','/',$canonical)),strtolower(str_replace('\\','/',$root)).'/'))throw new DomainException('Unsafe backup directory.');
        $sql=$canonical.'/'.$id.'.partial.sql';$temp=$canonical.'/'.$id.'.partial.gz';$final=$canonical.'/'.$id.'.sql.gz';
        if(file_exists($sql)||file_exists($temp)||file_exists($final))throw new DomainException('An earlier artifact requires operator review before retry.');
        $dumpRunner($sql);clearstatcache(true,$sql);
        if(!is_file($sql)||filesize($sql)<100 || !str_contains((string)file_get_contents($sql,false,null,0,1048576),'CREATE TABLE'))throw new RuntimeException('Incomplete database dump.');
        $in=fopen($sql,'rb');$out=gzopen($temp,'wb6');if(!$in||!$out)throw new RuntimeException('Backup compression failed.');
        try {while(!feof($in)){ $chunk=fread($in,1048576);if($chunk===false||gzwrite($out,$chunk)!==strlen($chunk))throw new RuntimeException('Backup write failed.');}}finally{fclose($in);gzclose($out);}
        chmod($temp,0600);if(!rename($temp,$final))throw new RuntimeException('Backup publication failed.');unlink($sql);$sql=null;$temp=null;
        $monthly=null;
        if($kind==='daily'){$q=$db->prepare("SELECT COUNT(*) FROM database_backups WHERE status='completed' AND monthly_until IS NOT NULL AND created_at>=? AND created_at<?");$q->execute([$now->format('Y-m-01 00:00:00'),$now->modify('first day of next month')->format('Y-m-d 00:00:00')]);if(!(int)$q->fetchColumn())$monthly=$now->modify('first day of this month')->modify('+12 months')->format('Y-m-d 00:00:00');}
        $db->prepare("UPDATE database_backups SET status='completed',completed_at=?,relative_path=?,bytes=?,sha256=?,monthly_until=? WHERE id=?")->execute([$now->format('Y-m-d H:i:s'),$relative,filesize($final),hash_file('sha256',$final),$monthly,$id]);
        securityAuditEvent($db,'backup.completed','success');
    }catch(Throwable $e){
        $db->prepare("UPDATE database_backups SET status='failed' WHERE id=?")->execute([$id]);
        // Failed artifacts remain private for operator review; never publish them as downloadable.
        error_log('SECURITY_ALERT database_backup_failed request_id='.securityRequestId());
    }finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    $q=$db->prepare('SELECT * FROM database_backups WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Backup job unavailable.');
}
function runBackupRetention(PDO $db,DateTimeImmutable $now,?string $configuredRoot=null):array {
    $root=backupRoot($configuredRoot);$now=$now->setTimezone(new DateTimeZone('Asia/Manila'));$deleted=[];$skipped=[];
    $latest=$db->query("SELECT id FROM database_backups WHERE status='completed' ORDER BY completed_at DESC,id DESC LIMIT 1")->fetchColumn();
    $q=$db->prepare("SELECT * FROM database_backups WHERE status='completed' AND created_at<? AND (monthly_until IS NULL OR monthly_until<=?) AND (retained_until IS NULL OR retained_until<=?)");
    $q->execute([$now->modify('-29 days')->format('Y-m-d 00:00:00'),$now->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s')]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $backup){
        if($backup['id']===$latest){$skipped[]=$backup['id'];continue;}
        $path=resolveBackupFile($root,$backup);if(!unlink($path))throw new RuntimeException('Backup expiration failed.');
        $db->prepare("UPDATE database_backups SET status='expired' WHERE id=? AND status='completed'")->execute([$backup['id']]);$deleted[]=$backup['id'];
    }
    return ['deleted'=>$deleted,'skipped'=>$skipped];
}

/** Argument-array process invocation; credentials are read from a private option file, never the command line. */
function dumpDatabaseToFile(string $output,array $connection,string $binary,string $privateRoot):void {
    if(!function_exists('proc_open')) {
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
        if(!empty($connection['tls_ca'])) {$options[PDO::MYSQL_ATTR_SSL_CA]=$connection['tls_ca'];$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
        $pdo=new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$connection['database'].';charset=utf8mb4',$connection['user'],$connection['password'],$options);
        dumpDatabaseWithPDO($pdo,$output);return;
    }
    if(!is_file($binary)||!is_executable($binary))throw new DomainException('Configure an executable database dump binary.');
    if(!preg_match('/^[a-zA-Z0-9_]+$/D',$connection['database']))throw new DomainException('Unsupported database identifier.');
    $credentials=$privateRoot.'/.dump-'.bin2hex(random_bytes(12)).'.cnf';$errors=$privateRoot.'/.dump-'.bin2hex(random_bytes(12)).'.err';
    $content="[client]\n";
    foreach(['host','port','user','password'] as $key){$value=(string)$connection[$key];if(preg_match('/[\x00-\x1f]/',$value))throw new DomainException('Invalid dump connection configuration.');$content.=$key.'="'.str_replace(['\\','"'],['\\\\','\\"'],$value)."\"\n";}
    if(!empty($connection['tls_ca']))$content.='ssl-ca="'.str_replace(['\\','"'],['\\\\','\\"'],$connection['tls_ca'])."\"\nssl-verify-server-cert\n";
    $handle=fopen($credentials,'xb');if(!$handle)throw new RuntimeException('Cannot create private dump configuration.');chmod($credentials,0600);fwrite($handle,$content);fclose($handle);
    try {
        $args=[$binary,'--defaults-extra-file='.$credentials,'--single-transaction','--quick','--hex-blob','--routines','--events','--triggers','--skip-comments',$connection['database']];
        $process=proc_open($args,[0=>['pipe','r'],1=>['file',$output,'wb'],2=>['file',$errors,'wb']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Dump process unavailable.');fclose($pipes[0]);$start=microtime(true);
        do{$status=proc_get_status($process);if(!$status['running'])break;if(microtime(true)-$start>300){proc_terminate($process);proc_close($process);throw new RuntimeException('Dump timed out.');}usleep(100000);}while(true);
        $exit=$status['exitcode'];proc_close($process);if($exit!==0)throw new RuntimeException('Database export failed.');
    }finally{if(is_file($credentials))unlink($credentials);if(is_file($errors))unlink($errors);}
}
