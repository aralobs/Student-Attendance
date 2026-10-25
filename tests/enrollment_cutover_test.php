<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/enrollment_migration.php';
require_once dirname(__DIR__).'/includes/moving_up.php';

function cutoverCli(array $f, array $args): array
{
    $env=getenv(); $env['MYSQLDATABASE']=$f['database']; $env['APP_ENV']='testing';
    $process=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/migrations/school_year_enrollment.php'],$args),
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    if (!is_resource($process)) throw new RuntimeException('Cutover CLI process unavailable');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}

function cutoverState(PDO $db): array
{
    $state=[];
    foreach (['school_years','student_enrollments','enrollment_sections','students','attendance','moving_up_decisions','system_settings','security_events','security_change_history'] as $table) {
        $state[$table]=$db->query("SELECT * FROM $table")->fetchAll();
    }
    return $state;
}

// The first assertion exercises the real CLI contract and fails before bootstrap exists.
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; $db->exec("UPDATE school_years SET state='planned' WHERE id=1; UPDATE student_enrollments SET state='prepared'");
    $before=cutoverState($db); $path=sys_get_temp_dir().'/enrollment_cutover_'.bin2hex(random_bytes(8)).'.json';
    try {
        [$exit,$out,$err]=cutoverCli($f,['--initialize-preview=1','--actor-id=1','--output='.$path]);
        checkEnrollment($exit===0 && $err==='', 'CLI must export an authorized initial cutover preview (expected exit 0; got '.$exit.')');
        $preview=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        checkEnrollment($preview['ready'] && preg_match('/\A[0-9a-f]{64}\z/',$preview['fingerprint'])===1,'Preview exports ready and a combined fingerprint');
        checkEnrollment($before===cutoverState($db),'Preview does not mutate or initialize partial mode 0');
        checkEnrollment($preview['fingerprint']!==enrollmentMigrationReadiness($db,1)['fingerprint'],'Initialization token differs from legacy-only readiness');
        checkEnrollment(enrollmentInitializationPreview($db,$f['admin'],1)['fingerprint']===$preview['fingerprint'],'CLI and service token stable across connection timezones');
        [$exit]=cutoverCli($f,['--initialize=1','--fingerprint='.$preview['fingerprint'],'--actor-id=7']);
        checkEnrollment($exit!==0 && !enrollmentModelEnabled($db),'CLI rejects teacher attribution');
        [$exit,$out,$err]=cutoverCli($f,['--initialize=1','--fingerprint='.$preview['fingerprint'],'--actor-id=1']);
        checkEnrollment($exit===0 && $err==='' && enrollmentModelEnabled($db),'CLI explicitly initializes verified year');
        checkEnrollment(!str_contains($out,'SYNTHETIC') && !str_contains($out,$preview['fingerprint']),'CLI output excludes profile data and private fingerprint');
        checkEnrollment(getActiveSchoolYear($db)['id']===1,'Initialization selects exactly one active year');
        checkEnrollment((int)$db->query("SELECT COUNT(*) FROM student_enrollments WHERE state='active'")->fetchColumn()===2,'Verified prepared initial enrollments become active');
        checkEnrollment($db->query("SELECT setting_value FROM system_settings WHERE setting_key='school_year'")->fetchColumn()==='2026-2027','Legacy year setting synchronized');
        checkEnrollment($db->query("SELECT setting_value FROM system_settings WHERE setting_key='enrollment_initialization_year_id'")->fetchColumn()==='1','Original initialization year recorded');
        checkEnrollment($db->query("SELECT setting_value FROM system_settings WHERE setting_key='enrollment_initialization_fingerprint'")->fetchColumn()===$preview['fingerprint'],'Original initialization token recorded');
        $after=cutoverState($db);
        initializeEnrollmentModel($db,$f['admin'],1,$preview['fingerprint']);
        checkEnrollment($after===cutoverState($db),'Exact initialization replay is entirely harmless');
        expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],2,$preview['fingerprint']));
        expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,str_repeat('a',64)));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Cutover sends no notifications');
    } finally { if (is_file($path)) unlink($path); }
});

withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];
    foreach ([$f['teacher'],['id'=>7,'role'=>'admin'],['id'=>9,'role'=>'scanner_operator']] as $actor) {
        expectEnrollmentError(DomainException::class,fn()=>enrollmentInitializationPreview($db,$actor,1));
        expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$actor,1,str_repeat('a',64)));
    }
    $db->beginTransaction();
    try { expectEnrollmentError(LogicException::class,fn()=>enrollmentInitializationPreview($db,$f['admin'],1)); }
    finally { $db->rollBack(); }
    $preview=enrollmentInitializationPreview($db,$f['admin'],1);
    $db->exec('UPDATE users SET is_active=2,session_token=NULL WHERE id=1');
    $before=cutoverState($db);
    expectEnrollmentError(DomainException::class,fn()=>enrollmentInitializationPreview($db,$f['admin'],1));
    expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$preview['fingerprint']));
    [$exit]=cutoverCli($f,['--initialize=1','--fingerprint='.$preview['fingerprint'],'--actor-id=1']);
    checkEnrollment($exit!==0 && $before===cutoverState($db),'Archived administrator cannot initialize through CLI or service and leaves all state unchanged');
    $db->exec('UPDATE users SET is_active=0 WHERE id=1');
    expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$preview['fingerprint']));
    $db->exec('UPDATE users SET is_active=1 WHERE id=1');
    $_SESSION=['user_id'=>1,'session_token'=>'obsolete-session'];
    try { expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$preview['fingerprint'])); }
    finally { unset($_SESSION); }
    checkEnrollment(!enrollmentModelEnabled($db),'Authority failures preserve disabled model');
});

foreach ([
    "UPDATE students SET previous_school_year='2025-2026' WHERE id=101",
    'UPDATE school_years SET version=version+1 WHERE id=1',
    'UPDATE student_enrollments SET version=version+1 WHERE id=1001',
    'UPDATE enrollment_sections SET version=version+1 WHERE id=2001',
    'UPDATE sections SET adviser_id=8 WHERE id=10',
    'UPDATE attendance SET enrollment_id=NULL,assignment_id=NULL WHERE id=501',
] as $change) {
    withEnrollmentFixture(function(array $f) use($change): void {
        $db=$f['db']; $preview=enrollmentInitializationPreview($db,$f['admin'],1);
        $db->exec($change); $before=cutoverState($db);
        expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$preview['fingerprint']));
        checkEnrollment($before===cutoverState($db),'Stale legacy or managed state rejects atomically');
    });
}

withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; $db->exec('UPDATE attendance SET enrollment_id=NULL,assignment_id=NULL WHERE id=501');
    $p=enrollmentInitializationPreview($db,$f['admin'],1);
    checkEnrollment(!$p['ready'] && count($p['blocking_issues'])>0,'Unresolved current mapping blocks initialization preview');
    expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']));
    checkEnrollment(!enrollmentModelEnabled($db),'Blocked current mapping leaves mode disabled');
});

withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];
    $db->exec("INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(1101,101,2,'active','2027-06-01','2028-03-31'); INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(1101,20,'Grade 4','2027-06-01','2028-03-31')");
    $p=enrollmentInitializationPreview($db,$f['admin'],1);
    checkEnrollment(!$p['ready'],'Active future enrollment cannot be silently normalized by bootstrap');
    $before=cutoverState($db);
    expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']));
    checkEnrollment($before===cutoverState($db),'Invalid future state preserves all data');
    $db->exec("UPDATE student_enrollments SET state='prepared' WHERE id=1101");
    $p=enrollmentInitializationPreview($db,$f['admin'],1);
    checkEnrollment($p['ready'],'Valid future prepared enrollment permits initial cutover');
    initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']);
    checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id=1101')->fetchColumn()==='prepared','Future annual remains prepared');
    $activation=schoolYearActivationPreview($db,$f['admin'],2);
    activateSchoolYear($db,$f['admin'],2,$activation['fingerprint']);
    $after=cutoverState($db);
    initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']);
    checkEnrollment($after===cutoverState($db) && getActiveSchoolYear($db)['id']===2,'Original replay after activation never reopens year or rewrites projections');
    expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],2,$p['fingerprint']));
});

withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; $p=enrollmentInitializationPreview($db,$f['admin'],1); $before=cutoverState($db);
    $db->exec("CREATE TRIGGER cutover_fail_audit BEFORE INSERT ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic cutover audit failure'");
    expectEnrollmentError(PDOException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']));
    checkEnrollment($before===cutoverState($db),'Audit failure rolls back initialization, projections, settings, and audit');
    $db->exec('DROP TRIGGER cutover_fail_audit');
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    expectEnrollmentError(DomainException::class,fn()=>initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']));
    expectEnrollmentError(DomainException::class,fn()=>enrollmentInitializationPreview($db,$f['admin'],1));
});

echo 'Enrollment cutover tests passed ('.($GLOBALS['enrollment_checks']??0)." checks).\n";
