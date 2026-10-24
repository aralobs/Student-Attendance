<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
$GLOBALS['correction_notifications']=0;
function sendSMS(...$args) { $GLOBALS['correction_notifications']++; throw new RuntimeException('Unexpected SMS'); }
function sendArrivalEmail(...$args) { $GLOBALS['correction_notifications']++; throw new RuntimeException('Unexpected email'); }
require_once dirname(__DIR__).'/includes/moving_up.php';
require_once dirname(__DIR__).'/includes/enrollment_commands.php';
$service=dirname(__DIR__).'/includes/enrollment_corrections.php';
if (is_file($service)) require_once $service;
function correctionMode(PDO $db): void { $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')"); }
function correctionChange(string $kind='historical_assignment'): array { return ['kind'=>$kind,'reason'=>'Synthetic placement correction','assignment_id'=>2001,'section_id'=>11,'starts_on'=>'2026-10-01','ends_on'=>'2027-03-31']; }
withEnrollmentFixture(function(array $f): void {
    checkEnrollment(function_exists('revisePreparedEnrollment'),'Prepared corrections must be available with attendance and version guards');
    $db=$f['db']; correctionMode($db);
    foreach ([$f['teacher'],['id'=>9,'role'=>'scanner_operator']] as $actor) {
        expectEnrollmentError(DomainException::class,fn()=>previewEnrollmentCorrection($db,$actor,1001,correctionChange()));
        expectEnrollmentError(DomainException::class,fn()=>revisePreparedEnrollment($db,$actor,1001,['kind'=>'cancel','reason'=>'Wrong selection'],1));
    }
    expectEnrollmentError(DomainException::class,fn()=>revisePreparedEnrollment($db,$f['admin'],1001,['kind'=>'cancel','reason'=>'Wrong selection'],1));
    $command=['source_year_id'=>1,'source_section_id'=>10,'student_ids'=>[101],'target_year_id'=>2,'outcome'=>'promoted','destination_section_id'=>20];
    $p=previewMovingUp($db,$f['admin'],$command); $batch=prepareMovingUp($db,$f['admin'],$command,'correction-fixture',$p['fingerprint']);
    $id=$batch['destination_enrollment_ids'][0]; $assignment=(int)$db->query("SELECT id FROM enrollment_sections WHERE enrollment_id=$id")->fetchColumn();
    $change=['kind'=>'reassign','reason'=>'Correct to retained','assignment_id'=>$assignment,'section_id'=>21,'starts_on'=>'2027-06-01','ends_on'=>'2028-03-31'];
    revisePreparedEnrollment($db,$f['admin'],$id,$change,1);
    checkEnrollment($db->query('SELECT outcome FROM moving_up_decisions')->fetchColumn()==='retained','Prepared destination correction updates linked outcome');
    revisePreparedEnrollment($db,$f['admin'],$id,['kind'=>'cancel','reason'=>'Wrong selection'],2);
    checkEnrollment($db->query("SELECT state FROM student_enrollments WHERE id=$id")->fetchColumn()==='cancelled','Cancellation preserves annual identity');
    checkEnrollment($db->query('SELECT state FROM moving_up_decisions')->fetchColumn()==='cancelled','Cancellation cancels linked decision');
    revisePreparedEnrollment($db,$f['admin'],$id,$change,3);
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=101 AND school_year_id=2')->fetchColumn()===1,'Reinstatement reuses annual identity');
    checkEnrollment($db->query('SELECT state FROM moving_up_decisions')->fetchColumn()==='prepared','Reinstatement restores eligible decision');
    $db->exec("INSERT INTO attendance(student_id,date,enrollment_id,assignment_id,attendance_type) VALUES(101,'2027-06-01',$id,$assignment,'absent')");
    expectEnrollmentError(DomainException::class,fn()=>revisePreparedEnrollment($db,$f['admin'],$id,['kind'=>'cancel','reason'=>'Scan arrived'],4));
    checkEnrollment((int)$db->query('SELECT assignment_id FROM attendance WHERE id=501')->fetchColumn()===2001,'Prepared changes preserve source attendance');
});
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db); $original=$db->query('SELECT * FROM attendance WHERE id=501')->fetch();
    $future=correctionChange('reassign'); $future['starts_on']='2027-01-01';
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,$future);
    checkEnrollment($p['affected_attendance_ids']===[],'Future reassignment does not claim prior scans');
    applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']);
    checkEnrollment($db->query('SELECT * FROM attendance WHERE id=501')->fetch()===$original,'Future change preserves complete original attendance');
    checkEnrollment($db->query('SELECT ends_on FROM enrollment_sections WHERE id=2001')->fetchColumn()==='2026-12-31','Future move splits source interval');
    $change=correctionChange(); $change['ends_on']='2026-10-31';
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,$change);
    checkEnrollment($p['affected_attendance_ids']===[501],'Historical review names exact scan IDs');
    $cipher=$db->query('SELECT command_ciphertext FROM enrollment_corrections ORDER BY id DESC LIMIT 1')->fetchColumn();
    checkEnrollment(str_starts_with($cipher,'pii:v1:') && !str_contains($cipher,$change['reason']),'Stored command and reason are encrypted');
    applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']);
    $after=$db->query('SELECT * FROM attendance WHERE id=501')->fetch();
    checkEnrollment((int)$after['assignment_id']!==2001,'Reviewed scan ownership changes');
    unset($original['assignment_id'],$after['assignment_id']);
    checkEnrollment($after===$original,'No scan values or attendance timestamps change');
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM security_events WHERE action='enrollment.correct_ownership'")->fetchColumn()===1,'Ownership mutation has audit');
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']));
});
foreach (["UPDATE sections SET adviser_id=8 WHERE id=10","UPDATE student_enrollments SET version=version+1 WHERE id=1001","UPDATE enrollment_sections SET version=version+1 WHERE id=2001","UPDATE attendance SET am_out='11:00:00' WHERE id=501","INSERT INTO attendance(student_id,date,enrollment_id,assignment_id,attendance_type) VALUES(101,'2026-10-22',1001,2001,'absent')"] as $concurrent) {
    withEnrollmentFixture(function(array $f) use($concurrent): void {
        $db=$f['db']; correctionMode($db); $p=previewEnrollmentCorrection($db,$f['admin'],1001,correctionChange()); $db->exec($concurrent);
        expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']));
        checkEnrollment($db->query('SELECT state FROM enrollment_corrections')->fetchColumn()==='pending','Stale preview cannot partially apply');
    });
}
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db);
    $db->exec("UPDATE school_years SET state='closed' WHERE id=1; UPDATE school_years SET state='active' WHERE id=2; UPDATE student_enrollments SET state='completed' WHERE school_year_id=1");
    expectEnrollmentError(DomainException::class,fn()=>revisePreparedEnrollment($db,$f['admin'],1001,['kind'=>'cancel','reason'=>'closed'],1));
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,correctionChange()); applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']);
    checkEnrollment($db->query('SELECT state FROM school_years WHERE id=1')->fetchColumn()==='closed','Reviewed exception never reopens year');
    $remark=['kind'=>'sf2_remark','reason'=>'Correct historical remark','section_id'=>11,'report_month'=>10,'report_year'=>2026,'remark'=>'Reviewed synthetic remark','expected_remark_version'=>0];
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,$remark);
    $db->exec("INSERT INTO users(id,username,password,full_name,role) VALUES(2,'second_admin','unused','Second Admin','admin')");
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentCorrection($db,['id'=>2,'role'=>'admin'],$p['correction_id'],$p['fingerprint']));
    applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']);
    $r=$db->query('SELECT * FROM sf2_remarks')->fetch();
    checkEnrollment((int)$r['version']===1 && decryptPII($r['remark_ciphertext'],'sf2:101:11:10:2026')==='Reviewed synthetic remark','Corrected remark has encrypted value and optimistic version');
    $h=$db->query('SELECT * FROM sf2_remark_history')->fetch(); $audit=json_decode(decryptPII($h['change_ciphertext'],'sf2-audit:'.$h['event_id']),true);
    checkEnrollment($audit['before']==='' && $audit['after']==='Reviewed synthetic remark','Remark history retains encrypted before and after');
    expectEnrollmentError(DomainException::class,fn()=>previewEnrollmentCorrection($db,$f['admin'],1001,$remark));
    $remark['expected_remark_version']=1; $remark['remark']='Second correction';
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,$remark);
    $db->exec('UPDATE sf2_remarks SET version=version+1');
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']));
});
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db); $before=$db->query('SELECT * FROM student_enrollments WHERE id=1006')->fetch();
    $db->exec("INSERT INTO attendance(id,student_id,date,enrollment_id,assignment_id,am_in,am_status,attendance_type) VALUES(506,106,'2026-10-21',1006,2006,'07:01:00','present','partial')");
    $sourceScan=$db->query('SELECT * FROM attendance WHERE id=506')->fetch();
    $command=['source_year_id'=>1,'source_section_id'=>16,'student_ids'=>[106],'target_year_id'=>2,'outcome'=>'graduated','destination_section_id'=>null];
    $p=previewMovingUp($db,$f['admin'],$command); $b=prepareMovingUp($db,$f['admin'],$command,'graduate',$p['fingerprint']);
    revisePreparedGraduationDecision($db,$f['admin'],$b['decision_ids'][0],'Wrong outcome',1);
    checkEnrollment($db->query('SELECT * FROM student_enrollments WHERE id=1006')->fetch()===$before,'Graduation cancellation leaves active source annual unchanged');
    checkEnrollment($db->query('SELECT state FROM moving_up_decisions')->fetchColumn()==='cancelled','Graduation decision alone cancels');
    checkEnrollment($db->query('SELECT * FROM attendance WHERE id=506')->fetch()===$sourceScan,'Graduation cancellation preserves complete source scan');
    $db->exec("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(22,'Next Six','Grade 6',7,'2027-2028',2)");
    $command['outcome']='retained'; $command['destination_section_id']=22;
    $p=previewMovingUp($db,$f['admin'],$command); $replacement=prepareMovingUp($db,$f['admin'],$command,'retain-after-graduation',$p['fingerprint']);
    checkEnrollment(count($replacement['destination_enrollment_ids'])===1,'Cancelling graduation releases uniqueness for eligible retained replacement');
    $destinationId=$replacement['destination_enrollment_ids'][0];
    revisePreparedEnrollment($db,$f['admin'],$destinationId,['kind'=>'cancel','reason'=>'Correct retained outcome to graduation'],1);
    $cancelled=$db->query("SELECT * FROM student_enrollments WHERE id=$destinationId")->fetch();
    $assignments=$db->query("SELECT * FROM enrollment_sections WHERE enrollment_id=$destinationId ORDER BY id")->fetchAll();
    $command['outcome']='graduated'; $command['destination_section_id']=null;
    $p=previewMovingUp($db,$f['admin'],$command); $graduation=prepareMovingUp($db,$f['admin'],$command,'graduate-after-retention',$p['fingerprint']);
    checkEnrollment($graduation['destination_enrollment_ids']===[] && (int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106 AND school_year_id=2')->fetchColumn()===1,'Graduation replacement preserves the cancelled annual identity without another destination');
    checkEnrollment($db->query("SELECT * FROM student_enrollments WHERE id=$destinationId")->fetch()===$cancelled && $db->query("SELECT * FROM enrollment_sections WHERE enrollment_id=$destinationId ORDER BY id")->fetchAll()===$assignments,'Graduation replacement leaves cancelled destination and assignment history unchanged');
    checkEnrollment($db->query('SELECT * FROM student_enrollments WHERE id=1006')->fetch()===$before && $db->query('SELECT * FROM attendance WHERE id=506')->fetch()===$sourceScan,'Retained to graduated correction preserves source annual and scans');
    checkEnrollment((int)$db->query("SELECT COUNT(*) FROM moving_up_decisions WHERE source_enrollment_id=1006 AND target_year_id=2 AND state='prepared' AND outcome='graduated' AND destination_enrollment_id IS NULL")->fetchColumn()===1,'Replacement has exactly one live destination-free graduation decision');
    $activation=schoolYearActivationPreview($db,$f['admin'],2);
    checkEnrollment($activation['ready'] && $activation['counts']['graduated']===1 && $activation['counts']['cancelled']===1,'Activation accepts the corrected graduation while retaining cancelled destination history');
    activateSchoolYear($db,$f['admin'],2,$activation['fingerprint']);
    checkEnrollment($db->query("SELECT * FROM student_enrollments WHERE id=$destinationId")->fetch()===$cancelled && $db->query('SELECT * FROM attendance WHERE id=506')->fetch()===$sourceScan,'Activation preserves cancelled annual identity and source scan after outcome correction');
});
function cancelledGradeSixDestination(array $f): array {
    $db=$f['db']; correctionMode($db);
    $db->exec("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(22,'Next Six','Grade 6',7,'2027-2028',2)");
    $command=['source_year_id'=>1,'source_section_id'=>16,'student_ids'=>[106],'target_year_id'=>2,'outcome'=>'retained','destination_section_id'=>22];
    $p=previewMovingUp($db,$f['admin'],$command); $batch=prepareMovingUp($db,$f['admin'],$command,'initial-retention',$p['fingerprint']);
    $id=$batch['destination_enrollment_ids'][0]; $assignment=(int)$db->query("SELECT id FROM enrollment_sections WHERE enrollment_id=$id")->fetchColumn();
    revisePreparedEnrollment($db,$f['admin'],$id,['kind'=>'cancel','reason'=>'Wrong retained outcome'],1);
    return ['id'=>$id,'assignment'=>$assignment,'decision'=>$batch['decision_ids'][0],'command'=>array_replace($command,['outcome'=>'graduated','destination_section_id'=>null])];
}
foreach (['active','prepared','completed','referenced_scan','assignment_scan','dated_scan','live_decision'] as $invalidTarget) {
    withEnrollmentFixture(function(array $f) use($invalidTarget): void {
        $db=$f['db']; $target=cancelledGradeSixDestination($f); $id=$target['id']; $assignment=$target['assignment'];
        if (in_array($invalidTarget,['active','prepared','completed'],true)) $db->prepare('UPDATE student_enrollments SET state=? WHERE id=?')->execute([$invalidTarget,$id]);
        elseif ($invalidTarget==='referenced_scan') $db->exec("INSERT INTO attendance(student_id,date,enrollment_id,attendance_type) VALUES(106,'2026-10-22',$id,'absent')");
        elseif ($invalidTarget==='assignment_scan') $db->exec("INSERT INTO attendance(student_id,date,assignment_id,attendance_type) VALUES(101,'2026-10-22',$assignment,'absent')");
        elseif ($invalidTarget==='dated_scan') $db->exec("INSERT INTO attendance(student_id,date,attendance_type) VALUES(106,'2027-06-01','absent')");
        else $db->exec("UPDATE moving_up_decisions SET state='prepared' WHERE id=".$target['decision']);
        expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$target['command']));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===1,'Ineligible target blocks graduation without new batch: '.$invalidTarget);
    });
}
foreach (['target_version','reinstatement','new_scan'] as $changeAfterPreview) {
    withEnrollmentFixture(function(array $f) use($changeAfterPreview): void {
        $db=$f['db']; $target=cancelledGradeSixDestination($f); $id=$target['id'];
        $preview=previewMovingUp($db,$f['admin'],$target['command']);
        if ($changeAfterPreview==='target_version') $db->exec("UPDATE student_enrollments SET version=version+1 WHERE id=$id");
        elseif ($changeAfterPreview==='new_scan') $db->exec("INSERT INTO attendance(student_id,date,attendance_type) VALUES(106,'2027-06-01','absent')");
        else revisePreparedEnrollment($db,$f['admin'],$id,['kind'=>'reassign','reason'=>'Restore retention','assignment_id'=>$target['assignment'],'section_id'=>22,'starts_on'=>'2027-06-01','ends_on'=>'2028-03-31'],2);
        expectEnrollmentError(DomainException::class,fn()=>prepareMovingUp($db,$f['admin'],$target['command'],'stale-graduation',$preview['fingerprint']));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===1 && (int)$db->query("SELECT COUNT(*) FROM moving_up_decisions WHERE outcome='graduated'")->fetchColumn()===0,'Changed target invalidates graduation preview atomically: '.$changeAfterPreview);
    });
}
foreach ([true,false] as $hasCancelledTarget) {
    withEnrollmentFixture(function(array $f) use($hasCancelledTarget): void {
        $db=$f['db'];
        if ($hasCancelledTarget) $target=cancelledGradeSixDestination($f);
        else {
            correctionMode($db);
            $db->exec("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(22,'Next Six','Grade 6',7,'2027-2028',2)");
            $target=['command'=>['source_year_id'=>1,'source_section_id'=>16,'student_ids'=>[106],'target_year_id'=>2,'outcome'=>'graduated','destination_section_id'=>null]];
        }
        $db->exec("INSERT INTO school_years(id,label,starts_on,ends_on,state) VALUES(3,'2025-2026','2025-06-01','2026-03-31','closed'); INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(36,'Prior Six','Grade 6',7,'2025-2026',3); INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(9007,106,3,'completed','2025-06-01','2026-03-31'); INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(9007,9007,36,'Grade 6','2025-06-01','2026-03-31')");
        $historical=array_replace($target['command'],['source_year_id'=>3,'source_section_id'=>36]);
        $preview=previewMovingUp($db,$f['admin'],$historical); $saved=prepareMovingUp($db,$f['admin'],$historical,'historical-source-graduation',$preview['fingerprint']);
        $batchCount=(int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn();
        expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$target['command']));
        if ($hasCancelledTarget) {
            $restore=['kind'=>'reassign','reason'=>'Restore retention','assignment_id'=>$target['assignment'],'section_id'=>22,'starts_on'=>'2027-06-01','ends_on'=>'2028-03-31'];
            expectEnrollmentError(DomainException::class,fn()=>revisePreparedEnrollment($db,$f['admin'],$target['id'],$restore,2));
            checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id='.$target['id'])->fetchColumn()==='cancelled','Conflicting historical-source outcome blocks annual reinstatement');
        }
        if (!$hasCancelledTarget) {
            $retained=array_replace($target['command'],['outcome'=>'retained','destination_section_id'=>22]);
            expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$retained));
        }
        $db->exec("UPDATE moving_up_decisions SET state='applied' WHERE id=".$saved['decision_ids'][0]);
        expectEnrollmentError(DomainException::class,fn()=>previewMovingUp($db,$f['admin'],$target['command']));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===$batchCount,'Different source annual cannot hide a live same-student target decision');
        $otherStudent=['source_year_id'=>1,'source_section_id'=>10,'student_ids'=>[101],'target_year_id'=>2,'outcome'=>'promoted','destination_section_id'=>20];
        checkEnrollment(count(previewMovingUp($db,$f['admin'],$otherStudent)['rows'])===1,'Another student outcome does not conflict with the selected student');
        $db->exec("UPDATE moving_up_decisions SET state='prepared' WHERE id=".$saved['decision_ids'][0]);
        revisePreparedGraduationDecision($db,$f['admin'],$saved['decision_ids'][0],'Cancel historical source outcome',1);
        checkEnrollment(count(previewMovingUp($db,$f['admin'],$target['command'])['rows'])===1,'Cancelling the other source decision releases target selection');
    });
}
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db); $p=previewEnrollmentCorrection($db,$f['admin'],1001,correctionChange());
    $db->exec("CREATE TRIGGER correction_fail_audit BEFORE INSERT ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
    expectEnrollmentError(PDOException::class,fn()=>applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']));
    checkEnrollment((int)$db->query('SELECT assignment_id FROM attendance WHERE id=501')->fetchColumn()===2001 && (int)$db->query('SELECT version FROM student_enrollments WHERE id=1001')->fetchColumn()===1,'Audit failure rolls back all correction writes');
});
echo 'Enrollment corrections: '.($GLOBALS['enrollment_checks'] ?? 0)." checks passed\n";

