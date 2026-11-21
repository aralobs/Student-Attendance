<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once dirname(__DIR__).'/includes/absence_interventions.php';
class ClaimRacePDO extends PDO {
 public ?Closure $afterFirstCommit=null;
 public function commit():bool{$ok=parent::commit();if($this->afterFirstCommit){$callback=$this->afterFirstCommit;$this->afterFirstCommit=null;$callback();}return $ok;}
}
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);ensureInterventionSchema($db);
 $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE student_enrollments SET starts_on='2026-10-21' WHERE id=1001; UPDATE enrollment_sections SET starts_on='2026-10-21' WHERE id=2001; UPDATE students SET parent_contact='09123456789' WHERE id=101; UPDATE attendance SET am_in=NULL,am_status='absent',pm_status='absent',attendance_type='absent' WHERE id=501; INSERT INTO school_calendar(date,title,type) VALUES('2026-10-24','Synthetic class','school_day'),('2026-10-25','Synthetic class','school_day')");
 foreach(range(22,25) as $n)$db->prepare("INSERT INTO attendance(student_id,enrollment_id,assignment_id,date,attendance_type,am_status,pm_status) VALUES(101,1001,2001,?,'absent','absent','absent')")->execute(['2026-10-'.$n]);
 $now=new DateTimeImmutable('2026-10-25 18:00:00',new DateTimeZone('Asia/Manila'));recomputeEnrollmentInterventions($db,1001,'2026-10-21',$now);
 $race=new ClaimRacePDO('mysql:host=127.0.0.1;port=3307;dbname='.$f['database'],'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $race->afterFirstCommit=function()use($db,$f):void{
  // Simulate a writer precisely after evaluation commits, only if no claim exists yet.
  if(!(int)$db->query("SELECT COUNT(*) FROM absence_notifications WHERE status='sending'")->fetchColumn())saveAttendanceDecision($db,$f['teacher'],['student_id'=>101,'section_id'=>10,'date'=>'2026-10-25','decision'=>'present','reason'=>'Synthetic concurrent correction','expected_revision'=>0]);
 };
 $unsupported=0;putenv('ATTENDANCE_INTERVENTION_ENABLED_AT=2026-10-25 00:00:00');
 processAbsenceInterventions($race,$now,function()use($db,&$unsupported):string{if($db->query("SELECT decision FROM attendance_decisions WHERE learning_date='2026-10-25'")->fetchColumn()==='present')$unsupported++;return 'sent';});
 checkEnrollment($unsupported===0,'An intervening committed Present decision must not produce unsupported SMS');putenv('ATTENDANCE_INTERVENTION_ENABLED_AT');
});echo "Intervention evaluation/claim race passed.\n";
