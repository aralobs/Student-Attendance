<?php
require_once '../config/database.php';require_once '../includes/functions.php';require_once '../includes/database_backups.php';
requireAdmin();requireCsrfPost();$db=getDB();
try{
    if(isset($_POST['retain_until'])){
        $actor=backupActor($db,currentUser());$date=$_POST['retain_until'];$id=$_POST['id']??'';
        if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!($parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date))||$parsed->format('Y-m-d')!==$date||$date<date('Y-m-d')||!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Choose a valid future retention date.');
        $db->beginTransaction();$q=$db->prepare("UPDATE database_backups SET retained_until=? WHERE id=? AND status='completed'");$q->execute([$date.' 23:59:59',$id]);
        securityAuditContext($actor);securityAuditEvent($db,'backup.retention','success');$db->commit();setFlash('success','Retention date saved.');
    }else{queueManualBackup($db,currentUser());setFlash('success','Backup queued. Refresh this page to see completion.');}
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();setFlash('warning',$e->getMessage());}
catch(Throwable $e){if($db->inTransaction())$db->rollBack();setFlash('danger','Unable to queue or update backup. Contact the administrator.');}
header('Location: index.php');exit;
