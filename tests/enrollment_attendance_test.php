<?php
require_once __DIR__ . '/support/enrollment_fixture.php';
require_once dirname(__DIR__) . '/includes/attendance_scan.php';
require_once dirname(__DIR__) . '/includes/attendance_finalizer.php';

withEnrollmentFixture(function (array $f): void {
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $section=$db->query('SELECT * FROM sections WHERE id=10')->fetch();
    $scan=saveAttendanceScan($db,101,'2026-10-22',$section,7,new DateTimeImmutable('2026-10-22 07:00:00'));
    checkEnrollment($scan['success'], 'Eligible annual scan succeeds');
    $row=$db->query("SELECT * FROM attendance WHERE student_id=101 AND date='2026-10-22'")->fetch();
    checkEnrollment((int)$row['enrollment_id']===1001 && (int)$row['assignment_id']===2001,'Scan records annual ownership atomically');
    $before=$row['am_in'];
    checkEnrollment(getEnrollmentContext($db,101,'2026-10-19','write')['section_id']===10,'Forged-section test uses an otherwise eligible past assignment');
    try {
        saveAttendanceScan($db,101,'2026-10-19',array_merge($section,['id'=>11]),7,new DateTimeImmutable('2026-10-19 07:00:00'));
        throw new RuntimeException('Forged supplied section was accepted');
    } catch (DomainException $error) {
        checkEnrollment($error->getMessage()==='No eligible enrollment in the selected section.','Forged section reaches the section ownership guard');
    }
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM attendance WHERE student_id=101 AND date='2026-10-19'")->fetchColumn()===0,'Forged section writes nothing');
    $duplicate=saveAttendanceScan($db,101,'2026-10-22',$section,7,new DateTimeImmutable('2026-10-22 07:00:01'));
    checkEnrollment(!$duplicate['success'],'Duplicate scan remains rejected');
    checkEnrollment($db->query("SELECT am_in FROM attendance WHERE student_id=101 AND date='2026-10-22'")->fetchColumn()===$before,'Duplicate preserves scan');
    $db->exec("UPDATE students SET section_id=20,grade_level='Grade 4',school_year='2027-2028' WHERE id=101");
    $scan=saveAttendanceScan($db,101,'2026-10-20',$section,7,new DateTimeImmutable('2026-10-20 07:00:00'));
    checkEnrollment($scan['success'] && (int)$scan['record']['assignment_id']===2001,'Forged future profile cache cannot change dated ownership');
    $db->exec("UPDATE student_enrollments SET starts_on='2026-07-01',ends_on='2027-02-28' WHERE id=1001");
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2026-06-30',$section,7,new DateTimeImmutable('2026-06-30 07:00:00')));
    $db->exec("UPDATE student_enrollments SET ends_on='2026-10-20' WHERE id=1001");
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2026-10-21',$section,7,new DateTimeImmutable('2026-10-21 07:00:00')));
    $db->exec("UPDATE student_enrollments SET ends_on='2027-02-28' WHERE id=1001");
    $db->exec("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(3001,101,2,'prepared','2027-06-01','2028-03-31'); INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(4001,3001,20,'Grade 4','2027-06-01','2028-03-31')");
    $next=$db->query('SELECT * FROM sections WHERE id=20')->fetch();
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2027-06-01',$next,7,new DateTimeImmutable('2027-06-01 07:00:00')));
    $db->exec('UPDATE students SET is_active=0 WHERE id=101');
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2026-10-19',$section,7,new DateTimeImmutable('2026-10-19 07:00:00')));
    $db->exec("UPDATE students SET is_active=1 WHERE id=101; UPDATE school_years SET state='closed' WHERE id=1");
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2026-10-19',$section,7,new DateTimeImmutable('2026-10-19 07:00:00')));
});

withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];
    // Keep current-operation/archive fixtures eligible as the real clock moves.
    // Historical dates stay fixed; the planned year remains strictly disjoint.
    $operationalEnd=max('2027-03-31',(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->modify('+31 days')->format('Y-m-d'));
    $plannedStart=max('2027-06-01',(new DateTimeImmutable($operationalEnd))->modify('+1 day')->format('Y-m-d'));
    $plannedEnd=(new DateTimeImmutable($plannedStart))->modify('+1 year -1 day')->format('Y-m-d');
    $db->prepare('UPDATE school_years SET ends_on=? WHERE id=1')->execute([$operationalEnd]);
    $db->prepare('UPDATE school_years SET starts_on=?,ends_on=? WHERE id=2')->execute([$plannedStart,$plannedEnd]);
    $db->prepare('UPDATE student_enrollments SET ends_on=? WHERE school_year_id=1')->execute([$operationalEnd]);
    $db->prepare('UPDATE enrollment_sections SET ends_on=? WHERE enrollment_id IN (1001,1006)')->execute([$operationalEnd]);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1');
        INSERT INTO users(id,username,password,full_name,role) VALUES(9,'synthetic_scanner','unused','Synthetic Scanner','scanner_operator');
        UPDATE students SET created_at='2026-01-01',section_id=20,enrollment_status='promoted';
        UPDATE sections SET created_at='2026-01-01';
        UPDATE enrollment_sections SET ends_on='2026-10-15' WHERE id=2001");
    $db->prepare("INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2002,1001,11,'Grade 3','2026-10-16',?)")->execute([$operationalEnd]);
    $old=$db->query('SELECT * FROM sections WHERE id=10')->fetch();
    $current=$db->query('SELECT * FROM sections WHERE id=11')->fetch();
    $scan=saveAttendanceScan($db,101,'2026-10-14',$old,7,new DateTimeImmutable('2026-10-14 07:00:00'));
    checkEnrollment($scan['success'] && (int)$scan['record']['assignment_id']===2001,'Backdated scan uses its own dated assignment');
    $scan=saveAttendanceScan($db,101,'2026-10-22',$current,9,new DateTimeImmutable('2026-10-22 07:00:00'));
    checkEnrollment($scan['success'] && (int)$scan['record']['assignment_id']===2002,'Scanner can scan eligible annual student without staff/report rights');
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2026-10-20',$current,7,new DateTimeImmutable('2026-10-20 07:00:00')));
    $before=$db->query("SELECT am_in FROM attendance WHERE student_id=101 AND date='2026-10-22'")->fetchColumn();
    $db->beginTransaction();
    expectEnrollmentError(DomainException::class,fn()=>lockAttendanceEnrollment($db,101,'2026-10-22',$f['teacher'],11));
    $db->rollBack();
    checkEnrollment($db->query("SELECT am_in FROM attendance WHERE student_id=101 AND date='2026-10-22'")->fetchColumn()===$before,'Denied staff write preserves scan');
    $audit=$db->prepare("SELECT h.event_id,h.change_ciphertext FROM security_change_history h JOIN security_events e ON e.event_id=h.event_id WHERE e.action='attendance.scan' AND e.target_id=?");
    $audit->execute([$scan['record']['id']]); $event=$audit->fetch();
    $snapshot=json_decode(decryptPII($event['change_ciphertext'],'security-change:'.$event['event_id']),true);
    checkEnrollment((int)$snapshot['after']['enrollment_id']===1001 && (int)$snapshot['after']['assignment_id']===2002,'Encrypted audit retains annual ownership');
    // Current cache points to next year but finalization follows each learning date.
    $now=new DateTimeImmutable('2026-10-22 23:00:00');
    $db->exec("UPDATE students SET created_at=NULL WHERE id=101; UPDATE sections SET created_at='2026-10-22 22:00:00' WHERE id=10");
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-13',10,$now)===1,'Former assignment receives its own eligible dated absence');
    $row=$db->query("SELECT enrollment_id,assignment_id,attendance_type FROM attendance WHERE student_id=101 AND date='2026-10-13'")->fetch();
    checkEnrollment((int)$row['assignment_id']===2001 && $row['attendance_type']==='absent','Finalized absence has atomic dated ownership');
    checkEnrollment($row['attendance_type']==='absent','Verified annual eligibility survives null profile and later section creation timestamps');
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-20',10,$now)===0,'Former section does not receive reassigned student absence');
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-20',11,$now)===1,'New section receives eligible date absence');
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-20',20,$now)===0,'Prepared next-year section receives no absence');
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-23',null,$now)===0,'Future finalization writes nothing');
    $db->exec("UPDATE student_enrollments SET starts_on='2026-10-13',ends_on='2026-10-20' WHERE id=1001");
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-12',10,$now)===0,'Before entry is ineligible for absence');
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-22',11,$now)===0,'After exit never reconciles or finalizes');
    $db->prepare("UPDATE student_enrollments SET starts_on='2026-06-01',ends_on=? WHERE id=1001")->execute([$operationalEnd]);
    $db->exec("INSERT INTO attendance(student_id,date) VALUES(101,'2026-10-19')");
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-19',11,$now)===0,'Unmapped attendance is excluded from ordinary finalization');
    expectEnrollmentError(DomainException::class,fn()=>saveAttendanceScan($db,101,'2026-10-19',$current,9,new DateTimeImmutable('2026-10-19 07:00:00')));
    checkEnrollment(enrollmentOperationalStudent($db,101,'2026-10-22',$f['otherTeacher'])['section_id']==11,'Current card uses annual section despite future profile cache');
    checkEnrollment(enrollmentOperationalStudent($db,101,'2026-10-22',$f['teacher'])===null,'Former section teacher cannot issue current card');
    checkEnrollment(enrollmentHistoricalPhoto($db,101,1001,['id'=>9,'role'=>'scanner_operator'])===null,'Scanner denied historical photos');
    checkEnrollment(enrollmentHistoricalPhoto($db,106,1001,$f['admin'])===null,'Historical photo requires matching explicit annual student');
    checkEnrollment(enrollmentHistoricalPhoto($db,101,1001,$f['teacher'])!==null,'Historical assigned teacher may read selected enrollment photo');
    $db->exec('UPDATE sections SET adviser_id=8 WHERE id=10');
    checkEnrollment(enrollmentHistoricalPhoto($db,101,1001,$f['teacher'])===null,'Revoked adviser denied historical photo');
    $db->prepare("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(3001,101,2,'prepared',?,?)")->execute([$plannedStart,$plannedEnd]);
    $db->prepare("INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(4001,3001,20,'Grade 4',?,?)")->execute([$plannedStart,$plannedEnd]);
    $states=$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll();
    $assignments=$db->query('SELECT * FROM enrollment_sections ORDER BY id')->fetchAll();
    checkEnrollment(setEnrollmentEntityActive($db,$f['admin'],'student',101,false),'Archive succeeds');
    checkEnrollment(enrollmentOperationalStudent($db,101,'2026-10-22',$f['admin'])===null,'Archive revokes operational QR/card scope');
    checkEnrollment(enrollmentHistoricalPhoto($db,101,1001,$f['otherTeacher'])!==null,'Archived annual profile retains report photo membership');
    checkEnrollment(setEnrollmentEntityActive($db,$f['admin'],'student',101,true),'Restore succeeds');
    checkEnrollment($states===$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll() && $assignments===$db->query('SELECT * FROM enrollment_sections ORDER BY id')->fetchAll(),'Archive/restore preserve every annual state/date/assignment');
    expectEnrollmentError(DomainException::class,fn()=>setEnrollmentEntityActive($db,$f['admin'],'section',11,false));
    $db->exec("UPDATE school_years SET state='closed' WHERE id=1; UPDATE student_enrollments SET state='completed' WHERE school_year_id=1");
    checkEnrollment(finalizeEnrollmentAttendanceDate($db,'2026-10-15',10,$now)===0,'Closed-year finalizer writes nothing');
    setEnrollmentEntityActive($db,$f['admin'],'student',101,false);
    setEnrollmentEntityActive($db,$f['admin'],'student',101,true);
    checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id=1001')->fetchColumn()==='completed','Restore cannot reopen completed annual enrollment');
    checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id=3001')->fetchColumn()==='prepared','Restore cannot activate prepared enrollment');
    checkEnrollment(enrollmentOperationalStudent($db,101,'2026-10-22',$f['admin'])===null,'Restored completed student remains operationally ineligible');
});
/** Real adapters and SQL, with authentication/session and notification boundaries
 * substituted only inside this disposable application directory. */
