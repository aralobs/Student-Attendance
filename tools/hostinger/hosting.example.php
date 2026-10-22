<?php
// Upload as attendance-private/hosting.php BESIDE public_html, never inside it.
// Replace placeholders with the database details shown in your hPanel.
return [
    'APP_ENV' => 'production',
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'u123456789_attendance',
    'DB_USER' => 'u123456789_school',
    'DB_PASS' => 'REPLACE_WITH_DATABASE_PASSWORD',
    'BASE_URL' => 'https://your-domain.example/',
    'PII_KEY_FILE' => '/home/u123456789/domains/your-domain.example/attendance-private/pii_key.php',
    'STUDENT_PHOTO_DIR' => '/home/u123456789/domains/your-domain.example/attendance-private/student-photos',
    // Copy the EXISTING settings-key value from config/settings_key.php here.
    // Keep the same key when transferring an existing database.
    'SETTINGS_ENCRYPTION_KEY' => 'REPLACE_WITH_EXISTING_BASE64_SETTINGS_KEY',
];
