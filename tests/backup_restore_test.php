<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once dirname(__DIR__).'/includes/database_backups.php';
withEnrollmentFixture(function(array $f):void {
    $db=$f['db'];ensureBackupSchema($db);$root=sys_get_temp_dir().'/attendance-restore-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
    $database='backup_restore_test_'.bin2hex(random_bytes(8));
    $server=new PDO('mysql:host=127.0.0.1;port=3307','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $dump=getenv('TEST_MYSQLDUMP')?:'C:/xampp/mysql/bin/mysqldump.exe';$client=getenv('TEST_MYSQL_CLIENT')?:'C:/xampp/mysql/bin/mysql.exe';
    try{
        // Preserve and verify an actual append-only trigger as well as table data and indexes.
        $db->exec("CREATE TRIGGER backup_test_guard BEFORE DELETE ON students FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic immutable guard'");
        $value=encryptPII('synthetic recovery proof','backup-test');$db->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES(?,?)')->execute(['backup_test_cipher',$value]);
        $r=createDatabaseBackup($db,['root'=>$root,'kind'=>'manual'],fn($out)=>dumpDatabaseToFile($out,['host'=>'127.0.0.1','port'=>'3307','user'=>'root','password'=>'','database'=>$f['database']],$dump,$root));
        checkEnrollment($r['status']==='completed','Real database dump completed');$path=resolveBackupFile($root,$r);
        $plain=$root.'/restore.sql';$in=gzopen($path,'rb');$out=fopen($plain,'wb');while(!gzeof($in))fwrite($out,gzread($in,1048576));gzclose($in);fclose($out);
        $server->exec("CREATE DATABASE `$database`");
        $p=proc_open([$client,'--host=127.0.0.1','--port=3307','--user=root',$database],[0=>['file',$plain,'rb'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        checkEnrollment($exit===0,'Synthetic restore completed without SQL errors');
        $restored=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        foreach(['students','attendance','student_enrollments','enrollment_sections'] as $table)checkEnrollment((int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn()===(int)$restored->query("SELECT COUNT(*) FROM $table")->fetchColumn(),'Restored count matches: '.$table);
        checkEnrollment(count($restored->query('SHOW TRIGGERS')->fetchAll())===count($db->query('SHOW TRIGGERS')->fetchAll()),'Triggers restored');
        checkEnrollment(count($restored->query('SHOW INDEX FROM attendance')->fetchAll())===count($db->query('SHOW INDEX FROM attendance')->fetchAll()),'Indexes restored');
        checkEnrollment(decryptPII($restored->query("SELECT setting_value FROM system_settings WHERE setting_key='backup_test_cipher'")->fetchColumn(),'backup-test')==='synthetic recovery proof','Original key reads restored encrypted value');
    }finally{
        $server->exec("DROP DATABASE IF EXISTS `$database`");$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
    }
});echo "Real backup/restore passed (synthetic database, original key, indexes and triggers).\n";
