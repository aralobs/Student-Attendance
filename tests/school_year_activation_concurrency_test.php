<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/../includes/school_years.php';
if (($argv[1] ?? '')==='worker') {
    $input=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
    if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$input['database'])) throw new RuntimeException('Unsafe database');
    $db=new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$input['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='+00:00'");
    echo "READY\n"; flush();
    if (trim(fgets(STDIN))!=='GO') throw new RuntimeException('Barrier failed');
    try { activateSchoolYear($db,['id'=>1,'role'=>'admin'],2,$input['fingerprint']); echo json_encode(['ok'=>true]); }
    catch (DomainException $e) { echo json_encode(['ok'=>false]); }
    exit;
}
withEnrollmentFixture(function($f) {
    checkEnrollment(function_exists('schoolYearActivationPreview') && function_exists('activateSchoolYear'),'School-year activation services are missing');
    $db=$f['db']; $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $p=schoolYearActivationPreview($db,$f['admin'],2); $workers=[];
    try {
        for ($i=0;$i<2;$i++) {
            $payload=base64_encode(json_encode(['database'=>$f['database'],'fingerprint'=>$p['fingerprint']]));
            $process=proc_open([PHP_BINARY,__FILE__,'worker',$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            checkEnrollment(is_resource($process),'Concurrency worker starts'); $workers[]=[$process,$pipes];
        }
        foreach ($workers as [$process,$pipes]) checkEnrollment(trim(fgets($pipes[1]))==='READY','Independent PDO worker reached explicit barrier');
        foreach ($workers as [$process,$pipes]) { fwrite($pipes[0],"GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $success=0;
        foreach ($workers as [$process,$pipes]) {
            $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
            checkEnrollment($exit===0 && $errors==='','Concurrency worker completed cleanly: '.$errors);
            $result=json_decode($output,true,512,JSON_THROW_ON_ERROR); $success+=(int)$result['ok'];
        }
        $workers=[];
        checkEnrollment($success===1,'Concurrent activations have exactly one winner');
        checkEnrollment((int)$db->query("SELECT COUNT(*) FROM school_years WHERE state='active'")->fetchColumn()===1 && getActiveSchoolYear($db)['id']===2,'Exactly one active target year');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===2,'Competition invents no target enrollments');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Concurrent activation sends zero notifications');
    } finally { foreach($workers as [$process,$pipes]) { proc_terminate($process); foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process); } }
});
echo 'School-year activation barrier concurrency checks passed ('.$GLOBALS['enrollment_checks'].").\n";
