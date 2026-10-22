<?php
// Real live-log SQL against disposable data; finalization is stubbed to prevent writes.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.today-log-access-' . bin2hex(random_bytes(6));
foreach (['', '/config', '/includes', '/attendance'] as $dir) mkdir($fixture . $dir);
file_put_contents($fixture . '/config/database.php', <<<'PHP'
<?php
$case = $argv[1]; $reads = 0; $finalized = 0;
http_response_code(200);
class LogTestDB extends PDO {
    function __construct() {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->sqliteCreateFunction('GREATEST', fn(...$values) => max($values));
        $this->sqliteCreateFunction('FIELD', function ($value, ...$choices) {
            $position = array_search($value, $choices, true);
            return $position === false ? 0 : $position + 1;
        });
        $this->exec("CREATE TABLE sections (id INTEGER, adviser_id INTEGER, is_active INTEGER,
            grade_level TEXT, section_name TEXT, schedule_type TEXT,
            am_in_start TEXT DEFAULT '07:00:00', am_in_end TEXT DEFAULT '09:00:00', am_late_threshold TEXT DEFAULT '08:00:00',
            am_out_start TEXT DEFAULT '11:00:00', am_out_end TEXT DEFAULT '12:00:00',
            pm_in_start TEXT DEFAULT '13:00:00', pm_in_end TEXT DEFAULT '14:00:00', pm_late_threshold TEXT DEFAULT '13:30:00',
            pm_out_start TEXT DEFAULT '15:00:00', pm_out_end TEXT DEFAULT '17:00:00');
            INSERT INTO sections (id,adviser_id,is_active,grade_level,section_name,schedule_type) VALUES
                (10,7,1,'Grade 1','Assigned','am_only'),(20,8,1,'Grade 3','Other','full_day'),
                (30,7,0,'Grade 4','Inactive','full_day');
            CREATE TABLE students (id INTEGER,first_name TEXT,last_name TEXT,lrn TEXT,section_id INTEGER,is_active INTEGER,enrollment_status TEXT);
            INSERT INTO students VALUES (1,'Own','Student','LRN1',10,1,'active'),
                (2,'Other','Student','LRN2',20,1,'active'),(3,'Pending','Student','LRN3',10,1,'active'),
                (4,'Inactive section','Student','LRN4',30,1,'active'),(5,'Archived','Student','LRN5',10,0,'active'),
                (6,'Promoted','Student','LRN6',10,1,'promoted');
            CREATE TABLE attendance (id INTEGER,student_id INTEGER,date TEXT,am_in TEXT,am_out TEXT,pm_in TEXT,pm_out TEXT,
                am_status TEXT,pm_status TEXT,attendance_type TEXT,remarks TEXT DEFAULT '');");
        $insert = parent::prepare("INSERT INTO attendance (id,student_id,date,am_in,am_status,attendance_type) VALUES (?,?,?,'07:30:00','present','partial')");
        foreach ([1,2,4,5,6] as $id) $insert->execute([$id,$id,date('Y-m-d')]);
        if (str_ends_with($GLOBALS['case'], '_large')) {
            $student = parent::prepare("INSERT INTO students VALUES (?,'Recent','Student',?,20,1,'active')");
            for ($id = 10; $id < 70; $id++) {
                $student->execute([$id,'LRN'.$id]);
                $insert->execute([$id,$id,date('Y-m-d')]);
            }
        }
    }
    function prepare(string $query, array $options = []): PDOStatement|false {
        $GLOBALS['reads']++;
        return parent::prepare($query, $options);
    }
}
function getDB() { return $GLOBALS['db'] ??= new LogTestDB; }
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(['status'=>http_response_code(),'reads'=>$GLOBALS['reads'],'finalized'=>$GLOBALS['finalized']]);
});
PHP);
file_put_contents($fixture . '/includes/functions.php', <<<'PHP'
<?php
require_once __DIR__ . '/../config/database.php';
function requireLogin() { if ($GLOBALS['case'] === 'guest') { http_response_code(401); exit; } }
function currentUser() {
    $case = $GLOBALS['case'];
    $role = str_starts_with($case,'teacher') ? 'teacher' : (str_starts_with($case,'scanner') ? 'scanner_operator' :
        (str_starts_with($case,'admin') ? 'admin' : 'unrecognized'));
    return ['id'=>$case==='teacher_empty'?9:7,'role'=>$role];
}
PHP);
// Use the production schedule/status computations rather than duplicating them.
$functions = file_get_contents($root . '/includes/functions.php');
$start = strpos($functions, 'function getAttendanceScheduleType(');
$end = strpos($functions, 'function getDashboardStats(', $start);
file_put_contents($fixture . '/includes/functions.php', substr($functions, $start, $end - $start), FILE_APPEND);
file_put_contents($fixture . '/includes/attendance_finalizer.php', '<?php function finalizeAttendanceAbsences($date) { $GLOBALS["finalized"]++; }');
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD']='GET';
$_GET=['section'=>20,'user_id'=>8]; // Caller-supplied scope must not override the teacher session.
if ($argv[2] !== 'omitted') $_GET['scans_only']=$argv[2];
PHP);
copy($root . '/attendance/get_today_log.php', $fixture . '/attendance/get_today_log.php');
$checks = 0;
try {
    foreach (['guest','unknown','teacher','teacher_empty','admin','scanner','scanner_large','admin_large'] as $case) {
        foreach (['omitted','0','1'] as $filter) {
            $process = proc_open([PHP_BINARY,'-d','auto_prepend_file='.$fixture.'/request.php',
                $fixture.'/attendance/get_today_log.php',$case,$filter],
                [1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture.'/attendance');
            $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            if ($exit!==0 || $errors!=='' || !preg_match('/^(.*)\nRESULT:(.*)$/s',$output,$match)) throw new RuntimeException("$case/$filter failed: $errors $output");
            $payload=json_decode($match[1],true);$meta=json_decode($match[2],true);
            $expected=$case==='guest'?401:($case==='unknown'?403:200);
            if ($meta['status']!==$expected) throw new RuntimeException('Unexpected response status.');
            if ($meta['finalized'] !== 0) throw new RuntimeException('Read request finalized attendance.');
            if ($expected!==200) {
                if ($meta['reads'] || $meta['finalized']) throw new RuntimeException('Denied caller reached attendance data.');
            } else {
                $ids=array_column($payload,'student_id'); $sorted=$ids;sort($sorted);
                if ($case==='teacher' && $sorted!==($filter==='1'?[1]:[1,3])) throw new RuntimeException('Teacher received another section or lost their assigned roster.');
                if ($case==='teacher_empty' && $payload!==[]) throw new RuntimeException('Unassigned teacher received school data.');
                if ($case==='admin' && $sorted!==($filter==='1'?[1,2]:[1,2,3])) throw new RuntimeException('Admin log changed unexpectedly.');
                if ($case==='scanner' && $sorted!==[1,2]) throw new RuntimeException('Kiosk scans-only restriction failed.');
                if ($case==='scanner_large' && count($payload)!==50) throw new RuntimeException('Kiosk recent-log limit failed.');
                if ($case==='admin_large' && count($payload)!==($filter==='1'?62:63)) throw new RuntimeException('Kiosk limit incorrectly applied to administrator.');
                foreach ($payload as $row) {
                    if (str_starts_with($case,'scanner')) {
                        $keys=array_keys($row);sort($keys);
                        $allowed=['student_id','name','grade','section','time_in','time_out','arrival_status','attendance_type','last_event','last_event_time'];sort($allowed);
                        if ($keys!==$allowed || in_array($row['student_id'],[3,4,5,6])) throw new RuntimeException('Kiosk returned extra data or unscanned/inactive students.');
                    } elseif (!array_key_exists('lrn',$row)) throw new RuntimeException('Staff dashboard search lost LRN.');
                }
            }
            $checks++;
        }
    }
    echo "Live attendance log access checks passed ($checks cases, isolated SQL database).\n";
} finally {
    foreach (['config/database.php','includes/functions.php','includes/attendance_finalizer.php','request.php','attendance/get_today_log.php'] as $file) unlink($fixture.'/'.$file);
    foreach (['/attendance','/includes','/config',''] as $dir) rmdir($fixture.$dir);
}
