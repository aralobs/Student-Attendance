<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/enrollment_schema.php';
require_once dirname(__DIR__).'/includes/enrollment_migration.php';
require_once dirname(__DIR__).'/includes/moving_up.php';
require_once dirname(__DIR__).'/includes/enrollment_corrections.php';
require_once dirname(__DIR__).'/includes/enrollment_reports.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once __DIR__.'/support/enrollment_recovery_proof.php';
$GLOBALS['workflow_notifications']=0;
function sendSMS(...$args) { $GLOBALS['workflow_notifications']++; throw new RuntimeException('Unexpected SMS'); }
function sendArrivalEmail(...$args) { $GLOBALS['workflow_notifications']++; throw new RuntimeException('Unexpected email'); }

withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; ensureEnrollmentSchema($db);
    $db->exec("INSERT INTO students(id,lrn,first_name,last_name,gender,section_id,grade_level,school_year,qr_token) VALUES(102,'SYNTHETIC102','Synthetic','Retained','Male',10,'Grade 3','2026-2027','synthetic-102'); INSERT INTO attendance(id,student_id,date,am_in) VALUES(502,101,'2025-10-21','07:14:32')");
    $identity=$db->query('SELECT id,lrn,qr_token,first_name,last_name,parent_contact FROM students ORDER BY id')->fetchAll();
    $scans=$db->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
    $inventory=inventoryEnrollmentHistory($db);
    checkEnrollment(count($inventory['issues'])>0 && !enrollmentModelEnabled($db),'Synthetic legacy import remains unresolved and disabled');
    $map=['inventory_fingerprint'=>$inventory['fingerprint'],
        'school_years'=>[['label'=>'2026-2027','starts_on'=>'2026-06-01','ends_on'=>'2027-03-31'],['label'=>'2027-2028','starts_on'=>'2027-06-01','ends_on'=>'2028-03-31']],
        'sections'=>[], 'enrollments'=>[], 'attendance'=>[['attendance_id'=>501,'assignment_key'=>'a101']]];
    foreach ([10,11,16,20,21] as $id) $map['sections'][]=['section_id'=>$id,'school_year_label'=>in_array($id,[20,21],true)?'2027-2028':'2026-2027'];
    foreach ([101,102,106] as $id) {
        $map['enrollments'][]=['key'=>'e'.$id,'student_id'=>$id,'school_year_label'=>'2026-2027','state'=>'prepared','starts_on'=>'2026-06-01','ends_on'=>'2027-03-31',
            'assignments'=>[['key'=>'a'.$id,'section_id'=>$id===106?16:10,'grade_level'=>$id===106?'Grade 6':'Grade 3','starts_on'=>'2026-06-01','ends_on'=>'2027-03-31']]];
    }
    $mapped=applyEnrollmentMappings($db,$f['admin'],$map);
    $current=(int)$db->query("SELECT id FROM school_years WHERE label='2026-2027'")->fetchColumn();
    $next=(int)$db->query("SELECT id FROM school_years WHERE label='2027-2028'")->fetchColumn();
    $ready=enrollmentMigrationReadiness($db,$current);
    checkEnrollment($ready['ready'] && count($ready['historical_issues'])>0,'Explicit mapping resolves current ownership while historical ambiguity stays visible');
    checkEnrollment(function_exists('enrollmentInitializationPreview') && function_exists('initializeEnrollmentModel'),'Explicit initialization must complete the mapped legacy-to-managed workflow');
    $p=enrollmentInitializationPreview($db,$f['admin'],$current);
    initializeEnrollmentModel($db,$f['admin'],$current,$p['fingerprint']);
    checkEnrollment(enrollmentModelEnabled($db),'Explicit initial cutover enables annual consumers');
    $old=loadEnrollmentReport($db,$f['admin'],$current,10,'2026-10-01','2026-10-31');
    checkEnrollment($old['unresolved_count']>0 && count($old['attendance'])===1,'Historical report visibly qualifies unresolved legacy history');
    $beforePreparation=$db->query('SELECT * FROM students ORDER BY id')->fetchAll();
    foreach ([[101,'promoted',20],[102,'retained',21],[106,'graduated',null]] as [$id,$outcome,$destination]) {
        $command=['source_year_id'=>$current,'source_section_id'=>$id===106?16:10,'student_ids'=>[$id],'target_year_id'=>$next,'outcome'=>$outcome,'destination_section_id'=>$destination];
        $preview=previewMovingUp($db,$f['admin'],$command);
        checkEnrollment(count($preview['rows'])===1 && $preview['rows'][0]['outcome']===$outcome,'Each explicit outcome appears in preview');
        prepareMovingUp($db,$f['admin'],$command,'workflow-'.$outcome,$preview['fingerprint']);
    }
    checkEnrollment($beforePreparation===$db->query('SELECT * FROM students ORDER BY id')->fetchAll(),'Preparing all outcomes preserves today placement');
    checkEnrollment($old===loadEnrollmentReport($db,$f['admin'],$current,10,'2026-10-01','2026-10-31'),'Preparation preserves historical report');
    $p=schoolYearActivationPreview($db,$f['admin'],$next);
    checkEnrollment($p['ready'] && $p['counts']['promoted']===1 && $p['counts']['retained']===1 && $p['counts']['graduated']===1,'Activation integrates all three reviewed outcomes');
    activateSchoolYear($db,$f['admin'],$next,$p['fingerprint']);
    checkEnrollment(getActiveSchoolYear($db)['id']===$next,'Next year activates atomically');
    checkEnrollment($old['attendance']===loadEnrollmentReport($db,$f['admin'],$current,10,'2026-10-01','2026-10-31')['attendance'],'Closed-year report retains original scans');
    $change=['kind'=>'historical_assignment','reason'=>'Synthetic reviewed historical correction','assignment_id'=>$mapped['assignments']['a101'],'section_id'=>11,'starts_on'=>'2026-10-01','ends_on'=>'2027-03-31'];
    $p=previewEnrollmentCorrection($db,$f['admin'],$mapped['enrollments']['e101'],$change);
    checkEnrollment($p['affected_attendance_ids']===[501],'Correction preview names exact affected scan');
    applyEnrollmentCorrection($db,$f['admin'],$p['correction_id'],$p['fingerprint']);
    checkEnrollment(count(loadEnrollmentReport($db,$f['admin'],$current,11,'2026-10-01','2026-10-31')['attendance'])===1,'Reviewed correction moves historical ownership to verified section');
    checkEnrollment($identity===$db->query('SELECT id,lrn,qr_token,first_name,last_name,parent_contact FROM students ORDER BY id')->fetchAll(),'Entire workflow preserves profile identity, QR and contacts');
    $after=$db->query('SELECT * FROM attendance ORDER BY id')->fetchAll();
    foreach ($scans as $index=>$row) {
        unset($row['enrollment_id'],$row['assignment_id'],$after[$index]['enrollment_id'],$after[$index]['assignment_id']);
        checkEnrollment($row===$after[$index],'Workflow preserves every original scan value and timestamp');
    }
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM students')->fetchColumn()===3,'No duplicated profiles');
    checkEnrollment($GLOBALS['workflow_notifications']===0 && (int)$db->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'Entire workflow sends zero notifications');
},['legacy'=>true]);

