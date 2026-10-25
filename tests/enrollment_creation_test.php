<?php
require_once __DIR__ . '/support/enrollment_fixture.php';
if (is_file(__DIR__ . '/../includes/enrollment_commands.php')) require_once __DIR__ . '/../includes/enrollment_commands.php';
withEnrollmentFixture(function ($f) {
    checkEnrollment(function_exists('createAnnualEnrollment'), 'Annual enrollment creation command is missing');
    $db = $f['db'];
    expectEnrollmentError(DomainException::class, fn()=>createAnnualEnrollment($db,$f['admin'],101,2,20,'2027-06-01','2028-03-31'));
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $future = createAnnualEnrollment($db,$f['admin'],101,2,20,'2027-06-01','2028-03-31');
    checkEnrollment($future > 0, 'Future enrollment created');
    checkEnrollment((int)$db->query('SELECT section_id FROM students WHERE id=101')->fetchColumn() === 10, 'Future enrollment leaves current projection untouched');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn() === 2, 'Returning student reuses profile');
    checkEnrollment($db->query("SELECT state FROM student_enrollments WHERE id=$future")->fetchColumn() === 'prepared', 'Future enrollment prepared');
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['teacher'],101,2,20,'2027-06-01','2028-03-31'));
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['teacher'],106,2,21,'2027-06-01','2028-03-31'));
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,['id'=>7,'role'=>'scanner_operator'],106,2,20,'2027-06-01','2028-03-31'));
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['admin'],106,1,20,'2026-06-01','2027-03-31'));
    expectEnrollmentError(InvalidArgumentException::class,fn()=>createAnnualEnrollment($db,$f['admin'],106,2,20,'2027-02-30','2028-03-31'));
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['admin'],106,2,20,'2027-05-31','2028-03-31'));
    $profile = ['lrn'=>'SYNTHETICNEW','first_name'=>'Synthetic','last_name'=>'New','gender'=>'Female','qr_token'=>'synthetic-new','photo'=>'default.png'];
    expectEnrollmentError(DomainException::class,fn()=>createStudentWithEnrollment($db,$f['teacher'],$profile,2,21,'2027-06-01','2028-03-31'));
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM students WHERE lrn='SYNTHETICNEW'")->fetchColumn() === 0, 'Failed initial enrollment leaves no profile');
    $db->exec("CREATE TRIGGER enrollment_test_fail_audit BEFORE INSERT ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    expectEnrollmentError(PDOException::class,fn()=>createStudentWithEnrollment($db,$f['admin'],$profile,1,10,'2026-10-22','2027-03-31'));
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM students WHERE lrn='SYNTHETICNEW'")->fetchColumn() === 0, 'Audit failure rolls profile and enrollment back together');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn() === 3, 'Audit failure leaves no enrollment');
    $db->exec('DROP TRIGGER enrollment_test_fail_audit');
    $newId = createStudentWithEnrollment($db,$f['teacher'],$profile,1,10,'2026-10-22','2027-03-31');
    checkEnrollment((int)$db->query("SELECT section_id FROM students WHERE id=$newId")->fetchColumn() === 10, 'Initial current enrollment projects placement');
    checkEnrollment($db->query("SELECT starts_on FROM student_enrollments WHERE student_id=$newId")->fetchColumn() === '2026-10-22', 'Actual late entry preserved');
    expectEnrollmentError(DomainException::class,fn()=>createStudentWithEnrollment($db,$f['admin'],$profile,2,20,'2027-06-01','2028-03-31'));
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM students WHERE lrn='SYNTHETICNEW'")->fetchColumn() === 1, 'Duplicate LRN does not duplicate profile');
    $db->exec('UPDATE sections SET adviser_id=8 WHERE id=20');
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['teacher'],106,2,20,'2027-06-01','2028-03-31'));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106 AND school_year_id=2')->fetchColumn() === 0, 'Reassigned teacher cannot create enrollment');
    $enrollment = createAnnualEnrollment($db,$f['otherTeacher'],106,2,20,'2027-06-01','2028-03-31');
    checkEnrollment($enrollment > 0, 'Current adviser may create a new annual enrollment');
    $db->beginTransaction();
    expectEnrollmentError(LogicException::class,fn()=>createAnnualEnrollment($db,$f['admin'],106,2,20,'2027-06-01','2028-03-31'));
    $db->rollBack();
    expectEnrollmentError(LogicException::class,fn()=>updateCurrentEnrollmentProjection($db,101));
    $db->exec("UPDATE school_years SET state='closed' WHERE id=1");
    $db->exec("UPDATE school_years SET state='active' WHERE id=2");
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['admin'],$newId,1,10,'2026-10-22','2027-03-31'));
});

