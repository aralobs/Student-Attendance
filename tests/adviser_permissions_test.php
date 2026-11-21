<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/enrollment_commands.php';

withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $before=(int)$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn();
    expectEnrollmentError(DomainException::class,fn()=>createAnnualEnrollment($db,$f['teacher'],101,2,20,'2027-06-01','2028-03-31'));
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn()===$before,'Advisers cannot enroll learners even in their assigned section');
    require_once dirname(__DIR__).'/includes/adviser_workflows.php';
    ensureAdviserWorkflowSchema($db);
    ensureDeliverySchema($db); ensureAttendanceDecisionSchema($db); ensureInterventionSchema($db);
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
    requireTeacherAttendanceDate($db,$f['teacher'],$today->format('Y-m-d'),$today);
    requireTeacherAttendanceDate($db,$f['teacher'],$today->modify('-7 days')->format('Y-m-d'),$today);
    expectEnrollmentError(DomainException::class,fn()=>requireTeacherAttendanceDate($db,$f['teacher'],$today->modify('-8 days')->format('Y-m-d'),$today));
    expectEnrollmentError(DomainException::class,fn()=>requireTeacherAttendanceDate($db,$f['teacher'],$today->modify('+1 day')->format('Y-m-d'),$today));
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('teacher_attendance_edit_days','0')");
    expectEnrollmentError(DomainException::class,fn()=>requireTeacherAttendanceDate($db,$f['teacher'],$today->modify('-1 day')->format('Y-m-d'),$today));
    $q=$db->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('attendance_locked_through',?)");$q->execute([$today->format('Y-m-d')]);
    expectEnrollmentError(DomainException::class,fn()=>requireTeacherAttendanceDate($db,$f['teacher'],$today->format('Y-m-d'),$today));
    requireTeacherAttendanceDate($db,$f['admin'],$today->modify('-40 days')->format('Y-m-d'),$today);
    $request=createLearnerChangeRequest($db,$f['teacher'],101,'profile','Please correct the guardian number after verification.');
    checkEnrollment(count(loadLearnerChangeRequests($db,$f['teacher']))===1,'Assigned adviser sees request');
    checkEnrollment(loadLearnerChangeRequests($db,$f['otherTeacher'])===[],'Other adviser cannot read learner requests');
    expectEnrollmentError(DomainException::class,fn()=>createLearnerChangeRequest($db,$f['otherTeacher'],101,'profile','Foreign request'));
    expectEnrollmentError(InvalidArgumentException::class,fn()=>createLearnerChangeRequest($db,$f['teacher'],101,'profile',' '));
    expectEnrollmentError(DomainException::class,fn()=>reviewLearnerChangeRequest($db,$f['teacher'],$request,'resolved','Unauthorized'));
    reviewLearnerChangeRequest($db,$f['admin'],$request,'resolved','Verified and corrected using the profile form.');
    expectEnrollmentError(DomainException::class,fn()=>reviewLearnerChangeRequest($db,$f['admin'],$request,'rejected','Stale review'));
    $row=loadLearnerChangeRequests($db,$f['admin'])[0];
    checkEnrollment($row['status']==='resolved' && str_contains($row['details'],'guardian'),'Review status and decrypted details retained');
    checkEnrollment(!str_contains($db->query('SELECT details_ciphertext FROM learner_change_requests')->fetchColumn(),'guardian'),'Request details encrypted at rest');
    $db->exec("INSERT INTO absence_episodes(enrollment_id,starts_on,ends_on,absence_count,state,active,notification_owner) VALUES(1001,'2026-10-19','2026-10-21',3,'warning',1,'synthetic-followup')");
    $episode=(int)$db->lastInsertId();
    saveInterventionFollowup($db,$f['teacher'],$episode,'contacted','Guardian contacted; follow-up agreed.');
    expectEnrollmentError(DomainException::class,fn()=>saveInterventionFollowup($db,$f['otherTeacher'],$episode,'resolved','Foreign note'));
    expectEnrollmentError(InvalidArgumentException::class,fn()=>saveInterventionFollowup($db,$f['teacher'],$episode,'resolved',''));
    checkEnrollment(count(loadInterventionFollowups($db,$f['teacher'],[$episode]))===1,'Follow-up saved without changing absence evidence');
    checkEnrollment(loadInterventionFollowups($db,$f['otherTeacher'],[$episode])===[],'Follow-ups obey episode scope');
    $db->exec('UPDATE sections SET adviser_id=8 WHERE id=10');
    expectEnrollmentError(DomainException::class,fn()=>createLearnerChangeRequest($db,$f['teacher'],101,'profile','Revoked assignment'));
    expectEnrollmentError(DomainException::class,fn()=>saveInterventionFollowup($db,$f['teacher'],$episode,'resolved','Revoked assignment'));
    $db->exec("UPDATE school_years SET state='closed' WHERE id=1");
    expectEnrollmentError(DomainException::class,fn()=>saveInterventionFollowup($db,$f['otherTeacher'],$episode,'resolved','Closed year'));
    $copy=sys_get_temp_dir().'/enrollment_app_'.bin2hex(random_bytes(8));
    $db->exec("UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    createEnrollmentAppFixture($copy);
    try {
        foreach(['students/add.php','students/enroll.php','students/edit.php','students/save_enrollment.php'] as $endpoint) {
            $result=runEnrollmentAppEndpoint($copy,$f,$endpoint,$f['otherTeacher'],[],['id'=>101],'GET');
            checkEnrollment($result['status']===403,'Direct teacher access denied: '.$endpoint);
        }
    } finally { removeEnrollmentAppFixture($copy); }
});

withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];ensureDeliverySchema($db);ensureAttendanceDecisionSchema($db);ensureInterventionSchema($db);ensureAdviserWorkflowSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1');UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $db->exec("INSERT INTO absence_episodes(enrollment_id,starts_on,ends_on,absence_count,state,notification_owner) VALUES(1001,'2026-10-19','2026-10-21',3,'warning','synthetic-endpoint')");
    $episode=(int)$db->lastInsertId();
    $copy=sys_get_temp_dir().'/enrollment_app_'.bin2hex(random_bytes(8));createEnrollmentAppFixture($copy);
    try {
        $post=['student_id'=>101,'kind'=>'attendance','details'=>'Please correct the learning date 2026-10-01.'];
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['teacher'],$post);
        checkEnrollment($r['status']===302 && (int)$db->query('SELECT COUNT(*) FROM learner_change_requests')->fetchColumn()===1,'Teacher can submit a request through the real page');
        $id=(int)$db->query('SELECT id FROM learner_change_requests')->fetchColumn();
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['teacher'],$post+['csrf_token'=>'invalid']);
        checkEnrollment($r['status']===403 && (int)$db->query('SELECT COUNT(*) FROM learner_change_requests')->fetchColumn()===1,'Invalid CSRF creates no request');
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['otherTeacher'],$post);
        checkEnrollment($r['status']===400 && (int)$db->query('SELECT COUNT(*) FROM learner_change_requests')->fetchColumn()===1,'Forged learner request has no effect');
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['teacher'],['request_id'=>$id,'status'=>'resolved','review_note'=>'Forged review']);
        checkEnrollment($r['status']===400 && $db->query('SELECT status FROM learner_change_requests')->fetchColumn()==='pending','Teacher cannot review requests');
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['admin'],[],[],'GET');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Mark Resolved') && str_contains($r['body'],'2026-10-01'),'Administrator sees request and review controls');
        if (getenv('ADVISER_RENDER_QA')) file_put_contents(dirname(__DIR__).'/.qa/adviser-request-admin.html',$r['body']);
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['teacher'],[],['student_id'=>101],'GET');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Submit Request'),'Assigned adviser can open the learner request form');
        if (getenv('ADVISER_RENDER_QA')) file_put_contents(dirname(__DIR__).'/.qa/adviser-request-form.html',$r['body']);
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['teacher'],[],[],'GET');
        checkEnrollment($r['status']===200 && !str_contains($r['body'],'Mark Resolved'),'Adviser sees request status without review controls');
        $r=runEnrollmentAppEndpoint($copy,$f,'students/change_requests.php',$f['otherTeacher'],[],[],'GET');
        checkEnrollment($r['status']===200 && !str_contains($r['body'],'2026-10-01'),'Request details do not leak to another adviser');
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/interventions.php',$f['teacher'],['episode_id'=>$episode,'outcome'=>'contacted','note'=>'Synthetic parent contact']);
        checkEnrollment($r['status']===302 && (int)$db->query('SELECT COUNT(*) FROM intervention_followups')->fetchColumn()===1,'Adviser saves a follow-up through the real page');
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/interventions.php',$f['otherTeacher'],['episode_id'=>$episode,'outcome'=>'resolved','note'=>'Forged follow-up']);
        checkEnrollment($r['status']===400 && (int)$db->query('SELECT COUNT(*) FROM intervention_followups')->fetchColumn()===1,'Forged episode cannot receive a follow-up');
        $r=runEnrollmentAppEndpoint($copy,$f,'attendance/interventions.php',$f['teacher'],[],['school_year_id'=>1],'GET');
        checkEnrollment($r['status']===200 && str_contains($r['body'],'Synthetic parent contact') && str_contains($r['body'],'Record Follow-up'),'Follow-up history and form render for the assigned adviser');
        if (getenv('ADVISER_RENDER_QA')) file_put_contents(dirname(__DIR__).'/.qa/adviser-followups.html',$r['body']);
        $olderDay=(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->modify('-10 days');
        while ((int)$olderDay->format('N')>5) $olderDay=$olderDay->modify('-1 day');
        $date=$olderDay->format('Y-m-d');
        $command=['student_id'=>101,'section_id'=>10,'date'=>$date,'decision'=>'absent_excused','reason'=>'Older correction','expected_revision'=>0];
        expectEnrollmentError(DomainException::class,fn()=>saveAttendanceDecision($db,$f['teacher'],$command));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM attendance_decisions')->fetchColumn()===0,'Old-date teacher command creates no decision or notification');
        saveAttendanceDecision($db,$f['admin'],$command);
        $db->exec("CREATE TRIGGER adviser_test_audit_failure BEFORE INSERT ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
        expectEnrollmentError(PDOException::class,fn()=>createLearnerChangeRequest($db,$f['teacher'],101,'profile','Must roll back'));
        expectEnrollmentError(PDOException::class,fn()=>saveInterventionFollowup($db,$f['teacher'],$episode,'monitoring','Must roll back'));
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM learner_change_requests')->fetchColumn()===1 && (int)$db->query('SELECT COUNT(*) FROM intervention_followups')->fetchColumn()===1,'Audit failure rolls back both workflows');
        checkEnrollment(!file_exists($copy.'/provider-calls.log'),'Requests and follow-ups send no messages');
    } finally { removeEnrollmentAppFixture($copy); }
});
withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];$db->beginTransaction();
    lockLegacyTeacherAttendanceRoster($db,$f['teacher'],10,[101]);$db->rollBack();
    $db->exec('UPDATE sections SET adviser_id=8 WHERE id=10');
    $db->beginTransaction();
    expectEnrollmentError(DomainException::class,fn()=>lockLegacyTeacherAttendanceRoster($db,$f['teacher'],10,[101]));$db->rollBack();
    $db->exec('UPDATE students SET section_id=11 WHERE id=101');
    $db->beginTransaction();
    expectEnrollmentError(DomainException::class,fn()=>lockLegacyTeacherAttendanceRoster($db,$f['otherTeacher'],10,[101]));$db->rollBack();
},['legacy'=>true]);
echo "Adviser permissions passed ({$GLOBALS['enrollment_checks']} checks).\n";
