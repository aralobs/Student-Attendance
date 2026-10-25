<?php
declare(strict_types=1);

/** Copy a synthetic installation without private configuration, uploads or vendors.
 * Only the database binding and external providers are replaced. Real page,
 * authorization, session, CSRF, enrollment and rendering code remains installed.
 */
function createEnrollmentAppFixture(string $destination): void
{
    if (file_exists($destination)) throw new RuntimeException('Fixture destination must be new');
    mkdir($destination,0700,true);
    $root=dirname(__DIR__,2);
    foreach (['includes','students','sections','settings','attendance','reports','migrations'] as $dir) {
        mkdir($destination.'/'.$dir);
        foreach (glob($root.'/'.$dir.'/*.php') as $file) copy($file,$destination.'/'.$dir.'/'.basename($file));
    }
    foreach (['config','sessions'] as $dir) mkdir($destination.'/'.$dir);
    $privatePhotos=$destination.'-photos';
    if (file_exists($privatePhotos)) throw new RuntimeException('Fixture photo destination must be new');
    mkdir($privatePhotos,0700);
    foreach (['schedule_rules.php'] as $file) copy($root.'/config/'.$file,$destination.'/config/'.$file);
    foreach (['heartbeat.php','student_photo.php','scan_process.php','dashboard.php','finalize_absences.php','scanner.php','index.php'] as $file) {
        if (is_file($root.'/'.$file)) copy($root.'/'.$file,$destination.'/'.$file);
    }
    // Only repository assets, never uploaded school photos or private files.
    $assets=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/assets',FilesystemIterator::SKIP_DOTS));
    foreach ($assets as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative=substr($file->getPathname(),strlen($root)+1);
        $target=$destination.'/'.$relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target),0700,true);
        copy($file->getPathname(),$target);
    }
    file_put_contents($destination.'/config/database.php', <<<'PHP'
<?php
date_default_timezone_set('Asia/Manila');
$fixtureName=getenv('MYSQLDATABASE');
if (!is_string($fixtureName) || !preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$fixtureName)) throw new RuntimeException('Synthetic database required');
define('BASE_URL','http://127.0.0.1:18811/'); define('BASE_PATH',dirname(__DIR__).'/');
ini_set('error_log',dirname(__DIR__).'/errors.log'); ini_set('session.save_path',dirname(__DIR__).'/sessions');
putenv('APP_ENV=testing'); putenv('STUDENT_PHOTO_DIR='.dirname(__DIR__).'-photos');
function getDB(bool $throwOnFailure=false): PDO {
    static $db;
    if (!$db) {
        $db=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.getenv('MYSQLDATABASE').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $db->exec("SET time_zone='+08:00'");
    }
    return $db;
}
if (PHP_SAPI==='cli' && isset($argv[1]) && str_starts_with($argv[1],'fixture:')) {
    $request=json_decode(base64_decode(substr($argv[1],8)),true,512,JSON_THROW_ON_ERROR);
    $_SERVER['REQUEST_METHOD']=$request['method']??'GET'; $_POST=$request['post']??[]; $_GET=$request['get']??[];
    $_SERVER['HTTP_HOST']='127.0.0.1:18811'; $_SERVER['REMOTE_ADDR']='127.0.0.1';
    require_once dirname(__DIR__).'/includes/session_security.php'; startSession();
    $_SESSION=['user_id'=>$request['actor']['id'],'role'=>$request['actor']['role'],'full_name'=>'Synthetic User','username'=>'synthetic','session_token'=>'synthetic-fixture-session-'.$request['actor']['id'],'authenticated_at'=>time(),'last_activity_at'=>time(),'app_csrf'=>str_repeat('a',64)];
    http_response_code(200);
    register_shutdown_function(function(){ echo "\nRESULT:".json_encode(['status'=>http_response_code(),'flash'=>$_SESSION['flash']??null]); });
}
PHP);
    $functions=file_get_contents($destination.'/includes/functions.php');
    $start=strpos($functions,'function sendSMS('); $end=strpos($functions,'function buildSMSMessage(',$start);
    if ($start===false || $end===false) throw new RuntimeException('Provider stub boundaries changed');
    $stub= <<<'PHP'