function enrollmentAttendanceAdapterChecks(array $f): void
{
    $root=dirname(__DIR__); $path=__DIR__.'/.enrollment-attendance-'.bin2hex(random_bytes(6));
    foreach (['','/config','/includes','/attendance','/students','/sections'] as $dir) mkdir($path.$dir);
    $files=[];
    $write=function($file,$text) use($path,&$files) { file_put_contents($path.'/'.$file,$text); $files[]=$file; };
    foreach (['includes/enrollment_operations.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/school_years.php',
        'includes/security_audit.php','includes/pii_security.php','includes/csrf.php','includes/student_access.php','includes/qr_access.php',
        'includes/attendance_scan.php','includes/attendance_finalizer.php','includes/end_of_day_alerts.php','includes/notification_templates.php',
        'config/schedule_rules.php','attendance/manual.php','attendance/override.php','attendance/get_today_log.php',
        'students/archive.php','students/delete.php','students/restore.php','sections/archive.php','sections/delete.php','sections/restore.php',
        'students/qr_download.php'] as $file) $write($file,file_get_contents($root.'/'.$file));
    $write('config/database.php', <<<'PHP'
<?php
date_default_timezone_set('Asia/Manila');
ini_set('error_log',dirname(__DIR__).'/errors.log');
$request=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$request['database'])) throw new RuntimeException('Unsafe fixture database');
define('BASE_URL','/'); define('BASE_PATH',dirname(__DIR__).'/');
$_SERVER['REQUEST_METHOD']=$request['method']??'POST'; $_POST=$request['post']??[]; $_GET=$request['get']??[];
$_SESSION=['app_csrf'=>str_repeat('a',64)]; http_response_code(200);
function getDB(): PDO {
    global $request;
    if (!isset($GLOBALS['fixture_db'])) {
        $GLOBALS['fixture_db']=new PDO('mysql:host='.(getenv('MYSQLHOST')?:'127.0.0.1').';port='.(getenv('MYSQLPORT')?:'3307').';dbname='.$request['database'].';charset=utf8mb4',getenv('MYSQLUSER')!==false?getenv('MYSQLUSER'):'root',getenv('MYSQLPASSWORD')!==false?getenv('MYSQLPASSWORD'):'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $GLOBALS['fixture_db']->exec("SET time_zone='+08:00'");
    }
    return $GLOBALS['fixture_db'];
}
register_shutdown_function(function(){ echo "\nRESULT:".json_encode(['status'=>http_response_code(),'flash'=>$GLOBALS['fixture_flash']??null,'sends'=>$GLOBALS['fixture_sends']??0]); });
PHP);
    $functions= <<<'PHP'
