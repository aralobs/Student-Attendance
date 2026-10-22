<?php
// Isolated subprocesses use the actual session helper and temporary PHP sessions.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.session-security-' . bin2hex(random_bytes(6));
mkdir($fixture); mkdir($fixture . '/sessions');
$script = <<<'PHP'
<?php
$case = $argv[1]; http_response_code(200);
$_SERVER['HTTP_HOST'] = 'example.test'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_ACCEPT'] = $case === 'query_failure_json' ? 'application/json' : 'text/html';
if (in_array($case, ['local_cookie', 'production_cookie', 'spoofed_forwarded'], true)) {
    $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['HTTPS'] = 'off';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
}
if ($case === 'spoofed_forwarded') $_SERVER['REMOTE_ADDR'] = '192.0.2.123';
putenv('APP_ENV=' . ($case === 'production_cookie' ? 'production' : 'development'));
define('BASE_URL', in_array($case,['local_cookie','production_cookie','spoofed_forwarded'],true) ? 'http://localhost/' : 'https://example.test/');
class SessionStatement {
    function __construct(public string $sql) {}
    function execute($params = []) {
        if ($GLOBALS['case'] === 'query_failure' || $GLOBALS['case'] === 'query_failure_json') throw new RuntimeException('SECRET database error');
        if (str_starts_with($this->sql, 'UPDATE')) {
            if ($GLOBALS['case'] === 'touch_failure') throw new RuntimeException('SECRET activity error');
            $GLOBALS['updates'][] = [$this->sql, $params];
        }
    }
    function fetch() {
        if ($GLOBALS['case'] === 'inactive') return false;
        $role = str_starts_with($GLOBALS['case'], 'scanner') ? 'scanner_operator' : 'teacher';
        if ($GLOBALS['case'] === 'role_changed') $role = 'admin';
        if ($GLOBALS['case'] === 'unknown_role') $role = 'unexpected';
        return ['session_token' => $GLOBALS['case'] === 'taken' ? 'different-token' : ($GLOBALS['case'] === 'revoked' ? null : 'synthetic-token'), 'role' => $role];
    }
}
class SessionDB { function prepare($sql) { return new SessionStatement($sql); } }
function getDB(bool $throwOnFailure = false) {
    $GLOBALS['throwRequested'] = $throwOnFailure;
    if ($GLOBALS['case'] === 'connection_failure') throw new RuntimeException('SECRET connection failure');
    return new SessionDB;
}
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(['status'=>http_response_code(), 'reason'=>$GLOBALS['reason']??null,
        'cookie'=>session_get_cookie_params(), 'strict'=>ini_get('session.use_strict_mode'),
        'cookiesOnly'=>ini_get('session.use_only_cookies'), 'transSid'=>ini_get('session.use_trans_sid'),
        'updates'=>$GLOBALS['updates']??[], 'last'=>$_SESSION['last_activity_at']??null,
        'before'=>$GLOBALS['before']??null, 'throwRequested'=>$GLOBALS['throwRequested']??null,
        'sessionId'=>session_id()]);
});
PHP;
$script .= 'require ' . var_export($root . '/includes/session_security.php', true) . ';' . <<<'PHP'

if ($case === 'fixation') session_id('attacker-chosen-session-id');
startSession();
$now = time();
$_SESSION = ['user_id'=>7, 'session_token'=>'synthetic-token',
    'role'=>str_starts_with($case,'scanner') ? 'scanner_operator' : 'teacher',
    'authenticated_at'=>$now-600, 'last_activity_at'=>$now-100];
