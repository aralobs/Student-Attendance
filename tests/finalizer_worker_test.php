<?php
$root=dirname(__DIR__);$fixture=__DIR__.'/.finalizer-worker-'.bin2hex(random_bytes(6));$checks=0;
foreach(['','/attendance','/includes','/storage','/storage/private'] as $dir)mkdir($fixture.$dir);
try {
    copy($root.'/attendance/finalize_absences.php',$fixture.'/attendance/finalize_absences.php');
    file_put_contents($fixture.'/includes/attendance_finalizer.php', <<<'PHP'
<?php
date_default_timezone_set('Asia/Manila');
$case=$argv[1];$state=['from'=>null,'to'=>null,'cursor'=>null];
class WorkerDB {
    function query($sql){return $this;}
    function fetchColumn(){return $GLOBALS['case']==='empty'?false:date('Y-m-d',strtotime('-60 days'));}
}
function getDB(){return new WorkerDB;}
function getSetting($key){return $GLOBALS['case']==='progress'?date('Y-m-d',strtotime('-10 days')):null;}
function finalizeAttendanceRange($from,$to){if($GLOBALS['case']==='failure')throw new RuntimeException('SECRET database error');$GLOBALS['state']['from']=$from;$GLOBALS['state']['to']=$to;return 3;}
function updateSetting($key,$value){$GLOBALS['state']['cursor']=$value;}
register_shutdown_function(function(){file_put_contents(__DIR__.'/../state.json',json_encode($GLOBALS['state']));});
PHP);
    foreach(['initial','progress','empty','failure','locked'] as $case){
        $lock=null;
        if($case==='locked'){$lock=fopen($fixture.'/storage/private/attendance-finalizer.lock','c');flock($lock,LOCK_EX);}
        $process=proc_open([PHP_BINARY,$fixture.'/attendance/finalize_absences.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        if($lock){flock($lock,LOCK_UN);fclose($lock);}
        if($exit!==($case==='failure'?1:0))throw new RuntimeException('Wrong worker exit status');$checks++;
        $state=json_decode(file_get_contents($fixture.'/state.json'),true);
        if($case==='failure'){
            if(str_contains($out.$err,'SECRET')||!preg_match('/Reference: [a-f0-9]{24}/',$err)||$state['cursor']!==null)throw new RuntimeException('Failed job leaked details or advanced cursor');$checks++;
        } elseif($case==='locked') {
            if(!str_contains($out,'already running')||$state['cursor']!==null)throw new RuntimeException('Overlapping worker was not skipped');$checks++;
        } elseif($case==='empty') {
            if($state['from']!==null||$state['cursor']!==(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d'))throw new RuntimeException('Empty worker behavior wrong');$checks++;
        } else {
            $days=(new DateTimeImmutable($state['from']))->diff(new DateTimeImmutable($state['to']))->days+1;
            if($days>31||$state['cursor']!==$state['to'])throw new RuntimeException('Catch-up bound/cursor incorrect');$checks++;
            if($case==='initial'&&$days!==31)throw new RuntimeException('Initial catch-up was not bounded');$checks++;
        }
    }
    echo "Finalizer worker checks passed ($checks checks; bounded batches, failure cursor and overlap protection; no school DB).\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($fixture);
}
