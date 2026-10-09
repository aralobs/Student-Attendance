<?php
// Isolated endpoint checks: no live database or notification provider calls.
$root=dirname(__DIR__);$fixture=__DIR__.'/.attendance-access-'.bin2hex(random_bytes(6));
foreach(['','/config','/includes','/attendance'] as $dir) mkdir($fixture.$dir);
$stub= <<<'PHP'
<?php
$case=$argv[1];$writes=0;$schedule=null;
http_response_code(200);
register_shutdown_function(function(){echo "\nRESULT:".json_encode(['status'=>http_response_code(),'writes'=>$GLOBALS['writes'],'schedule'=>$GLOBALS['schedule']]);});
class FakeStatement {
 function __construct(public $sql){}
 function execute($params=[]){if(preg_match('/^\s*(INSERT|UPDATE|DELETE)/i',$this->sql))$GLOBALS['writes']++;return true;}
 function fetch(){if(str_contains($this->sql,'SELECT a.*'))return ['id'=>1,'section_id'=>in_array($GLOBALS['case'],['other_section','admin_other_section'])?20:($GLOBALS['case']==='unassigned'?null:10),'schedule_type'=>'am_only'];return false;}
 function fetchAll(){return [['id'=>1],['id'=>2]];}
}
class FakeDB {function prepare($sql){return new FakeStatement($sql);}}
function getDB(){return new FakeDB;}
define('BASE_URL','/');
PHP;
file_put_contents($fixture.'/config/database.php',$stub);
file_put_contents($fixture.'/includes/functions.php', <<<'PHP'
<?php
function requireLogin(){if($GLOBALS['case']==='guest'){http_response_code(401);exit;}}
function isAdmin(){return in_array($GLOBALS['case'],['admin','admin_other_section']);}
function isTeacher(){return !in_array($GLOBALS['case'],['guest','scanner','unknown','admin','admin_other_section']);}
function canAccessSection($id){return isAdmin()||$id===10;}
function getAllowedSections(){return [['id'=>10]];}
function getSection($id){return ['schedule_type'=>'am_only'];}
function currentUser(){return ['id'=>7];}
function setFlash(...$args){}
function computeAttendanceType($record,$section){$GLOBALS['schedule']=$section['schedule_type'];return 'partial';}
function sendSMS(...$args){throw new RuntimeException('Unexpected message');}
PHP);
file_put_contents($fixture.'/request.php', <<<'PHP'
<?php
$case=$argv[1];$_SERVER['REQUEST_METHOD']='POST';
$_POST=['attendance_id'=>1,'section_id'=>in_array($case,['other_section','admin_other_section'])?20:10,'attendance_date'=>'2026-10-05','schedule_type'=>'pm_only','am_in'=>'08:00','entries'=>[1=>['am_in'=>'08:00']]];
if($case==='mixed_batch')$_POST['entries'][99]=['am_in'=>'08:00'];
if($case==='foreign_student')$_POST['entries']=[99=>['am_in'=>'08:00']];
if($case==='invalid_id')$_POST['entries']=['1x'=>['am_in'=>'08:00']];
if($case==='invalid_data')$_POST['entries']=[1=>'invalid'];
if($case==='invalid_batch')$_POST['entries']='invalid';
PHP);
$cases=['override'=>['guest'=>401,'scanner'=>403,'unknown'=>403,'other_section'=>403,'unassigned'=>403,'teacher'=>302,'admin'=>302,'admin_other_section'=>302],
 'manual'=>['guest'=>401,'scanner'=>403,'unknown'=>403,'other_section'=>403,'foreign_student'=>403,'mixed_batch'=>403,'invalid_id'=>400,'invalid_data'=>400,'invalid_batch'=>400,'teacher'=>302,'admin'=>302,'admin_other_section'=>302]];
try {
 foreach($cases as $endpoint=>$checks){
  copy($root.'/attendance/'.$endpoint.'.php',$fixture.'/attendance/'.$endpoint.'.php');
  foreach($checks as $case=>$expected){
   $process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$fixture.'/request.php',$fixture.'/attendance/'.$endpoint.'.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture.'/attendance');
   $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
   if($exit!==0||$errors!==''||!preg_match('/RESULT:(.*)$/',$output,$match))throw new RuntimeException("$endpoint/$case failed: $errors $output");
   $result=json_decode($match[1],true);
   if($result['status']!==$expected||$result['writes']!==($expected===302?1:0)||($endpoint==='override'&&$expected===302&&$result['schedule']!=='am_only'))throw new RuntimeException("$endpoint/$case unexpected result: ".$match[1]);
  }
 }
 echo "Attendance access checks passed (20 cases).\n";
} finally {
 foreach(['config/database.php','includes/functions.php','request.php','attendance/manual.php','attendance/override.php'] as $file)if(is_file($fixture.'/'.$file))unlink($fixture.'/'.$file);
 foreach(['/attendance','/includes','/config',''] as $dir)rmdir($fixture.$dir);
}