function sendSMS(string $number,string $message,int $studentId,string $type,?string &$deliveryStatus=null): bool {
    file_put_contents(dirname(__DIR__).'/provider-calls.log',"sms\n",FILE_APPEND); $deliveryStatus='sent'; return true;
}

PHP;
    file_put_contents($destination.'/includes/functions.php',substr($functions,0,$start).$stub.substr($functions,$end));
    file_put_contents($destination.'/includes/mail_helper.php', <<<'PHP'
<?php
function sendArrivalEmail(array $student,...$args): bool { file_put_contents(dirname(__DIR__).'/provider-calls.log',"email\n",FILE_APPEND); return true; }
function sendDepartureEmail(array $student,...$args): bool { file_put_contents(dirname(__DIR__).'/provider-calls.log',"email\n",FILE_APPEND); return true; }
function sendEmail(...$args): array { file_put_contents(dirname(__DIR__).'/provider-calls.log',"email\n",FILE_APPEND); return ['success'=>true]; }
PHP);
    file_put_contents($destination.'/includes/UniSms.php','<?php class UniSms { public function __construct(...$args) { throw new RuntimeException("External provider forbidden in fixture"); } }');
    file_put_contents($destination.'/includes/calendarific.php','<?php function fetchCalendarificHolidays(...$args) { throw new RuntimeException("External provider forbidden in fixture"); }');
    file_put_contents($destination.'/fixture-login.php', <<<'PHP'
<?php
require_once __DIR__.'/config/database.php'; require_once __DIR__.'/includes/functions.php';
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) { http_response_code(403); exit; }
$id=match($_GET['role']??'admin') {'admin'=>1,'teacher'=>7,'scanner'=>9,default=>0};
$q=getDB()->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $user=$q->fetch();
if (!$user) { http_response_code(403); exit; }
startSession(); $_SESSION=['user_id'=>$id,'role'=>$user['role'],'username'=>$user['username'],'full_name'=>$user['full_name']]; registerSession($id);
header('Location: /settings/school_years.php');
PHP);
}

/** Delete only a caller-created synthetic copy, never the source installation. */
function removeEnrollmentAppFixture(string $path): void
{
    $resolved=realpath($path);
    if ($resolved===false || !preg_match('~(?:^|[/\\\\])enrollment_app_[0-9a-f]{16}$~',$resolved)) throw new RuntimeException('Unsafe fixture cleanup');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($resolved);
    removeEnrollmentAppFixturePhotos($resolved);
}

/** Private sibling belongs to this exact generated copy, including hot-fixed
 * browser fixtures whose original owner loaded an older cleanup function.
 */
function removeEnrollmentAppFixturePhotos(string $copy): void
{
    if (!preg_match('~(?:^|[/\\\\])enrollment_app_[0-9a-f]{16}$~',$copy)) throw new RuntimeException('Unsafe fixture photo cleanup');
    $path=$copy.'-photos';
    if (!file_exists($path)) return;
    $parent=realpath(dirname($copy)); $resolved=realpath($path);
    if (!$parent || !$resolved || is_link($path) || dirname($resolved)!==$parent || basename($resolved)!==basename($copy).'-photos') throw new RuntimeException('Unsafe private photo path');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname());
    }
    rmdir($resolved);
}

function runEnrollmentAppEndpoint(string $copy,array $f,string $endpoint,array $actor,array $post=[],array $get=[],string $method='POST'): array
{
    $post+=['csrf_token'=>str_repeat('a',64)];
    $payload='fixture:'.base64_encode(json_encode(compact('actor','post','get','method'),JSON_THROW_ON_ERROR));
    $env=getenv(); $env['MYSQLDATABASE']=$f['database'];
    $p=proc_open([PHP_BINARY,$copy.'/'.$endpoint,$payload],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname($copy.'/'.$endpoint),$env);
    if (!is_resource($p)) throw new RuntimeException('Fixture subprocess unavailable');
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
    checkEnrollment($exit===0 && $err==='' && preg_match('/RESULT:(.*)$/',$out,$match)===1,'Copied endpoint must finish without PHP errors: '.$endpoint);
    return json_decode($match[1],true,512,JSON_THROW_ON_ERROR)+['body'=>$out];
}
