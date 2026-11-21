<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/database_backups.php';
checkEnrollment(!function_exists('proc_open'),'Run this proof with -d disable_functions=proc_open');
withEnrollmentFixture(function(array $fixture):void {
    $db=$fixture['db'];ensureBackupSchema($db);
    $root=sys_get_temp_dir().'/hostinger-backup-'.bin2hex(random_bytes(8));mkdir($root,0700);
    try {
        $r=createDatabaseBackup($db,['root'=>$root,'kind'=>'manual'],static function(string $path)use($fixture,$root):void {
            dumpDatabaseToFile($path,['host'=>getenv('MYSQLHOST')?:'127.0.0.1','port'=>getenv('MYSQLPORT')?:'3307','database'=>$fixture['database'],'user'=>getenv('MYSQLUSER')?:'root','password'=>getenv('MYSQLPASSWORD')?:''],'',$root);
        });
        checkEnrollment($r['status']==='completed','Backup completes without process execution or a configured binary');
        $file=resolveBackupFile($root,$r);$handle=gzopen($file,'rb');$sql='';while(!gzeof($handle))$sql.=gzread($handle,1048576);gzclose($handle);
        checkEnrollment(str_contains($sql,'-- ASAM dump complete'),'Completed archive contains the complete PHP dump');
        checkEnrollment(hash_file('sha256',$file)===$r['sha256'],'Compressed backup checksum matches');
    } finally {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
    }
});
echo "Shared-hosting backup passed with proc_open disabled.\n";
