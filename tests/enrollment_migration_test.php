<?php
declare(strict_types=1);
require_once __DIR__ . '/support/enrollment_fixture.php';
if (is_file(__DIR__ . '/../includes/enrollment_migration.php')) require_once __DIR__ . '/../includes/enrollment_migration.php';

withEnrollmentFixture(function (array $f): void {
    $db = $f['db'];
    checkEnrollment(function_exists('inventoryEnrollmentHistory'), 'Migration inventory service is available');
    $db->exec("UPDATE attendance SET enrollment_id=NULL,assignment_id=NULL WHERE id=501;
        DELETE FROM enrollment_sections WHERE enrollment_id=1001;
        DELETE FROM student_enrollments WHERE id=1001;
        UPDATE students SET school_year='',previous_school_year='2025-2026',previous_section_id=11 WHERE id=101;
        UPDATE sections SET school_year='' WHERE id=10;
        INSERT INTO attendance (id,student_id,date,am_in) VALUES (502,101,'2025-10-21','07:14:32');");
    $beforeProfiles = $db->query('SELECT * FROM students ORDER BY id')->fetchAll();
    $beforeAttendance = $db->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
    $beforeCount = $db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    $inventory = inventoryEnrollmentHistory($db);
    checkEnrollment($db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn() === $beforeCount, 'Inventory performs no writes');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM enrollment_migration_issues')->fetchColumn() === 0, 'Inventory does not persist issues');
    foreach (['ambiguous_assignment','blank_year_label','previous_placement_without_dates','attendance_before_current_placement','attendance_outside_year'] as $code) {
        checkEnrollment(in_array($code,array_column($inventory['issues'],'code'),true), 'Inventory identifies ' . $code);
    }
    checkEnrollment(!enrollmentMigrationReadiness($db,1)['ready'], 'Current profile missing mapping blocks readiness');
    $map = ['inventory_fingerprint'=>$inventory['fingerprint'],
        'school_years'=>[['id'=>1,'label'=>'2026-2027','starts_on'=>'2026-06-01','ends_on'=>'2027-03-31']],
        'sections'=>[['section_id'=>10,'school_year_id'=>1]],
        'enrollments'=>[['key'=>'student101-current','student_id'=>101,'school_year_id'=>1,'state'=>'active','starts_on'=>'2026-06-01','ends_on'=>'2027-03-31',
            'assignments'=>[['key'=>'student101-current-a','section_id'=>10,'grade_level'=>'Grade 3','starts_on'=>'2026-06-01','ends_on'=>'2027-03-31']]]],
        'attendance'=>[['attendance_id'=>501,'assignment_key'=>'student101-current-a']]];
    $bad=$map; $bad['enrollments'][0]['assignments'][0]['ends_on']='2027-04-01';
    expectEnrollmentError(InvalidArgumentException::class,fn()=>applyEnrollmentMappings($db,$f['admin'],$bad));
    checkEnrollment($db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn() === $beforeCount, 'Invalid complete map makes no changes');
    $bad=$map; $bad['attendance'][]=['attendance_id'=>502,'assignment_key'=>'student101-current-a'];
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentMappings($db,$f['admin'],$bad));
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentMappings($db,['id'=>7,'role'=>'admin'],$map));
    $db->exec('UPDATE users SET is_active=0 WHERE id=1');
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentMappings($db,$f['admin'],$map));
    $db->exec('UPDATE users SET is_active=1 WHERE id=1');
    $result=applyEnrollmentMappings($db,$f['admin'],$map);
    checkEnrollment($result['enrollments']['student101-current'] > 0, 'Enrollment local key resolves to database ID');
    checkEnrollment($result['assignments']['student101-current-a'] > 0, 'Assignment local key resolves to database ID');
    $afterAttendance=$db->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
    foreach ($beforeAttendance as $i=>$before) {
        unset($before['enrollment_id'],$before['assignment_id']);
        $after=$afterAttendance[$i]; unset($after['enrollment_id'],$after['assignment_id']);
        checkEnrollment($before === $after, 'Raw attendance scans and timestamps preserved');
    }
    checkEnrollment($beforeProfiles === $db->query('SELECT * FROM students ORDER BY id')->fetchAll(), 'Profiles, LRN, QR, contacts preserved exactly');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === 0, 'Mapping sends no notifications');
    checkEnrollment(!enrollmentModelEnabled($db), 'Mapping leaves legacy mode unchanged');
    $ready=enrollmentMigrationReadiness($db,1);
    checkEnrollment($ready['ready'] && count($ready['historical_issues']) > 0, 'Historical exceptions visible without blocking verified current ownership');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM enrollment_migration_issues WHERE resolved_at IS NULL')->fetchColumn() > 0, 'Unresolved historical IDs and reasons persist');
    $counts=$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    checkEnrollment(applyEnrollmentMappings($db,$f['admin'],$map) === $result, 'Identical mapping reruns resolve same stable local keys');
    checkEnrollment($db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn() === $counts, 'Rerun never duplicates annual rows');
    $bad=$map; $bad['enrollments'][0]['assignments'][]=['key'=>'overlap','section_id'=>11,'grade_level'=>'Grade 3','starts_on'=>'2026-08-01','ends_on'=>'2027-03-31'];
    expectEnrollmentError(InvalidArgumentException::class,fn()=>applyEnrollmentMappings($db,$f['admin'],$bad));
    $db->exec("INSERT INTO attendance (student_id,date,am_in) VALUES (101,'2026-10-20','07:10:01')");
    expectEnrollmentError(DomainException::class,fn()=>applyEnrollmentMappings($db,$f['admin'],$map));
    checkEnrollment(!enrollmentMigrationReadiness($db,1)['ready'], 'New unmapped current scan blocks readiness');
});

