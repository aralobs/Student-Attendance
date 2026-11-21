<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/returning_student_search.php';

withEnrollmentFixture(function(array $f): void {
    $db=$f['db'];
    $db->exec("INSERT INTO students(id,lrn,first_name,last_name,gender,is_active,qr_token) VALUES
        (201,'SEARCH201','Ana','Santos','Female',1,'secret201'),
        (202,'SEARCH202','Ana','Archived','Female',0,'secret202'),
        (203,'SEARCH203','Ana','Foreign','Female',1,'secret203'),
        (204,'SEARCH204','Ana','Unplaced','Female',1,'secret204'),
        (205,'SPECIAL%_LRN','Literal','Wildcard','Male',1,'secret205');
        INSERT INTO school_years(id,label,starts_on,ends_on,state) VALUES(3,'2025-2026','2025-06-01','2026-03-31','closed');
        INSERT INTO sections(id,section_name,grade_level,schedule_type,adviser_id,is_active,school_year_id) VALUES(30,'Past','Grade 3','full_day',7,0,3);
        INSERT INTO student_enrollments(id,student_id,school_year_id,state,starts_on,ends_on) VALUES
        (1201,201,3,'completed','2025-06-01','2026-03-31'),
        (1202,202,1,'active','2026-06-01','2027-03-31'),
        (1203,203,1,'active','2026-06-01','2027-03-31');
        INSERT INTO enrollment_sections(id,enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES
        (2201,1201,30,'Grade 3','2025-06-01','2026-03-31'),
        (2202,1202,10,'Grade 3','2026-06-01','2027-03-31'),
        (2203,1203,11,'Grade 3','2026-06-01','2027-03-31')");
    $ids=static fn(array $rows): array=>array_map('intval',array_column($rows,'id'));
    checkEnrollment($ids(searchReturningStudents($db,$f['admin'],'Ana'))===[202,203,201,204],'Admin name search includes archived and unplaced identities in name order');
    checkEnrollment($ids(searchReturningStudents($db,$f['teacher'],'Ana'))===[201],'Teacher can find authorized historical student but not archived, foreign or unplaced identities');
    checkEnrollment($ids(searchReturningStudents($db,$f['admin'],'sAnToS aNa'))===[201],'Full name matching is case insensitive');
    checkEnrollment($ids(searchReturningStudents($db,$f['admin'],'SEARCH201'))===[201],'LRN search finds identity');
    checkEnrollment($ids(searchReturningStudents($db,$f['admin'],'%_'))===[205],'Wildcards are literal');
    foreach (['',' ','A'] as $query) checkEnrollment(searchReturningStudents($db,$f['admin'],$query)===[],'Short or empty query does not enumerate students');
    foreach ([['id'=>9,'role'=>'scanner'],['id'=>1,'role'=>'unknown'],['id'=>0,'role'=>'admin']] as $actor) {
        checkEnrollment(searchReturningStudents($db,$actor,'Ana')===[],'Denied actor cannot search');
        checkEnrollment(selectReturningStudent($db,$actor,201)===null,'Denied actor cannot select');
    }
    $selected=selectReturningStudent($db,$f['teacher'],201);
    checkEnrollment($selected!==null && $selected['lrn']==='SEARCH201','Scoped active identity can be selected');
    $keys=array_keys($selected); sort($keys);
    checkEnrollment($keys===['first_name','id','is_active','last_name','lrn','middle_name'],'Only minimal identity fields are returned');
    checkEnrollment(selectReturningStudent($db,$f['admin'],202)===null,'Archived identity cannot be selected');
    checkEnrollment(selectReturningStudent($db,$f['teacher'],203)===null,'Forged foreign selection is rejected');
    checkEnrollment(selectReturningStudent($db,$f['teacher'],204)===null,'Unplaced identity needs administrator lookup');
    checkEnrollment(selectReturningStudent($db,$f['admin'],204)!==null,'Administrator can select an unplaced identity');
    checkEnrollment(selectReturningStudent($db,$f['admin'],999999)===null,'Unknown identity has no selection');
    $db->exec('UPDATE sections SET adviser_id=8 WHERE id=30');
    checkEnrollment(searchReturningStudents($db,$f['teacher'],'Santos')===[],'Revoked historical adviser no longer sees identity');
    checkEnrollment(selectReturningStudent($db,$f['teacher'],201)===null,'Selection rechecks revoked access');
    for($i=0;$i<23;$i++) {
        $q=$db->prepare('INSERT INTO students(lrn,first_name,last_name,gender,is_active,qr_token) VALUES(?,?,?,?,1,?)');
        $q->execute(['BOUND'.$i,'Bounded','Match '.$i,'Male','bound-secret'.$i]);
    }
    checkEnrollment(count(searchReturningStudents($db,$f['admin'],'Bounded'))===20,'Search result size is bounded');
});
echo 'Returning student search checks passed ('.$GLOBALS['enrollment_checks'].").\n";
