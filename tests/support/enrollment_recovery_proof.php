<?php
declare(strict_types=1);

/** Real dump/restore of synthetic legacy data only. Raw export and keys never
 * leave private temporary files/environment or appear in diagnostic output.
 */
function verifyEnrollmentSyntheticRecovery(array $f): void
{
    $db=$f['db'];
    if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$f['database'])) throw new RuntimeException('Unsafe recovery source');
    $restore='enrollment_test_'.bin2hex(random_bytes(8));
    $private=sys_get_temp_dir().'/enrollment_recovery_'.bin2hex(random_bytes(8));
    mkdir($private,0700); $created=false; $restored=null;
    $oldSettingsKey=getenv('SETTINGS_ENCRYPTION_KEY'); putenv('SETTINGS_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
    require_once dirname(__DIR__,2).'/includes/settings_security.php';
    try {
        $db->beginTransaction();
        securityAuditContext(1);
        securityAuditChange($db,'fixture.recovery','student',101,['first_name'=>'Synthetic before'],['first_name'=>'Synthetic after']);
        $db->prepare('INSERT INTO sf2_remarks(student_id,section_id,report_month,report_year,remark_ciphertext,version,updated_by) VALUES(101,10,10,2026,?,1,1)')->execute([encryptPII('Synthetic recovered remark','sf2:101:10:10:2026')]);
        $db->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES('mail_password',?)")->execute([encryptSettingSecret('Synthetic private setting')]);
        $db->commit();
        $beforeTables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); $counts=[];
        foreach ($beforeTables as $table) $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        $indexes=$db->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll();
        $constraints=$db->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll();
        $triggers=$db->query('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT,ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll();
        $run=static function(array $command,array $input=[0=>['pipe','r']]): void {
            $p=proc_open($command,$input+[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($p)) throw new RuntimeException('Synthetic recovery tool unavailable');
            if (isset($pipes[0])) fclose($pipes[0]);
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            checkEnrollment(proc_close($p)===0 && $out==='' && $err==='','Synthetic dump/restore command completed without diagnostics');
        };
        $binaryDir=getenv('ENROLLMENT_MYSQL_BIN')?:'C:/xampp/mysql/bin';
        $dump=$private.'/snapshot.sql';
        $run([$binaryDir.'/mysqldump.exe','--host=127.0.0.1','--port=3307','--user=root','--password=','--single-transaction','--triggers','--hex-blob','--skip-comments','--result-file='.$dump,$f['database']]);
        $checksum=hash_file('sha256',$dump); checkEnrollment(filesize($dump)>0 && strlen($checksum)===64,'Complete synthetic export has a checksum');
        $db->exec('CREATE DATABASE `'.$restore.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true;
        $run([$binaryDir.'/mysql.exe','--host=127.0.0.1','--port=3307','--user=root','--password=','--binary-mode=1','--database='.$restore],[0=>['file',$dump,'r']]);
        $restored=new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$restore.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        checkEnrollment($beforeTables===$restored->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),'All legacy tables restored');
        foreach ($counts as $table=>$count) checkEnrollment($count===(int)$restored->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn(),'Synthetic table row count restored: '.$table);
        checkEnrollment($indexes===$restored->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(),'Original primary/unique and query indexes restored');
        checkEnrollment($constraints===$restored->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(),'Original constraints restored');
        checkEnrollment($triggers===$restored->query('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT,ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(),'All original audit trigger definitions restored');
        $history=$restored->query('SELECT * FROM security_change_history')->fetch();
        $plain=json_decode(decryptPII($history['change_ciphertext'],'security-change:'.$history['event_id']),true,512,JSON_THROW_ON_ERROR);
        checkEnrollment($plain['before']['first_name']==='Synthetic before' && $plain['after']['first_name']==='Synthetic after','Original key decrypts restored encrypted history');
        $remark=$restored->query('SELECT remark_ciphertext FROM sf2_remarks')->fetchColumn();
        checkEnrollment(decryptPII($remark,'sf2:101:10:10:2026')==='Synthetic recovered remark','Original key and context decrypt restored SF2 remark');
        checkEnrollment(decryptSettingSecret($restored->query("SELECT setting_value FROM system_settings WHERE setting_key='mail_password'")->fetchColumn())==='Synthetic private setting','Original settings key decrypts restored private setting');
        $key=getenv('PII_ENCRYPTION_KEY'); putenv('PII_ENCRYPTION_KEY='.base64_encode(random_bytes(32)));
        try { expectEnrollmentError(RuntimeException::class,fn()=>decryptPII($remark,'sf2:101:10:10:2026')); }
        finally { putenv('PII_ENCRYPTION_KEY='.$key); }
        foreach (['security_events','security_change_history'] as $table) {
            expectEnrollmentError(PDOException::class,fn()=>$restored->exec("UPDATE $table SET event_id=event_id"));
            expectEnrollmentError(PDOException::class,fn()=>$restored->exec("DELETE FROM $table"));
        }
        // Photos are a separate recovery stream, not hidden in the DB export.
        $photo=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');
        file_put_contents($private.'/original.png',$photo);
        copy($private.'/original.png',$private.'/uploads-backup.png');
        unlink($private.'/original.png');
        copy($private.'/uploads-backup.png',$private.'/restored.png');
        checkEnrollment(hash_file('sha256',$private.'/restored.png')===hash('sha256',$photo) && getimagesize($private.'/restored.png')[0]===1,'Separate synthetic photo backup restores matching decodable file');
        echo 'Synthetic recovery: tables='.count($counts).' triggers='.count($triggers).' export_sha256='.$checksum."; encrypted history/remarks/settings and separate photo verified.\n";
    } finally {
        $restored=null;
        if ($created) {
            if (!preg_match('/\Aenrollment_test_[0-9a-f]{16}\z/',$restore) || $restore===$f['database']) throw new RuntimeException('Unsafe recovery cleanup');
            $db->exec('DROP DATABASE `'.$restore.'`');
        }
        foreach (['snapshot.sql','original.png','uploads-backup.png','restored.png'] as $file) if (is_file($private.'/'.$file)) unlink($private.'/'.$file);
        rmdir($private); putenv($oldSettingsKey===false?'SETTINGS_ENCRYPTION_KEY':'SETTINGS_ENCRYPTION_KEY='.$oldSettingsKey);
    }
}
