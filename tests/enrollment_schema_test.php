<?php
declare(strict_types=1);
require_once __DIR__ . '/support/enrollment_fixture.php';

try {
    withEnrollmentFixture(function (array $f): void {
        $db = $f['db'];
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn() === 2, 'Legacy schema imports synthetic profiles');
        $path = dirname(__DIR__) . '/includes/enrollment_schema.php';
        if (is_file($path)) require_once $path;
        checkEnrollment(function_exists('ensureEnrollmentSchema'), 'Missing annual enrollment migration after successful legacy import');
        $before = $db->query('SELECT * FROM attendance WHERE id=501')->fetch();
        $identity = $db->query('SELECT * FROM students WHERE id=101')->fetch();
        ensureEnrollmentSchema($db);
        ensureEnrollmentSchema($db);
        foreach ([['--schema',0],['--schema',0],['--unknown',2]] as [$argument,$expected]) {
            $env = getenv();
            $env['MYSQLDATABASE'] = $f['database'];
            $env['APP_ENV'] = 'development';
            $process = proc_open([PHP_BINARY,dirname(__DIR__) . '/migrations/school_year_enrollment.php',$argument],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $env);
            if (!is_resource($process)) throw new RuntimeException('Cannot start schema CLI test');
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            checkEnrollment(proc_close($process) === $expected, 'Schema CLI returned an unexpected exit code');
            checkEnrollment($expected === 0 ? str_contains($stdout, 'schema ready') && $stderr === '' : str_contains($stderr, 'Usage:'), 'Schema CLI response contract');
        }
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students WHERE id=101')->fetchColumn() === 1, 'Identity preserved');
        checkEnrollment($identity === $db->query('SELECT * FROM students WHERE id=101')->fetch(), 'All legacy identity/profile fields preserved');
        $after = $db->query('SELECT * FROM attendance WHERE id=501')->fetch();
        foreach ($before as $key=>$value) checkEnrollment($after[$key] === $value, 'Legacy scan value preserved: ' . $key);
        checkEnrollment($after['enrollment_id'] === null && $after['assignment_id'] === null, 'Legacy ownership stays unresolved');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM school_years')->fetchColumn() === 0, 'DDL does not invent managed years');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sections WHERE school_year_id IS NULL')->fetchColumn() === 5, 'Legacy sections stay unresolved');
        seedEnrollmentFixture($db);
        verifyEnrollmentConstraints($db);
        // Retry a deliberately interrupted ALTER sequence without losing existing ownership.
        $db->exec('ALTER TABLE attendance DROP FOREIGN KEY fk_attendance_assignment');
        ensureEnrollmentSchema($db);
        verifyEnrollmentOwnership($db);
        checkEnrollment((int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('security_events_no_update','security_events_no_delete','security_history_no_update','security_history_no_delete')")->fetchColumn() === 4, 'Append-only audit triggers preserved');
        $db->exec("INSERT INTO security_events (event_id,request_id,action,outcome,target_type,route_name) VALUES (REPEAT('a',32),REPEAT('b',24),'fixture','success','student','fixture'); INSERT INTO security_change_history VALUES (REPEAT('a',32),'synthetic-ciphertext')");
        foreach (['security_events','security_change_history'] as $table) {
            expectEnrollmentError(PDOException::class, fn()=>$db->exec("UPDATE $table SET event_id=event_id"));
            expectEnrollmentError(PDOException::class, fn()=>$db->exec("DELETE FROM $table"));
        }
        $db->beginTransaction();
        expectEnrollmentError(LogicException::class, fn()=>ensureEnrollmentSchema($db));
        checkEnrollment($db->inTransaction(), 'DDL refuses implicit transaction commits');
        $db->rollBack();
    }, ['legacy'=>true]);
    foreach (['attendance_system.sql','config/database_schema_update.sql'] as $schema) {
        withEnrollmentFixture(function (array $f): void {
            $db = $f['db'];
            // Exercise the imported SQL itself before migration has a chance to repair it.
            checkEnrollment((int)$db->query('SELECT COUNT(*) FROM school_year_control WHERE id=1')->fetchColumn() === 1, 'Fresh import includes serialization row');
            $db->query('SELECT enrollment_id,assignment_id FROM attendance LIMIT 0');
            seedEnrollmentFixture($db);
            verifyEnrollmentConstraints($db);
            ensureEnrollmentSchema($db);
        }, ['schema'=>$schema, 'legacy'=>true]);
    }
    withEnrollmentFixture(function (array $f): void {
        checkEnrollment($f['student'] === 101 && $f['grade6Student'] === 106 && $f['admin'] === ['id'=>1,'role'=>'admin'], 'Shared fixture contracts');
        checkEnrollment((int)$f['db']->query('SELECT COUNT(*) FROM attendance WHERE enrollment_id=1001 AND assignment_id=2001')->fetchColumn() === 1, 'Default fixture provides mapped ownership');
        checkEnrollment((int)$f['db']->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === 0, 'Schema operations send no notifications');
    });
    echo 'PASS: annual enrollment schema (' . $GLOBALS['enrollment_checks'] . " checks)\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}