withEnrollmentFixture(function ($f) {
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $db->exec('UPDATE users SET is_active=2,session_token=NULL WHERE id=8');
    $before=$db->query('SELECT * FROM sections ORDER BY id')->fetchAll();
    $events=$db->query('SELECT * FROM security_events')->fetchAll();
    $history=$db->query('SELECT * FROM security_change_history')->fetchAll();
    foreach ([[10=>8],[10=>1,11=>8]] as $assignments) {
        expectEnrollmentError(DomainException::class,fn()=>saveEnrollmentSectionAdvisers($db,$f['admin'],$assignments));
        checkEnrollment($before===$db->query('SELECT * FROM sections ORDER BY id')->fetchAll(),'Archived adviser rejects the entire batch including an earlier valid assignment');
        checkEnrollment($events===$db->query('SELECT * FROM security_events')->fetchAll() && $history===$db->query('SELECT * FROM security_change_history')->fetchAll(),'Rejected adviser batch writes no audit records');
    }
    $db->exec('UPDATE users SET is_active=2,session_token=NULL WHERE id=1');
    expectEnrollmentError(DomainException::class,fn()=>saveEnrollmentSectionAdvisers($db,$f['admin'],[10=>7]));
    $db->exec('UPDATE users SET is_active=1 WHERE id IN (1,8)');
    saveEnrollmentSectionAdvisers($db,$f['admin'],[10=>1,11=>7]);
    checkEnrollment((int)$db->query('SELECT adviser_id FROM sections WHERE id=10')->fetchColumn()===1 && (int)$db->query('SELECT adviser_id FROM sections WHERE id=11')->fetchColumn()===7,'Active administrator and teacher remain valid advisers');
});