function correctionEndpointChecks(array $f): void {
    $root=dirname(__DIR__); $path=__DIR__.'/.correction-http-'.bin2hex(random_bytes(6)); $files=[];
    checkEnrollment(is_file($root.'/students/enrollment_corrections.php') && is_file($root.'/students/save_enrollment_correction.php'),'Correction preview and confirm routes must exist');
    foreach(['','/config','/includes','/students'] as $dir) mkdir($path.$dir);
    $write=function($file,$text) use($path,&$files) { file_put_contents($path.'/'.$file,$text); $files[]=$file; };
    foreach(['includes/enrollment_corrections.php','includes/enrollment_commands.php','includes/moving_up.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/school_years.php','includes/security_audit.php','includes/pii_security.php','includes/csrf.php','students/enrollment_corrections.php','students/save_enrollment_correction.php'] as $file) $write($file,file_get_contents($root.'/'.$file));
    foreach(['header','sidebar','footer'] as $file) $write('includes/'.$file.'.php','<?php');
    $write('config/database.php', <<<'PHP'
<?php
$request=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$request['database'])) throw new RuntimeException('Unsafe fixture');
define('BASE_URL','/'); $_SERVER['REQUEST_METHOD']=$request['method']; $_POST=$request['post']; $_GET=$request['get']; $_SESSION=['app_csrf'=>str_repeat('a',64)]; http_response_code(200);
function getDB(): PDO { global $request; return $GLOBALS['fixture_db'] ??= new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$request['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); }
register_shutdown_function(function() { echo "\nRESULT:".json_encode(['status'=>http_response_code(),'flash'=>$GLOBALS['fixture_flash'] ?? null]); });
PHP);
    $write('includes/functions.php', <<<'PHP'
<?php
require_once __DIR__.'/csrf.php';
function startSession() {}
function currentUser() { return $GLOBALS['request']['actor']; }
function isAdmin() { return currentUser()['role']==='admin'; }
function requireLogin() { if (!(currentUser()['id'] ?? 0)) { http_response_code(401); exit; } }
function sanitize($text) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
function setFlash($kind,$text) { $GLOBALS['fixture_flash']=[$kind,$text]; }
function showFlash() {}
PHP);
    $run=function(string $endpoint,array $actor,array $post=[],array $get=[],string $method='POST') use($f,$path): array {
        $post+=['csrf_token'=>str_repeat('a',64)];
        $payload=base64_encode(json_encode(['database'=>$f['database'],'actor'=>$actor,'post'=>$post,'get'=>$get,'method'=>$method]));
        $p=proc_open([PHP_BINARY,$path.'/students/'.$endpoint,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$path.'/students');
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        checkEnrollment($exit===0 && $err==='' && preg_match('/RESULT:(.*)$/',$out,$match)===1,'Correction HTTP fixture failed: '.$err);
        return json_decode($match[1],true)+['body'=>$out];
    };
    try {
        $db=$f['db']; correctionMode($db); $post=correctionChange()+['enrollment_id'=>1001];
        foreach ([$f['teacher'],['id'=>9,'role'=>'scanner_operator']] as $actor) foreach(['enrollment_corrections.php','save_enrollment_correction.php'] as $endpoint) {
            $r=$run($endpoint,$actor,$post); checkEnrollment($r['status']===403,'Teacher and scanner POST denied');
        }
        $r=$run('enrollment_corrections.php',$f['admin'],array_replace($post,['csrf_token'=>'bad'])); checkEnrollment($r['status']===403,'Preview requires CSRF');
        $r=$run('enrollment_corrections.php',$f['admin'],$post);
        checkEnrollment(str_contains($r['body'],'501') && str_contains($r['body'],'2026-10-01') && str_contains($r['body'],'Confirm correction'),'Preview renders exact IDs, dates and separate explicit confirmation');
        checkEnrollment((int)$db->query('SELECT assignment_id FROM attendance WHERE id=501')->fetchColumn()===2001,'Preview alone does not mutate ownership');
        $c=$db->query('SELECT id,preview_fingerprint FROM enrollment_corrections')->fetch();
        $r=$run('save_enrollment_correction.php',$f['admin'],['correction_id'=>$c['id'],'expected_fingerprint'=>$c['preview_fingerprint']]);
        checkEnrollment($r['status']<400 && $db->query('SELECT state FROM enrollment_corrections')->fetchColumn()==='applied','Explicit second action applies stored command');
        $db->prepare('INSERT INTO sf2_remarks(student_id,section_id,report_month,report_year,remark_ciphertext,version,updated_by) VALUES(101,11,10,2026,?,1,1)')->execute([encryptPII('Original <synthetic> remark','sf2:101:11:10:2026')]);
        $r=$run('enrollment_corrections.php',$f['admin'],[],['enrollment_id'=>1001,'report_section_id'=>11,'report_month'=>10,'report_year'=>2026],'GET');
        checkEnrollment(str_contains($r['body'],'type="hidden" name="expected_remark_version" value="1"') && str_contains($r['body'],'Original &lt;synthetic&gt; remark'),'SF2 correction loads current text escaped and optimistic version as hidden input');
        $command=['source_year_id'=>1,'source_section_id'=>16,'student_ids'=>[106],'target_year_id'=>2,'outcome'=>'graduated','destination_section_id'=>null];
        $p=previewMovingUp($db,$f['admin'],$command); $b=prepareMovingUp($db,$f['admin'],$command,'http-graduate',$p['fingerprint']);
        $r=$run('enrollment_corrections.php',$f['admin'],[],['enrollment_id'=>1006],'GET');
        checkEnrollment(str_contains($r['body'],'Cancel prepared graduation') && str_contains($r['body'],'decision_id'),'Graduation exposes a distinct decision-only cancellation');
        $before=$db->query('SELECT * FROM student_enrollments WHERE id=1006')->fetch();
        $r=$run('save_enrollment_correction.php',$f['admin'],['action'=>'cancel_graduation','decision_id'=>$b['decision_ids'][0],'expected_version'=>1,'reason'=>'Wrong graduation']);
        checkEnrollment($r['status']<400 && $db->query('SELECT * FROM student_enrollments WHERE id=1006')->fetch()===$before,'Graduation POST preserves source annual');
    } finally { foreach(array_unique($files) as $file) unlink($path.'/'.$file); foreach(['/students','/includes','/config',''] as $dir) rmdir($path.$dir); }
}
withEnrollmentFixture('correctionEndpointChecks');
checkEnrollment($GLOBALS['correction_notifications']===0,'Corrections send zero notifications');
echo 'Correction endpoint checks included: '.$GLOBALS['enrollment_checks']." checks passed\n";




withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db);
    $db->exec("INSERT INTO attendance(id,student_id,date,enrollment_id,assignment_id,attendance_type) VALUES(502,101,'2026-11-02',1001,2001,'absent'),(503,101,'2026-09-01',1001,2001,'absent')");
    $before=$db->query('SELECT id,date,am_in,am_out,am_status,pm_in,pm_out,pm_status,attendance_type,remarks,recorded_by,created_at,updated_at FROM attendance ORDER BY id')->fetchAll();
    $change=correctionChange(); $change['ends_on']='2026-10-31';
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,$change);
    checkEnrollment($p['affected_attendance_ids']===[501,502],'Bounded correction reviews both changed interval and preserved right-tail ownership IDs');
    applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']);
    $owners=$db->query('SELECT a.id,a.assignment_id,es.section_id FROM attendance a JOIN enrollment_sections es ON es.id=a.assignment_id ORDER BY a.id')->fetchAll();
    checkEnrollment((int)$owners[0]['section_id']===11 && (int)$owners[1]['section_id']===10 && (int)$owners[2]['assignment_id']===2001,'Middle slice changes section while both outside intervals retain original section semantics');
    checkEnrollment($db->query('SELECT id,date,am_in,am_out,am_status,pm_in,pm_out,pm_status,attendance_type,remarks,recorded_by,created_at,updated_at FROM attendance ORDER BY id')->fetchAll()===$before,'Split changes no scan values, authors or timestamps');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Correction writes no notification history');
});
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db);
    foreach ([['reason'=>''],['section_id'=>20],['section_id'=>16],['assignment_id'=>2006],['starts_on'=>'2026-05-31'],['ends_on'=>'2027-04-01'],['starts_on'=>'2026-10-31','ends_on'=>'2026-10-01']] as $bad) {
        expectEnrollmentError(DomainException::class,fn()=>previewEnrollmentCorrection($db,$f['admin'],1001,array_replace(correctionChange(),$bad)));
    }
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM enrollment_corrections')->fetchColumn()===0,'Invalid corrections create no pending metadata');
    $db->exec("UPDATE student_enrollments SET state='completed' WHERE id=1001");
    $change=correctionChange('reassign'); $change['starts_on']='2027-01-01';
    expectEnrollmentError(DomainException::class,fn()=>previewEnrollmentCorrection($db,$f['admin'],1001,$change));
    $db->exec("UPDATE student_enrollments SET state='active' WHERE id=1001");
    $p=previewEnrollmentCorrection($db,$f['admin'],1001,correctionChange()); $db->exec('UPDATE users SET is_active=0 WHERE id=1');
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']));
});
echo 'Correction edge checks included: '.$GLOBALS['enrollment_checks']." checks passed\n";


