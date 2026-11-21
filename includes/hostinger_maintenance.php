<?php
declare(strict_types=1);

function hostingerMaintenanceJobs(DateTimeImmutable $now,bool $interventionsEnabled):array {
    $local=$now->setTimezone(new DateTimeZone('Asia/Manila'));
    return [
        ['worker'=>'interventions','arguments'=>$interventionsEnabled?[]:['--dry-run']],
        ['worker'=>'backups','arguments'=>$local->format('H:i')<'02:00'?['--queued-only']:[]],
    ];
}

/** In-process workers support shared hosts that disable process execution. */
function runHostingerMaintenance(array $jobs,callable $run):array {
    $results=[];
    foreach($jobs as $job) {
        try {$exit=(int)$run($job);}catch(Throwable $error){$exit=1;error_log('Hostinger maintenance worker failed: '.$job['worker'].' exception='.get_class($error));}
        $results[]=['worker'=>$job['worker'],'exit_code'=>$exit];
    }
    return $results;
}

function hostingerMaintenanceInterventions(PDO $db,DateTimeImmutable $now,bool $dryRun):int {
    require_once __DIR__.'/functions.php';
    require_once __DIR__.'/absence_interventions.php';
    requirePanelFeatures($db);
    $db->prepare("UPDATE attendance_absence_notifications SET status='unknown',completed_at=? WHERE status='sending' AND attempted_at<?")->execute([$now->format('Y-m-d H:i:s'),$now->modify('-15 minutes')->format('Y-m-d H:i:s')]);
    foreach($db->query("SELECT id FROM student_enrollments WHERE state='active' OR id IN(SELECT enrollment_id FROM attendance_recompute_queue)")->fetchAll(PDO::FETCH_COLUMN) as $id)recomputeEnrollmentInterventions($db,(int)$id,$now->format('Y-m-d'),$now);
    if($dryRun)return 0;
    $result=processAbsenceInterventions($db,$now,static function($phone,$student,$episode):string {
        $status=null;
        sendSMS($phone,renderNotificationText(notificationTemplate('sms_intervention_template'),$student,$episode['threshold_date']),(int)$student['student_id'],'intervention',$status);
        return $status==='sent'?'sent':($status==='failed'?'failed':'unknown');
    });
    return (int)(($result['failed']??0)>0 || ($result['unknown']??0)>0);
}

function hostingerMaintenanceBackups(PDO $db,DateTimeImmutable $now,bool $queuedOnly):int {
    require_once __DIR__.'/database_backups.php';
    $root=backupRoot();$failed=0;
    $runner=static function(string $path)use($root):void {
        dumpDatabaseToFile($path,['host'=>DB_HOST,'port'=>DB_PORT,'database'=>DB_NAME,'user'=>DB_USER,'password'=>DB_PASS,'tls_ca'=>DB_TLS_CA],getenv('BACKUP_DUMP_BINARY')?:'',$root);
    };
    foreach($db->query("SELECT id FROM database_backups WHERE status='queued' ORDER BY created_at LIMIT 5")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $result=createDatabaseBackup($db,['root'=>$root,'kind'=>'manual','id'=>$id,'now'=>$now],$runner);$failed+=(int)($result['status']!=='completed');
    }
    if(!$queuedOnly){$result=createDatabaseBackup($db,['root'=>$root,'kind'=>'daily','now'=>$now],$runner);$failed+=(int)($result['status']!=='completed');}
    runBackupRetention($db,$now,$root);
    return $failed?1:0;
}
