<?php
/**
 * Railway Deployment Debugger
 * DELETE THIS FILE after debugging!
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>1. PHP Info</h2>";
echo "PHP Version: " . phpversion() . "<br>";

echo "<h2>2. Environment Variables</h2>";
echo "BASE_URL env: [" . (getenv('BASE_URL') ?: 'NOT SET') . "]<br>";
echo "MYSQLHOST env: [" . (getenv('MYSQLHOST') ?: 'NOT SET') . "]<br>";
echo "MYSQLPORT env: [" . (getenv('MYSQLPORT') ?: 'NOT SET') . "]<br>";
echo "MYSQLUSER env: [" . (getenv('MYSQLUSER') ?: 'NOT SET') . "]<br>";
echo "MYSQLDATABASE env: [" . (getenv('MYSQLDATABASE') ?: 'NOT SET') . "]<br>";
echo "MYSQLPASSWORD env: " . (getenv('MYSQLPASSWORD') ? '✅ SET' : '❌ NOT SET') . "<br>";

echo "<h2>3. Resolved Config Constants</h2>";
require_once 'config/database.php';
echo "DB_HOST: " . DB_HOST . "<br>";
echo "DB_PORT: " . DB_PORT . "<br>";
echo "DB_USER: " . DB_USER . "<br>";
echo "DB_NAME: " . DB_NAME . "<br>";
echo "BASE_URL: [" . BASE_URL . "]<br>";
echo "BASE_PATH: " . BASE_PATH . "<br>";

echo "<h2>4. Database Connection</h2>";
try {
    $db = getDB();
    echo "✅ Connected to database<br>";
    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "✅ Users table: {$count} rows<br>";
} catch (Exception $e) {
    echo "❌ DB Error: " . $e->getMessage() . "<br>";
}

echo "<h2>5. Session Test</h2>";
session_start();
echo "Session ID: " . session_id() . "<br>";
echo "Session data: <pre>" . print_r($_SESSION, true) . "</pre>";

echo "<h2>6. Testing index.php flow</h2>";
require_once 'includes/functions.php';
echo "isLoggedIn(): " . (isLoggedIn() ? 'YES (will redirect!)' : 'NO') . "<br>";
echo "getSetting('school_name'): " . (getSetting('school_name') ?? 'NULL') . "<br>";

echo "<h2>7. Login redirect target</h2>";
echo "Would redirect to: " . BASE_URL . "dashboard.php<br>";

echo "<hr><p><strong>If BASE_URL shows 'http://localhost/...' above, that's the problem!</strong></p>";
?>