if ($case === 'missing') unset($_SESSION['user_id']);
if ($case === 'legacy') unset($_SESSION['authenticated_at']);
if ($case === 'future') $_SESSION['last_activity_at'] = $now+100;
if ($case === 'idle') { $_SESSION['authenticated_at']=$now-2000; $_SESSION['last_activity_at']=$now-1800; }
if ($case === 'absolute') { $_SESSION['authenticated_at']=$now-28800; $_SESSION['last_activity_at']=$now-1; }
if ($case === 'scanner_valid') { $_SESSION['authenticated_at']=$now-15000; $_SESSION['last_activity_at']=$now-13000; }
if ($case === 'scanner_idle') { $_SESSION['authenticated_at']=$now-15000; $_SESSION['last_activity_at']=$now-14400; }
if ($case === 'scanner_absolute') { $_SESSION['authenticated_at']=$now-43200; $_SESSION['last_activity_at']=$now-1; }
$before = $_SESSION['last_activity_at'];
$reason = checkSession($case !== 'passive');
PHP;
file_put_contents($fixture . '/run.php', $script);
$checks = 0;
function sessionCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    foreach (['valid'=>null,'passive'=>null,'fixation'=>null,'missing'=>'invalid','legacy'=>'invalid','future'=>'invalid','inactive'=>'invalid','taken'=>'session_taken','revoked'=>'invalid','role_changed'=>'invalid','unknown_role'=>'invalid','idle'=>'timeout','absolute'=>'timeout','scanner_valid'=>null,'scanner_idle'=>'timeout','scanner_absolute'=>'timeout','query_failure'=>null,'query_failure_json'=>null,'connection_failure'=>null,'touch_failure'=>null,'local_cookie'=>null,'production_cookie'=>null,'spoofed_forwarded'=>null] as $case=>$expected) {
        $env = getenv(); foreach (['SESSION_IDLE_TIMEOUT','SESSION_ABSOLUTE_TIMEOUT','SCANNER_SESSION_IDLE_TIMEOUT','SCANNER_SESSION_ABSOLUTE_TIMEOUT'] as $key) unset($env[$key]);
        $process = proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions',$fixture.'/run.php',$case], [1=>['pipe','w'],2=>['pipe','w']], $pipes, $fixture, $env);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        sessionCheck($exit === 0 && ($err === '' || preg_match('/^Session validation temporarily unavailable\. request_id=[a-f0-9]{24}$/',trim($err))), "$case execution error: $err");
        sessionCheck((bool)preg_match('/RESULT:(.*)$/',$out,$match), "$case missing result");
        $result = json_decode($match[1],true);
        $failure = in_array($case,['query_failure','query_failure_json','connection_failure','touch_failure'],true);
        sessionCheck($result['status'] === ($failure ? 503 : 200), "$case unexpected HTTP status");
        sessionCheck($result['reason'] === $expected, "$case unexpected validation reason");
        sessionCheck(!str_contains($out,'SECRET'), "$case exposed diagnostics");
        if ($case === 'query_failure_json') sessionCheck(str_contains($out,'"error":"session_unavailable"'), 'AJAX outage not JSON');
        sessionCheck($result['cookie']['httponly'] && $result['cookie']['samesite'] === 'Lax' && $result['cookie']['domain'] === '' && $result['cookie']['lifetime'] === 0, 'Cookie flags wrong');
        sessionCheck($result['cookie']['secure'] === ($case !== 'local_cookie'), 'Secure flag wrong');
        sessionCheck($result['strict'] === '1' && $result['cookiesOnly'] === '1' && $result['transSid'] === '0', 'Strict session startup missing');
        if ($case === 'passive') sessionCheck($result['last'] === $result['before'] && $result['updates'] === [], 'Passive polling refreshed idle activity');
        if ($case === 'fixation') sessionCheck($result['sessionId'] !== 'attacker-chosen-session-id', 'Caller-chosen session ID accepted');
        if ($case === 'valid' || $case === 'scanner_valid') {
            sessionCheck($result['last'] > $result['before'], 'Active request failed to refresh idle activity');
            sessionCheck(count($result['updates']) === 1 && $result['updates'][0][1][2] === 'synthetic-token', 'Activity not bound to the token');
        }
        if ($case !== 'missing') sessionCheck($result['throwRequested'] === true, 'Validation connection must throw for generic failure handling');
    }
    echo "Session security checks passed ($checks checks; isolated sessions, no application DB).\n";
} finally {
    foreach (glob($fixture . '/sessions/*') as $file) unlink($file);
    unlink($fixture . '/run.php'); rmdir($fixture . '/sessions'); rmdir($fixture);
}
