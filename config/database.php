<?php
date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/deployment_security.php';
require_once __DIR__ . '/hosting_environment.php';
try { loadHostingEnvironment(dirname(__DIR__, 2) . '/attendance-private/hosting.php'); }
catch (Throwable $error) { deploymentUnavailable('private_hosting_configuration_failed'); }
$production = deploymentIsProduction();
$dbConfig = [
    'host'=>deploymentEnvironmentValue('MYSQLHOST','DB_HOST') ?? ($production ? '' : 'localhost'),
    'port'=>deploymentEnvironmentValue('MYSQLPORT','DB_PORT') ?? '3306',
    'user'=>deploymentEnvironmentValue('MYSQLUSER','DB_USER') ?? ($production ? '' : 'root'),
    'password'=>deploymentEnvironmentValue('MYSQLPASSWORD','DB_PASS') ?? '',
    'database'=>deploymentEnvironmentValue('MYSQLDATABASE','DB_NAME') ?? ($production ? '' : 'attendance_system'),
    'base_url'=>getenv('BASE_URL') ?: ($production ? '' : 'http://localhost/attendance_system/'),
    'tls_ca'=>getenv('DB_TLS_CA') ?: '',
];
try { validateDatabaseConfiguration($dbConfig, $production); }
catch (Throwable $error) { deploymentUnavailable($error->getMessage()); }
define('DB_HOST', $dbConfig['host']); define('DB_PORT', $dbConfig['port']);
define('DB_USER', $dbConfig['user']); define('DB_PASS', $dbConfig['password']);
define('DB_NAME', $dbConfig['database']); define('DB_CHARSET', 'utf8mb4');
define('DB_TLS_CA', $dbConfig['tls_ca']); define('BASE_URL', $dbConfig['base_url']);
define('BASE_PATH', dirname(__DIR__) . '/');
unset($dbConfig, $production);
requireProductionHTTPS();
function getDB(bool $throwOnFailure = false): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $connection = new PDO($dsn, DB_USER, DB_PASS, databasePDOOptions(DB_TLS_CA));
            // Match PHP's school timezone for NOW()/TIMESTAMP audit and activity values.
            $connection->exec("SET time_zone = '+08:00'");
            if (DB_TLS_CA !== '') {
                $tls = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
                if (!$tls || empty($tls['Value'])) throw new RuntimeException('Database transport is not encrypted');
            }
            $pdo = $connection;
        } catch (Throwable $error) {
            if ($throwOnFailure) throw $error;
            deploymentUnavailable('database_connection_failed');
        }
    }
    return $pdo;
}
