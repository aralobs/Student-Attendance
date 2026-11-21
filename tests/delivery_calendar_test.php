<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/delivery_modes.php';

// A missing delivery lookup must fail on the rendered Today panel, not just a helper.
withEnrollmentFixture(function (array $f): void {
    $db=$f['db']; ensureDeliverySchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1')");
    $db->exec("UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    // Keep the fixture valid regardless of when the regression is run.
    $db->prepare('UPDATE school_years SET starts_on=?,ends_on=? WHERE id=1')->execute([$today,$today]);
    $db->prepare("INSERT INTO school_calendar(date,title,type) VALUES(?,'Synthetic learning day','school_day')")->execute([$today]);
    $deadline=$today.' 17:00:00';
    $insert=$db->prepare('INSERT INTO delivery_modes(school_year_id,scope_section_id,learning_date,mode,deadline_at,recorded_by) VALUES(1,?,?,?, ?,1)');
    $insert->execute([0,$today,'modular',$deadline]);
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    $get=['month'=>(int)substr($today,5,2),'year'=>(int)substr($today,0,4)];
    $render=fn(array $actor,array $query=[])=>runEnrollmentAppEndpoint($copy,$f,'calendar/calendar.php',$actor,[],$query?:$get,'GET');
    $parse=function(array $response) use ($today): array {
        checkEnrollment($response['status']===200,'Calendar request succeeds');
        $doc=new DOMDocument(); @$doc->loadHTML($response['body']); $xp=new DOMXPath($doc);
        $panel=$xp->query('//div[contains(@class,"card-body")][div[normalize-space(.)="Today"]]')->item(0);
        checkEnrollment($panel!==null,'Today panel renders');
        $cell=$xp->query('//*[@data-date="'.$today.'"]')->item(0);
        return [$panel->textContent,$cell?->textContent ?? '',$response['body']];
    };
    $monthEntries=function(string $html): string {
        $doc=new DOMDocument(); @$doc->loadHTML($html); $xp=new DOMXPath($doc);
        $list=$xp->query('//div[contains(@class,"card")][div[contains(@class,"card-header") and contains(normalize-space(.),"Entries")]]')->item(0);
        checkEnrollment($list!==null,'Month entries panel renders');
        return $list->textContent;
    };
    try {
        [$panel,$cell,$html]=$parse($render($f['admin']));
        file_put_contents(dirname(__DIR__).'/.qa/delivery-calendar.html',$html);
        checkEnrollment(str_contains($panel,'Modular') && !str_contains($panel,'Regular school day'),'Today displays saved Modular instead of regular class');
        checkEnrollment(str_contains($cell,'Modular'),'Month cell displays saved Modular');
        checkEnrollment(str_contains($panel,$deadline),'Today includes modular deadline in Manila time');
        $entries=$monthEntries($html);
        checkEnrollment(str_contains($entries,'Modular') && str_contains($entries,'Whole school') && str_contains($entries,$deadline),'Month list includes delivery scope, mode and deadline alongside events');
        $db->prepare('DELETE FROM school_calendar WHERE date=?')->execute([$today]);
        $entries=$monthEntries($render($f['admin'])['body']);
        checkEnrollment(str_contains($entries,'Modular') && !str_contains($entries,'No entries this month'),'A delivery-only month is not reported as empty');
        $db->prepare("INSERT INTO school_calendar(date,title,type) VALUES(?,'Synthetic learning day','school_day')")->execute([$today]);
        $insert->execute([10,$today,'online',null]);
        $insert->execute([11,$today,'face_to_face',null]);
        [$panel,$cell,$teacherHtml]=$parse($render($f['teacher']));
        checkEnrollment(str_contains($panel,'Current A') && str_contains($panel,'Online'),'Assigned section override is visible');
        checkEnrollment(!str_contains($panel,'Current B') && !str_contains($cell,'Current B'),'Other teacher section overrides are hidden');
        $entries=$monthEntries($teacherHtml);
        checkEnrollment(str_contains($entries,'Current A') && str_contains($entries,'Online') && !str_contains($entries,'Current B'),'Month delivery list preserves teacher section restrictions');
        [$panel]=$parse($render($f['admin']));
        checkEnrollment(str_contains($panel,'Current B') && str_contains($panel,'Face-to-face'),'Admin sees each override with its scope');
        [$panel,,$otherMonthHtml]=$parse($render($f['admin'],['month'=>1,'year'=>2020]));
        checkEnrollment(str_contains($panel,'Modular'),'Today remains correct while browsing another month');
        checkEnrollment(!str_contains($monthEntries($otherMonthHtml),'Modular'),'Month list does not include delivery dates from a different month');
        $db->prepare("UPDATE school_calendar SET type='holiday',title='Synthetic holiday' WHERE date=?")->execute([$today]);
        [$panel,$cell,$closedHtml]=$parse($render($f['admin']));
        checkEnrollment(str_contains($panel,'Synthetic holiday') && !str_contains($panel,'Modular') && !str_contains($cell,'Modular'),'Holiday wins over saved delivery schedules');
        $entries=$monthEntries($closedHtml);
        checkEnrollment(str_contains($entries,'Synthetic holiday') && str_contains($entries,'Modular') && str_contains($entries,'Not applied'),'Saved delivery on a closure stays listed as not applied');
        $db->prepare("UPDATE school_calendar SET type='no_class',title='Synthetic closure' WHERE date=?")->execute([$today]);
        [$panel,$cell]=$parse($render($f['admin']));
        checkEnrollment(str_contains($panel,'Synthetic closure') && !str_contains($panel,'Modular') && !str_contains($cell,'Modular'),'No-class closure wins over saved delivery schedules');
        $db->prepare("UPDATE school_calendar SET type='school_day' WHERE date=?")->execute([$today]);
        $db->exec('DELETE FROM delivery_modes');
        [$panel]=$parse($render($f['admin']));
        checkEnrollment(str_contains($panel,'Face-to-face'),'Unscheduled eligible school day defaults to face-to-face');
        $db->exec('DROP TABLE delivery_modes');
        [$panel]=$parse($render($f['admin']));
        checkEnrollment(str_contains($panel,'Regular school day'),'Legacy installation renders without the optional delivery table');
        checkEnrollment(!is_file($copy.'/provider-calls.log'),'Calendar viewing never sends notifications');
    } finally { removeEnrollmentAppFixture($copy); removeEnrollmentAppFixturePhotos($copy); }
});
echo 'Delivery calendar passed ('.$GLOBALS['enrollment_checks']." checks).\n";
