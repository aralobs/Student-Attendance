<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once __DIR__.'/support/enrollment_app_fixture.php';
require_once dirname(__DIR__).'/includes/delivery_modes.php';
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; ensureDeliverySchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); UPDATE users SET session_token=CONCAT('synthetic-fixture-session-',id)");
    $copy=__DIR__.'/enrollment_app_'.bin2hex(random_bytes(8)); createEnrollmentAppFixture($copy);
    $config=file_get_contents($copy.'/config/database.php');
    file_put_contents($copy.'/config/database.php',str_replace("'app_csrf'=>str_repeat('a',64)","'settings_csrf'=>str_repeat('a',64),'app_csrf'=>str_repeat('a',64)",$config));
    $get=['month'=>10,'year'=>2026];
    $run=fn($actor,$post=[])=>runEnrollmentAppEndpoint($copy,$f,'calendar/calendar.php',$actor,$post,$get,$post?'POST':'GET');
    $json=function(array $response): array { return json_decode(preg_replace('/\nRESULT:.*$/s','',$response['body']),true,512,JSON_THROW_ON_ERROR); };
    try {
        $html=$run($f['admin'])['body'];
        checkEnrollment(str_contains($html,'id="entryKind"') && str_contains($html,'Class delivery'),'Add Entry contains the class delivery choice');
        $doc=new DOMDocument(); @$doc->loadHTML($html); $xp=new DOMXPath($doc);
        checkEnrollment($xp->query('//*[@id="calendarModal"]//*[@id="entryTitle"]')->length===1 && $xp->query('//*[@id="calendarModal"]//*[@id="deliveryMode"]')->length===1,'Event and delivery fields share one modal');
        $teacherHtml=$run($f['teacher'])['body'];
        $doc=new DOMDocument(); @$doc->loadHTML($teacherHtml); $xp=new DOMXPath($doc);
        checkEnrollment($xp->query('//*[@id="deliverySection"]/option[@value="10"]')->length===1 && $xp->query('//*[@id="deliverySection"]/option[@value="11" or @value="0"]')->length===0,'Teacher modal lists only allowed section scopes');
        checkEnrollment($xp->query('//*[@id="entryKind"]/option[@value="event"]')->length===0,'Teachers cannot select event management');
        $db->exec("INSERT INTO school_calendar(date,title,type) VALUES('2026-10-14','Synthetic closure','no_class')");
        $command=['ajax'=>'1','action'=>'delivery','section_id'=>0,'from'=>'2026-10-12','to'=>'2026-10-16','mode'=>'modular','deadline_at'=>'2026-10-16T17:00'];
        $preview=$json($run($f['admin'],$command));
        checkEnrollment($preview['success'] && !$preview['saved'] && count($preview['dates'])===4 && $preview['skipped']===['2026-10-14'],'Preview lists eligible dates and skipped closures');
        checkEnrollment((int)$db->query('SELECT COUNT(*) FROM delivery_modes')->fetchColumn()===0,'Preview makes no schedule writes');
        $bad=$run($f['admin'],$command+['csrf_token'=>'invalid']);
        checkEnrollment($bad['status']===403,'Delivery requires valid CSRF');
        $result=$json($run($f['admin'],$command+['confirm_token'=>$preview['token']]));
        checkEnrollment($result['success'] && $result['saved'] && (int)$db->query('SELECT COUNT(*) FROM delivery_modes')->fetchColumn()===4,'Confirmed modal request saves the schedule');
        $changed=$command; $changed['mode']='online'; $changed['confirm_token']=$preview['token'];
        $result=$json($run($f['admin'],$changed));
        checkEnrollment($result['success'] && !$result['saved'] && $db->query('SELECT mode FROM delivery_modes LIMIT 1')->fetchColumn()==='modular','Changed command invalidates confirmation');
        foreach([0,11] as $deniedScope) {
            $forged=$command; $forged['section_id']=$deniedScope;
            checkEnrollment(!$json($run($f['teacher'],$forged))['success'],'Teacher cannot schedule forged whole-school or foreign section scope');
        }
        $own=$command; $own['section_id']=10;
        $preview=$json($run($f['teacher'],$own));
        checkEnrollment($json($run($f['teacher'],$own+['confirm_token'=>$preview['token']]))['saved'],'Teacher can confirm assigned-section schedule');
        $event=['ajax'=>'1','action'=>'add','date'=>'2026-10-19','title'=>'Synthetic event','type'=>'special_event','description'=>'Shared modal'];
        checkEnrollment($json($run($f['admin'],$event))['success'],'Admin event still saves through protected writer');
        $forgedEvent=$event; $forgedEvent['title']='Unauthorized replacement';
        checkEnrollment(in_array($run($f['teacher'],$forgedEvent)['status'],[302,403],true) && $db->query("SELECT title FROM school_calendar WHERE date='2026-10-19'")->fetchColumn()==='Synthetic event','Teacher cannot submit an event directly');
        $event['date']='2026-10-21'; $event['type']='holiday';
        checkEnrollment(!$json($run($f['admin'],$event))['success'],'Recorded attendance still protects calendar eligibility');
        $legacy=runEnrollmentAppEndpoint($copy,$f,'calendar/delivery_modes.php',$f['admin'],[],[],'GET');
        checkEnrollment(str_contains($legacy['body'],'calendar.php?entry=delivery') && !str_contains($legacy['body'],'name="deadline_at"'),'Old schedule page links to the unified editor instead of duplicating its form');
        file_put_contents(dirname(__DIR__).'/.qa/calendar-entry-modal.html',$html);
        $db->exec('DROP TABLE delivery_modes');
        $unavailable=$run($f['teacher']);
        checkEnrollment($unavailable['status']===200 && str_contains($unavailable['body'],"showEntry('2026-10-12')"),'Teacher calendar remains usable without delivery schema');
        checkEnrollment(!is_file($copy.'/provider-calls.log'),'Calendar editing tests send no notifications');
    } finally { removeEnrollmentAppFixture($copy); removeEnrollmentAppFixturePhotos($copy); }
});
echo 'Unified calendar modal passed ('.$GLOBALS['enrollment_checks']." checks).\n";
