<?php
declare(strict_types=1);
require_once __DIR__ . '/support/enrollment_fixture.php';
$service = dirname(__DIR__) . '/includes/enrollment_reports.php';
if (is_file($service)) require_once $service;
require_once dirname(__DIR__) . '/includes/enrollment_commands.php';
withEnrollmentFixture(function (array $f): void {
    checkEnrollment(function_exists('loadEnrollmentReport'), 'Historical report loader must exist instead of reading current profile placement');
    $db = $f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $old = loadEnrollmentReport($db,$f['admin'],1,10,'2026-10-01','2026-10-31');
    checkEnrollment(count($old['roster']) === 1 && count($old['attendance']) === 1, 'Source month includes unique learner and scan');
    checkEnrollment($old['attendance'][0]['assignment_id'] === 2001, 'Original assignment owns scan');
    $db->exec("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(1101,101,2,'prepared','2027-06-01','2028-03-31'); INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2101,1101,20,'Grade 4','2027-06-01','2028-03-31')");
    checkEnrollment(loadEnrollmentReport($db,$f['admin'],1,10,'2026-10-01','2026-10-31')['attendance'] === $old['attendance'], 'Preparation cannot change old totals');
    $db->exec("UPDATE enrollment_sections SET ends_on='2026-10-21' WHERE id=2001; INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2002,1001,11,'Grade 3','2026-10-22','2027-03-31'); UPDATE students SET section_id=11 WHERE id=101; INSERT INTO attendance(student_id,date,attendance_type,enrollment_id,assignment_id,recorded_by) VALUES(101,'2026-10-22','absent',1001,2002,8)");
    $source = loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31');
    checkEnrollment(count($source['attendance']) === 1 && $source['roster'][0]['assignments'][0]['ends_on'] === '2026-10-21', 'Transfer retains source scan and clips source membership');
    $target = loadEnrollmentReport($db,$f['otherTeacher'],1,11,'2026-10-01','2026-10-31');
    checkEnrollment(count($target['attendance']) === 1 && $target['attendance'][0]['assignment_id'] === 2002, 'Destination owns only destination scans');
    checkEnrollment(count(loadEnrollmentReport($db,$f['teacher'],1,null,'2026-10-01','2026-10-31')['attendance']) === 1, 'Teacher sees only permitted section history');
    expectEnrollmentError(DomainException::class,fn()=>loadEnrollmentReport($db,$f['otherTeacher'],1,10,'2026-10-01','2026-10-31'));
    expectEnrollmentError(DomainException::class,fn()=>loadEnrollmentReport($db,['id'=>9,'role'=>'scanner_operator'],1,null,'2026-10-01','2026-10-31'));
    $db->exec("UPDATE school_years SET state='closed' WHERE id=1; UPDATE school_years SET state='active' WHERE id=2; UPDATE student_enrollments SET state='completed' WHERE school_year_id=1; UPDATE student_enrollments SET state='active' WHERE id=1101; UPDATE students SET section_id=20,grade_level='Grade 4',school_year='2027-2028',is_active=0,enrollment_status='graduated' WHERE id=101; UPDATE sections SET is_active=0 WHERE id=10");
    checkEnrollment(loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31')['attendance'] === $source['attendance'], 'Activation and archived graduated caches preserve old attendance');
    $db->exec("INSERT INTO enrollment_migration_issues(record_type,record_id,reason_code) VALUES('attendance',999,'unknown_assignment')");
    checkEnrollment(loadEnrollmentReport($db,$f['admin'],1,10,'2026-10-01','2026-10-31')['unresolved_count'] === 1, 'Admin sees unresolved historical exception count');
    checkEnrollment(loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31')['unresolved_count'] === 0, 'Teachers do not receive global exception details');
    expectEnrollmentError(DomainException::class,fn()=>resolveEnrollmentReportYear($db,0,'2026-10-01','2027-10-31'));
    checkEnrollment((int)resolveEnrollmentReportYear($db,1,'2026-10-01','2027-10-31')['id'] === 1, 'Explicit separate year selection scopes a long interval');
    $db->exec("UPDATE school_years SET starts_on='2026-06-15' WHERE id=1; UPDATE student_enrollments SET starts_on='2026-06-15' WHERE school_year_id=1; UPDATE enrollment_sections SET starts_on='2026-06-15' WHERE id IN(2001,2006)");
    $first = loadEnrollmentReport($db,$f['admin'],1,10,'2026-06-01','2026-06-30');
    checkEnrollment($first['roster'][0]['assignments'][0]['starts_on'] === '2026-06-15', 'First calendar month clips to verified opening after day one');
});
withEnrollmentFixture(function(array $f): void {
    checkEnrollment(function_exists('saveEnrollmentSectionAdvisers'),'Managed adviser batches require atomic year-aware command');
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    expectEnrollmentError(DomainException::class,fn()=>saveEnrollmentSectionAdvisers($db,$f['teacher'],[10=>8]));
    expectEnrollmentError(DomainException::class,fn()=>saveEnrollmentSectionAdvisers($db,$f['admin'],[10=>8,11=>999]));
    checkEnrollment((int)$db->query('SELECT adviser_id FROM sections WHERE id=10')->fetchColumn()===7,'Invalid batch preserves all adviser assignments');
    $db->exec("UPDATE school_years SET state='closed' WHERE id=2");
    expectEnrollmentError(DomainException::class,fn()=>saveEnrollmentSectionAdvisers($db,$f['admin'],[10=>8,20=>8]));
    checkEnrollment((int)$db->query('SELECT adviser_id FROM sections WHERE id=10')->fetchColumn()===7,'Mixed closed-year batch rolls back');
    saveEnrollmentSectionAdvisers($db,$f['admin'],[10=>8,11=>7]);
    expectEnrollmentError(DomainException::class,fn()=>loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31'));
    checkEnrollment(count(loadEnrollmentReport($db,$f['otherTeacher'],1,10,'2026-10-01','2026-10-31')['attendance'])===1,'Current adviser gains historical scope after reassignment');
    $db->exec("CREATE TRIGGER enrollment_test_fail_audit BEFORE INSERT ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    expectEnrollmentError(PDOException::class,fn()=>saveEnrollmentSectionAdvisers($db,$f['admin'],[10=>7,11=>8]));
    checkEnrollment((int)$db->query('SELECT adviser_id FROM sections WHERE id=10')->fetchColumn()===8,'Audit failure rolls entire adviser batch back');
});
/** Real SQL and full rendered PHP routes, with only connection/session/layout isolated. */
withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $profile=$db->prepare("INSERT INTO students(id,lrn,first_name,last_name,gender,section_id,qr_token) VALUES(?,?,'Synthetic','Paged','Male',20,?)");
    $annual=$db->prepare("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(?,?,1,'active','2026-10-15','2026-10-25')");
    $assignment=$db->prepare("INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(?,?,10,'Grade 3','2026-10-15','2026-10-25')");
    for ($i=1;$i<=251;$i++) { $profile->execute([10000+$i,'SYNTHETICPAGE'.$i,'page-'.$i]); $annual->execute([20000+$i,10000+$i]); $assignment->execute([30000+$i,20000+$i]); }
    $report=loadEnrollmentReport($db,$f['teacher'],1,10,'2026-10-01','2026-10-31');
    checkEnrollment(count($report['roster'])===252 && count(array_unique(array_column($report['roster'],'enrollment_id')))===252,'Loader paginates more than 250 unique annual rows despite future profile cache');
    $sources=enrollmentReportSources($db,$report);
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM '.$sources['students'].' s')->fetchColumn()===252,'Real SQL adapter retains every paginated member once');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM '.$sources['attendance'].' a JOIN '.$sources['students'].' s ON s.id=a.student_id AND s.enrollment_id=a.enrollment_id AND s.section_id=a.report_section_id')->fetchColumn()===1,'Real SQL adapter does not multiply student-days');
});

