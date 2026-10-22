<?php
// Real routes, HTTP shutdown logging, InnoDB transactions and triggers. Synthetic DB only.
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/security_audit.php';
$root=dirname(__DIR__); $name='audit_test_' . bin2hex(random_bytes(6)); $fixture=__DIR__ . '/.' . $name;
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$dsn='mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$admin=new PDO($dsn,DB_USER,DB_PASS,$options); $created=false; $server=null; $checks=0;
$key=base64_encode(random_bytes(32)); putenv('PII_ENCRYPTION_KEY=' . $key);
function auditCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); $GLOBALS['checks']++; }
try {
    $admin->exec("CREATE DATABASE `$name`"); $created=true;
    $db=new PDO($dsn . ';dbname=' . $name,DB_USER,DB_PASS,$options);
    $db->exec(file_get_contents($root . '/migrations/security_audit.sql'));
    $db->exec(file_get_contents($root . '/migrations/security_abuse_controls.sql'));
    foreach (explode(';',file_get_contents($root . '/migrations/security_audit_triggers.sql')) as $sql) if (trim($sql)) $db->exec($sql);
    $db->exec("CREATE TABLE attendance (id INT AUTO_INCREMENT PRIMARY KEY,student_id INT,date DATE,am_in TIME,am_out TIME,pm_in TIME,pm_out TIME,
        am_status VARCHAR(10),pm_status VARCHAR(10),attendance_type VARCHAR(15),remarks TEXT,recorded_by INT,UNIQUE KEY(student_id,date));
        CREATE TABLE users (id INT PRIMARY KEY,username VARCHAR(100),password VARCHAR(255),full_name VARCHAR(100),email VARCHAR(100),role VARCHAR(30),
        is_active INT,session_token VARCHAR(64),session_ip VARCHAR(50),session_user_agent VARCHAR(255),session_last_activity DATETIME);
        CREATE TABLE sections (id INT PRIMARY KEY,adviser_id INT,is_active INT,grade_level VARCHAR(30),schedule_type VARCHAR(20),school_year VARCHAR(20),section_name VARCHAR(20));
        CREATE TABLE students (id INT PRIMARY KEY,section_id INT,is_active INT,lrn VARCHAR(20),first_name VARCHAR(100),middle_name VARCHAR(100),last_name VARCHAR(100),gender VARCHAR(20),birth_date DATE,address TEXT,grade_level VARCHAR(20),school_year VARCHAR(20),photo VARCHAR(255),parent_name VARCHAR(100),parent_contact VARCHAR(100),parent_email VARCHAR(100));
        CREATE TABLE system_settings (setting_key VARCHAR(100),setting_value TEXT);
        INSERT INTO sections VALUES (10,7,1,'Grade 1','am_only','2026-2027','Synthetic'),(20,8,1,'Grade 1','am_only','2026-2027','Foreign');
        INSERT INTO students VALUES (1,10,1,'LRN1','Synthetic','','Student','Male',NULL,'','Grade 1','2026-2027','','Synthetic Parent','ContactA','parent@example.test'),(2,20,1,'LRN2','Other','','Student','Male',NULL,'','Grade 1','2026-2027','','','ContactC','other@example.test');
        INSERT INTO attendance (student_id,date,attendance_type,remarks) VALUES (1,'2026-10-09','absent','Before remark');");
    foreach ([[7,'teacher'],[8,'teacher'],[9,'scanner_operator'],[99,'admin']] as [$id,$role]) {
        $db->prepare('INSERT INTO users VALUES (?,?,?, ?,NULL,?,1,?,NULL,NULL,NOW())')->execute([$id,'fixture'.$id,password_hash('FixturePass123',PASSWORD_DEFAULT),'Synthetic User',$role,'token'.$id]);
    }
    foreach (['','/config','/includes','/attendance','/users','/students','/sessions'] as $dir) mkdir($fixture . $dir);
    foreach (['config/database.php','config/deployment_security.php','config/hosting_environment.php','config/schedule_rules.php','includes/functions.php','includes/sms_status.php','includes/notification_templates.php','includes/session_security.php',
        'includes/student_access.php','includes/student_photos.php','includes/csrf.php','includes/abuse_security.php','includes/settings_security.php','includes/pii_security.php','includes/security_audit.php',
        'students/edit.php','attendance/override.php','attendance/manual.php','users/edit.php','index.php','security_audit.php','includes/header.php','includes/sidebar.php','includes/footer.php'] as $file) copy($root . '/' . $file,$fixture . '/' . $file);
    copy($root . '/includes/browser_security.php',$fixture . '/includes/browser_security.php');

    file_put_contents($fixture . '/router.php', <<<'PHP'
<?php
require_once __DIR__.'/includes/functions.php';
startSession();
$parts=explode('/',trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/'));
$role=$parts[0]??'guest'; $id=['teacher'=>7,'operator'=>9,'admin'=>99][$role]??0;
$_SESSION=$id ? ['user_id'=>$id,'role'=>$role==='operator'?'scanner_operator':$role,'session_token'=>'token'.$id,
    'full_name'=>'Synthetic User','authenticated_at'=>time()-30,'last_activity_at'=>time(),'app_csrf'=>str_repeat('a',64)] : [];
$file=['override'=>'attendance/override.php','manual'=>'attendance/manual.php','audit'=>'security_audit.php','user'=>'users/edit.php','student'=>'students/edit.php','login'=>'index.php'][$parts[1]??'']??null;
if (!$file) { http_response_code(404); exit; }
$_SERVER['SCRIPT_FILENAME']=__DIR__.'/'.$file; $_SERVER['PHP_SELF']='/'.$file;
chdir(dirname(__DIR__.'/'.$file)); require __DIR__.'/'.$file;
PHP);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); auditCheck((bool)$socket,'Cannot allocate test port');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket);
    $env=getenv(); $env['MYSQLDATABASE']=$name; $env['APP_ENV']='test'; $env['BASE_URL']='http://127.0.0.1:'.$port.'/'; $env['PII_ENCRYPTION_KEY']=$key;
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$fixture.'/sessions','-S','127.0.0.1:'.$port,'-t',$fixture,$fixture.'/router.php'],
        [0=>['pipe','r'],1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes,$fixture,$env);
    auditCheck(is_resource($server),'Server did not start'); fclose($pipes[0]);
    for ($i=0;$i<50;$i++) { $ready=@fsockopen('127.0.0.1',$port,$errno,$error,.1); if ($ready) { fclose($ready); break; } usleep(100000); }
    $request=function (string $path,?array $post=null) use ($port): array {
        $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>'Content-Type: application/x-www-form-urlencoded',
            'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
        $body=file_get_contents('http://127.0.0.1:'.$port.'/'.$path,false,$context);
        preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$match); return [(int)$match[1],$body];
    };
    $post=['csrf_token'=>str_repeat('a',64),'attendance_id'=>1,'recorded_by'=>99,'actor_id'=>99,'remarks'=>'After <script>alert(1)</script> remark'];
    [$status]=$request('teacher/override',$post); auditCheck($status===302,'Authorized override failed');
    $event=$db->query("SELECT * FROM security_events WHERE action='attendance.override'")->fetch();
    auditCheck($event && (int)$event['actor_id']===7 && $event['target_id']==1,'Wrong attendance actor/target');
    auditCheck($event['device_hash']===hash('sha256','token7') && $event['route_name']==='attendance/override.php','Wrong device/route metadata');
    $cipher=$db->query('SELECT change_ciphertext FROM security_change_history')->fetchColumn();
    auditCheck(!str_contains($cipher,'After') && !str_contains($cipher,'Before'),'Remarks stored in plaintext history');
    $history=json_decode(decryptPII($cipher,'security-change:'.$event['event_id']),true);
    auditCheck($history['before']['remarks']==='Before remark' && $history['after']['remarks']===$post['remarks'],'Before/after history incorrect');
    auditCheck($history['after']['recorded_by']==7,'Caller forged change actor');
    auditCheck(!isset($history['after']['password'],$history['after']['parent_contact']),'Unnecessary secrets in attendance history');
    [$status]=$request('teacher/audit?event='.$event['event_id']); auditCheck($status===302,'Teacher read administrator history');
    [$status]=$request('operator/audit'); auditCheck($status===302,'Operator read audit log');
    [$status]=$request('guest/audit'); auditCheck($status===302,'Guest read audit log');
    [$status,$body]=$request('admin/audit?event='.$event['event_id']);
    auditCheck($status===200 && str_contains($body,'Before remark') && str_contains($body,'&lt;script&gt;'),'Admin history missing or not escaped');
    auditCheck(!str_contains($body,'<script>alert(1)</script>'),'History executes stored markup');
    auditCheck($db->query("SELECT COUNT(*) FROM security_events WHERE action='audit.history_read'")->fetchColumn()==1,'History access not logged');
    [$status]=$request('teacher/override',['csrf_token'=>str_repeat('a',64),'attendance_id'=>2]); auditCheck($status===302,'Missing foreign record route changed');
    $db->exec("INSERT INTO attendance (student_id,date,attendance_type) VALUES (2,'2026-10-09','absent')");
    [$status]=$request('teacher/override',['csrf_token'=>str_repeat('a',64),'attendance_id'=>2,'remarks'=>'Forbidden']);
    auditCheck($status===403,'Cross-section override allowed');
    auditCheck($db->query("SELECT COUNT(*) FROM security_events WHERE route_name='attendance/override.php' AND outcome='denied' AND actor_id=7")->fetchColumn()>=1,'Denied request not logged');
    [$status]=$request('teacher/manual',['csrf_token'=>str_repeat('a',64),'section_id'=>10,'attendance_date'=>'2026-10-09','entries'=>[1=>['am_in'=>'08:00','remarks'=>'Manual change']]]);
    auditCheck($status===302,'Manual route failed');
    auditCheck($db->query("SELECT COUNT(*) FROM security_events WHERE action='attendance.manual'")->fetchColumn()==1,'Manual history missing');
    foreach (['security_events','security_change_history'] as $table) foreach (['UPDATE','DELETE'] as $operation) {
        try { $db->exec($operation==='UPDATE' ? "UPDATE $table SET event_id=event_id" : "DELETE FROM $table"); throw new RuntimeException('History mutation accepted'); }
        catch (PDOException $error) { auditCheck($error->getCode()==='45000','Unexpected immutability failure'); }
    }
    [$status]=$request('guest/login',['username'=>'fixture7','password'=>'WrongPassword']); auditCheck($status===200,'Login fixture failed');
    auditCheck($db->query("SELECT COUNT(*) FROM security_events WHERE action='auth.login' AND outcome='failure' AND actor_id IS NULL")->fetchColumn()==1,'Failed login not logged anonymously');
    $contactPost=['csrf_token'=>str_repeat('a',64),'lrn'=>'LRN1','first_name'=>'Updated','middle_name'=>'','last_name'=>'Student','gender'=>'Male','birth_date'=>'','address'=>'','section_id'=>10,'parent_name'=>'Synthetic Parent','parent_contact'=>'ContactB','parent_email'=>'parent@example.test'];
    [$status]=$request('teacher/student?id=1',$contactPost); auditCheck($status===302,'Contact edit failed');
    $contact=$db->query("SELECT * FROM security_events WHERE action='student.contact_change'")->fetch();
    auditCheck($contact && $contact['alert']==1 && $contact['actor_id']==7,'Contact edit not flagged');
    $read=$db->prepare('SELECT change_ciphertext FROM security_change_history WHERE event_id=?'); $read->execute([$contact['event_id']]);
    $encryptedContact=$read->fetchColumn(); $delta=json_decode(decryptPII($encryptedContact,'security-change:'.$contact['event_id']),true);
    auditCheck($delta['before']['parent_contact']==='ContactA' && $delta['after']['parent_contact']==='ContactB','Contact history incorrect');
    auditCheck(array_keys($delta['before'])===['first_name','parent_contact'],'Unchanged PII copied into history');
    auditCheck(!str_contains($encryptedContact,'ContactA') && !str_contains($encryptedContact,'ContactB'),'Contact values leaked in stored history');
    [$status]=$request('admin/user?id=7',['csrf_token'=>str_repeat('a',64),'full_name'=>'Synthetic User','username'=>'fixture7','email'=>'','role'=>'admin','is_active'=>1,'password'=>'','password_confirm'=>'']);
    auditCheck($status===302,'Role edit failed');
    $account=$db->query("SELECT * FROM security_events WHERE action='account.update'")->fetch();
    auditCheck($account && $account['alert']==1 && $account['actor_id']==99,'Role edit not flagged');
    $read=$db->prepare('SELECT change_ciphertext FROM security_change_history WHERE event_id=?'); $read->execute([$account['event_id']]);
    $change=json_decode(decryptPII($read->fetchColumn(),'security-change:'.$account['event_id']),true);
    auditCheck($change['before']['role']==='teacher' && $change['after']['role']==='admin','Role history incorrect');
    auditCheck(!isset($change['before']['password']) && !isset($change['after']['session_token']),'Credential leaked in account history');
    // Simulate unavailable history storage: the actual edit must not commit.
    $db->exec('RENAME TABLE security_change_history TO unavailable_history');
    $before=$db->query('SELECT remarks FROM attendance WHERE id=1')->fetchColumn();
    $eventCount=$db->query("SELECT COUNT(*) FROM security_events WHERE action='attendance.override'")->fetchColumn();
    [$status]=$request('admin/override',['csrf_token'=>str_repeat('a',64),'attendance_id'=>1,'remarks'=>'Must roll back']);
    auditCheck($status===503,'Missing history storage did not fail closed');
    auditCheck($db->query('SELECT remarks FROM attendance WHERE id=1')->fetchColumn()===$before,'Attendance committed without history');
    auditCheck($db->query("SELECT COUNT(*) FROM security_events WHERE action='attendance.override'")->fetchColumn()==$eventCount,'Rolled-back change left a success event');
    [$status]=$request('admin/user?id=7',['csrf_token'=>str_repeat('a',64),'full_name'=>'Synthetic User','username'=>'fixture7','email'=>'','role'=>'teacher','is_active'=>1,'password'=>'','password_confirm'=>'']);
    auditCheck($status===503,'Account edit committed without history');
    auditCheck($db->query('SELECT role FROM users WHERE id=7')->fetchColumn()==='admin','Unaudited role change was not rolled back');
    $contactPost['parent_contact']='UnauditedContact';
    [$status]=$request('admin/student?id=1',$contactPost);
    auditCheck($status===503 && $db->query('SELECT parent_contact FROM students WHERE id=1')->fetchColumn()==='ContactB','Contact edit committed without history');
    $db->exec('RENAME TABLE unavailable_history TO security_change_history');
    try { decryptPII($cipher,'security-change:wrong-event'); throw new RuntimeException('History context substitution accepted'); }
    catch (RuntimeException $error) { auditCheck($error->getMessage()==='PII decryption failed.','History event context was not enforced'); }
    echo "Security audit checks passed ($checks checks; synthetic HTTP/SQL, no provider calls).\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_dir($fixture)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname()); rmdir($fixture);
    }
    if ($created) $admin->exec("DROP DATABASE `$name`");
}
