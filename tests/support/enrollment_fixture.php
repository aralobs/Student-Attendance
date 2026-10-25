<?php
declare(strict_types=1);

function checkEnrollment(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['enrollment_checks'] = ($GLOBALS['enrollment_checks'] ?? 0) + 1;
}

function expectEnrollmentError(string $class, callable $operation): void
{
    try { $operation(); }
    catch (Throwable $error) {
        checkEnrollment($error instanceof $class, 'Unexpected exception type: ' . get_class($error));
        return;
    }
    throw new RuntimeException('Expected exception: ' . $class);
}

/** Only synthetic data; never loads application connection configuration or sends notifications.
 * Options: legacy=true leaves enrollment schema/seed absent; schema selects an allowed fresh import.
 */
function withEnrollmentFixture(callable $test, array $options = []): void
{
    $root = dirname(__DIR__, 2);
    $dsn = 'mysql:host=' . (getenv('MYSQLHOST') ?: '127.0.0.1') . ';port=' . (getenv('MYSQLPORT') ?: '3307') . ';charset=utf8mb4';
    $user = getenv('MYSQLUSER') !== false ? getenv('MYSQLUSER') : 'root';
    $password = getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '';
    $pdoOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $server = new PDO($dsn, $user, $password, $pdoOptions);
    $name = 'enrollment_test_' . bin2hex(random_bytes(8));
    if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/', $name)) throw new RuntimeException('Invalid disposable database name');
    $created = false;
    $oldKey = getenv('PII_ENCRYPTION_KEY');
    putenv('PII_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    try {
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created = true;
        $db = new PDO($dsn . ';dbname=' . $name, $user, $password, $pdoOptions);
        $schema = $options['schema'] ?? 'tests/fixtures/enrollment_legacy_schema.sql';
        if (!in_array($schema, ['tests/fixtures/enrollment_legacy_schema.sql', 'attendance_system.sql', 'config/database_schema_update.sql'], true)) {
            throw new RuntimeException('Unsupported fixture schema');
        }
        $sql = file_get_contents($root . '/' . $schema);
        // The secondary fresh-install import names production explicitly: remove only that header.
        $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS attendance_system\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;\s*USE attendance_system;/i', '', $sql);
        if (preg_match('/\b(?:CREATE\s+DATABASE|USE\s+`?attendance_system)\b/i', $sql)) throw new RuntimeException('Unsafe schema import');
        $db->exec($sql);
        $db->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $db->exec("INSERT INTO users (id,username,password,full_name,role) VALUES (1,'fixture_admin','unused','Synthetic Admin','admin'),(7,'fixture_teacher','unused','Synthetic Teacher','teacher'),(8,'fixture_other','unused','Synthetic Other','teacher');
            INSERT INTO sections (id,section_name,grade_level,adviser_id,school_year) VALUES (10,'Current A','Grade 3',7,'2026-2027'),(11,'Current B','Grade 3',8,'2026-2027'),(16,'Current Six','Grade 6',7,'2026-2027'),(20,'Next Four','Grade 4',7,'2027-2028'),(21,'Next Three','Grade 3',8,'2027-2028');
            INSERT INTO students (id,lrn,first_name,last_name,gender,section_id,qr_token) VALUES (101,'SYNTHETIC101','Synthetic','Three','Male',10,'synthetic-101'),(106,'SYNTHETIC106','Synthetic','Six','Female',16,'synthetic-106');
            INSERT INTO attendance (id,student_id,date,am_in,am_status,attendance_type,recorded_by) VALUES (501,101,'2026-10-21','07:00:00','present','partial',7)");
        // Both imports must preserve legacy profile placement columns as well.
        foreach (['grade_level', 'school_year'] as $column) {
            $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'students\' AND COLUMN_NAME=?');
            $q->execute([$column]);
            if ($q->fetchColumn()) $db->exec($column === 'grade_level' ? "UPDATE students SET grade_level=IF(id=101,'Grade 3','Grade 6')" : "UPDATE students SET school_year='2026-2027'");
        }
        if (empty($options['legacy'])) {
            require_once $root . '/includes/enrollment_schema.php';
            ensureEnrollmentSchema($db);
            seedEnrollmentFixture($db);
        }
        $test(['db'=>$db, 'database'=>$name, 'admin'=>['id'=>1,'role'=>'admin'], 'teacher'=>['id'=>7,'role'=>'teacher'],
            'otherTeacher'=>['id'=>8,'role'=>'teacher'], 'student'=>101, 'grade6Student'=>106, 'currentYear'=>1, 'nextYear'=>2]);
    } finally {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $db = null;
        if ($created) $server->exec("DROP DATABASE `$name`");
        putenv($oldKey === false ? 'PII_ENCRYPTION_KEY' : 'PII_ENCRYPTION_KEY=' . $oldKey);
    }
}

function seedEnrollmentFixture(PDO $db): void
{
    $db->exec("INSERT INTO school_years (id,label,starts_on,ends_on,state) VALUES (1,'2026-2027','2026-06-01','2027-03-31','active'),(2,'2027-2028','2027-06-01','2028-03-31','planned');
        UPDATE sections SET school_year_id=IF(id IN (20,21),2,1);
        INSERT INTO student_enrollments (id,student_id,school_year_id,state,starts_on,ends_on) VALUES (1001,101,1,'active','2026-06-01','2027-03-31'),(1006,106,1,'active','2026-06-01','2027-03-31');
        INSERT INTO enrollment_sections (id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (2001,1001,10,'Grade 3','2026-06-01','2027-03-31'),(2006,1006,16,'Grade 6','2026-06-01','2027-03-31');
        UPDATE attendance SET enrollment_id=1001,assignment_id=2001 WHERE id=501");
}
