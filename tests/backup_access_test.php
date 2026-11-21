<?php
require_once __DIR__.'/support/enrollment_fixture.php';require_once dirname(__DIR__).'/includes/database_backups.php';
checkEnrollment(function_exists('authorizedBackupDownload'),'Authorized backup download service must exist');
withEnrollmentFixture(function(array $f):void{
 $db=$f['db'];ensureBackupSchema($db);
 expectEnrollmentError(DomainException::class,fn()=>authorizedBackupDownload($db,$f['teacher'],str_repeat('a',32)));
 expectEnrollmentError(DomainException::class,fn()=>authorizedBackupDownload($db,['id'=>1,'role'=>'scanner_operator'],str_repeat('a',32)));
 expectEnrollmentError(DomainException::class,fn()=>authorizedBackupDownload($db,$f['admin'],'../private.sql'));
 expectEnrollmentError(DomainException::class,fn()=>authorizedBackupDownload($db,$f['admin'],str_repeat('a',32)));
});echo "Backup access passed.\n";
