<?php
require_once __DIR__.'/support/enrollment_fixture.php';
if (is_file(__DIR__.'/../includes/section_preparation.php')) require_once __DIR__.'/../includes/section_preparation.php';
withEnrollmentFixture(function(array $f):void {
    checkEnrollment(function_exists('previewSectionPreparation'),'Section preparation service exists');
    $db=$f['db'];
    $cmd=['source_year_id'=>1,'target_year_id'=>2,'section_ids'=>[10,11]];
    expectEnrollmentError(DomainException::class,fn()=>previewSectionPreparation($db,$f['admin'],$cmd));
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    foreach([$f['teacher'],['id'=>7,'role'=>'admin'],['id'=>999,'role'=>'admin']] as $actor) {
        expectEnrollmentError(DomainException::class,fn()=>previewSectionPreparation($db,$actor,$cmd));
        expectEnrollmentError(DomainException::class,fn()=>prepareSections($db,$actor,$cmd,str_repeat('a',64)));
    }
    foreach([['section_ids'=>[]],['section_ids'=>[10,10]],['section_ids'=>[0]],['section_ids'=>['10x']],['section_ids'=>'10'],['section_ids'=>[20]],['section_ids'=>[999]],['target_year_id'=>1],['source_year_id'=>2],['target_year_id'=>999]] as $bad) {
        expectEnrollmentError(DomainException::class,fn()=>previewSectionPreparation($db,$f['admin'],array_replace($cmd,$bad)));
    }
    $db->exec('UPDATE sections SET is_active=0 WHERE id=11');
    expectEnrollmentError(DomainException::class,fn()=>previewSectionPreparation($db,$f['admin'],$cmd));
    $db->exec('UPDATE sections SET is_active=1 WHERE id=11');
    $before=$db->query('SELECT * FROM sections WHERE school_year_id=1 ORDER BY id')->fetchAll();
    $students=$db->query('SELECT * FROM students ORDER BY id')->fetchAll();
    $enrollments=$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll();
    $p=previewSectionPreparation($db,$f['admin'],$cmd);
    checkEnrollment(count($p['rows'])===2 && strlen($p['fingerprint'])===64,'Preview includes selected sections');
    $db->exec("UPDATE sections SET am_in_start='07:05:00' WHERE id=10");
    expectEnrollmentError(DomainException::class,fn()=>prepareSections($db,$f['admin'],$cmd,$p['fingerprint']));
    $db->exec("UPDATE sections SET am_in_start=".$db->quote($before[0]['am_in_start']).' WHERE id=10');
    // The synthetic stale-preview edit also updates the section timestamp.
    $before=$db->query('SELECT * FROM sections WHERE school_year_id=1 ORDER BY id')->fetchAll();
    $p=previewSectionPreparation($db,$f['admin'],$cmd);
    $r=prepareSections($db,$f['admin'],$cmd,$p['fingerprint']);
    checkEnrollment($r===['created'=>2,'skipped'=>0],'Confirmation creates selected sections');
    foreach($p['rows'] as $row) {
        $q=$db->prepare('SELECT * FROM sections WHERE school_year_id=2 AND section_name=? AND grade_level=?');
        $q->execute([$row['section']['section_name'],$row['section']['grade_level']]); $copy=$q->fetch();
        foreach(sectionPreparationFields() as $field) checkEnrollment($copy[$field]===$row['section'][$field],'Copied '.$field);
        checkEnrollment($copy['adviser_id']===null && (int)$copy['is_active']===1 && $copy['school_year']==='2027-2028','New section is active, labelled and unassigned');
    }
    expectEnrollmentError(DomainException::class,fn()=>prepareSections($db,$f['admin'],$cmd,$p['fingerprint']));
    $p=previewSectionPreparation($db,$f['admin'],$cmd);
    checkEnrollment(prepareSections($db,$f['admin'],$cmd,$p['fingerprint'])===['created'=>0,'skipped'=>2],'Fresh preview skips matching sections');
    $db->exec("UPDATE sections SET is_active=0 WHERE school_year_id=2 AND section_name='Current A'");
    $p=previewSectionPreparation($db,$f['admin'],$cmd);
    checkEnrollment($p['rows'][0]['status']==='archived','Archived match is explicitly skipped');
    $db->exec("UPDATE sections SET section_name='CURRENT A' WHERE school_year_id=2 AND section_name='Current A'");
    $p=previewSectionPreparation($db,$f['admin'],$cmd);
    checkEnrollment($p['rows'][0]['status']==='archived','Matching uses database collation');
    checkEnrollment($db->query('SELECT * FROM sections WHERE school_year_id=1 ORDER BY id')->fetchAll()===$before,'Source sections unchanged');
    checkEnrollment($db->query('SELECT * FROM students ORDER BY id')->fetchAll()===$students && $db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll()===$enrollments,'Student placements unchanged');
    $db->exec("INSERT INTO sections(section_name,grade_level,school_year,school_year_id) VALUES('current a','Grade 3','2026-2027',1)");
    $duplicate=(int)$db->lastInsertId();
    expectEnrollmentError(DomainException::class,fn()=>previewSectionPreparation($db,$f['admin'],array_replace($cmd,['section_ids'=>[10,$duplicate]])));
    // Audit failure after insert must roll back the complete batch.
    $db->exec("CREATE TRIGGER reject_section_copy_audit BEFORE INSERT ON security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
    $newCmd=array_replace($cmd,['section_ids'=>[16]]); $p=previewSectionPreparation($db,$f['admin'],$newCmd);
    $count=(int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn();
    expectEnrollmentError(PDOException::class,fn()=>prepareSections($db,$f['admin'],$newCmd,$p['fingerprint']));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn()===$count && !$db->inTransaction(),'Failed audit rolls back section copy');
    $db->exec('DROP TRIGGER reject_section_copy_audit');
    $db->exec("INSERT INTO sections(section_name,grade_level,school_year,school_year_id) VALUES('Rollback One','Grade 3','2026-2027',1),('Rollback Two','Grade 3','2026-2027',1)");
    $ids=$db->query("SELECT id FROM sections WHERE school_year_id=1 AND section_name IN ('Rollback One','Rollback Two') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $rollbackCommand=array_replace($cmd,['section_ids'=>$ids]); $p=previewSectionPreparation($db,$f['admin'],$rollbackCommand);
    $count=(int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn();
    $auditCount=(int)$db->query('SELECT COUNT(*) FROM security_events')->fetchColumn();
    $db->exec("CREATE TRIGGER reject_second_section BEFORE INSERT ON sections FOR EACH ROW BEGIN IF NEW.school_year_id=2 AND NEW.section_name='Rollback Two' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic second section failure'; END IF; END");
    expectEnrollmentError(PDOException::class,fn()=>prepareSections($db,$f['admin'],$rollbackCommand,$p['fingerprint']));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sections')->fetchColumn()===$count && (int)$db->query('SELECT COUNT(*) FROM security_events')->fetchColumn()===$auditCount,'Second insert failure rolls back the first copy and its audit event');
    $db->exec('DROP TRIGGER reject_second_section');
    $db->exec("UPDATE school_years SET state='closed' WHERE id=2");
    expectEnrollmentError(DomainException::class,fn()=>prepareSections($db,$f['admin'],$rollbackCommand,$p['fingerprint']));
    $db->exec("UPDATE school_years SET state='planned' WHERE id=2; UPDATE users SET is_active=0 WHERE id=1");
    expectEnrollmentError(DomainException::class,fn()=>previewSectionPreparation($db,$f['admin'],$cmd));
});
echo 'Section preparation checks passed ('.($GLOBALS['enrollment_checks'] ?? 0).").\n";
