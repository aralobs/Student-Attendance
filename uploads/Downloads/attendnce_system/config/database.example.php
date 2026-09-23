<?php
/**
 * Database Configuration
 * Automated Student Attendance Monitoring System
 * San Pablo City Central School - Kindergarten Department
 */
// Set Philippine timezone globally
date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', 'chupapikerby');
define('DB_NAME', 'attendance_system');
define('DB_CHARSET', 'utf8mb4');

// Base URL - adjust if not in root
define('BASE_URL', 'http://localhost/attendance_system/');
define('BASE_PATH', dirname(__DIR__) . '/');

/**
 * Create and return a PDO connection (singleton pattern)
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die(json_encode([
                'error' => true,
                'message' => 'Database connection failed. Check config/database.php'
            ]));
        }
    }
    return $pdo;
}