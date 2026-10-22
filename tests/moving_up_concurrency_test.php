<?php
require_once __DIR__.'/support/enrollment_fixture.php';
if (is_file(__DIR__.'/../includes/moving_up.php')) require_once __DIR__.'/../includes/moving_up.php';
if (($argv[1] ?? '')==='worker') {
    $input=json_decode(base64_decode($argv[2]),true,512,JSON_THROW_ON_ERROR);
    if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$input['database'])) throw new RuntimeException('Unsafe database');
    $db=new PDO('mysql:host='.(getenv('MYSQLHOST') ?: '127.0.0.1').';port='.(getenv('MYSQLPORT') ?: '3307').';dbname='.$input['database'].';charset=utf8mb4',getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root',getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='+00:00'"); // Parent import uses +08:00; preview must survive connection differences.
    echo "READY\n"; flush();
    if (trim(fgets(STDIN))!=='GO') throw new RuntimeException('Barrier failed');
    try { $s=prepareMovingUp($db,['id'=>1,'role'=>'admin'],$input['command'],$input['key'],$input['fingerprint']); echo json_encode(['ok'=>true,'saved'=>$s]); }
    catch (DomainException $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
    exit;
}
withEnrollmentFixture(function($f) {
    checkEnrollment(function_exists('previewMovingUp') && function_exists('prepareMovingUp'),'Moving-up atomic batch service is missing');
    $db=$f['db']; $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES ('enrollment_model_version','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $command=['source_year_id'=>1,'source_section_id'=>10,'student_ids'=>[101],'target_year_id'=>2,'outcome'=>'promoted','destination_section_id'=>20];
    $p=previewMovingUp($db,$f['admin'],$command); $workers=[];
    try {
        for ($i=0;$i<2;$i++) {
            $payload=base64_encode(json_encode(['database'=>$f['database'],'command'=>$command,'key'=>'race-'.$i,'fingerprint'=>$p['fingerprint']]));
            $process=proc_open([PHP_BINARY,__FILE__,'worker',$payload],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            checkEnrollment(is_resource($process),'Concurrency worker starts'); $workers[]=[$process,$pipes];
        }
        foreach ($workers as [$process,$pipes]) checkEnrollment(trim(fgets($pipes[1]))==='READY','Independent PDO worker reached explicit barrier');
        foreach ($workers as [$process,$pipes]) { fwrite($pipes[0],"GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $success=0; $outcomes=[];
        foreach ($workers as [$process,$pipes]) {
            $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
            checkEnrollment($exit===0 && $errors==='','Concurrency worker completed cleanly: '.$errors);
            $result=json_decode($output,true,512,JSON_THROW_ON_ERROR); $success+=(int)$result['ok']; $outcomes[]=$result['ok'] ? 'prepared' : ($result['error'] ?? 'domain conflict');
        }
        $workers=[];
        checkEnrollment($success===1,'Concurrent batches have exactly one winner: '.json_encode($outcomes));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=101 AND school_year_id=2')->fetchColumn()===1,'Exactly one destination enrollment');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_decisions')->fetchColumn()===1 && (int)$db->query('SELECT COUNT(*) FROM moving_up_batches')->fetchColumn()===1,'Losing batch rolls back metadata and decisions');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Concurrent preparation sends zero notifications');
    } finally { foreach($workers as [$process,$pipes]) { proc_terminate($process); foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process); } }
});
echo 'Moving-up barrier concurrency checks passed ('.$GLOBALS['enrollment_checks'].").\n";
