<?php
function deploymentIsProduction(): bool {
    $mode = strtolower(getenv('APP_ENV') ?: '');
    if ($mode !== '') return !in_array($mode, ['development','local','test','testing'], true);
    if (getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_ENVIRONMENT_NAME')) return true;
    if (PHP_SAPI === 'cli') return false;
    $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    return !(in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
        && in_array($host, ['localhost','127.0.0.1','::1'], true)
        && empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && empty($_SERVER['HTTP_X_FORWARDED_HOST']));
}
function deploymentUnavailable(string $reason): never {
    $id = bin2hex(random_bytes(12));
    error_log('Service unavailable request_id=' . $id . ' reason=' . $reason);
    http_response_code(503);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Service unavailable. Reference: $id\n"); exit(1); }
    header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); header('Retry-After: 30');
    echo json_encode(['error'=>true,'message'=>'Service unavailable. Please contact the administrator.','request_id'=>$id]);
    exit;
}
function deploymentEnvironmentValue(string $primary, string $fallback): ?string {
    foreach ([$primary,$fallback] as $name) { $value = getenv($name); if ($value !== false && $value !== '') return $value; }
    return null;
}
function validateDatabaseConfiguration(array $config, bool $production): void {
    if ($production) {
        foreach (['host','user','password','database','base_url'] as $field) if (!is_string($config[$field] ?? null) || $config[$field] === '') throw new RuntimeException('Missing production ' . $field);
        if (strtolower($config['user']) === 'root') throw new RuntimeException('Production database user must be unprivileged');
        $url = parse_url($config['base_url']);
        if (!$url || !filter_var($config['base_url'], FILTER_VALIDATE_URL) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) throw new RuntimeException('Production BASE_URL must be a canonical HTTPS URL');
    }
    if (!preg_match('/^[a-zA-Z0-9_.:\-]+$/D', $config['host'] ?? '') || !preg_match('/^[a-zA-Z0-9_\-]+$/D', $config['database'] ?? '')
        || !filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]])) throw new RuntimeException('Invalid database address');
    if (!in_array(strtolower($config['host']), ['localhost','127.0.0.1','::1'], true) && empty($config['tls_ca'])) throw new RuntimeException('Remote database requires verified TLS');
    if (!empty($config['tls_ca']) && !is_readable($config['tls_ca'])) throw new RuntimeException('Database CA bundle is unavailable');
}
function databasePDOOptions(string $tlsCA): array {
    $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
    if ($tlsCA !== '') {
        if (!defined('PDO::MYSQL_ATTR_SSL_CA') || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) throw new RuntimeException('Verified database TLS is unsupported');
        $options[PDO::MYSQL_ATTR_SSL_CA] = $tlsCA; $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    return $options;
}
function requireProductionHTTPS(): void {
    if (!deploymentIsProduction() || PHP_SAPI === 'cli') return;
    $direct = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $trusted = array_filter(array_map('trim', explode(',', getenv('TRUSTED_PROXY_IPS') ?: '')), static fn($ip)=>filter_var($ip,FILTER_VALIDATE_IP)!==false);
    $proxied = in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if ($direct || $proxied) return;
    if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true)) { header('Location: ' . BASE_URL, true, 308); exit; }
    http_response_code(403); header('Cache-Control: no-store'); exit('HTTPS is required.');
}
