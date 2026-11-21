<?php
// Exercise the real login route with isolated sessions and synthetic accounts.
$root=dirname(__DIR__);
$fixture=__DIR__.'/.login-csrf-'.bin2hex(random_bytes(6));
foreach (['','/config','/includes','/sessions'] as $directory) mkdir($fixture.$directory);
$checks=0;
function loginCheck(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    copy($root.'/index.php',$fixture.'/index.php');
    copy($root.'/includes/browser_security.php',$fixture.'/includes/browser_security.php');
    file_put_contents($fixture.'/config/database.php', "<?php define('BASE_URL','https://example.test/');");
    file_put_contents($fixture.'/includes/functions.php','<?php require_once '.var_export($root.'/includes/csrf.php',true).';'. <<<'PHP'
function startSession():void { if(session_status()!==PHP_SESSION_ACTIVE) session_start(); }
function getSetting($key){return 'Synthetic School';}
function loginAbuseBuckets($name):array{return [];}
function enforceAbuseLimits(...$args):void{}
function abuseBucket(...$args):array{return [];}
function securityAuditContext(...$args):void{}
function registerSession($id):string { unset($_SESSION['app_csrf']); return 'synthetic-auth-token'; }
function getDB(){ $GLOBALS['dbReads']++; return new LoginFixtureDB; }
class LoginFixtureDB { function prepare($sql){return new LoginFixtureStatement;} }
class LoginFixtureStatement {
    function execute($args){}
    function fetch(){return ['id'=>1,'username'=>'fixture','full_name'=>'Synthetic Staff','role'=>$GLOBALS['case']==='scanner'?'scanner_operator':'admin','password'=>password_hash('FixturePassword123',PASSWORD_DEFAULT)];}
}
PHP);
    file_put_contents($fixture.'/request.php', <<<'PHP'
<?php
$GLOBALS['case']=$argv[1]; $GLOBALS['dbReads']=0;
http_response_code(200);
session_start();
$_SESSION=['app_csrf'=>str_repeat('a',64)];
$_SERVER['REQUEST_METHOD']=$argv[1]==='get'?'GET':'POST';
$_POST=['username'=>'fixture','password'=>'FixturePassword123','csrf_token'=>str_repeat('a',64)];
if($argv[1]==='missing')unset($_POST['csrf_token']);
if($argv[1]==='wrong'||$argv[1]==='other_session')$_POST['csrf_token']=str_repeat('b',64);
if($argv[1]==='array')$_POST['csrf_token']=['bad'];
if($argv[1]==='wrong_password')$_POST['password']='incorrect';
register_shutdown_function(function(){echo "\nRESULT:".json_encode(['status'=>http_response_code(),'dbReads'=>$GLOBALS['dbReads'],'user'=>$_SESSION['user_id']??null,'csrf'=>$_SESSION['app_csrf']??null]);});
PHP);
    foreach(['missing','wrong','array','other_session','get','wrong_password','valid','scanner'] as $case){
        $process=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-d','auto_prepend_file='.$fixture.'/request.php',$fixture.'/index.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        loginCheck(proc_close($process)===0&&$err==='',"Execution failed for $case: $err");
        loginCheck((bool)preg_match('/RESULT:(.*)$/',$out,$match),"Missing result for $case");
        $result=json_decode($match[1],true);
        if(in_array($case,['missing','wrong','array','other_session'],true)){
            loginCheck($result['status']===403,"$case must reject the login submission");
            loginCheck($result['dbReads']===0&&$result['user']===null,"$case reached credential processing");
        }elseif($case==='get'){
            loginCheck((bool)preg_match('/name="csrf_token" value="a{64}"/',$out),'Login form lacks its session token');
        }elseif($case==='wrong_password'){
            loginCheck($result['status']===200&&$result['user']===null,'Wrong password accepted');
            loginCheck(str_contains($out,'Invalid username or password.'),'Wrong password message lost');
        }else{
            loginCheck($result['status']===302&&$result['user']===1,"$case valid login failed");
            loginCheck($result['csrf']===null,'Pre-authentication token survived login');
        }
    }
    echo "Login CSRF checks passed ($checks checks; isolated sessions, no database).\n";
}finally{
    foreach(['/sessions','/config','/includes',''] as $directory){foreach(glob($fixture.$directory.'/*')?:[] as $file)if(is_file($file))unlink($file);rmdir($fixture.$directory);}
}
