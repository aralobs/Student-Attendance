<?php
$root=dirname(__DIR__);$fixture=__DIR__.'/.pdf-access-'.bin2hex(random_bytes(6));$checks=0;
foreach(['','/includes','/reports'] as $dir)mkdir($fixture.$dir);
try {
    copy($root.'/includes/student_access.php',$fixture.'/includes/student_access.php');
    copy($root.'/reports/export_pdf.php',$fixture.'/reports/export_pdf.php');
    file_put_contents($fixture.'/includes/functions.php', <<<'PHP'
<?php
define('BASE_URL','/');http_response_code(200);$case=$argv[1];$reads=0;
function requireLogin(){if($GLOBALS['case']==='guest'){http_response_code(401);exit;}}
function isAdmin(){return $GLOBALS['case']==='admin';}
function isTeacher(){return str_starts_with($GLOBALS['case'],'teacher');}
function currentUser(){return ['id'=>7];}
class PDFTestDB extends PDO {
    function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->exec('CREATE TABLE sections(id INTEGER,adviser_id INTEGER,is_active INTEGER); INSERT INTO sections VALUES(10,7,1),(20,8,1),(30,7,0);');}
    function prepare(string $sql,array $options=[]):PDOStatement|false{$GLOBALS['reads']++;return parent::prepare($sql,$options);}
}
function getDB(){return $GLOBALS['db']??=new PDFTestDB;}
register_shutdown_function(function(){echo json_encode(['status'=>http_response_code(),'reads'=>$GLOBALS['reads']]);});
PHP);
    file_put_contents($fixture.'/request.php', <<<'PHP'
<?php
$_GET=['section'=>10,'month'=>10,'year'=>2026];
if($argv[1]==='teacher_foreign'||$argv[1]==='admin')$_GET['section']=20;
if($argv[1]==='teacher_inactive')$_GET['section']=30;
if($argv[1]==='teacher_missing')$_GET['section']=99;
if($argv[1]==='teacher_bad_month')$_GET['month']=13;
if($argv[1]==='teacher_bad_year')$_GET['year']=[];
if($argv[1]==='teacher_bad_section')$_GET['section']='10 OR 1=1';
PHP);
    foreach(['guest'=>401,'scanner'=>403,'teacher'=>302,'teacher_foreign'=>403,'teacher_inactive'=>403,'teacher_missing'=>403,
        'teacher_bad_month'=>400,'teacher_bad_year'=>400,'teacher_bad_section'=>400,'admin'=>302] as $case=>$expected){
        $process=proc_open([PHP_BINARY,'-d','auto_prepend_file='.$fixture.'/request.php',$fixture.'/reports/export_pdf.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        if($exit!==0||$err!==''||!preg_match('/(\{"status":.*\})$/',$out,$match))throw new RuntimeException("$case failed: $err $out");
        $result=json_decode($match[1],true);
        if($result['status']!==$expected)throw new RuntimeException("$case incorrect status");
        if(in_array($expected,[400,401],true)||$case==='scanner'){if($result['reads']!==0)throw new RuntimeException('Rejected caller reached report queries');}
        else if($result['reads']!==1)throw new RuntimeException('Unexpected report queries');
        $checks++;
    }
    echo "Legacy PDF access checks passed ($checks cases; isolated section data).\n";
} finally {
    foreach(['includes/student_access.php','includes/functions.php','reports/export_pdf.php','request.php'] as $file)unlink($fixture.'/'.$file);
    foreach(['/reports','/includes',''] as $dir)rmdir($fixture.$dir);
}
