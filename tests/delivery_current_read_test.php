<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/delivery_modes.php';
withEnrollmentFixture(function(array $f): void {
    $db=$f['db']; ensureDeliverySchema($db);
    $db->exec("INSERT INTO system_settings(setting_key,setting_value) VALUES('enrollment_model_version','1'); INSERT INTO delivery_modes(school_year_id,scope_section_id,learning_date,mode,recorded_by) VALUES(1,0,'2026-10-20','face_to_face',1)");
    $writer=new PDO('mysql:host='.(getenv('MYSQLHOST')?:'127.0.0.1').';port='.(getenv('MYSQLPORT')?:'3307').';dbname='.$f['database'].';charset=utf8mb4',getenv('MYSQLUSER')!==false?getenv('MYSQLUSER'):'root',getenv('MYSQLPASSWORD')!==false?getenv('MYSQLPASSWORD'):'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    try {
        $db->beginTransaction();
        // The scan's enrollment-mode lookup establishes an older repeatable-read snapshot.
        checkEnrollment(enrollmentModelEnabled($db),'Managed scan primes its transaction snapshot');
        $writer->exec("UPDATE delivery_modes SET mode='modular',deadline_at='2026-10-23 17:00:00'");
        $db->query('SELECT id FROM school_years WHERE id=1 LOCK IN SHARE MODE')->fetchAll();
        checkEnrollment(resolveDeliveryDay($db,1,10,'2026-10-20')['mode']==='modular','Scan reads newly committed Modular after acquiring the year lock');
        $db->rollBack();
        $db->beginTransaction();
        checkEnrollment(enrollmentModelEnabled($db),'Closure case primes its transaction snapshot');
        $writer->exec("INSERT INTO school_calendar(date,title,type) VALUES('2026-10-20','Newly committed closure','no_class')");
        $db->query('SELECT id FROM school_years WHERE id=1 LOCK IN SHARE MODE')->fetchAll();
        $day=resolveDeliveryDay($db,1,10,'2026-10-20');
        checkEnrollment(!$day['eligible'] && $day['mode']===null,'Scan reads newly committed closure after acquiring the year lock');
        $db->rollBack();
    } finally { if($db->inTransaction()) $db->rollBack(); }
});
echo 'Delivery current reads passed ('.$GLOBALS['enrollment_checks']." checks, isolated connections).\n";