// A graduate remains the same live profile and must not block a later cycle.
foreach ([false,true] as $cancelledRetention) withEnrollmentFixture(function(array $f) use($cancelledRetention): void {
    $db=$f['db']; $p=enrollmentInitializationPreview($db,$f['admin'],1);
    initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']);
    $cancelled=null;
    if ($cancelledRetention) {
        $db->exec("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(22,'Next Six','Grade 6',7,'2027-2028',2)");
        $command=['source_year_id'=>1,'source_section_id'=>16,'student_ids'=>[106],'target_year_id'=>2,'outcome'=>'retained','destination_section_id'=>22];
        $p=previewMovingUp($db,$f['admin'],$command); $batch=prepareMovingUp($db,$f['admin'],$command,'cycle-cancel-retention',$p['fingerprint']);
        $cancelled=$batch['destination_enrollment_ids'][0];
        revisePreparedEnrollment($db,$f['admin'],$cancelled,['kind'=>'cancel','reason'=>'Verified graduation instead'],1);
    }
    foreach ([[101,10,'promoted',20],[106,16,'graduated',null]] as [$id,$section,$outcome,$destination]) {
        $command=['source_year_id'=>1,'source_section_id'=>$section,'student_ids'=>[$id],'target_year_id'=>2,'outcome'=>$outcome,'destination_section_id'=>$destination];
        $p=previewMovingUp($db,$f['admin'],$command); prepareMovingUp($db,$f['admin'],$command,'cycle-'.$outcome,$p['fingerprint']);
    }
    $p=schoolYearActivationPreview($db,$f['admin'],2); activateSchoolYear($db,$f['admin'],2,$p['fingerprint']);
    $graduate=$db->query('SELECT * FROM students WHERE id=106')->fetch();
    checkEnrollment((int)$graduate['is_active']===1 && $graduate['enrollment_status']==='active','Applied graduation remains metadata, not a profile archive/status rewrite');
    $third=savePlannedSchoolYear($db,$f['admin'],['label'=>'2028-2029','starts_on'=>'2028-06-01','ends_on'=>'2029-03-31']);
    $db->prepare("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(30,'Third Five','Grade 5',7,'2028-2029',?)")->execute([$third]);
    $command=['source_year_id'=>2,'source_section_id'=>20,'student_ids'=>[101],'target_year_id'=>$third,'outcome'=>'promoted','destination_section_id'=>30];
    $p=previewMovingUp($db,$f['admin'],$command); prepareMovingUp($db,$f['admin'],$command,'cycle-third',$p['fingerprint']);
    $p=schoolYearActivationPreview($db,$f['admin'],$third);
    checkEnrollment($p['ready'],'Applied prior-year graduation must not block third-year activation');
    $db->exec("UPDATE moving_up_decisions SET version=version+1 WHERE outcome='graduated'");
    expectEnrollmentError(DomainException::class,fn()=>activateSchoolYear($db,$f['admin'],$third,$p['fingerprint']));
    checkEnrollment(getActiveSchoolYear($db)['id']===2,'Graduation metadata changes invalidate a previously ready preview');
    $db->exec("UPDATE students SET section_id=16,grade_level='Grade 6',school_year='2026-2027' WHERE id=106");
    checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],$third)['ready'],'Conflicting graduate projection still requires reviewed mapping');
    $db->exec('UPDATE students SET section_id=NULL,grade_level=NULL,school_year=NULL WHERE id=106');
    // An unknown unassigned live profile is not equivalent to a verified graduate.
    $db->exec("INSERT INTO students(id,lrn,first_name,last_name,gender,qr_token) VALUES(109,'SYNTHETIC109','Synthetic','Unknown','Male','synthetic-109')");
    checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],$third)['ready'],'Unknown unassigned profile still blocks later activation');
    $db->exec('UPDATE students SET is_active=0 WHERE id=109');
    $db->exec("INSERT INTO attendance(student_id,date) VALUES(106,'2027-10-21')");
    checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],$third)['ready'],'Current unmapped scan cannot be waived by prior graduation');
    $db->exec("DELETE FROM attendance WHERE student_id=106 AND date='2027-10-21'");
    if (!$cancelledRetention) {
        $db->exec("INSERT INTO sections(id,section_name,grade_level,adviser_id,school_year,school_year_id) VALUES(22,'Returned Six','Grade 6',7,'2027-2028',2); INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES(1106,106,2,'active','2027-06-01','2028-03-31')");
        checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],$third)['ready'],'Re-enrolled graduate with missing current assignment is fully validated');
        $db->exec("INSERT INTO enrollment_sections(enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES(1106,22,'Grade 6','2027-06-01','2028-03-31')");
        checkEnrollment(schoolYearActivationPreview($db,$f['admin'],$third)['ready'],'Valid re-enrollment uses ordinary outgoing readiness');
        $db->exec('DELETE FROM enrollment_sections WHERE enrollment_id=1106; DELETE FROM student_enrollments WHERE id=1106');
    }
    if ($cancelled!==null) {
        $assignment=(int)$db->query('SELECT id FROM enrollment_sections WHERE enrollment_id='.$cancelled)->fetchColumn();
        $db->prepare("INSERT INTO attendance(student_id,date,enrollment_id,assignment_id) VALUES(106,'2026-10-20',NULL,?)")->execute([$assignment]);
        checkEnrollment(!schoolYearActivationPreview($db,$f['admin'],$third)['ready'],'A reference to cancelled retention cannot be waived by graduation');
        $db->exec("DELETE FROM attendance WHERE student_id=106 AND date='2026-10-20'");
        checkEnrollment($db->query('SELECT state FROM student_enrollments WHERE id='.$cancelled)->fetchColumn()==='cancelled','Cancelled unused retention identity remains preserved');
    }
    $p=schoolYearActivationPreview($db,$f['admin'],$third); activateSchoolYear($db,$f['admin'],$third,$p['fingerprint']);
    checkEnrollment(getActiveSchoolYear($db)['id']===$third,'Third year activates after valid follow-up');
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments WHERE student_id=106')->fetchColumn()===($cancelledRetention?2:1),'Second cycle invents no annual enrollment for graduate');
    $after=$db->query('SELECT * FROM students WHERE id=106')->fetch(); unset($graduate['updated_at'],$after['updated_at']);
    checkEnrollment($graduate===$after,'Second cycle preserves graduated learner profile');
});