function verifyEnrollmentOwnership(PDO $db): void
{
    expectEnrollmentError(PDOException::class, fn()=>$db->exec('UPDATE attendance SET enrollment_id=1006 WHERE id=501'));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec('UPDATE attendance SET assignment_id=2006 WHERE id=501'));
    // Isolate annual ownership: a null assignment must not bypass the student FK.
    $rejected = false;
    try {
        $db->exec('UPDATE attendance SET enrollment_id=1006,assignment_id=NULL WHERE id=501');
    } catch (PDOException $error) {
        $rejected = true;
        checkEnrollment(str_contains($error->getMessage(), 'fk_attendance_enrollment'), 'Independent annual ownership rejection uses fk_attendance_enrollment');
    }
    checkEnrollment($rejected, 'Another student annual enrollment is rejected even with a null assignment');
    checkEnrollment((int)$db->query('SELECT enrollment_id FROM attendance WHERE id=501')->fetchColumn() === 1001, 'Rejected annual ownership preserves attendance');
}

function verifyEnrollmentConstraints(PDO $db): void
{
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO student_enrollments (student_id,school_year_id,state,starts_on,ends_on) VALUES (101,1,'active','2026-06-01','2027-03-31')"));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO school_years (label,starts_on,ends_on,state) VALUES ('Other','2026-06-01','2027-03-31','active')"));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO school_years (label,starts_on,ends_on) VALUES ('Backwards','2027-03-31','2026-06-01')"));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("UPDATE student_enrollments SET ends_on='2026-05-01' WHERE id=1001"));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("UPDATE enrollment_sections SET starts_on='2028-01-01' WHERE id=2001"));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO enrollment_sections (enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (1001,11,'Grade 3','2026-06-01','2027-03-31')"));
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO attendance (student_id,date) VALUES (101,'2026-10-21')"));
    verifyEnrollmentOwnership($db);
    foreach (['DELETE FROM students WHERE id=101','DELETE FROM school_years WHERE id=1','DELETE FROM enrollment_sections WHERE id=2001','DELETE FROM sections WHERE id=10','DELETE FROM student_enrollments WHERE id=1001'] as $sql) {
        expectEnrollmentError(PDOException::class, fn()=>$db->exec($sql));
    }
    expectEnrollmentError(PDOException::class, fn()=>$db->exec('INSERT INTO school_year_control (id) VALUES (2)'));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM school_year_control WHERE id=1')->fetchColumn() === 1, 'Serialization row exists');
    $db->exec("INSERT INTO moving_up_batches (id,request_key,payload_hash,source_year_id,target_year_id,outcome,destination_section_id,actor_id) VALUES (1,'fixture-request',REPEAT('a',64),1,2,'promoted',20,1)");
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO moving_up_batches (request_key,payload_hash,source_year_id,target_year_id,outcome,actor_id) VALUES ('fixture-request',REPEAT('b',64),1,2,'retained',1)"));
    $db->exec("INSERT INTO moving_up_decisions (batch_id,source_enrollment_id,target_year_id,outcome) VALUES (1,1001,2,'promoted')");
    expectEnrollmentError(PDOException::class, fn()=>$db->exec("INSERT INTO moving_up_decisions (batch_id,source_enrollment_id,target_year_id,outcome) VALUES (1,1001,2,'promoted')"));
    $db->exec("UPDATE moving_up_decisions SET state='cancelled'; INSERT INTO moving_up_decisions (batch_id,source_enrollment_id,target_year_id,outcome) VALUES (1,1001,2,'promoted')");
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM moving_up_decisions')->fetchColumn() === 2, 'Cancellation releases decision uniqueness');
    foreach (['enrollment_corrections','enrollment_migration_issues','enrollment_schema_progress'] as $table) {
        $db->query("SELECT * FROM $table LIMIT 0");
        checkEnrollment(true, 'Metadata table available: ' . $table);
    }
}
