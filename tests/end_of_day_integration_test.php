<?php
// Isolated MySQL fixture; no live parent messages or attendance changes.
putenv('PII_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
$name = 'enrollment_test_' . bin2hex(random_bytes(8));
if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$name)) throw new RuntimeException('Invalid disposable database name');
putenv('MYSQLDATABASE=' . $name);
require_once dirname(__DIR__) . '/includes/end_of_day_alerts.php';
$dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$admin = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = false;
function assertEod(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $admin->exec("CREATE DATABASE `$name`"); $created = true; $db = getDB();
    $db->exec('CREATE TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT)');
    $db->exec("INSERT INTO system_settings VALUES('enrollment_model_version','0')");
    updateSetting('sms_missing_checkout_template', 'CUSTOM: {student_name} did not scan Time-Out on {date}. Section: {section}.');
    $db->exec(file_get_contents(dirname(__DIR__) . '/migrations/security_audit.sql'));
    $db->exec("CREATE TABLE attendance (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT, date DATE,
        am_in TIME NULL, am_out TIME NULL, pm_in TIME NULL, pm_out TIME NULL, am_status VARCHAR(10), pm_status VARCHAR(10),
        attendance_type ENUM('full_day','partial','absent','holiday') DEFAULT 'absent', remarks TEXT, recorded_by INT,
        UNIQUE KEY (student_id,date)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE sms_logs (id INT, type ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence'))");
    $db->exec('CREATE TABLE school_calendar (date DATE, type VARCHAR(20))');
    $db->exec("CREATE TABLE sections (id INT PRIMARY KEY, adviser_id INT, section_name VARCHAR(30), grade_level VARCHAR(30),
        schedule_type VARCHAR(20), am_out_end TIME, pm_out_end TIME, is_active INT, created_at DATETIME)");
    $db->exec("CREATE TABLE students (id INT PRIMARY KEY, section_id INT, first_name VARCHAR(30), last_name VARCHAR(30),
        parent_contact VARCHAR(20), is_active INT, enrollment_status VARCHAR(20), created_at DATETIME)");
    foreach (explode(';', file_get_contents(dirname(__DIR__) . '/migrations/end_of_day_alerts.sql')) as $sql) if (trim($sql)) $db->exec($sql);
    // The migration can be applied again safely.
    foreach (explode(';', file_get_contents(dirname(__DIR__) . '/migrations/end_of_day_alerts.sql')) as $sql) if (trim($sql)) $db->exec($sql);
    $db->exec("INSERT INTO sections VALUES (1,7,'A','Grade 3','full_day','12:00:00','16:00:00',1,'2026-01-01'), (2,8,'B','Grade 3','full_day','12:00:00','16:00:00',1,'2026-01-01')");
    for ($id = 1; $id <= 6; $id++) $db->prepare("INSERT INTO students VALUES (?,?,'Test','Student','09171234567',1,'active','2026-01-01')")->execute([$id, $id === 6 ? 2 : 1]);
    $date = '2026-10-09'; $teacher = ['role' => 'teacher', 'id' => 7]; $calls = 0;
    $db->exec("INSERT INTO attendance (student_id,date,am_in,pm_out,attendance_type,remarks) VALUES
        (1,'$date','08:00:00',NULL,'partial',NULL), (3,'$date',NULL,NULL,'remarked','Excused'),
        (4,'$date','08:00:00','15:30:00','full_day',NULL), (5,'$date','08:00:00',NULL,'partial',NULL)");
    $sender = function ($phone, $message, $id, $type) use (&$calls, $db) {
        assertEod(!$db->inTransaction(), 'Provider I/O must follow commit');
        assertEod($type === 'missing_checkout' && str_contains($message, 'did not scan Time-Out'), 'Correct alert');
        assertEod(str_starts_with($message, 'CUSTOM:') && str_contains($message, 'October 9, 2026'), 'Saved template and historical review date used');
        $calls++; return true;
    };
    assertEod(array_column(endOfDayRows($date,$teacher),'student_id') === [1,2,5], 'Only eligible assigned students');
    submitEndOfDayReview($date,[1=>['remarks'=>'']],$teacher,$sender);
    assertEod($calls === 0, 'Unchecked checkbox does not notify');
    assertEod(endOfDayRows($date,['role'=>'teacher','id'=>99]) === [], 'Unassigned teacher must see no students');
    foreach (['admin', 'scanner_operator', 'unknown', ''] as $role) {
        try { endOfDayRows($date, ['role' => $role, 'id' => 7]); throw new RuntimeException('Non-teacher review accepted'); }
        catch (InvalidArgumentException $e) {}
        try { submitEndOfDayReview($date, [1 => ['notify' => 1, 'remarks' => 'Forbidden']], ['role' => $role, 'id' => 7], $sender); throw new RuntimeException('Non-teacher submission accepted'); }
        catch (InvalidArgumentException $e) {}
    }
    assertEod($calls === 0, 'Denied roles cannot send parent alerts');
    try { submitEndOfDayReview($date,[1=>['remarks'=>'Saved'],6=>['remarks'=>'Forbidden']],$teacher,$sender); throw new RuntimeException('Foreign roster accepted'); }
    catch (InvalidArgumentException $e) {}
    assertEod($db->query('SELECT remarks FROM attendance WHERE student_id=1')->fetchColumn() === null, 'Reject whole batch before writes');
    try { submitEndOfDayReview($date,[2=>['notify'=>1]],$teacher,$sender); throw new RuntimeException('Absence notified'); }
    catch (InvalidArgumentException $e) {}
    $result = submitEndOfDayReview($date,[1=>['notify'=>1]],$teacher,$sender);
    assertEod($result['sent'] === 1 && $calls === 1, 'Checked missing checkout sends');
    submitEndOfDayReview($date,[1=>['notify'=>1]],$teacher,$sender);
    assertEod($calls === 1, 'Duplicate submit never resends');
    submitEndOfDayReview($date,[2=>['remarks'=>'Excused'],5=>['remarks'=>'Sent Home Sick','notify'=>1]],$teacher,$sender);
    assertEod($calls === 2, 'Remark and checked notification delivered together');
    finalizeAttendanceAbsences($date);
    assertEod($db->query('SELECT attendance_type FROM attendance WHERE student_id=2')->fetchColumn() === 'remarked', 'Remark survives automatic finalization');
    assertEod(array_column(endOfDayRows($date,$teacher),'student_id') === [1], 'Reviewed students excluded');
    $db->exec("INSERT INTO students VALUES (7,1,'Test','Failure','09171234567',1,'active','2026-01-01')");
    $db->exec("INSERT INTO attendance (student_id,date,am_in,attendance_type) VALUES (7,'$date','08:00:00','partial')");
    $failedCalls = 0;
    $failedSender = function () use (&$failedCalls) { $failedCalls++; return false; };
    $result = submitEndOfDayReview($date,[7=>['notify'=>1,'remarks'=>'Parent follow-up needed']],$teacher,$failedSender);
    assertEod($result['failed'] === 1 && $result['saved'] === 1, 'SMS failure does not lose teacher remarks');
    assertEod($db->query('SELECT status FROM end_of_day_parent_alerts WHERE student_id=7')->fetchColumn() === 'failed', 'Failed delivery recorded');
    $db->exec('UPDATE attendance SET remarks=NULL WHERE student_id=7');
    submitEndOfDayReview($date,[7=>['notify'=>1]],$teacher,$failedSender);
    assertEod($failedCalls === 1, 'Failed alert is not automatically resent');
    $db->exec("INSERT INTO students VALUES (8,1,'Test','Pending','09171234567',1,'active','2026-01-01')");
    $db->exec("INSERT INTO attendance (student_id,date,am_in,attendance_type) VALUES (8,'$date','08:00:00','partial')");
    $pendingCalls = 0;
    $pendingSender = function () use (&$pendingCalls) { $pendingCalls++; return 'pending'; };
    $result = submitEndOfDayReview($date,[8=>['notify'=>1]],$teacher,$pendingSender);
    assertEod($result['pending'] === 1 && $result['sent'] === 0 && $result['failed'] === 0, 'Pending is neither sent nor failed');
    assertEod($db->query('SELECT status FROM end_of_day_parent_alerts WHERE student_id=8')->fetchColumn() === 'pending', 'Pending provider response preserved');
    submitEndOfDayReview($date,[8=>['notify'=>1,'remarks'=>'Parent contacted']],$teacher,$pendingSender);
    assertEod($pendingCalls === 1, 'Saving a remark must not resend pending SMS');
    $db->exec("ALTER TABLE sms_logs ADD student_id INT, ADD status VARCHAR(20), ADD api_response TEXT, ADD sent_at DATETIME");
    $insertLog = $db->prepare('INSERT INTO sms_logs (id, student_id, type, status, api_response, sent_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([[6,7,'pending'], [7,8,'pending'], [8,7,'failed'], [9,7,'pending']] as [$logId,$studentId,$providerStatus]) {
        $insertLog->execute([$logId,$studentId,'missing_checkout','failed',json_encode(['message'=>['status'=>$providerStatus]]),$date.' 16:00:00']);
    }
    $db->exec(file_get_contents(__DIR__ . '/../tools/hostinger/fix_pending_sms_logs.sql'));
    assertEod($db->query('SELECT status FROM sms_logs WHERE id=6')->fetchColumn() === 'pending', 'Known pending response corrected');
    assertEod($db->query('SELECT status FROM sms_logs WHERE id=8')->fetchColumn() === 'failed', 'Real failure preserved');
    assertEod($db->query('SELECT status FROM sms_logs WHERE id=9')->fetchColumn() === 'failed', 'Correction limited to screenshot IDs');
    assertEod($db->query('SELECT status FROM end_of_day_parent_alerts WHERE student_id=7')->fetchColumn() === 'pending', 'Review status corrected without another send');
    $db->exec("INSERT INTO school_calendar VALUES ('$date','holiday')");
    assertEod(endOfDayRows($date,$teacher) === [], 'School holiday excludes all students');
    echo "End-of-day integration checks passed (isolated database, stubbed SMS).\n";
} finally { if ($created) $admin->exec("DROP DATABASE `$name`"); }
