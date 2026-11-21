<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
$file=$argv[1]??'';
checkEnrollment(is_file($file),'Pass the generated additive SQL path');
$sql=file_get_contents($file);
checkEnrollment(!preg_match('/\b(?:DROP\s+(?:TABLE|DATABASE)|TRUNCATE|DELETE\s+FROM|REPLACE\s+INTO)\b/i',$sql),'Update must preserve populated tables');
withEnrollmentFixture(function(array $fixture)use($sql):void {
    $db=$fixture['db'];
    $before=[];foreach(['students','sections','attendance','users'] as $table)$before[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $db->exec($sql);$db->exec($sql);
    foreach($before as $table=>$rows) {
        $columns=array_keys($rows[0]);$after=$db->query('SELECT `'.implode('`,`',$columns).'` FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        checkEnrollment($rows===$after,'Additive update and rerun preserve legacy rows: '.$table);
    }
    checkEnrollment((int)$db->query('SELECT COUNT(*) FROM school_years')->fetchColumn()===0,'SQL must not invent years or ownership');
    checkEnrollment($db->query("SELECT setting_value FROM system_settings WHERE setting_key='enrollment_model_version'")->fetchColumn()===false,'SQL must not enable annual enrollment directly');
},['legacy'=>true]);
echo "Additive update SQL passed: legacy records preserved, rerun safe, annual cutover remains explicit.\n";
