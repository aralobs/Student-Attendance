<?php
declare(strict_types=1);
function checkMaintenance(bool $value,string $message):void { if(!$value) throw new RuntimeException($message); }
$module=dirname(__DIR__).'/includes/hostinger_maintenance.php';
checkMaintenance(is_file($module),'Maintenance coordinator module must exist');
require $module;
$jobs=hostingerMaintenanceJobs(new DateTimeImmutable('2026-10-26 17:55:00',new DateTimeZone('UTC')),false);
checkMaintenance($jobs[0]['arguments']===['--dry-run'],'Unapproved intervention sending must remain disabled');
checkMaintenance($jobs[1]['arguments']===['--queued-only'],'Before 02:00 Manila only queued backups run');
$jobs=hostingerMaintenanceJobs(new DateTimeImmutable('2026-10-26 18:00:00',new DateTimeZone('UTC')),true);
checkMaintenance($jobs[0]['arguments']===[],'Explicitly enabled intervention worker keeps its configured dispatch behavior');
checkMaintenance($jobs[1]['arguments']===[],'02:00 Manila enables the idempotent daily backup');
$ran=[];
$results=runHostingerMaintenance($jobs,static function(array $job)use(&$ran):int { $ran[]=$job['worker']; if(count($ran)===1) throw new RuntimeException('sensitive provider details'); return 0; });
checkMaintenance(count($ran)===2,'One worker failure must not prevent the other worker from running');
checkMaintenance($results[0]['exit_code']===1 && $results[1]['exit_code']===0,'Failures remain observable');
checkMaintenance(!str_contains(json_encode($results),'sensitive'),'Worker exception details must not leak');
$entry=dirname(__DIR__).'/tools/hostinger_maintenance.php';
checkMaintenance(is_file($entry),'CLI entrypoint must exist');
checkMaintenance(!str_contains(file_get_contents($entry),'proc_open'),'Coordinator must run without disabled subprocess functions');
echo "Hostinger maintenance scheduling and failure-isolation checks passed.\n";
