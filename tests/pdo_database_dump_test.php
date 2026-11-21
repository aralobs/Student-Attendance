<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/database_backups.php';
checkEnrollment(function_exists('dumpDatabaseWithPDO'),'A PHP-only dump must be available when process execution is disabled');
withEnrollmentFixture(function(array $fixture):void {
    $db=$fixture['db'];
    $db->exec("SET SESSION sql_mode=CONCAT(@@sql_mode,',NO_AUTO_VALUE_ON_ZERO')");
    $db->exec("CREATE TABLE dump_probe(id INT AUTO_INCREMENT PRIMARY KEY,amount DECIMAL(10,2),payload LONGBLOB,note TEXT,computed INT AS (id+1) VIRTUAL) ENGINE=InnoDB");
    $bytes="zero\0slash\\quote'\r\n";
    $db->prepare('INSERT INTO dump_probe(id,amount,payload,note) VALUES(?,?,?,?)')->execute([0,'12.34',$bytes,'日本語 😀']);
    $db->exec("CREATE TRIGGER dump_probe_guard BEFORE DELETE ON dump_probe FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic immutable guard'");
    $db->exec('CREATE VIEW dump_probe_view AS SELECT id,amount FROM dump_probe');
    $db->exec('CREATE PROCEDURE dump_probe_count() SELECT COUNT(*) FROM dump_probe');
    $db->exec('CREATE EVENT dump_probe_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DISABLE DO SET @synthetic_backup_probe=1');
    $cipher=encryptPII('synthetic native-dump proof','native-dump-test');
    $db->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?)')->execute(['native_dump_cipher',$cipher]);
    $path=sys_get_temp_dir().'/pdo-dump-'.bin2hex(random_bytes(8)).'.sql';
    $name='pdo_restore_test_'.bin2hex(random_bytes(8));
    $server=new PDO('mysql:host='.(getenv('MYSQLHOST')?:'127.0.0.1').';port='.(getenv('MYSQLPORT')?:'3307'),getenv('MYSQLUSER')?:'root',getenv('MYSQLPASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    try {
        dumpDatabaseWithPDO($db,$path);
        checkEnrollment(str_contains(file_get_contents($path),'-- ASAM dump complete'),'Only a complete dump gets the completion marker');
        $server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
        $client=getenv('TEST_MYSQL_CLIENT')?:'C:/xampp/mysql/bin/mysql.exe';
        $args=[$client,'--host='.(getenv('MYSQLHOST')?:'127.0.0.1'),'--port='.(getenv('MYSQLPORT')?:'3307'),'--user='.(getenv('MYSQLUSER')?:'root'),'--default-character-set=utf8mb4',$name];
        $env=getenv();$env['MYSQL_PWD']=getenv('MYSQLPASSWORD')?:'';
        $p=proc_open($args,[0=>['file',$path,'rb'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        checkEnrollment(proc_close($p)===0,'PHP-only dump restores without SQL errors: '.$error);
        $restored=new PDO('mysql:host='.(getenv('MYSQLHOST')?:'127.0.0.1').';port='.(getenv('MYSQLPORT')?:'3307').';dbname='.$name.';charset=utf8mb4',getenv('MYSQLUSER')?:'root',getenv('MYSQLPASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $restored->exec('SET time_zone='.$restored->quote((string)$db->query('SELECT @@session.time_zone')->fetchColumn()));
        foreach(['students','attendance','student_enrollments','enrollment_sections','dump_probe'] as $table) {
            $before=$db->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $after=$restored->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            checkEnrollment($before===$after,'Restored rows match: '.$table);
        }
        checkEnrollment(count($db->query('SHOW TRIGGERS')->fetchAll())===count($restored->query('SHOW TRIGGERS')->fetchAll()),'Trigger count preserved');
        expectEnrollmentError(PDOException::class,fn()=>$restored->exec('DELETE FROM dump_probe'));
        checkEnrollment((int)$restored->query('SELECT COUNT(*) FROM dump_probe_view')->fetchColumn()===1,'View restored');
        checkEnrollment((int)$restored->query('CALL dump_probe_count()')->fetchColumn()===1,'Routine restored');
        checkEnrollment(count($restored->query('SHOW EVENTS')->fetchAll())===1,'Disabled event restored');
        checkEnrollment(decryptPII($restored->query("SELECT setting_value FROM system_settings WHERE setting_key='native_dump_cipher'")->fetchColumn(),'native-dump-test')==='synthetic native-dump proof','Original key decrypts restored history');
    } finally {
        $server->exec("DROP DATABASE IF EXISTS `$name`");
        if(is_file($path))unlink($path);
    }
});
echo "PHP-only backup restore passed: records, binary data, Unicode, generated columns, views, triggers and routine.\n";
