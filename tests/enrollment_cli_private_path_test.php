<?php
declare(strict_types=1);
require_once __DIR__.'/support/enrollment_fixture.php';
withEnrollmentFixture(function(array $fixture):void {
    $directory=sys_get_temp_dir().'/enrollment-cli-home-'.bin2hex(random_bytes(8));
    mkdir($directory,0700);mkdir($directory.'/attendance-private',0700);
    $inventory=$directory.'/attendance-private/inventory.json';
    $map=$directory.'/attendance-private/map.json';
    $run=static function(array $arguments)use($fixture,$directory):array {
        $process=proc_open(array_merge([PHP_BINARY,dirname(__DIR__).'/migrations/school_year_enrollment.php'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$directory,enrollmentFixtureCliEnvironment($fixture));
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        return [proc_close($process),$out,$err];
    };
    try {
        [$exit]=$run(['--inventory','--output='.$inventory]);
        checkEnrollment($exit===0,'CLI must permit a private directory beneath its working directory when DOCUMENT_ROOT is empty');
        $data=json_decode(file_get_contents($inventory),true,512,JSON_THROW_ON_ERROR);
        file_put_contents($map,json_encode(['inventory_fingerprint'=>$data['fingerprint'],'school_years'=>[],'sections'=>[],'enrollments'=>[],'attendance'=>[]],JSON_THROW_ON_ERROR));
        [$exit]=$run(['--apply-map='.$map,'--actor-id=1']);
        checkEnrollment($exit===0,'CLI must read a private mapping beneath its working directory');
        [$exit]=$run(['--inventory','--output='.dirname(__DIR__).'/public-cli-inventory.json']);
        checkEnrollment($exit!==0,'The actual application directory remains prohibited');
        [$exit,$out,$err]=$run(['--apply-map='.$directory.'/attendance-private/missing.json','--actor-id=1']);
        checkEnrollment($exit!==0 && str_contains($err,'PRIVATE_INPUT:'),'Missing private files report a safe actionable reason');
        checkEnrollment(!str_contains($err,$directory),'Diagnostic must not print the private path');
    } finally {
        foreach([$inventory,$map] as $file)if(is_file($file))unlink($file);
        rmdir($directory.'/attendance-private');rmdir($directory);
    }
});
echo "CLI private paths work from cron home directories; web paths remain blocked.\n";
