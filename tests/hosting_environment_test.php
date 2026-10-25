<?php
require_once __DIR__ . '/../config/hosting_environment.php';
$fixture = __DIR__ . '/.hosting-' . bin2hex(random_bytes(6)) . '.php';
$keys = ['APP_ENV', 'DB_HOST', 'DB_PASS'];
$original = [];
foreach ($keys as $key) { $original[$key] = getenv($key); putenv($key); }
function hostingCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
try {
    loadHostingEnvironment($fixture);
    hostingCheck(getenv('APP_ENV') === false, 'Missing file changed the environment');
    putenv('DB_HOST=server-environment');
    file_put_contents($fixture, '<?php return ' . var_export([
        'APP_ENV' => 'production', 'DB_HOST' => 'file-host', 'DB_PASS' => 'synthetic=$password',
    ], true) . ';');
    loadHostingEnvironment($fixture);
    hostingCheck(getenv('APP_ENV') === 'production', 'Production setting not loaded');
    hostingCheck(getenv('DB_HOST') === 'server-environment', 'Server environment overwritten');
    hostingCheck(getenv('DB_PASS') === 'synthetic=$password', 'Credential characters changed');
    foreach ([['APP_ENV' => 'development', 'PATH' => 'bad'], ['DB_PASS' => [1]], ['DB_PASS' => "bad\0value"], 'bad'] as $values) {
        file_put_contents($fixture, '<?php return ' . var_export($values, true) . ';');
        $rejected = false;
        try { loadHostingEnvironment($fixture); } catch (RuntimeException $expected) { $rejected = true; }
        hostingCheck($rejected, 'Invalid configuration accepted');
        hostingCheck(getenv('APP_ENV') === 'production', 'Invalid configuration partially loaded');
    }
    echo "Hosting configuration checks passed (no database or real credentials).\n";
} finally {
    if (is_file($fixture)) unlink($fixture);
    foreach ($original as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
}