// Observe a real lock wait, then commit a newly referenced row before the direct
// command gets its year lock. No operational scanner is invoked for a planned year.
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; correctionMode($db);
    $id=createAnnualEnrollment($db,$f['admin'],101,2,20,'2027-06-01','2028-03-31');
    $assignment=(int)$db->query('SELECT id FROM enrollment_sections WHERE enrollment_id='.$id)->fetchColumn();
    $worker=__DIR__.'/.correction-worker-'.bin2hex(random_bytes(6)).'.php'; $ready=$worker.'.ready';
    file_put_contents($worker, <<<'PHP'
<?php
require_once dirname(__DIR__).'/includes/enrollment_corrections.php';
$input=json_decode(base64_decode($argv[1]),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$input['database'])) throw new RuntimeException('Unsafe disposable database');
$db=new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$input['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db->exec('SET SESSION innodb_lock_wait_timeout=20');
file_put_contents($input['ready'],(string)$db->query('SELECT CONNECTION_ID()')->fetchColumn());
try { revisePreparedEnrollment($db,['id'=>1,'role'=>'admin'],$input['enrollment_id'],['kind'=>'cancel','reason'=>'Concurrent correction'],1); echo 'APPLIED'; }
catch(DomainException $error) { echo 'DENIED'; }
PHP);
    $observer=new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$f['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $process=null; $pipes=[];
    try {
        $holder=(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
        $db->beginTransaction();
        $db->query('SELECT id FROM school_years WHERE id=2 LOCK IN SHARE MODE')->fetchAll();
        $db->query('SELECT id FROM sections WHERE id=20 LOCK IN SHARE MODE')->fetchAll();
        $db->query('SELECT id FROM students WHERE id=101 FOR UPDATE')->fetchAll();
        $db->query('SELECT id FROM student_enrollments WHERE id='.$id.' FOR UPDATE')->fetchAll();
        $db->query('SELECT id FROM enrollment_sections WHERE id='.$assignment.' FOR UPDATE')->fetchAll();
        $payload=base64_encode(json_encode(['database'=>$f['database'],'ready'=>$ready,'enrollment_id'=>$id],JSON_THROW_ON_ERROR));
        $process=proc_open([PHP_BINARY,$worker,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
        if (!is_resource($process)) throw new RuntimeException('Unable to start correction worker');
        $wait=$observer->prepare('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX waiting ON waiting.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX holding ON holding.trx_id=w.blocking_trx_id WHERE waiting.trx_mysql_thread_id=? AND holding.trx_mysql_thread_id=?');
        $deadline=microtime(true)+10; $blocked=false;
        do {
            if (is_file($ready)) { $wait->execute([(int)file_get_contents($ready),$holder]); $blocked=(int)$wait->fetchColumn()>0; }
            if ($blocked) break;
            // MariaDB trx0i_s.cc refreshes lock snapshots only after >100ms
            // idle between reads. Keep the observed-wait assertion and deadline.
            usleep(200000);
        } while (microtime(true)<$deadline);
        checkEnrollment($blocked,'Prepared correction waits on the real concurrent writer year lock');
        $db->prepare("INSERT INTO attendance(student_id,date,enrollment_id,assignment_id,am_in,am_status,attendance_type) VALUES(101,'2027-06-01',?,?,'07:02:00','present','partial')")->execute([$id,$assignment]);
        $db->commit();
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $pipes=[]; $exit=proc_close($process); $process=null;
        checkEnrollment($exit===0 && $err==='' && $out==='DENIED','Post-wait attendance recheck blocks prepared cancellation');
        checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id='.$id)->fetchColumn()==='prepared' && (int)$db->query('SELECT version FROM student_enrollments WHERE id='.$id)->fetchColumn()===1,'Concurrent denial preserves annual state/version');
        checkEnrollment($db->query("SELECT am_in FROM attendance WHERE student_id=101 AND date='2027-06-01'")->fetchColumn()==='07:02:00','Concurrent attendance is preserved');
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process); }
        if (is_file($ready)) unlink($ready);
        unlink($worker);
    }
});
checkEnrollment($GLOBALS['correction_notifications']===0,'All correction operations send zero notifications');
echo 'Enrollment corrections final: '.$GLOBALS['enrollment_checks']." checks passed\n";