<?php
require_once __DIR__.'/../config/database.php'; require_once __DIR__.'/csrf.php'; require_once __DIR__.'/../config/schedule_rules.php';
function startSession() {}
function currentUser() { return $GLOBALS['request']['actor']; }
function isAdmin() { return (currentUser()['role']??'')==='admin'; }
function isTeacher() { return (currentUser()['role']??'')==='teacher'; }
function requireLogin() { if (!(currentUser()['id']??0)) { http_response_code(401); exit; } }
function requireAdmin() { requireLogin(); if (!isAdmin()) { http_response_code(403); exit; } }
function sendNoCacheHeaders() {}
function setFlash($kind,$message) { $GLOBALS['fixture_flash']=[$kind,$message]; }
function sanitize($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function getAllowedSections() { require_once __DIR__.'/student_access.php'; [$scope,$params]=studentSectionScope('sec'); $q=getDB()->prepare("SELECT sec.* FROM sections sec WHERE sec.is_active=1 AND ($scope)"); $q->execute($params); return $q->fetchAll(); }
function canAccessSection($id) { foreach(getAllowedSections() as $section) if ((int)$section['id']===$id) return true; return false; }
function getSection($id) { $q=getDB()->prepare('SELECT * FROM sections WHERE id=?'); $q->execute([$id]); return $q->fetch(); }
function getSetting($key) { $q=getDB()->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?'); $q->execute([$key]); return $q->fetchColumn()?:null; }
function getCalendarEntry($date) { $q=getDB()->prepare('SELECT * FROM school_calendar WHERE date=?'); $q->execute([$date]); return $q->fetch(); }
function isHolidayOrNoClass($date) { return in_array(getCalendarEntry($date)['type']??'', ['holiday','no_class'],true); }
function buildSMSMessage($template,$student) { return 'Synthetic message'; }
function sendSMS(...$args) { if (getDB()->inTransaction()) throw new RuntimeException('Provider inside transaction'); $GLOBALS['fixture_sends']=($GLOBALS['fixture_sends']??0)+1; return true; }
PHP;
    foreach (['getAttendanceScheduleType','getAttendanceEvents','getNextAttendanceEvent','getSessionStatus','computeAttendanceType'] as $function) {
        $reflection=new ReflectionFunction($function); $lines=file($reflection->getFileName());
        $functions.="\n".implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
    }
    $write('includes/functions.php',$functions);
    $files[]='errors.log';
    $write('includes/qr_card.php','<?php function generateStudentQRCardSVG($student,$school){return "QR:".$student["section_id"].":".$student["qr_token"];}');
    $write('attendance/eod_fixture.php', <<<'PHP'
<?php
require_once __DIR__.'/../includes/end_of_day_alerts.php';
$result=submitEndOfDayReview($request['date']??'2026-10-20',$_POST['entries']??[],currentUser(),function(){ if(getDB()->inTransaction()) throw new RuntimeException('Provider inside transaction'); $GLOBALS['fixture_sends']=($GLOBALS['fixture_sends']??0)+1; return true; });
echo json_encode($result);
PHP);
    $write('attendance/scan_fixture.php', <<<'PHP'
<?php
require_once __DIR__.'/../includes/attendance_scan.php';
$db=getDB(); $section=$db->query('SELECT * FROM sections WHERE id=10')->fetch();
if (!empty($request['ready'])) file_put_contents($request['ready'],'ready');
if (!empty($request['gate'])) { $deadline=microtime(true)+10; while(!is_file($request['gate'])) { if(microtime(true)>$deadline) throw new RuntimeException('Gate timeout'); usleep(10000); } }
try { $result=saveAttendanceScan($db,101,'2026-10-22',$section,7,new DateTimeImmutable('2026-10-22 07:00:00')); echo $result['success']?'accepted':'duplicate'; }
catch(DomainException $error) { echo 'denied'; }
PHP);
    $start=function($endpoint,$actor,$post=[],$get=[],$method='POST',$extra=[]) use($path,$f) {
        $post['csrf_token']=str_repeat('a',64);
        $payload=base64_encode(json_encode(array_merge(['database'=>$f['database'],'actor'=>$actor,'post'=>$post,'get'=>$get,'method'=>$method],$extra),JSON_THROW_ON_ERROR));
        $p=proc_open([PHP_BINARY,$path.'/'.$endpoint,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($path.'/'.$endpoint));
        return [$p,$pipes];
    };
    $finish=function($process): array {
        [$p,$pipes]=$process; $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        checkEnrollment($exit===0 && $errors==='' && preg_match('/RESULT:(.*)$/',$output,$match)===1,'Managed attendance fixture failed: '.$errors);
        $result=json_decode($match[1],true); $result['body']=$output; return $result;
    };
    $run=fn(...$args)=>$finish($start(...$args));
    $processes=[];
    try {
        $db=$f['db'];
        $futureDate=(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d');
        $operationalEnd=max('2027-03-31',(new DateTimeImmutable($futureDate))->modify('+30 days')->format('Y-m-d'));
        $plannedStart=max('2027-06-01',(new DateTimeImmutable($operationalEnd))->modify('+1 day')->format('Y-m-d'));
        $plannedEnd=(new DateTimeImmutable($plannedStart))->modify('+1 year -1 day')->format('Y-m-d');
        $db->prepare('UPDATE school_years SET ends_on=? WHERE id=1')->execute([$operationalEnd]);
        $db->prepare('UPDATE school_years SET starts_on=?,ends_on=? WHERE id=2')->execute([$plannedStart,$plannedEnd]);
        $db->prepare('UPDATE student_enrollments SET ends_on=? WHERE school_year_id=1')->execute([$operationalEnd]);
        $db->prepare('UPDATE enrollment_sections SET ends_on=? WHERE enrollment_id IN (1001,1006)')->execute([$operationalEnd]);
        $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE students SET section_id=20,created_at='2026-01-01'; UPDATE sections SET created_at='2026-01-01'");
        $post=['section_id'=>10,'attendance_date'=>'2026-10-20','entries'=>[101=>['am_in'=>'07:00','pm_out'=>'']]];
        $result=$run('attendance/manual.php',$f['teacher'],$post);
        checkEnrollment($result['status']===302,'Managed manual writes through annual section despite future profile cache');
        $record=$db->query("SELECT * FROM attendance WHERE student_id=101 AND date='2026-10-20'")->fetch();
        checkEnrollment((int)$record['enrollment_id']===1001 && (int)$record['assignment_id']===2001,'Manual creates ownership in mutation transaction');
        $post['entries'][106]=['am_in'=>'07:00'];
        checkEnrollment($run('attendance/manual.php',$f['teacher'],$post)['status']===403,'Mixed annual manual roster batch denied');
        unset($post['entries'][106]);
        checkEnrollment($run('attendance/manual.php',$f['otherTeacher'],$post)['status']===403,'Foreign adviser manual POST denied');
        $override=['attendance_id'=>$record['id'],'am_in'=>'07:10','redirect_date'=>'2026-10-20'];
        checkEnrollment($run('attendance/override.php',$f['teacher'],$override)['status']===302,'Managed override follows record date annual assignment');
        checkEnrollment($db->query('SELECT am_in FROM attendance WHERE id='.(int)$record['id'])->fetchColumn()==='07:10:00','Managed override saves eligible edit');
        $db->exec("UPDATE enrollment_sections SET ends_on='2026-10-20' WHERE id=2001");
        $db->prepare("INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2002,1001,11,'Grade 3','2026-10-21',?)")->execute([$operationalEnd]);
        checkEnrollment($run('attendance/override.php',$f['teacher'],$override)['status']===302,'Backdated override retains former assignment scope');
        checkEnrollment($run('attendance/override.php',$f['otherTeacher'],$override)['status']===503,'Current new-section adviser cannot edit former dated assignment');
        $qr=$run('students/qr_download.php',$f['otherTeacher'],[],['id'=>101],'GET');
        checkEnrollment($qr['status']===200 && str_contains($qr['body'],'QR:11:synthetic-101'),'QR card uses annual section and stable identity');
        checkEnrollment($run('students/qr_download.php',$f['teacher'],[],['id'=>101],'GET')['status']===403,'Former teacher cannot issue current QR');
        $db->exec("UPDATE students SET parent_contact='synthetic-contact' WHERE id=101");
        $db->exec("UPDATE students SET created_at=NULL WHERE id=101; UPDATE sections SET created_at='2026-10-22 22:00:00' WHERE id=10");
        $eod=$run('attendance/eod_fixture.php',$f['teacher'],['entries'=>[101=>['notify'=>1]]]);
        checkEnrollment($eod['sends']===1,'Managed dated end-of-day eligibility ignores null/later creation timestamps and notifies once after commit');
        checkEnrollment($run('attendance/eod_fixture.php',$f['teacher'],['entries'=>[101=>['notify'=>1]]])['sends']===0,'Reviewed record does not send another notification');
        // Archive and restore adapters retain annual records and revoke operations.
        $annual=$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll();
        foreach (['archive.php','delete.php'] as $archive) {
            checkEnrollment($run('students/'.$archive,$f['admin'],['id'=>101])['flash'][0]==='success','Managed soft archive endpoint succeeds');
            checkEnrollment($run('students/qr_download.php',$f['otherTeacher'],[],['id'=>101],'GET')['status']===404,'Archived profile cannot issue QR');
            checkEnrollment($run('students/restore.php',$f['admin'],['id'=>101])['flash'][0]==='success','Managed student restore endpoint succeeds');
        }
        checkEnrollment($annual===$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll(),'Student archive adapters preserve annual history');
        checkEnrollment($run('sections/archive.php',$f['admin'],['id'=>11])['flash'][0]==='danger','Section archive reads active annual assignments rather than profile cache');
        checkEnrollment($run('sections/delete.php',$f['admin'],['id'=>11])['flash'][0]==='danger','Section delete reads active annual assignments rather than profile cache');
        checkEnrollment($run('sections/archive.php',$f['admin'],['id'=>21])['flash'][0]==='success','Unused section archive endpoint succeeds');
        checkEnrollment($run('sections/restore.php',$f['admin'],['id'=>21])['flash'][0]==='success','Section restore endpoint succeeds');
        $db->exec("UPDATE school_years SET state='closed' WHERE id=1; UPDATE student_enrollments SET state='completed' WHERE school_year_id=1");
        checkEnrollment($run('attendance/override.php',$f['admin'],$override)['status']===503,'Closed annual override rejected');
        $run('students/archive.php',$f['admin'],['id'=>101]); $run('students/restore.php',$f['admin'],['id'=>101]);
        checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id=1001')->fetchColumn()==='completed','Restore adapter cannot reopen completed enrollment');
        // Rebuild this disposable synthetic context for real competing scan tests.
        $db->exec("UPDATE school_years SET state='active' WHERE id=1; UPDATE student_enrollments SET state='active' WHERE school_year_id=1; DELETE FROM enrollment_sections WHERE id=2002");
        $db->prepare('UPDATE enrollment_sections SET ends_on=? WHERE id=2001')->execute([$operationalEnd]);
        $waitReady=function(array $ready) {
            $deadline=microtime(true)+10;
            do { if (count(array_filter($ready,'is_file'))===count($ready)) return; usleep(10000); } while(microtime(true)<$deadline);
            throw new RuntimeException('Managed scan workers not ready');
        };
        $gate=$path.'/gate'; $files[]='gate';
        foreach (['archive','reassign'] as $revoke) {
            $ready=$path.'/ready-'.$revoke; $files[]='ready-'.$revoke;
            $db->beginTransaction();
            $db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchAll();
            $db->query('SELECT id FROM school_years ORDER BY id FOR UPDATE')->fetchAll();
            $db->query('SELECT id FROM sections ORDER BY id FOR UPDATE')->fetchAll();
            $db->query('SELECT id FROM students WHERE id=101 FOR UPDATE')->fetchAll();
            $db->query('SELECT id FROM student_enrollments WHERE id=1001 FOR UPDATE')->fetchAll();
            $db->query('SELECT id FROM enrollment_sections WHERE enrollment_id=1001 ORDER BY id FOR UPDATE')->fetchAll();
            $processes[]=$start('attendance/scan_fixture.php',$f['teacher'],[],[],'POST',['ready'=>$ready]);
            $waitReady([$ready]); usleep(200000);
            $db->exec($revoke==='archive'?'UPDATE students SET is_active=0 WHERE id=101':'UPDATE enrollment_sections SET section_id=11 WHERE id=2001');
            $db->commit();
            $result=$finish(array_shift($processes));
            checkEnrollment(str_starts_with($result['body'],'denied'),'In-flight '.$revoke.' revokes waiting scan');
            checkEnrollment((int)$db->query("SELECT COUNT(*) FROM attendance WHERE student_id=101 AND date='2026-10-22'")->fetchColumn()===0,'Revoked in-flight write creates no attendance');
            $db->exec('UPDATE students SET is_active=1 WHERE id=101; UPDATE enrollment_sections SET section_id=10 WHERE id=2001');
        }
        $ready=[];
        for($i=0;$i<2;$i++) { $ready[]=$path.'/ready-race-'.$i; $files[]='ready-race-'.$i; $processes[]=$start('attendance/scan_fixture.php',$f['teacher'],[],[],'POST',['ready'=>$ready[$i],'gate'=>$gate]); }
        $waitReady($ready); file_put_contents($gate,'go'); $results=[];
        while($processes) $results[]=$finish(array_shift($processes));
        checkEnrollment(count(array_filter($results,fn($r)=>str_starts_with($r['body'],'accepted')))===1,'Managed duplicate scans have exactly one winner');
        checkEnrollment(count(array_filter($results,fn($r)=>str_starts_with($r['body'],'duplicate')))===1,'Managed duplicate scan loser observes cooldown');
        checkEnrollment((int)$db->query("SELECT COUNT(*) FROM attendance WHERE student_id=101 AND date='2026-10-22'")->fetchColumn()===1,'Managed race creates one owned attendance row');
        // Real tomorrow, otherwise eligible annual ownership: these assertions
        // must reach the future guard even after the original fixture term ends.
        $futureContext=getEnrollmentContext($db,101,$futureDate,'write');
        checkEnrollment($futureContext!==null && $futureContext['assignment_id']===2001 && $futureContext['section_id']===10,'Dynamic future date has otherwise eligible active annual ownership');
        $section=$db->query('SELECT * FROM sections WHERE id=10')->fetch();
        try {
            saveAttendanceScan($db,101,$futureDate,$section,7,new DateTimeImmutable($futureDate.' 07:00:00'));
            throw new RuntimeException('Future scan was accepted');
        } catch (DomainException $error) {
            checkEnrollment($error->getMessage()==='Future attendance cannot be recorded.','Dynamic future scan reaches the explicit future-date guard');
        }
        $futureCount=$db->prepare('SELECT COUNT(*) FROM attendance WHERE student_id=101 AND date=?');
        $futureCount->execute([$futureDate]);
        checkEnrollment((int)$futureCount->fetchColumn()===0,'Future scan rejection leaves no attendance');
        $futurePost=['section_id'=>10,'attendance_date'=>$futureDate,'entries'=>[101=>['am_in'=>'07:00','pm_out'=>'']]];
        checkEnrollment($run('attendance/manual.php',$f['teacher'],$futurePost)['status']===503,'Dynamic future manual POST rejected atomically');
        $futureCount->execute([$futureDate]);
        checkEnrollment((int)$futureCount->fetchColumn()===0,'Future manual rejection leaves no attendance');
    } finally {
        if ($f['db']->inTransaction()) $f['db']->rollBack();
        foreach($processes as [$process,$pipes]) { proc_terminate($process); foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process); }
        foreach($files as $file) if(is_file($path.'/'.$file)) unlink($path.'/'.$file);
        foreach(['/attendance','/students','/sections','/includes','/config',''] as $dir) rmdir($path.$dir);
    }
}
withEnrollmentFixture('enrollmentAttendanceAdapterChecks');
echo 'Enrollment attendance checks passed (' . ($GLOBALS['enrollment_checks']??0) . " checks).\n";