function enrollmentRenderedReports(array $f): void
{
    $root=dirname(__DIR__); $path=__DIR__.'/.enrollment-report-'.bin2hex(random_bytes(6));
    foreach (['','/includes','/config','/reports','/students','/analytics'] as $dir) mkdir($path.$dir);
    foreach (glob($root.'/includes/*.php') as $file) copy($file,$path.'/includes/'.basename($file));
    foreach (glob($root.'/reports/*.php') as $file) copy($file,$path.'/reports/'.basename($file));
    foreach (['view','students','archived','master_list','section_advisers'] as $file) copy($root.'/students/'.$file.'.php',$path.'/students/'.$file.'.php');
    copy($root.'/analytics/analytics.php',$path.'/analytics/analytics.php');
    copy($root.'/dashboard.php',$path.'/dashboard.php');
    copy($root.'/config/schedule_rules.php',$path.'/config/schedule_rules.php');
    foreach (['header','sidebar','footer'] as $file) file_put_contents($path.'/includes/'.$file.'.php','<?php');
    file_put_contents($path.'/includes/session_security.php','<?php function startSession(): void {} function checkSession(bool $touch=true): ?string { return null; }');
    file_put_contents($path.'/config/database.php', <<<'PHP'
<?php
$request=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$request['database'])) throw new RuntimeException('Unsafe fixture database');
define('BASE_URL','/'); define('BASE_PATH',dirname(__DIR__).'/'); date_default_timezone_set('Asia/Manila');
$_GET=$request['get']; $_POST=$request['post'] ?? []; $_SERVER['REQUEST_METHOD']=$request['method'] ?? 'GET';
$_SERVER['SCRIPT_NAME']='/'.$request['route']; $_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SESSION=['user_id'=>$request['actor']['id'],'role'=>$request['actor']['role'],'app_csrf'=>str_repeat('a',64),'settings_csrf'=>str_repeat('a',64)];
http_response_code(200);
function getDB(...$args): PDO {
    global $request;
    return $GLOBALS['fixture_db'] ??= new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$request['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
register_shutdown_function(function () {
    echo "\nRESULT:".json_encode(['status'=>http_response_code(),'sf2'=>$GLOBALS['sf2PrintSummary'] ?? null,
        'sf4'=>$GLOBALS['sf4Attendance'] ?? null,'summary'=>$GLOBALS['summary'] ?? null,'school_days'=>$GLOBALS['schoolDays'] ?? null,
        'sf2_overrides'=>$GLOBALS['sf2SummaryOverrides'] ?? [], 'sf4_rows'=>$GLOBALS['sf4Rows'] ?? [],
        'roster_count'=>isset($GLOBALS['students']) && is_array($GLOBALS['students']) ? count($GLOBALS['students']) : null,
        'student_totals'=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['id','grade_level','section_name','full_day','partial','absent','total_recorded'])),is_array($GLOBALS['students'] ?? null) ? $GLOBALS['students'] : []),
        'member_days'=>$GLOBALS['memberDays'] ?? null,
        'recent_ids'=>array_column($GLOBALS['recent'] ?? [],'assignment_id')]);
});
PHP);
    file_put_contents($path.'/remark_lock_worker.php', <<<'PHP'
<?php
require_once __DIR__.'/includes/sf2_remarks.php';
$db=getDB();
file_put_contents($request['ready'],(string)$db->query('SELECT CONNECTION_ID()')->fetchColumn());
try { saveSF2Remark($db,101,10,10,2026,'Synthetic lock-order remark',0); echo 'SAVED'; }
catch (DomainException $error) { echo 'DENIED'; }
PHP);
    $lockProbe=function(string $change) use($path,$f): void {
        $db=$f['db'];
        $dsn='mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$f['database'].';charset=utf8mb4';
        $observer=new PDO($dsn,getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $holderId=(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
        $ready=$path.'/remark-ready-'.$change;
        $payload=base64_encode(json_encode(['database'=>$f['database'],'actor'=>$f['teacher'],'get'=>[],'route'=>'remark_lock_worker.php','ready'=>$ready],JSON_THROW_ON_ERROR));
        $worker=null; $pipes=[];
        try {
            $db->exec('DELETE FROM sf2_remarks; DELETE FROM sf2_remark_history');
            $db->beginTransaction();
            $db->query('SELECT id FROM school_years WHERE id=1 FOR UPDATE')->fetchAll();
            $worker=proc_open([PHP_BINARY,$path.'/remark_lock_worker.php',$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$path);
            $wait=$observer->prepare('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w
                JOIN information_schema.INNODB_TRX waiting ON waiting.trx_id=w.requesting_trx_id
                JOIN information_schema.INNODB_TRX holding ON holding.trx_id=w.blocking_trx_id
                WHERE waiting.trx_mysql_thread_id=? AND holding.trx_mysql_thread_id=?');
            $deadline=microtime(true)+10; $blocked=false;
            do {
                if (is_file($ready)) { $wait->execute([(int)file_get_contents($ready),$holderId]); $blocked=(int)$wait->fetchColumn()>0; }
                if ($blocked) break;
                usleep(10000);
            } while (microtime(true)<$deadline);
            if (!$blocked) {
                $workerId=is_file($ready) ? (int)file_get_contents($ready) : 0;
                $state=$observer->prepare('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID=?');
                $state->execute([$workerId]); $workerState=$state->fetchColumn();
                $db->rollBack();
                $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $pipes=[]; $exit=proc_close($worker); $worker=null;
                throw new RuntimeException('Remark lock probe did not observe wait; ready='.(int)($workerId>0).'; state='.($workerState===false ? 'disconnected' : $workerState).'; exit='.$exit.'; saved='.(int)str_starts_with($output,'SAVED').'; denied='.(int)str_starts_with($output,'DENIED').'; errors='.$errors);
            }
            checkEnrollment(true,'Remark worker reaches an observed InnoDB wait on the held year');
            $sectionAvailable=false;
            try {
                $observer->beginTransaction();
                $observer->query('SELECT id FROM sections WHERE id=10 FOR UPDATE NOWAIT')->fetchAll();
                $sectionAvailable=true;
            } catch (PDOException $error) {
                if ((int)($error->errorInfo[1] ?? 0)!==1205) throw $error;
            } finally { if ($observer->inTransaction()) $observer->rollBack(); }
            if ($sectionAvailable) {
                if ($change==='adviser') $db->exec('UPDATE sections SET adviser_id=8 WHERE id=10');
                if ($change==='mapping') $db->exec('UPDATE sections SET school_year_id=2 WHERE id=10');
                if ($change==='year') $db->exec("UPDATE school_years SET state='closed' WHERE id=1");
            }
            $db->commit();
            $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $pipes=[]; $exit=proc_close($worker); $worker=null;
            checkEnrollment($sectionAvailable,'Waiting for the year leaves the section lockable (year-first remark locks)');
            checkEnrollment($exit===0 && $errors==='','Remark lock worker completes after releasing the year: '.$errors);
            $saved=$change==='unchanged';
            checkEnrollment(str_starts_with($output,$saved ? 'SAVED' : 'DENIED'),'Remark save revalidates locked '.$change.' state');
            checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sf2_remarks')->fetchColumn()===($saved ? 1 : 0),'Lock-state change preserves remark write boundary');
        } finally {
            if ($db->inTransaction()) $db->rollBack();
            if ($observer->inTransaction()) $observer->rollBack();
            if (is_resource($worker)) { proc_terminate($worker); foreach ($pipes as $pipe) fclose($pipe); proc_close($worker); }
            $db->exec("UPDATE sections SET school_year_id=1,adviser_id=7 WHERE id=10; UPDATE school_years SET state='active' WHERE id=1; DELETE FROM sf2_remarks; DELETE FROM sf2_remark_history");
        }
    };
    $run=function(string $route,array $actor,array $get=[],array $post=[]) use($path,$f): array {
        if ($post) $post['csrf_token']=str_repeat('a',64);
        $payload=base64_encode(json_encode(['database'=>$f['database'],'actor'=>$actor,'get'=>$get,'post'=>$post,'method'=>$post ? 'POST' : 'GET','route'=>$route],JSON_THROW_ON_ERROR));
        $p=proc_open([PHP_BINARY,$path.'/'.$route,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($path.'/'.$route));
        $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        checkEnrollment($exit===0 && $errors==='' && preg_match('/RESULT:(.*)$/',$body,$match)===1,'Rendered report failed: '.$route.' '.$errors);
        return ['body'=>$body]+json_decode($match[1],true);
    };
    try {
        $db=$f['db']; $get=['month'=>10,'year'=>2026,'section'=>10,'school_year_id'=>1];
        $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
        foreach (['unchanged','adviser','mapping','year'] as $change) $lockProbe($change);
        $before=$run('reports/sf2.php',$f['teacher'],$get);
        checkEnrollment($before['status']===200 && str_contains($before['body'],'sf2PrintRoot') && $before['sf2']['TOTAL']['present']===0.5,'Rendered SF2 uses recorded sessions');
        checkEnrollment(str_contains($before['body'],'name="school_year_id" value="1"'),'SF2 filter retains selected historical year');
        $sf4=$run('reports/sf4.php',$f['teacher'],$get);
        checkEnrollment(str_contains($sf4['body'],'sf4PrintRoot') && $sf4['sf4'][10]['male']===0.5,'Rendered SF4 print shares source ownership');
        checkEnrollment(str_contains($sf4['body'],'name="school_year_id" value="1"'),'SF4 filter retains selected historical year');
        $save=$run('reports/save_sf2_remark.php',$f['teacher'],[],['student_id'=>101,'section_id'=>10,'month'=>10,'year'=>2026,'version'=>0,'remark'=>'Synthetic original section remark']);
        checkEnrollment($save['status']===200 && str_contains($save['body'],'"success":true'),'Original section remark saves encrypted');
        $cipher=$db->query('SELECT remark_ciphertext FROM sf2_remarks WHERE student_id=101')->fetchColumn();
        checkEnrollment(!str_contains($cipher,'Synthetic original section remark'),'Remark storage is encrypted');
        $db->exec("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(1101,101,2,'prepared','2027-06-01','2028-03-31'); INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2101,1101,20,'Grade 4','2027-06-01','2028-03-31')");
        $prepared=$run('reports/sf2.php',$f['teacher'],$get);
        checkEnrollment($prepared['sf2']===$before['sf2'],'Future preparation preserves rendered SF2 totals');
        $db->exec("UPDATE enrollment_sections SET ends_on='2026-10-21' WHERE id=2001; INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(2002,1001,11,'Grade 3','2026-10-22','2026-10-25'),(2003,1001,10,'Grade 3','2026-10-26','2027-03-31'); UPDATE students SET section_id=11 WHERE id=101; INSERT INTO attendance(student_id,date,attendance_type,enrollment_id,assignment_id,recorded_by) VALUES(101,'2026-10-22','absent',1001,2002,8)");
        // Give the destination a distinct grade to prove both label dimensions, then
        // restore it before the existing same-grade report assertions continue.
        $db->exec("UPDATE sections SET grade_level='Grade 4' WHERE id=11; UPDATE enrollment_sections SET grade_level='Grade 4' WHERE id=2002");
        foreach (['by_student','date_range'] as $route) {
            $combined=$run('reports/'.$route.'.php',$f['admin'],['month'=>10,'year'=>2026,'school_year_id'=>1,'date_from'=>'2026-10-01','date_to'=>'2026-10-31']);
            $learner=array_values(array_filter($combined['student_totals'],fn($row)=>(int)$row['id']===101));
            checkEnrollment(count($learner)===1 && $learner[0]['section_name']==='Current A / Current B' && $learner[0]['grade_level']==='Grade 3 / Grade 4','Combined '.$route.' labels every contributing section and grade');
            checkEnrollment((int)$learner[0]['partial']===1 && (int)$learner[0]['absent']===1,'Combined '.$route.' totals count both assignments exactly once');
            checkEnrollment(str_contains($combined['body'],'Grade 3 / Grade 4 / Current A / Current B'),'Rendered '.$route.' presents the combined placement labels with the totals');
            $filtered=$run('reports/'.$route.'.php',$f['admin'],['month'=>10,'year'=>2026,'school_year_id'=>1,'date_from'=>'2026-10-01','date_to'=>'2026-10-31','grade'=>'Grade 4']);
            $row=$filtered['student_totals'][0];
            checkEnrollment(count($filtered['student_totals'])===1 && $row['grade_level']==='Grade 4' && $row['section_name']==='Current B'
                && (int)$row['partial']===0 && (int)$row['absent']===1 && $filtered['member_days']===2,'Filtered '.$route.' labels, totals and eligible days share the same contributing ranges');
        }
        $db->exec("UPDATE sections SET grade_level='Grade 3' WHERE id=11; UPDATE enrollment_sections SET grade_level='Grade 3' WHERE id=2002");
        $transferred=$run('reports/sf2.php',$f['teacher'],$get);
        checkEnrollment($transferred['sf2']===$before['sf2'] && $transferred['roster_count']===1,'Return to section renders one annual row with original totals');
        checkEnrollment(($transferred['sf2_overrides']['attendancePct']['M'] ?? null)===2.5,'SF2 printed percentage divides by eligible source student-days');
        $transferSF4=$run('reports/sf4.php',$f['teacher'],$get);
        checkEnrollment(($transferSF4['sf4_rows'][0]['possible_male'] ?? null)===20,'SF4 printed percentage uses clipped source membership denominator');
        checkEnrollment(str_contains($transferred['body'],'Synthetic original section remark'),'Original section retains encrypted remark after transfer');
        $foreign=$run('reports/sf2.php',$f['otherTeacher'],$get);
        checkEnrollment($foreign['status']===400 && !str_contains($foreign['body'],'sf2PrintRoot'),'Foreign adviser cannot render section report');
        $profile=$run('students/view.php',$f['teacher'],['id'=>101,'enrollment_id'=>1001]);
        checkEnrollment($profile['summary']['total_days']===1 && $profile['recent_ids']===[2001],'Historical profile summary/recent rows exclude another teacher assignment');
        checkEnrollment(str_contains($profile['body'],'student_photo.php?id=101&amp;enrollment_id=1001'),'Historical photo URL includes matching annual enrollment');
        $batch=$run('students/section_advisers.php',$f['admin'],[],['adviser'=>[10=>8,11=>999]]);
        checkEnrollment((int)$db->query('SELECT adviser_id FROM sections WHERE id=10')->fetchColumn()===7,'Invalid adviser endpoint batch preserves first section');
        $inline=$run('students/master_list.php',$f['admin'],[],['action'=>'update_adviser','section_id'=>10,'adviser_id'=>8]);
        checkEnrollment(str_contains($inline['body'],'"success":true'),'Inline adviser endpoint calls managed atomic command');
        $revoked=$run('reports/sf2.php',$f['teacher'],$get);
        checkEnrollment($revoked['status']===400,'Reassigned adviser loses historical report access immediately');
        $run('students/section_advisers.php',$f['admin'],[],['adviser'=>[10=>7,11=>8]]);
        $db->exec("UPDATE school_years SET state='closed' WHERE id=1; UPDATE school_years SET state='active' WHERE id=2; UPDATE student_enrollments SET state='completed' WHERE school_year_id=1; UPDATE student_enrollments SET state='active' WHERE id=1101; UPDATE students SET section_id=20,grade_level='Grade 4',school_year='2027-2028',is_active=0,enrollment_status='graduated' WHERE id=101; UPDATE sections SET is_active=0 WHERE id=10; INSERT INTO enrollment_migration_issues(record_type,record_id,reason_code) VALUES('attendance',999,'unknown_assignment')");
        $closed=$run('reports/sf2.php',$f['teacher'],$get);
        checkEnrollment($closed['sf2']===$before['sf2'] && str_contains($closed['body'],'readonly'),'Closed archived year preserves SF2 totals and read-only remarks');
        $save=$run('reports/save_sf2_remark.php',$f['teacher'],[],['student_id'=>101,'section_id'=>10,'month'=>10,'year'=>2026,'version'=>1,'remark'=>'Attempted ordinary edit']);
        checkEnrollment($save['status']===403 && $db->query('SELECT remark_ciphertext FROM sf2_remarks WHERE student_id=101')->fetchColumn()===$cipher,'Ordinary closed-year remark save rejected without mutation');
        $inline=$run('students/master_list.php',$f['admin'],['school_year_id'=>1],['action'=>'update_adviser','section_id'=>10,'adviser_id'=>8]);
        checkEnrollment(str_contains($inline['body'],'"success":false') && (int)$db->query('SELECT adviser_id FROM sections WHERE id=10')->fetchColumn()===7,'Closed-year adviser endpoint rejects writes');
        $sf4=$run('reports/sf4.php',$f['teacher'],$get);
        checkEnrollment($sf4['sf4'][10]['male']===0.5 && !isset($sf4['sf4'][11]),'Closed SF4 remains scoped to original current adviser');
        $csv=$run('reports/export_excel.php',$f['admin'],['date'=>'2026-10-21','section'=>10,'school_year_id'=>1]);
        checkEnrollment(str_contains($csv['body'],'Current A') && !str_contains($csv['body'],'Next Four') && str_contains($csv['body'],'Unresolved historical ownership exceptions: 1'),'CSV uses original section and visibly qualifies unresolved ownership');
        $pdf=$run('reports/export_pdf.php',$f['teacher'],$get);
        checkEnrollment($pdf['status']===302,'Existing PDF route accepts authorized archived annual section');
        $scan=$run('reports/sf2.php',['id'=>9,'role'=>'scanner_operator'],$get);
        checkEnrollment($scan['status']===403 && !str_contains($scan['body'],'sf2PrintRoot'),'Scanner cannot render historical report');
        $range=$run('reports/date_range.php',$f['admin'],['date_from'=>'2026-10-01','date_to'=>'2027-10-31']);
        checkEnrollment($range['status']===400,'Unscoped multiple-year HTTP report is rejected');
        foreach (['daily','by_student','by_section','by_grade','date_range','am_pm','partial','absence_summary'] as $route) {
            $result=$run('reports/'.$route.'.php',$f['admin'],$get+['date'=>'2026-10-21','date_from'=>'2026-10-01','date_to'=>'2026-10-31']);
            checkEnrollment($result['status']===200 && str_contains($result['body'],'unresolved historical ownership exceptions'),'Managed report adapter executes real SQL: '.$route);
        }
        foreach (['students/students.php','students/archived.php','students/master_list.php','students/section_advisers.php','analytics/analytics.php'] as $route) {
            $result=$run($route,$f['admin'],$get);
            checkEnrollment($result['status']===200 && str_contains($result['body'],'2026-2027'),'Historical directory/analytics route renders selected year: '.$route);
            if (in_array($route,['students/archived.php','analytics/analytics.php'],true)) checkEnrollment(str_contains($result['body'],'name="school_year_id" value="1"'),'Directory and analytics filters retain selected historical year');
        }
        $db->exec("UPDATE school_years SET starts_on='2026-06-15' WHERE id=1; UPDATE student_enrollments SET starts_on='2026-06-15' WHERE school_year_id=1; UPDATE enrollment_sections SET starts_on='2026-06-15' WHERE id IN(2001,2006)");
        $first=$run('reports/sf2.php',$f['admin'],array_replace($get,['month'=>6]));
        checkEnrollment($first['school_days']===12 && $first['sf2']['TOTAL']['present']===0,'First partial month renders only eligible school days without implied attendance');
    } finally {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($path);
    }
}
withEnrollmentFixture('enrollmentRenderedReports');
echo 'Enrollment report checks passed (' . ($GLOBALS['enrollment_checks'] ?? 0) . ").\n";