/** Real adapters and command services, copied into a disposable application fixture. */
function enrollmentEndpointChecks(array $f): void
{
    $root = dirname(__DIR__); $path = __DIR__ . '/.enrollment-http-' . bin2hex(random_bytes(6));
    foreach (['','/config','/includes','/students','/sections','/settings'] as $dir) mkdir($path . $dir);
    $files = [];
    $write = function (string $file, string $contents) use ($path,&$files) { file_put_contents($path.'/'.$file,$contents); $files[]=$file; };
    $copy = function (string $file) use ($root,$write) { $write($file,file_get_contents($root.'/'.$file)); };
    foreach (['includes/student_access.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/enrollment_commands.php','includes/school_years.php','includes/security_audit.php','includes/pii_security.php','includes/student_photos.php','includes/csrf.php','includes/settings_security.php','config/schedule_rules.php','students/add.php','students/edit.php','students/enroll.php','students/save_enrollment.php','sections/save.php','settings/settings.php','settings/save_school_year.php'] as $file) $copy($file);
    foreach (['header.php','sidebar.php','footer.php'] as $file) $write('includes/'.$file,"<?php\n");
    $write('config/database.php', <<<'PHP'
<?php
$request=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$request['database'])) throw new RuntimeException('Unsafe fixture database');
define('BASE_URL','/'); define('BASE_PATH',dirname(__DIR__).'/');
$_SERVER['REQUEST_METHOD']=$request['method'] ?? 'POST';
$_POST=$request['post'] ?? []; $_GET=$request['get'] ?? []; $_FILES=[];
$_SESSION=['app_csrf'=>str_repeat('a',64),'settings_csrf'=>str_repeat('a',64)];
http_response_code(200);
function getDB(): PDO {
    global $request;
    return $GLOBALS['fixture_db'] ??= new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$request['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
register_shutdown_function(function () { echo "\nRESULT:".json_encode(['status'=>http_response_code(),'flash'=>$GLOBALS['fixture_flash'] ?? null]); });
PHP);
    $write('includes/functions.php', <<<'PHP'
<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/csrf.php'; require_once __DIR__.'/settings_security.php'; require_once __DIR__.'/../config/schedule_rules.php';
function startSession(): void {}
function currentUser() { return $GLOBALS['request']['actor']; }
function isAdmin() { return currentUser()['role']==='admin'; }
function isTeacher() { return currentUser()['role']==='teacher'; }
function requireLogin() { if (!(currentUser()['id'] ?? 0)) { http_response_code(401); exit; } }
function requireAdmin() { requireLogin(); if (!isAdmin()) { http_response_code(403); exit; } }
function setFlash($kind,$message) { $GLOBALS['fixture_flash']=[$kind,$message]; }
function showFlash() {}
function sanitize($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function generateQRToken($lrn) { return 'synthetic-'.bin2hex(random_bytes(12)); }
function getAllowedSections() { [$scope,$params]=studentSectionScope('sec'); $q=getDB()->prepare("SELECT sec.* FROM sections sec WHERE sec.is_active=1 AND ($scope)"); $q->execute($params); return $q->fetchAll(); }
function canAccessSection($id) { foreach (getAllowedSections() as $section) if ((int)$section['id']===$id) return true; return false; }
function getSection($id) { $q=getDB()->prepare('SELECT * FROM sections WHERE id=?');$q->execute([$id]);return $q->fetch(); }
function getGradeLevels() { return ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6']; }
function notificationTemplateDefaults() { return []; }
function updateSetting($key,$value) { getDB()->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,$value]); }
PHP);
    $run = function (string $endpoint,array $actor,array $post=[],array $get=[],string $method='POST') use ($path,$f) {
        if (!isset($post['csrf_token'])) $post['csrf_token']=str_repeat('a',64);
        $payload=base64_encode(json_encode(['database'=>$f['database'],'actor'=>$actor,'post'=>$post,'get'=>$get,'method'=>$method],JSON_THROW_ON_ERROR));
        $p=proc_open([PHP_BINARY,$path.'/'.$endpoint,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($path.'/'.$endpoint));
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        checkEnrollment($exit===0 && $errors==='' && preg_match('/RESULT:(.*)$/',$output,$match)===1,'Synthetic enrollment endpoint failed: '.$endpoint.' '.$errors);
        $result=json_decode($match[1],true); $result['body']=$output; return $result;
    };
    try {
        $db=$f['db'];
        $post=['lrn'=>'SYNTHETIC101','school_year_id'=>2,'section_id'=>21,'starts_on'=>'2027-06-01','ends_on'=>'2028-03-31'];
        $result=$run('students/save_enrollment.php',$f['teacher'],$post);
        checkEnrollment($result['status']===409,'Legacy mode blocks annual endpoint');
        $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
        $result=$run('students/save_enrollment.php',$f['teacher'],$post);
        checkEnrollment($result['flash'][0]==='danger' && (int)$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===2,'Teacher cannot POST another section');
        $post['section_id']=20;
        $result=$run('students/save_enrollment.php',$f['teacher'],$post);
        checkEnrollment($result['flash'][0]==='success' && (int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn()===2,'Returning POST reuses identity');
        $result=$run('students/save_enrollment.php',$f['teacher'],$post);
        checkEnrollment($result['flash'][0]==='danger','Teacher cannot replace an existing annual enrollment');
        $db->exec('UPDATE sections SET adviser_id=8 WHERE id=20');
        $post['lrn']='SYNTHETIC106';
        $result=$run('students/save_enrollment.php',$f['teacher'],$post);
        checkEnrollment($result['flash'][0]==='danger' && (int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106 AND school_year_id=2')->fetchColumn()===0,'Reassignment revokes annual POST');
        $profile=['lrn'=>'SYNTHETIC101','first_name'=>'Synthetic','last_name'=>'Three','gender'=>'Male','section_id'=>11,'parent_contact'=>'synthetic-contact'];
        $result=$run('students/edit.php',$f['admin'],$profile,['id'=>101]);
        checkEnrollment($result['status']===403 && (int)$db->query('SELECT section_id FROM students WHERE id=101')->fetchColumn()===10,'Admin profile POST cannot bypass annual placement');
        $profile['section_id']=10;
        $result=$run('students/edit.php',$f['teacher'],$profile,['id'=>101]);
        checkEnrollment($result['status']===302,'Managed assigned teacher may edit profile');
        $db->exec('UPDATE sections SET adviser_id=8 WHERE id=10');
        $result=$run('students/edit.php',$f['teacher'],$profile,['id'=>101]);
        checkEnrollment($result['status']===302 && $result['flash'][0]==='danger','Reassigned teacher loses profile access');
        $profile['school_year_id']=2; $profile['starts_on']='2027-06-01'; $profile['ends_on']='2028-03-31'; $profile['section_id']=21;
        $profile['parent_contact']='';
        $result=$run('students/add.php',$f['teacher'],$profile);
        checkEnrollment(str_contains($result['body'],'Returning Student Enrollment') && !str_contains($result['body'],'synthetic-contact'), 'Existing LRN prompts returning flow without existing contact disclosure');
        $newProfile=$profile; $newProfile['lrn']='SYNTHETICHTTPNEW';
        $result=$run('students/add.php',$f['teacher'],$newProfile);
        checkEnrollment((int)$db->query("SELECT COUNT(*) FROM students WHERE lrn='SYNTHETICHTTPNEW'")->fetchColumn()===0,'Invalid initial enrollment POST creates no orphan profile');
        $result=$run('students/enroll.php',$f['teacher'],[],['school_year_id'=>2],'GET');
        checkEnrollment(!str_contains($result['body'],'synthetic-contact') && !str_contains($result['body'],'value="21"'),'Returning form filters foreign sections without loading contacts');
        $section=['id'=>11,'section_name'=>'Current B','grade_level'=>'Grade 4','schedule_type'=>'full_day','adviser_id'=>8,'school_year_id'=>2];
        $result=$run('sections/save.php',$f['admin'],$section);
        checkEnrollment($result['flash'][0]==='success' && (int)$db->query('SELECT school_year_id FROM sections WHERE id=11')->fetchColumn()===2 && $db->query('SELECT school_year FROM sections WHERE id=11')->fetchColumn()==='2027-2028','Unused section year ID synchronizes legacy label');
        $section['id']=10;
        $result=$run('sections/save.php',$f['admin'],$section);
        checkEnrollment($result['flash'][0]==='danger' && (int)$db->query('SELECT school_year_id FROM sections WHERE id=10')->fetchColumn()===1,'Enrollment history blocks section year and grade edits');
        $result=$run('settings/settings.php',$f['admin'],['school_name'=>'Synthetic School','school_year'=>'2099-2100','enrollment_model_version'=>'0','school_year_id'=>2]);
        checkEnrollment($result['status']===302 && enrollmentModelEnabled($db) && getActiveSchoolYear($db)['id']===1 && $db->query("SELECT setting_value FROM system_settings WHERE setting_key='school_year'")->fetchColumn()!=='2099-2100','General settings cannot alter managed year or model');
        $result=$run('settings/save_school_year.php',$f['teacher'],['label'=>'2028-2029','starts_on'=>'2028-06-01','ends_on'=>'2029-03-31']);
        checkEnrollment($result['status']===403,'Teacher cannot create school years');
    } finally {
        foreach ($files as $file) unlink($path.'/'.$file);
        foreach (['/students','/sections','/settings','/includes','/config',''] as $dir) rmdir($path.$dir);
    }
}
withEnrollmentFixture('enrollmentEndpointChecks');
echo 'Enrollment creation checks passed (' . $GLOBALS['enrollment_checks'] . ").\n";
