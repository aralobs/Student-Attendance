<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once dirname(__DIR__).'/includes/absence_interventions.php';
if(($argv[1]??'')==='worker'){
 $name=$argv[2]??'';$marker=$argv[3]??'';$slot=$argv[4]??'';
 if(!preg_match('/^enrollment_test_[a-f0-9]{16}$/D',$name)||dirname($marker)!==__DIR__||!preg_match('/^\.intervention-race-[a-f0-9]{16}$/D',basename($marker))||!in_array($slot,['0','1'],true))throw new RuntimeException('Synthetic race coordinates required');
 $db=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$name,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 file_put_contents($marker.'.ready'.$slot,'ready');$until=microtime(true)+10;while(!is_file($marker.'.go')){if(microtime(true)>$until)throw new RuntimeException('Race gate timeout');usleep(10000);}
 putenv('ATTENDANCE_INTERVENTION_ENABLED_AT=2026-10-25 00:00:00');
 processAbsenceInterventions($db,new DateTimeImmutable('2026-10-25 18:00:00',new DateTimeZone('Asia/Manila')),function()use($marker):string{file_put_contents($marker,"attempt\n",FILE_APPEND|LOCK_EX);usleep(200000);return 'unknown';});exit;
}
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);ensureInterventionSchema($db);
 $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE student_enrollments SET starts_on='2026-10-21' WHERE id=1001; UPDATE enrollment_sections SET starts_on='2026-10-21' WHERE id=2001; UPDATE students SET parent_contact='09123456789' WHERE id=101; UPDATE attendance SET am_in=NULL,am_status='absent',pm_status='absent',attendance_type='absent' WHERE id=501; INSERT INTO school_calendar(date,title,type) VALUES('2026-10-24','Synthetic class','school_day'),('2026-10-25','Synthetic class','school_day')");
 foreach(range(22,25) as $n)$db->prepare("INSERT INTO attendance(student_id,enrollment_id,assignment_id,date,attendance_type,am_status,pm_status) VALUES(101,1001,2001,?,'absent','absent','absent')")->execute(['2026-10-'.$n]);
 recomputeEnrollmentInterventions($db,1001,'2026-10-21',new DateTimeImmutable('2026-10-25 18:00:00'));
 $marker=__DIR__.'/.intervention-race-'.bin2hex(random_bytes(8));$workers=[];
 try{
  foreach([0,1] as $slot){$p=proc_open([PHP_BINARY,__FILE__,'worker',$f['database'],$marker,(string)$slot],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
  $until=microtime(true)+10;while(!is_file($marker.'.ready0')||!is_file($marker.'.ready1')){if(microtime(true)>$until)throw new RuntimeException('Workers not ready');usleep(10000);}file_put_contents($marker.'.go','go');
  foreach($workers as [$p,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);checkEnrollment(proc_close($p)===0 && $errors==='','Concurrent dispatcher completes without error');}$workers=[];
  checkEnrollment(is_file($marker)&&count(file($marker))===1,'Concurrent workers make one external attempt');
  checkEnrollment($db->query('SELECT status FROM absence_notifications')->fetchColumn()==='unknown','Ambiguous timeout stays unknown');
  putenv('ATTENDANCE_INTERVENTION_ENABLED_AT=2026-10-25 00:00:00');$attempts=0;processAbsenceInterventions($db,new DateTimeImmutable('2026-10-25 19:00:00'),function()use(&$attempts){$attempts++;return 'sent';});checkEnrollment($attempts===0,'Unknown attempt is not retried');putenv('ATTENDANCE_INTERVENTION_ENABLED_AT');
 }finally{foreach($workers as [$p,$pipes]){proc_terminate($p);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($p);}foreach(['','.go','.ready0','.ready1'] as $suffix)if(is_file($marker.$suffix))unlink($marker.$suffix);}
});echo "Intervention concurrency passed (one synthetic attempt; unknown not retried).\n";
