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

// Read from environment variables (Railway) with local fallbacks (XAMPP)
define('DB_HOST', getenv('MYSQLHOST')     ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_PORT', getenv('MYSQLPORT')     ?: (getenv('DB_PORT') ?: '3306'));
define('DB_USER', getenv('MYSQLUSER')     ?: (getenv('DB_USER') ?: 'root'));
define('DB_PASS', getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''));
define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'attendance_system'));
define('DB_CHARSET', 'utf8mb4');

// Base URL — set BASE_URL env var in Railway dashboard, fallback to localhost for dev
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/attendance_system/');
define('BASE_PATH', dirname(__DIR__) . '/');

/**
 * Create and return a PDO connection (singleton pattern)
 */
function getDB()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
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