withEnrollmentFixture(function (array $f): void {
    $db=$f['db'];
    $db->exec("UPDATE students SET grade_level='Grade 2',school_year='2025-2026',section_id=NULL WHERE id=101");
    $codes=array_column(inventoryEnrollmentHistory($db)['issues'],'code');
    checkEnrollment(in_array('missing_section',$codes,true),'Missing cached section stays visible');
    $db->exec('UPDATE students SET section_id=10 WHERE id=101');
    checkEnrollment(in_array('placement_disagreement',array_column(inventoryEnrollmentHistory($db)['issues'],'code'),true),'Student/section disagreement stays visible');
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try { $db->exec("INSERT INTO attendance (student_id,date) VALUES (999,'2026-10-20')"); }
    finally { $db->exec('SET FOREIGN_KEY_CHECKS=1'); }
    checkEnrollment(in_array('missing_profile',array_column(inventoryEnrollmentHistory($db)['issues'],'code'),true),'Orphan historical scan stays visible');
    checkEnrollment(!enrollmentMigrationReadiness($db,1)['ready'],'Orphan current scan blocks readiness');
});

withEnrollmentFixture(function (array $f): void {
    $db=$f['db'];
    $db->exec("UPDATE student_enrollments SET state='prepared' WHERE id=1001");
    checkEnrollment(!enrollmentMigrationReadiness($db,1)['ready'],'Prepared enrollment cannot authorize active-year operations');
    $db->exec("UPDATE student_enrollments SET state='active' WHERE id=1001;
        INSERT INTO enrollment_sections (enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (1001,11,'Grade 3','2026-08-01','2027-03-31')");
    checkEnrollment(!enrollmentMigrationReadiness($db,1)['ready'],'Overlapping current assignments block readiness');
    $db->exec("DELETE FROM enrollment_sections WHERE enrollment_id=1001 AND section_id=11;
        INSERT INTO students (id,lrn,first_name,last_name,gender,section_id,grade_level,school_year,qr_token) VALUES (107,'SYNTHETIC107','Synthetic','Unmapped','Male',10,'Grade 3','2026-2027','synthetic-107')");
    checkEnrollment(!enrollmentMigrationReadiness($db,1)['ready'],'Live current profile without any scans still requires explicit annual ownership');
    $db->exec("UPDATE students SET is_active=0 WHERE id=107;
        INSERT INTO student_enrollments (student_id,school_year_id,state,starts_on,ends_on) VALUES (101,2,'prepared','2027-06-01','2028-03-31')");
    $id=(int)$db->lastInsertId();
    $db->exec("INSERT INTO enrollment_sections (enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES ($id,20,'Grade 4','2027-06-01','2028-03-31')");
    checkEnrollment(enrollmentMigrationReadiness($db,2)['ready'],'Planned-year prepared ownership remains eligible for activation readiness');
    $db->exec("DELETE FROM enrollment_sections WHERE enrollment_id=$id");
    checkEnrollment(!enrollmentMigrationReadiness($db,2)['ready'],'Future annual enrollment without assignments blocks activation readiness despite current cached placement');
});

withEnrollmentFixture(function (array $f): void {
    $run=static function(array $args) use ($f): array {
        $env=getenv(); $env['MYSQLDATABASE']=$f['database']; $env['APP_ENV']='testing';
        $process=proc_open(array_merge([PHP_BINARY,__DIR__.'/../migrations/school_year_enrollment.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if (!is_resource($process)) throw new RuntimeException('CLI subprocess unavailable');
        fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process),$out,$err];
    };
    $private=sys_get_temp_dir().'/enrollment_inventory_'.bin2hex(random_bytes(8)).'.json';
    $mapPath=sys_get_temp_dir().'/enrollment_mapping_'.bin2hex(random_bytes(8)).'.json';
    try {
        [$exit,$out,$err]=$run(['--inventory','--output='.$private]);
        checkEnrollment($exit===0 && $err==='', 'CLI writes inventory to a private file');
        checkEnrollment(!str_contains($out,'SYNTHETIC') && !str_contains($out,'Synthetic'), 'CLI summary contains no profile data');
        $inventory=json_decode(file_get_contents($private),true,512,JSON_THROW_ON_ERROR);
        checkEnrollment($inventory['fingerprint']===inventoryEnrollmentHistory($f['db'])['fingerprint'],'Inventory fingerprints are stable across connection time zones');
        [$exit]=$run(['--inventory']); checkEnrollment($exit!==0,'Large inventory requires an explicit private destination');
        [$exit]=$run(['--inventory','--output='.__DIR__.'/public-inventory.json']); checkEnrollment($exit!==0,'CLI refuses web accessible inventory files');
        $map=['inventory_fingerprint'=>$inventory['fingerprint'],'school_years'=>[],'sections'=>[],'enrollments'=>[],'attendance'=>[]];
        file_put_contents($mapPath,json_encode($map,JSON_THROW_ON_ERROR));
        [$exit]=$run(['--apply-map='.$mapPath,'--actor-id=7']); checkEnrollment($exit!==0,'CLI rejects teacher attribution');
        [$exit,$out,$err]=$run(['--apply-map='.$mapPath,'--actor-id=1']); checkEnrollment($exit===0 && $err==='','CLI verifies active administrator and applies mapping');
        [$exit,$out,$err]=$run(['--readiness=1','--output='.$private.'-readiness']); checkEnrollment($exit===0 && $err==='','CLI exports ID-only readiness to private file');
    } finally {
        foreach ([$private,$mapPath,$private.'-readiness'] as $path) if (is_file($path)) unlink($path);
    }
});
echo 'Enrollment migration tests passed (' . ($GLOBALS['enrollment_checks'] ?? 0) . " checks).\n";
