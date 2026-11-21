<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/hostinger_maintenance.php';
$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
$jobs=hostingerMaintenanceJobs($now,(getenv('ATTENDANCE_INTERVENTION_ENABLED_AT')?:'')!=='');
if(in_array('--dry-run',$argv,true)){echo json_encode(['time_zone'=>'Asia/Manila','jobs'=>$jobs],JSON_THROW_ON_ERROR).PHP_EOL;exit;}
$directory=dirname(__DIR__).'/storage/private';
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){fwrite(STDERR,"Maintenance storage unavailable.\n");exit(1);}
$lock=fopen($directory.'/hostinger-maintenance.lock','c');
if(!$lock){fwrite(STDERR,"Maintenance lock unavailable.\n");exit(1);}
if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);echo "Maintenance already running.\n";exit;}
try {
    $db=getDB(true);
    $results=runHostingerMaintenance($jobs,static function(array $job)use($db,$now):int {
        return match($job['worker']) {
            'interventions'=>hostingerMaintenanceInterventions($db,$now,in_array('--dry-run',$job['arguments'],true)),
            'backups'=>hostingerMaintenanceBackups($db,$now,in_array('--queued-only',$job['arguments'],true)),
            default=>throw new LogicException('Unknown maintenance worker'),
        };
    });
    echo json_encode(['time_zone'=>'Asia/Manila','results'=>$results],JSON_THROW_ON_ERROR).PHP_EOL;
    $exit=(int)(count(array_filter($results,fn($row)=>$row['exit_code']!==0))>0);
} catch(Throwable $error) {fwrite(STDERR,"Maintenance configuration unavailable.\n");$exit=1;}
finally {flock($lock,LOCK_UN);fclose($lock);}
exit($exit);
