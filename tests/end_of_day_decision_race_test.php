<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once __DIR__.'/support/enrollment_app_fixture.php';require_once dirname(__DIR__).'/includes/attendance_decisions.php';
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);$db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
 $db->exec("UPDATE students SET parent_contact='09123456789' WHERE id=101");
 $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8));createEnrollmentAppFixture($copy);
 $config=file_get_contents($copy.'/config/database.php');$config=str_replace('new PDO(','new EodDecisionRacePDO(',$config);
 $config.= <<<'PHP'

class EodDecisionRacePDO extends PDO {
 public function beginTransaction():bool {
  $other=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.getenv('MYSQLDATABASE'),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  saveAttendanceDecision($other,['id'=>7,'role'=>'teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-21','decision'=>'present','reason'=>'Synthetic concurrent correction','expected_revision'=>0]);
  return parent::beginTransaction();
 }
}
PHP;
 file_put_contents($copy.'/config/database.php',$config);
 file_put_contents($copy.'/attendance/eod-race.php', <<<'PHP'
<?php
require_once '../includes/end_of_day_alerts.php';require_once '../includes/attendance_decisions.php';
try{submitEndOfDayReview('2026-10-21',[101=>['remarks'=>'Stale review','notify'=>true]],['id'=>7,'role'=>'teacher'],function(){throw new RuntimeException('Unsupported parent send');});echo 'saved';}
catch(InvalidArgumentException $e){echo 'denied';}
PHP);
 try{$r=runEnrollmentAppEndpoint($copy,$f,'attendance/eod-race.php',$f['teacher']);checkEnrollment(str_starts_with($r['body'],'denied'),'Stale end-of-day review must reject a concurrent explicit decision');checkEnrollment(trim((string)$db->query('SELECT remarks FROM attendance WHERE id=501')->fetchColumn())==='','Rejected review preserves scan record');}
 finally{removeEnrollmentAppFixture($copy);removeEnrollmentAppFixturePhotos($copy);}
});echo "End-of-day concurrent decision protection passed.\n";
