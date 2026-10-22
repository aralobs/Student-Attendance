<?php
require_once __DIR__ . '/../config/deployment_security.php';
$root=dirname(__DIR__);$fixture=__DIR__.'/.deployment-'.bin2hex(random_bytes(6));mkdir($fixture);$checks=0;$server=null;
function deployCheck(bool $ok,string $message):void {if (!$ok) throw new RuntimeException($message);$GLOBALS['checks']++;}
try {
    $valid=['host'=>'localhost','port'=>'3306','user'=>'school_app','password'=>'SyntheticPassword','database'=>'school_fixture','base_url'=>'https://school.example.test/','tls_ca'=>''];
    validateDatabaseConfiguration($valid,true);$checks++;
    foreach (['host','user','password','database','base_url'] as $field) {
        $bad=$valid;$bad[$field]='';try {validateDatabaseConfiguration($bad,true);throw new LogicException('Missing config accepted');}catch(RuntimeException $expected){$checks++;}
    }
    foreach ([['user'=>'root'],['base_url'=>'http://school.example.test/'],['base_url'=>'https://user:pass@school.example.test/'],
        ['base_url'=>'https://school.example.test/?x=1'],['host'=>'remote.example.test'],['host'=>'localhost;dbname=other'],['port'=>'0'],['database'=>'school;DROP'],['tls_ca'=>$fixture.'/missing.pem']] as $patch) {
        try {validateDatabaseConfiguration(array_replace($valid,$patch),true);throw new LogicException('Unsafe config accepted');}catch(RuntimeException $expected){$checks++;}
    }
    file_put_contents($fixture.'/ca.pem','Synthetic CA option fixture, never used for a connection');
    validateDatabaseConfiguration(array_replace($valid,['host'=>'remote.example.test','tls_ca'=>$fixture.'/ca.pem']),true);$checks++;
    $options=databasePDOOptions($fixture.'/ca.pem');
    deployCheck($options[PDO::MYSQL_ATTR_SSL_CA]===$fixture.'/ca.pem' && $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]===true,'Database TLS verification disabled');
    putenv('APP_ENV=production');deployCheck(deploymentIsProduction(),'Explicit production mode ignored');putenv('APP_ENV=development');deployCheck(!deploymentIsProduction(),'Local mode lost');putenv('APP_ENV');
    $env=getenv();foreach(['MYSQLHOST','MYSQLPORT','MYSQLUSER','MYSQLPASSWORD','MYSQLDATABASE','DB_TLS_CA','TRUSTED_PROXY_IPS'] as $key) unset($env[$key]);
    $env['APP_ENV']='production';$env['DB_HOST']='localhost';$env['DB_PORT']='3306';$env['DB_USER']='school_fixture';
    $env['DB_PASS']='SECRET_FIXTURE_CREDENTIAL';$env['DB_NAME']='school_fixture';$env['BASE_URL']='https://school.example.test/';
    $router='<?php require_once '.var_export($root.'/config/deployment_security.php',true).';' . <<<'PHP'
$case=$_GET['case']??'';
if ($case==='direct'||$case==='missing'||$case==='connection') $_SERVER['HTTPS']='on';
if ($case==='trusted') putenv('TRUSTED_PROXY_IPS=127.0.0.1');
if ($case==='spoofed'||$case==='chain') putenv('TRUSTED_PROXY_IPS=192.0.2.1');
if ($case==='missing') putenv('DB_USER');
if ($case==='connection') putenv('DB_PORT=1');
PHP;
    $router.='if ($case==="worker") require '.var_export($root.'/attendance/finalize_absences.php',true).';';
    $router.='require '.var_export($root.'/config/database.php',true).'; if ($case==="connection") getDB(); echo "ALLOWED";';
    file_put_contents($fixture.'/index.php',$router);
    $socket=stream_socket_server('tcp://127.0.0.1:0');$address=stream_socket_get_name($socket,false);fclose($socket);
    $port=(int)substr(strrchr($address,':'),1);
    $server=proc_open([PHP_BINARY,'-d','error_log='.$fixture.'/private-server.log','-S','127.0.0.1:'.$port,'-t',$fixture],
        [0=>['pipe','r'],1=>['file',$fixture.'/stdout.log','a'],2=>['file',$fixture.'/stderr.log','a']],$pipes,$fixture,$env);
    fclose($pipes[0]);
    for($attempt=0;$attempt<100;$attempt++){ $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,0.05);if($socket){fclose($socket);break;}usleep(20000); }
    deployCheck($attempt<100,'Test server did not start');
    foreach ([['direct','GET','',200],['trusted','GET','https',200],['trusted','POST','https',200],['spoofed','GET','https',308],
        ['spoofed','POST','https',403],['chain','GET','https,http',308],['plain','GET','',308],['plain','POST','',403],['worker','GET','',403],['missing','GET','',503],['connection','GET','',503]] as [$case,$method,$forwarded,$expected]) {
        $headers='Content-Type: application/x-www-form-urlencoded'."\r\n".($forwarded!==''?'X-Forwarded-Proto: '.$forwarded."\r\n":'');
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$method==='POST'?'x=1':'','ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
        $out=file_get_contents('http://127.0.0.1:'.$port.'/?case='.$case,false,$context);
        preg_match('/\s(\d{3})\s/',$http_response_header[0],$match);$code=(int)$match[1];
        deployCheck($code===$expected,"$case/$method returned $code");
        if ($expected===200) deployCheck($out==='ALLOWED','Secure request blocked');
        if ($expected===308) deployCheck(in_array('Location: https://school.example.test/',$http_response_header,true),'Unsafe redirect target');
        if ($expected===503) {
            $data=json_decode($out,true);deployCheck((bool)preg_match('/^[a-f0-9]{24}$/',$data['request_id']??''),'Missing failure correlation ID');
            deployCheck(!str_contains($out,'school_fixture') && !str_contains($out,'SECRET') && !str_contains($out,'SQLSTATE') && !str_contains($out,'debug'),'Database diagnostics leaked');
            deployCheck(str_contains(file_get_contents($fixture.'/private-server.log'),$data['request_id']),'Failure ID missing from private log');
        }
    }
    foreach (['dashboard.php','attendance/attendance.php','attendance/get_today_log.php','reports/daily.php','reports/export_excel.php','reports/sf2.php'] as $file) {
        deployCheck(!preg_match('/\b(?:reconcileAttendanceCompletion|finalizeAttendanceAbsences|finalizeAttendanceRange)\s*\(/',file_get_contents($root.'/'.$file)),"$file still mutates attendance on read");
    }
    echo "Deployment security checks passed ($checks checks; synthetic HTTP server, no school DB connection).\n";
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    putenv('APP_ENV');
    foreach(glob($fixture.'/*')?:[] as $file)unlink($file);rmdir($fixture);
}
