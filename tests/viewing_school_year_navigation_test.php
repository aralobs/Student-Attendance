<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';
withEnrollmentFixture(function(array $f):void {
 $db=$f['db'];
 ensureDeliverySchema($db); ensureAttendanceDecisionSchema($db); ensureInterventionSchema($db);
 $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
 $db->exec("INSERT INTO school_years(id,label,starts_on,ends_on,state) VALUES(3,'2025-2026','2025-06-01','2026-03-31','closed'); INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year_id,school_year,is_active) VALUES(30,'Historical Class','Grade 2',7,3,'2025-2026',0); INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(1301,101,3,'completed','2025-06-01','2026-03-31'); INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2301,1301,30,'Grade 2','2025-06-01','2026-03-31')");
 $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
 $config=$copy.'/config/database.php';$source=file_get_contents($config);
 // Strict PHP session mode accepts only an existing session ID, as with a browser cookie.
 file_put_contents($copy.'/sessions/sess_viewing-year-'.$f['teacher']['id'],'');
 $source=str_replace("startSession();", "session_id('viewing-year-'.\$request['actor']['id']); startSession();",$source);
 $source=str_replace('$_SESSION=[', 'if (empty($_SESSION)) $_SESSION=[',$source);file_put_contents($config,$source);
 // CLI SCRIPT_NAME is a Windows filesystem path; emulate the HTTP route for generated JS.
 $httpScriptName= <<<'PHP'
$_SERVER['SCRIPT_NAME']='/'.str_replace('\\','/',substr($_SERVER['SCRIPT_FILENAME'],strlen(dirname(__DIR__))+1));
PHP;
 $source=str_replace("\$_SERVER['REQUEST_METHOD']=",$httpScriptName."\n\$_SERVER['REQUEST_METHOD']=",$source);
 file_put_contents($config,$source);
 try {
  $run=function(string $endpoint,array $get=[])use($copy,$f):array {
   $r=runEnrollmentAppEndpoint($copy,$f,$endpoint,$f['teacher'],[],$get,'GET');
   checkEnrollment($r['status']===200,'Renders '.$endpoint.' '.json_encode($get));
   checkEnrollment(!str_contains($r['body'],'Warning:')&&!str_contains($r['body'],'Fatal error:'),'No diagnostics '.$endpoint);
   return $r;
  };
  $r=$run('dashboard.php',['view_school_year'=>'3']);
  checkEnrollment(str_contains($r['body'],'Historical view')&&str_contains($r['body'],'Snapshot as of 2026-03-31'),'Historical dashboard labels');
  checkEnrollment(!str_contains($r['body'],'id="todayLogBody"'),'Historical dashboard excludes live scans');
  file_put_contents(dirname(__DIR__).'/.qa/viewing-year-dashboard.php.html',preg_replace('/\nRESULT:.*$/s','',$r['body']));
  foreach(['students/students.php','students/archived.php','students/master_list.php','sections/sections.php','analytics/analytics.php','reports/index_reports.php','reports/sf2.php','reports/daily.php','attendance/attendance.php','attendance/interventions.php'] as $endpoint) {
   $r=$run($endpoint);
   checkEnrollment(str_contains($r['body'],'value="3" selected'),'Remembered historical selection '.$endpoint);
   checkEnrollment(str_contains($r['body'],'Return to current year'),'Historical notice '.$endpoint);
   file_put_contents(dirname(__DIR__).'/.qa/viewing-year-'.str_replace('/','-', $endpoint).'.html',preg_replace('/\nRESULT:.*$/s','',$r['body']));
  }
  $r=$run('students/students.php',['search'=>'Synthetic']);
  checkEnrollment(str_contains($r['body'],'Historical Class'),'Students retains historical roster after search');
  $r=$run('students/students.php',['view_school_year'=>'current']);
  checkEnrollment(str_contains($r['body'],'value="1" selected')&&!str_contains($r['body'],'Historical view'),'Return current resets context');
  file_put_contents(dirname(__DIR__).'/.qa/viewing-year-current-students.html',preg_replace('/\nRESULT:.*$/s','',$r['body']));
  $r=$run('sections/sections.php');checkEnrollment(str_contains($r['body'],'value="1" selected'),'Reset persists across pages');
  $r=$run('reports/sf2.php',['section'=>30]);
  checkEnrollment(str_contains($r['body'],'March 2026'),'Section-only historical link defaults its own period');
  $run('dashboard.php',['view_school_year'=>'3']);
  $run('reports/sf2.php',['school_year_id'=>1,'month'=>10,'year'=>2026]);
  $r=$run('students/students.php');checkEnrollment(str_contains($r['body'],'value="3" selected'),'Deep link did not overwrite global choice');
  checkEnrollment((int)$db->query("SELECT id FROM school_years WHERE state='active'")->fetchColumn()===1,'Active year unchanged');
 }finally {removeEnrollmentAppFixture($copy);}
});
echo "Shared viewing-year navigation integration passed.\n";
