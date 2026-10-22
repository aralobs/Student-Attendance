<?php
// Exercise the real endpoint in isolated processes, without a database.
$endpoint = dirname(__DIR__) . '/register.php';
foreach (['GET', 'POST'] as $method) {
    $code = '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';'
        . '$_POST = ["username" => "registration-test", "role" => "admin",'
        . '"full_name" => "Registration Test", "password" => "test-password",'
        . '"password_confirm" => "test-password"];'
        . 'register_shutdown_function(function () {'
        . 'echo "\nRESULT:" . json_encode(["status" => http_response_code(),'
        . '"database_loaded" => function_exists("getDB")]); });'
        . 'require ' . var_export($endpoint, true) . ';';
    $process = proc_open([PHP_BINARY, '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start test process.');
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $errors !== '' || !preg_match('/RESULT:(.*)$/', $output, $match)) {
        throw new RuntimeException("$method failed: $errors $output");
    }
    $result = json_decode($match[1], true);
    if ($result['status'] !== 403 || $result['database_loaded']
        || !str_contains($output, 'Public registration is disabled.')) {
        throw new RuntimeException("$method did not block public registration before database access.");
    }
}
echo "Public registration checks passed (GET and POST blocked).\n";
