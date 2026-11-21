<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
require_once dirname(__DIR__).'/includes/database_backups.php';
$original=getcwd();$document=$_SERVER['DOCUMENT_ROOT']??null;
$home=sys_get_temp_dir().'/backup-cli-home-'.bin2hex(random_bytes(8));
mkdir($home,0700);mkdir($home.'/backups',0700);
try {
    chdir($home);$_SERVER['DOCUMENT_ROOT']='';
    checkEnrollment(backupRoot($home.'/backups')===realpath($home.'/backups'),'Cron home is not a public document root when DOCUMENT_ROOT is empty');
    expectEnrollmentError(DomainException::class,fn()=>backupRoot(''));
    expectEnrollmentError(DomainException::class,fn()=>backupRoot(dirname(__DIR__)));
    $_SERVER['DOCUMENT_ROOT']=$home;
    expectEnrollmentError(DomainException::class,fn()=>backupRoot($home.'/backups'));
} finally {
    chdir($original);if($document===null)unset($_SERVER['DOCUMENT_ROOT']);else $_SERVER['DOCUMENT_ROOT']=$document;
    rmdir($home.'/backups');rmdir($home);
}
echo "Backup CLI paths pass; empty configuration and web-accessible directories remain blocked.\n";
