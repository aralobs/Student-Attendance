<?php
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/absence_interventions.php';
withEnrollmentFixture(function(array $f):void {
    $db=$f['db']; ensureDeliverySchema($db); ensureAttendanceDecisionSchema($db); ensureInterventionSchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $db->exec("INSERT INTO absence_episodes(enrollment_id,starts_on,ends_on,absence_count,state,blocked_on,notification_owner) VALUES
        (1001,'2026-10-01','2026-10-05',3,'warning',NULL,'filter-warning'),
        (1001,'2026-10-06','2026-10-12',0,'needs_review','2026-09-30','filter-review'),
        (1006,'2026-10-01','2026-10-09',5,'intervention_required',NULL,'filter-required')");
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    try {
        $read=function(array $filters=[],?array $actor=null)use($copy,$f):array {
            $r=runEnrollmentAppEndpoint($copy,$f,'attendance/interventions.php',$actor??$f['admin'],[],$filters,'GET');
            $doc=new DOMDocument(); @$doc->loadHTML($r['body']); return [$r,new DOMXPath($doc)];
        };
        [$r,$x]=$read();
        foreach(['school_year_id','date','grade','section','type'] as $name) checkEnrollment($x->query('//*[@name="'.$name.'"]')->length===1,'Attendance-style filter: '.$name);
        checkEnrollment($x->query('//table/tbody/tr/td[1]')->length===3,'Default date does not hide historical episodes');
        checkEnrollment(str_contains($r['body'],'Counted unexcused')&&str_contains($r['body'],'First unresolved date'),'Review and count meaning is explicit');
        [$r,$x]=$read(['grade'=>'Grade 3']);
        checkEnrollment($x->query('//table/tbody/tr/td[1]')->length===2,'Grade filters cases');
        checkEnrollment($x->query('//select[@name="section"]/option[@value="16"]')->length===0,'Grade filters section options');
        [$r,$x]=$read(['section'=>16]);
        checkEnrollment($x->query('//table/tbody/tr/td[1]')->length===1&&str_contains($x->query('//table/tbody')->item(0)->textContent,'Six'),'Section filters cases');
        [$r,$x]=$read(['type'=>'needs_review']);
        checkEnrollment($x->query('//table/tbody/tr/td[1]')->length===1&&str_contains($x->query('//table/tbody')->item(0)->textContent,'First unresolved date: 2026-09-30'),'Status includes zero-count review case');
        [$r,$x]=$read(['date'=>'2026-10-03']);
        checkEnrollment($x->query('//table/tbody/tr/td[1]')->length===2,'Optional date finds episodes covering date');
        [$r,$x]=$read(['date'=>'2026-09-30']);
        checkEnrollment($x->query('//table/tbody/tr/td[1]')->length===1&&str_contains($x->query('//table/tbody')->item(0)->textContent,'First unresolved date'),'Date also finds the blocking review date');
        [$r,$x]=$read(['grade'=>'Grade 3','section'=>16]);
        checkEnrollment($r['status']===200&&!str_contains($x->query('//table/tbody')->item(0)->textContent,'Six'),'Stale section resets after grade change');
        [$r,$x]=$read(['section'=>11],$f['teacher']);
        checkEnrollment($r['status']===400&&!str_contains($x->query('//table/tbody')->item(0)->textContent,'Three'),'Unauthorized section rejected');
        [$r,$x]=$read([],$f['otherTeacher']);
        checkEnrollment(!str_contains($x->query('//table/tbody')->item(0)->textContent,'Three'),'All sections respects adviser scope');
        foreach([['type'=>'bad'],['grade'=>'bad'],['date'=>'2026-99-99'],['school_year_id'=>999]] as $filters) {
            [$r,$x]=$read($filters); checkEnrollment($r['status']===400,'Invalid filter rejected');
        }
        [$r,$x]=$read(['school_year_id'=>2]);
        checkEnrollment($x->query('//select[@name="section"]/option[@value="10"]')->length===0&&$x->query('//select[@name="section"]/option[@value="20"]')->length===1,'Sections follow school year');
    } finally {removeEnrollmentAppFixture($copy);}
});
echo 'Intervention filters passed ('.$GLOBALS['enrollment_checks']." checks).\n";
