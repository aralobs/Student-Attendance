<?php
declare(strict_types=1);

require_once __DIR__ . '/support/enrollment_fixture.php';
$service = dirname(__DIR__) . '/includes/school_years.php';
if (is_file($service)) require_once $service;
checkEnrollment(function_exists('nextSchoolYearLabel'), 'School-year validation service is not implemented');

checkEnrollment(nextSchoolYearLabel('2026-2027') === '2027-2028', 'Next year is based on source label');
foreach (['2026-2028', '2026/2027', '26-27', '2026-2027 ', '0000-0001', '9999-10000'] as $label) {
    expectEnrollmentError(InvalidArgumentException::class, fn() => nextSchoolYearLabel($label));
}
expectEnrollmentError(InvalidArgumentException::class, fn() => nextSchoolYearLabel('9998-9999'));

withEnrollmentFixture(function (array $f): void {
    $db = $f['db'];
    $db->exec(file_get_contents(dirname(__DIR__) . '/migrations/security_audit.sql'));
    checkEnrollment(!enrollmentModelEnabled($db), 'Table existence must not enable converted mode');
    $db->exec("INSERT INTO system_settings (setting_key,setting_value) VALUES ('enrollment_model_version','0')");
    checkEnrollment(!enrollmentModelEnabled($db), 'Version zero is legacy mode');
    $db->exec("UPDATE system_settings SET setting_value='1' WHERE setting_key='enrollment_model_version'");
    checkEnrollment(enrollmentModelEnabled($db), 'Version one enables converted mode');
    $year = getActiveSchoolYear($db);
    checkEnrollment($year['id'] === 1 && $year['version'] === 1 && $year['label'] === '2026-2027', 'Read normalized active year');
    expectEnrollmentError(LogicException::class, fn() => getActiveSchoolYear($db, true));
    $db->beginTransaction();
    checkEnrollment(getActiveSchoolYear($db, true)['id'] === 1, 'Caller may lock active year inside transaction');
    $db->rollBack();

    $valid = ['id'=>null, 'label'=>'2028-2029', 'starts_on'=>'2028-06-01', 'ends_on'=>'2029-03-31', 'expected_version'=>null];
    foreach ([$f['teacher'], ['id'=>9,'role'=>'scanner_operator'], ['id'=>0,'role'=>'admin'], []] as $actor) {
        expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $actor, $valid));
    }
    foreach ([
        ['starts_on'=>'2028-02-30'], ['ends_on'=>'2029-02-29'], ['starts_on'=>'2029-04-01'],
        ['starts_on'=>'2028-6-01'], ['starts_on'=>''], ['starts_on'=>null], ['ends_on'=>[]],
        ['label'=>'2028-2030'], ['label'=>'2028/2029'], ['label'=>[]],
        ['id'=>-1], ['id'=>'abc'], ['expected_version'=>1],
    ] as $changes) {
        expectEnrollmentError(InvalidArgumentException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($valid, $changes)));
    }
    foreach ([
        ['label'=>'2027-2028'],
        ['starts_on'=>'2027-03-31','ends_on'=>'2027-04-30'],
        ['starts_on'=>'2027-05-01','ends_on'=>'2027-06-01'],
        ['starts_on'=>'2026-01-01','ends_on'=>'2029-01-01'],
    ] as $changes) {
        expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($valid, $changes)));
    }
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM school_years')->fetchColumn() === 2, 'Rejected saves leave no rows');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM security_events')->fetchColumn() === 0, 'Rejected saves leave no success audit');

    // Dates come from the administrator, including a valid leap day and one-day range.
    $leap = ['label'=>'2031-2032', 'starts_on'=>'2032-02-29', 'ends_on'=>'2032-02-29'];
    $id = savePlannedSchoolYear($db, $f['admin'], $leap);
    $read = $db->prepare('SELECT * FROM school_years WHERE id=?');
    $read->execute([$id]);
    $saved = $read->fetch();
    checkEnrollment($saved['starts_on'] === '2032-02-29' && $saved['ends_on'] === '2032-02-29' && $saved['state'] === 'planned', 'Verified leap-day boundaries are preserved');
    checkEnrollment(getActiveSchoolYear($db)['id'] === 1, 'Planned save never activates a year');
    $edit = array_replace($valid, ['id'=>$id,'expected_version'=>1]);
    checkEnrollment(savePlannedSchoolYear($db, $f['admin'], $edit) === $id, 'Edit preserves year identity');
    $read->execute([$id]);
    $edited = $read->fetch();
    checkEnrollment($edited['version'] === 2 && $edited['label'] === '2028-2029', 'Successful edit increments version');
    expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $f['admin'], $edit));
    expectEnrollmentError(InvalidArgumentException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($edit, ['expected_version'=>null])));
    expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($edit, ['id'=>9999])));
    expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($edit, ['id'=>1])));
    $db->exec("UPDATE school_years SET state='closed' WHERE id=$id");
    expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($edit, ['expected_version'=>2])));
    $db->exec("UPDATE school_years SET state='planned' WHERE id=$id");
    $db->beginTransaction();
    expectEnrollmentError(LogicException::class, fn() => savePlannedSchoolYear($db, $f['admin'], $valid));
    checkEnrollment($db->inTransaction(), 'Command does not roll back caller transaction');
    $db->rollBack();

    $events = $db->query("SELECT * FROM security_events WHERE target_type='school_year' ORDER BY occurred_at,event_id")->fetchAll();
    checkEnrollment(count($events) === 2 && (int)$events[0]['actor_id'] === 1 && (int)$events[1]['target_id'] === $id, 'Year saves record trusted actor and target');
    $historyRead = $db->prepare('SELECT change_ciphertext FROM security_change_history WHERE event_id=?');
    $historyRead->execute([$events[1]['event_id']]);
    $ciphertext = $historyRead->fetchColumn();
    $history = json_decode(decryptPII($ciphertext, 'security-change:' . $events[1]['event_id']), true);
    checkEnrollment($history['before']['starts_on'] === '2032-02-29' && $history['after']['starts_on'] === '2028-06-01', 'Year history keeps encrypted before/after boundaries');
    checkEnrollment(!str_contains($ciphertext, '2028-06-01'), 'Year boundaries are encrypted in change history');
    $snapshot = attendanceAuditSnapshot(['id'=>501,'student_id'=>101,'enrollment_id'=>1001,'assignment_id'=>2001,'parent_contact'=>'unnecessary']);
    checkEnrollment($snapshot['enrollment_id'] === 1001 && $snapshot['assignment_id'] === 2001 && !isset($snapshot['parent_contact']), 'Attendance snapshot includes ownership and excludes unrelated fields');
    foreach (['route','user','student','attendance','report','school_year','enrollment','enroll_correct'] as $target) {
        securityAuditEvent($db, 'fixture.target', 'success', $target, 1);
    }
    expectEnrollmentError(InvalidArgumentException::class, fn() => securityAuditEvent($db, 'fixture.target', 'success', 'enrollment_correction', 1));
    foreach (['security_events','security_change_history'] as $table) {
        expectEnrollmentError(PDOException::class, fn() => $db->exec("UPDATE $table SET event_id=event_id"));
        expectEnrollmentError(PDOException::class, fn() => $db->exec("DELETE FROM $table"));
    }

    $beforeEvents = (int)$db->query('SELECT COUNT(*) FROM security_events')->fetchColumn();
    $db->exec('RENAME TABLE security_change_history TO unavailable_history');
    expectEnrollmentError(PDOException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($leap, ['label'=>'2032-2033','starts_on'=>'2032-06-01','ends_on'=>'2033-03-31'])));
    expectEnrollmentError(PDOException::class, fn() => savePlannedSchoolYear($db, $f['admin'], array_replace($edit, ['expected_version'=>2,'ends_on'=>'2029-03-30'])));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM school_years')->fetchColumn() === 3, 'Missing history rolls back year creation');
    $read->execute([$id]);
    $rolledBack = $read->fetch();
    checkEnrollment($rolledBack['version'] === 2 && $rolledBack['ends_on'] === '2029-03-31', 'Missing history rolls back year edit');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM security_events')->fetchColumn() === $beforeEvents && !$db->inTransaction(), 'Audit failure leaves neither success events nor open transaction');
    $db->exec('RENAME TABLE unavailable_history TO security_change_history');
    $db->exec("UPDATE school_years SET state='closed' WHERE id=1");
    expectEnrollmentError(DomainException::class, fn() => getActiveSchoolYear($db));
    expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($db, $f['admin'], $leap));
    $db->exec("UPDATE system_settings SET setting_value='0' WHERE setting_key='enrollment_model_version'");
    checkEnrollment(getActiveSchoolYear($db) === null, 'Migration bootstrap may have no active year');
    savePlannedSchoolYear($db, $f['admin'], $leap);
    checkEnrollment(getActiveSchoolYear($db) === null, 'Bootstrap save does not activate automatically');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn() === 2 && (int)$db->query('SELECT COUNT(*) FROM attendance')->fetchColumn() === 1, 'Year saves do not duplicate profiles or attendance');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === 0, 'Year saves send no messages');
});

echo 'School-year checks passed (' . $GLOBALS['enrollment_checks'] . " checks; synthetic SQL, no provider calls).\n";