withEnrollmentFixture('verifyEnrollmentSyntheticRecovery',['legacy'=>true]);

foreach ([false,true] as $managed) withEnrollmentFixture(function(array $f) use($managed): void {
    $db=$f['db'];
    $db->exec("INSERT INTO users(id,username,password,full_name,role) VALUES(9,'fixture_scanner','unused','Synthetic Scanner','scanner_operator'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    if ($managed) { $p=enrollmentInitializationPreview($db,$f['admin'],1); initializeEnrollmentModel($db,$f['admin'],1,$p['fingerprint']); }
    $copy=sys_get_temp_dir().'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    try {
        file_put_contents($copy.'/fixture-photo-path.php', <<<'PHP'
<?php
require_once __DIR__.'/config/database.php'; require_once __DIR__.'/includes/student_photos.php';
$_SERVER['DOCUMENT_ROOT']=__DIR__;
$directory=studentPhotoDirectory();
if (!is_dir($directory) || dirname(realpath($directory))!==dirname(realpath(__DIR__)) || realpath($directory)===realpath(__DIR__)) throw new RuntimeException('Fixture photo root is not a private sibling');
echo 'PRIVATE_PHOTO_ROOT_OK';
PHP);
        $r=runEnrollmentAppEndpoint($copy,$f,'fixture-photo-path.php',$f['admin'],[],[],'GET');
        checkEnrollment(str_contains($r['body'],'PRIVATE_PHOTO_ROOT_OK'),'Copied installation satisfies real photo document-root guard');
        $post=['lrn'=>'SYNTHETIC101','first_name'=>'Synthetic Updated','last_name'=>'Three','gender'=>'Male'];
        $placement=static function() use($db): array {
            return [$db->query('SELECT id,section_id,grade_level,school_year FROM students ORDER BY id')->fetchAll(),$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll(),$db->query('SELECT * FROM enrollment_sections ORDER BY id')->fetchAll(),$db->query('SELECT * FROM attendance ORDER BY id')->fetchAll()];
        };
        $before=$placement();
        foreach ([$f['teacher'],['id'=>9,'role'=>'scanner_operator']] as $actor) {
            $r=runEnrollmentAppEndpoint($copy,$f,'students/edit.php',$actor,$post+['section_id'=>16],['id'=>101]);
            checkEnrollment($r['status']===403,'Teacher/scanner cannot transfer via Profile Edit');
        }
        if ($managed) {
            $r=runEnrollmentAppEndpoint($copy,$f,'students/enrollment_corrections.php',$f['admin'],[],['enrollment_id'=>1001],'GET');
            checkEnrollment($r['status']===200 && preg_match('/<option value="1001"[^>]*>(.*?)<\/option>/s',$r['body'],$choice)===1,'Administrator can render and select the known annual enrollment');
            checkEnrollment(str_contains($choice[1],'Synthetic') && str_contains($choice[1],'Three') && str_contains($choice[1],'SYNTHETIC101') && str_contains($choice[1],'2026-2027') && str_contains($choice[1],'active'),'Correction selector identifies learner by display name, LRN, year and state');
            $context=substr($r['body'],strpos($r['body'],'</select>')+9);
            checkEnrollment(str_contains($context,'Synthetic') && str_contains($context,'Three') && str_contains($context,'SYNTHETIC101'),'Selected correction context identifies the learner by name and LRN');
            $db->exec("UPDATE students SET first_name='Synthetic <Name>',last_name='Three & Family',lrn='SYNTHETIC<101>' WHERE id=101");
            $r=runEnrollmentAppEndpoint($copy,$f,'students/enrollment_corrections.php',$f['admin'],[],['enrollment_id'=>1001],'GET');
            checkEnrollment(substr_count($r['body'],'Synthetic &lt;Name&gt;')===2 && substr_count($r['body'],'Three &amp; Family')===2 && substr_count($r['body'],'SYNTHETIC&lt;101&gt;')===2 && !str_contains($r['body'],'Synthetic <Name>'),'Correction option and selected context escape learner name and LRN');
            $db->exec("UPDATE sections SET section_name='Current <B> & Class' WHERE id=11");
            $r=runEnrollmentAppEndpoint($copy,$f,'students/enrollment_corrections.php',$f['admin'],['enrollment_id'=>1001,'kind'=>'historical_assignment','assignment_id'=>2001,'section_id'=>11,'starts_on'=>'2026-10-01','ends_on'=>'2027-03-31','reason'=>'Synthetic identity preview']);
            checkEnrollment($r['status']===200 && str_contains($r['body'],'Review correction #'),'Known historical correction renders its preview');
            $previewText=substr($r['body'],strpos($r['body'],'Review correction #'));
            checkEnrollment(str_contains($previewText,'Synthetic &lt;Name&gt;') && str_contains($previewText,'Three &amp; Family') && str_contains($previewText,'SYNTHETIC&lt;101&gt;') && str_contains($previewText,'2026-2027'),'Correction preview identifies the same learner with escaped name, LRN and year');
            checkEnrollment(str_contains($previewText,'Reviewed historical assignment') && str_contains($previewText,'Grade 3') && str_contains($previewText,'Current &lt;B&gt; &amp; Class') && !str_contains($previewText,'historical_assignment'),'Correction preview uses readable kind and escaped destination grade/name');
            $db->exec("UPDATE sections SET section_name='Current B' WHERE id=11");
            $db->exec("UPDATE students SET first_name='Synthetic',last_name='Three',lrn='SYNTHETIC101' WHERE id=101");
            checkEnrollment($before===$placement(),'Rendering correction identity leaves annual placement and attendance unchanged');
            $r=runEnrollmentAppEndpoint($copy,$f,'students/edit.php',$f['admin'],$post+['section_id'=>11],['id'=>101]);
            checkEnrollment($r['status']===403 && $before===$placement(),'Managed Profile Edit rejects changed section without mutation');
            $r=runEnrollmentAppEndpoint($copy,$f,'students/edit.php',$f['admin'],$post+['grade_level'=>'Grade 6','school_year'=>'2099-2100','school_year_id'=>2],['id'=>101]);
            checkEnrollment($r['status']<400 && $before===$placement(),'Forged grade/year fields have no effect on annual or projected placement');
            checkEnrollment($db->query('SELECT first_name FROM students WHERE id=101')->fetchColumn()==='Synthetic Updated','Profile-only edit still saves authorized profile fields');
            $r=runEnrollmentAppEndpoint($copy,$f,'students/edit.php',$f['admin'],[],['id'=>101],'GET');
            checkEnrollment(str_contains($r['body'],'enrollment_corrections.php'),'Managed Profile Edit links to correction workflow');
            $r=runEnrollmentAppEndpoint($copy,$f,'students/bulk_assign_section.php',$f['admin'],['section_id'=>11,'student_ids'=>[101]]);
            checkEnrollment($r['status']===409 && str_contains($r['body'],'enrollment_corrections.php') && $before===$placement(),'Legacy bulk placement entrypoint directs managed moves to correction');
            foreach ([$f['teacher'],['id'=>9,'role'=>'scanner_operator']] as $actor) {
                $r=runEnrollmentAppEndpoint($copy,$f,'students/save_enrollment_correction.php',$actor,['enrollment_id'=>1001,'kind'=>'cancel','reason'=>'Forged role']);
                checkEnrollment($r['status']===403 && $before===$placement(),'Teacher/scanner correction POST denied without placement mutation');
            }
            // Run the actual installed CLI worker with deliberately misleading
            // legacy caches and a legacy-only live profile. Version 1 must use
            // annual ownership and never fall back to those projections.
            $db->exec("UPDATE students SET section_id=20,created_at='2020-01-01'; UPDATE sections SET created_at='2020-01-01'; INSERT INTO students(id,lrn,first_name,last_name,gender,section_id,qr_token,created_at) VALUES(109,'SYNTHETIC109','Synthetic','Legacy Only','Male',10,'synthetic-109','2020-01-01'); INSERT INTO system_settings(setting_key,setting_value) VALUES('attendance_absence_last_date','2026-10-20')");
            $r=runEnrollmentAppEndpoint($copy,$f,'attendance/finalize_absences.php',$f['admin'],[],[],'GET');
            checkEnrollment(str_contains($r['body'],'Finalized '),'Converted real CLI worker completes');
            checkEnrollment((int)$db->query("SELECT COUNT(*) FROM attendance WHERE student_id=101 AND date='2026-10-20' AND enrollment_id=1001 AND assignment_id=2001")->fetchColumn()===1,'Worker records managed owner despite wrong current profile cache');
            checkEnrollment((int)$db->query('SELECT COUNT(*) FROM attendance WHERE student_id=109')->fetchColumn()===0,'Post-cutover worker cannot select legacy-only profile');
            checkEnrollment(!is_file($copy.'/provider-calls.log'),'Profile, correction denials and finalizer send zero provider calls');
        } else {
            $r=runEnrollmentAppEndpoint($copy,$f,'students/edit.php',$f['admin'],$post+['section_id'=>11],['id'=>101]);
            checkEnrollment($r['status']<400 && (int)$db->query('SELECT section_id FROM students WHERE id=101')->fetchColumn()===11,'Version 0 administrator Profile Edit retains legacy placement behavior');
            $r=runEnrollmentAppEndpoint($copy,$f,'students/bulk_assign_section.php',$f['admin'],['section_id'=>10,'student_ids'=>[101]]);
            checkEnrollment($r['status']<400 && (int)$db->query('SELECT section_id FROM students WHERE id=101')->fetchColumn()===10,'Version 0 legacy bulk placement remains functional');
            checkEnrollment(!enrollmentModelEnabled($db),'Legacy entrypoints never automatically initialize model');
        }
        checkEnrollment(!is_file($copy.'/errors.log') || trim(file_get_contents($copy.'/errors.log'))==='','Copied real endpoints emit no PHP error log');
    } finally { removeEnrollmentAppFixture($copy); }
});

echo 'Enrollment workflow tests passed ('.($GLOBALS['enrollment_checks']??0)." checks).\n";
