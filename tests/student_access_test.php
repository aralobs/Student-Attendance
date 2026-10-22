<?php
// Run real student endpoints against disposable SQLite data, never the application DB.
$root = dirname(__DIR__);
$fixture = __DIR__ . '/.student-access-' . bin2hex(random_bytes(6));
foreach (['', '/config', '/includes', '/students'] as $dir) mkdir($fixture . $dir);
file_put_contents($fixture . '/config/database.php', <<<'PHP'
<?php
$case = $argv[1]; $writes = 0; $reads = 0;
http_response_code(200);
define('BASE_URL', '/'); define('BASE_PATH', __DIR__ . '/../');
class TestDB extends PDO {
    function __construct() {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->sqliteCreateFunction('CURDATE', fn() => '2026-10-10');
        $this->sqliteCreateFunction('FIELD', function ($value, ...$choices) {
            $position = array_search($value, $choices, true);
            return $position === false ? 0 : $position + 1;
        });
        $this->exec("CREATE TABLE users (id INTEGER, full_name TEXT, role TEXT, is_active INTEGER);
            CREATE TABLE system_settings (setting_key TEXT,setting_value TEXT);
            INSERT INTO system_settings VALUES ('enrollment_model_version','0');
            INSERT INTO users VALUES (7,'Assigned Teacher','teacher',1),(8,'Other Teacher','teacher',1);
            CREATE TABLE sections (id INTEGER, section_name TEXT, grade_level TEXT, school_year TEXT,
                adviser_id INTEGER, is_active INTEGER, schedule_type TEXT);
            INSERT INTO sections VALUES (10,'Assigned','Grade 1','2026-2027',7,1,'am_only'),
                (11,'Also assigned','Grade 2','2026-2027',7,1,'am_only'),
                (20,'Other','Grade 3','2026-2027',8,1,'full_day'),
                (30,'Inactive','Grade 4','2026-2027',7,0,'full_day');
            CREATE TABLE students (id INTEGER PRIMARY KEY, section_id INTEGER, is_active INTEGER DEFAULT 1,
                lrn TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, gender TEXT, birth_date TEXT,
                address TEXT, grade_level TEXT, school_year TEXT, photo TEXT, qr_token TEXT,
                parent_name TEXT, parent_contact TEXT, parent_email TEXT);
            INSERT INTO students VALUES
                (1,10,1,'LRN1','Assigned','','Student','Male',NULL,'','Grade 1','2026-2027','default.png','TOKEN1','Parent','09111111111','parent@example.test'),
                (2,20,1,'LRN2','Other','','Student','Male',NULL,'','Grade 3','2026-2027','default.png','TOKEN2','Parent','09222222222','other@example.test'),
                (3,NULL,1,'LRN3','Unassigned','','Student','Male',NULL,'','Grade 1','2026-2027','default.png','TOKEN3','','',''),
                (4,30,1,'LRN4','Inactive','','Student','Male',NULL,'','Grade 4','2026-2027','default.png','TOKEN4','','',''),
                (5,10,0,'LRN5','Archived own','','Student','Male',NULL,'','Grade 1','2026-2027','default.png','TOKEN5','','',''),
                (6,20,0,'LRN6','Archived other','','Student','Male',NULL,'','Grade 3','2026-2027','default.png','TOKEN6','','','');
            CREATE TABLE attendance (id INTEGER, student_id INTEGER, date TEXT, attendance_type TEXT,
                am_status TEXT, pm_status TEXT);
            INSERT INTO attendance VALUES (1,1,'2026-10-10','full_day','present','present'),
                (2,2,'2026-10-10','absent','absent','absent');");
    }
    function prepare(string $query, array $options = []): PDOStatement|false {
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)/i', $query)) {
            $GLOBALS['writes']++;
            if (str_contains($query, 'UPDATE students s SET')) {
                if ($GLOBALS['case'] === 'teacher_revoked') parent::exec('UPDATE sections SET adviser_id=8 WHERE id=10');
                if ($GLOBALS['case'] === 'teacher_moved_owned') parent::exec('UPDATE students SET section_id=11 WHERE id=1');
            }
        } else $GLOBALS['reads']++;
        // SQLite requires AS for UPDATE aliases; all predicates/bindings stay unchanged.
        return parent::prepare(str_replace(['UPDATE students s SET',' FOR UPDATE'], ['UPDATE students AS s SET',''], $query), $options);
    }
}
function getDB() { return $GLOBALS['db'] ??= new TestDB; }
register_shutdown_function(function () {
    $db = $GLOBALS['db'] ?? null;
    echo "\nRESULT:" . json_encode(['status' => http_response_code(), 'writes' => $GLOBALS['writes'],
        'reads' => $GLOBALS['reads'], 'rows' => $GLOBALS['visibleRows'] ?? [],
        'students' => $db ? $db->query('SELECT id,section_id,parent_contact FROM students ORDER BY id')->fetchAll() : []]);
});
PHP);
file_put_contents($fixture . '/includes/functions.php', <<<'PHP'
<?php
require_once __DIR__ . '/../config/database.php';
function requireLogin() { if ($GLOBALS['case'] === 'guest') { http_response_code(401); exit; } }
function isAdmin() { return str_starts_with($GLOBALS['case'], 'admin'); }
function isTeacher() { return str_starts_with($GLOBALS['case'], 'teacher'); }
function currentUser() { return ['id' => $GLOBALS['case'] === 'teacher_empty' ? 9 : 7, 'role'=>isAdmin() ? 'admin' : (isTeacher() ? 'teacher' : 'scanner_operator')]; }
function securityAuditChange(...$args) {}
function setFlash(...$args) {}
function gradeLevelOrderSQL($column) { return $column; }
function generateQRToken($lrn) { return 'SYNTHETIC-TOKEN'; }
function getAllowedSections() {
    [$scope,$params] = studentSectionScope('sec');
    $stmt = getDB()->prepare("SELECT sec.* FROM sections sec WHERE sec.is_active=1 AND ($scope)");
    $stmt->execute($params); return $stmt->fetchAll();
}
PHP);
file_put_contents($fixture . '/includes/header.php', <<<'PHP'
<?php
if (isset($students)) $GLOBALS['visibleRows'] = array_column($students, 'id');
elseif (isset($studentsBySection)) {
    foreach ($studentsBySection as $roster) foreach ($roster as $student) $GLOBALS['visibleRows'][] = $student['id'];
} elseif (isset($student)) $GLOBALS['visibleRows'] = [$student['id']];
exit;
PHP);
file_put_contents($fixture . '/request.php', <<<'PHP'
<?php
$case = $argv[1]; $endpoint = $argv[2];
$_SERVER['REQUEST_METHOD'] = in_array($endpoint,['edit','add']) ? 'POST' : 'GET';
$_GET = ['id' => in_array($case,['teacher_foreign','admin_foreign']) ? 2 :
    ($case === 'teacher_unassigned' ? 3 : ($case === 'teacher_inactive' ? 4 : 1))];
$_POST = ['lrn'=>'LRN1','first_name'=>'Assigned','middle_name'=>'','last_name'=>'Student',
    'gender'=>'Male','birth_date'=>'','address'=>'','section_id'=>10,'parent_name'=>'Parent',
    'parent_contact'=>'09333333333','parent_email'=>'parent@example.test'];
if (str_contains($case, 'transfer')) $_POST['section_id'] = $case === 'teacher_transfer_owned' ? 11 : 20;
if ($case === 'teacher_clear') $_POST['section_id'] = '';
if ($case === 'teacher_foreign') $_POST['section_id'] = 20;
if ($endpoint === 'add') $_POST['lrn'] = 'LRN-NEW';
if ($case === 'teacher_add_foreign') $_POST['section_id'] = 20;
if ($case === 'teacher_add_unassigned') $_POST['section_id'] = 0;
if ($case === 'teacher_add_inactive') $_POST['section_id'] = 30;
PHP);
copy($root . '/includes/student_access.php', $fixture . '/includes/student_access.php');
foreach (['enrollment_access.php','enrollment_repository.php','school_years.php'] as $dependency) copy($root . '/includes/' . $dependency,$fixture . '/includes/' . $dependency);
// This fixture already supplies its audit stub in functions.php; service reads do not audit.
file_put_contents($fixture . '/includes/security_audit.php', "<?php\n");
copy($root . '/includes/student_photos.php', $fixture . '/includes/student_photos.php');
$cases = [
    'view' => ['guest'=>401,'scanner'=>403,'unknown'=>403,'teacher'=>200,'teacher_foreign'=>302,
        'teacher_unassigned'=>302,'teacher_inactive'=>302,'teacher_empty'=>302,'admin_foreign'=>200],
    'edit' => ['guest'=>401,'scanner'=>403,'unknown'=>403,'teacher'=>302,'teacher_foreign'=>302,
        'teacher_transfer'=>403,'teacher_transfer_owned'=>403,'teacher_clear'=>403,
        'teacher_revoked'=>403,'teacher_moved_owned'=>403,'admin_transfer'=>302],
    'add' => ['guest'=>401,'scanner'=>403,'unknown'=>403,'teacher'=>302,'teacher_add_foreign'=>403,
        'teacher_add_unassigned'=>403,'teacher_add_inactive'=>403,'teacher_empty'=>403,'admin'=>302],
    'students' => ['guest'=>401,'scanner'=>403,'unknown'=>403,'teacher'=>200,'teacher_empty'=>200,'admin'=>200],
    'archived' => ['guest'=>401,'scanner'=>403,'unknown'=>403,'teacher'=>200,'teacher_empty'=>200,'admin'=>200],
    'master_list' => ['guest'=>401,'scanner'=>403,'unknown'=>403,'teacher'=>200,'teacher_empty'=>200,'admin'=>200],
];
$checks = 0;
file_put_contents($fixture . '/includes/functions.php', "\nfunction startSession(): void {}\n", FILE_APPEND);
file_put_contents($fixture . '/includes/functions.php', 'require_once ' . var_export($root . '/includes/csrf.php', true) . ';' . "\n" . '$_SESSION["app_csrf"] = str_repeat("a", 64); $_POST["csrf_token"] = $_SESSION["app_csrf"];' . "\n", FILE_APPEND);

try {
    foreach ($cases as $endpoint => $scenarios) {
        copy($root . '/students/' . $endpoint . '.php', $fixture . '/students/' . $endpoint . '.php');
        foreach ($scenarios as $case => $expected) {
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . $fixture . '/request.php',
                $fixture . '/students/' . $endpoint . '.php', $case, $endpoint],
                [1=>['pipe','w'],2=>['pipe','w']], $pipes, $fixture . '/students');
            $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            if ($exit !== 0 || $errors !== '' || !preg_match('/RESULT:(.*)$/',$output,$match)) throw new RuntimeException("$endpoint/$case failed: $errors $output");
            $result = json_decode($match[1],true);
            if ($result['status'] !== $expected) throw new RuntimeException("$endpoint/$case status: " . $match[1]);
            if (in_array($case,['guest','scanner','unknown']) && ($result['reads'] || $result['writes'])) throw new RuntimeException('Unauthorized role reached database queries.');
            if (str_starts_with($case,'teacher')) {
                $expectedRows = $case === 'teacher_empty' || str_contains($case,'foreign') || str_contains($case,'unassigned') || str_contains($case,'inactive') ? [] :
                    ($endpoint === 'archived' ? [5] : ($endpoint === 'view' || in_array($endpoint,['students','master_list']) ? [1] : []));
                if ($result['rows'] !== $expectedRows) throw new RuntimeException("$endpoint/$case exposed unexpected students: " . $match[1]);
            }
            if ($case === 'admin' && in_array($endpoint,['students','archived','master_list'])) {
                $actualRows = $result['rows']; sort($actualRows);
                $expectedRows = $endpoint === 'students' ? [1,2,3,4] : ($endpoint === 'archived' ? [5,6] : [1,2,4]);
                if ($actualRows !== $expectedRows) throw new RuntimeException('Administrator lost student visibility.');
            }
            if ($endpoint === 'edit' && $result['students']) {
                $first = $result['students'][0];
                $saved = in_array($case,['teacher','admin_transfer']);
                if ($first['parent_contact'] !== ($saved ? '09333333333' : '09111111111')) throw new RuntimeException('Unauthorized parent contact change.');
                if ($first['section_id'] !== ($case === 'admin_transfer' ? 20 : ($case === 'teacher_moved_owned' ? 11 : 10))) throw new RuntimeException('Unauthorized section transfer.');
                if ($result['students'][1]['parent_contact'] !== '09222222222' || $result['students'][1]['section_id'] !== 20) throw new RuntimeException('Another section\'s student was changed.');
                if (!in_array($case,['teacher','admin_transfer','teacher_revoked','teacher_moved_owned']) && $result['writes'] !== 0) throw new RuntimeException('Denied edit reached a write query.');
            }
            if ($endpoint === 'add' && $result['students'] && count($result['students']) !== ($expected === 302 ? 7 : 6)) throw new RuntimeException('Unauthorized enrollment.');
            $checks++;
        }
    }
    // Exercise converted adapter predicates without replacing any legacy assertions.
    file_put_contents($fixture . '/scope_probe.php', <<<'PHP'
<?php
require __DIR__.'/includes/student_access.php';
$db=getDB();
$db->exec("UPDATE system_settings SET setting_value='1'; ALTER TABLE sections ADD COLUMN school_year_id INTEGER;
  UPDATE sections SET school_year_id=CASE WHEN id=11 THEN 2 ELSE 1 END;
  UPDATE sections SET is_active=0 WHERE id=11;
  CREATE TABLE school_years(id INTEGER,state TEXT,starts_on TEXT,ends_on TEXT);
  INSERT INTO school_years VALUES (1,'active','2026-06-01','2027-03-31'),(2,'closed','2025-06-01','2026-03-31');
  CREATE TABLE student_enrollments(id INTEGER,student_id INTEGER,school_year_id INTEGER,state TEXT,starts_on TEXT,ends_on TEXT);
  INSERT INTO student_enrollments VALUES (1001,1,1,'active','2026-06-01','2027-03-31'),(1002,1,2,'completed','2025-06-01','2026-03-31');
  CREATE TABLE enrollment_sections(id INTEGER,enrollment_id INTEGER,section_id INTEGER,starts_on TEXT,ends_on TEXT);
  INSERT INTO enrollment_sections VALUES (2001,1001,10,'2026-06-01','2027-03-31'),(2002,1002,11,'2025-06-01','2026-03-31');
  UPDATE students SET section_id=20 WHERE id=1");
[$scope,$params]=studentSectionScope('sec');
$q=$db->prepare("SELECT sec.id FROM sections sec WHERE ($scope) ORDER BY id"); $q->execute($params);
if ($q->fetchAll(PDO::FETCH_COLUMN)!==[10]) throw new RuntimeException('Managed sections must default to active year');
[$scope,$params]=studentRecordScope('s');
$q=$db->prepare("SELECT s.id FROM students s WHERE ($scope)"); $q->execute($params);
if ($q->fetchAll(PDO::FETCH_COLUMN)!==[1]) throw new RuntimeException('Managed access must follow annual placement');
[$scope,$params]=studentRecordScope('s',2,true);
$q=$db->prepare("SELECT s.id FROM students s WHERE ($scope)"); $q->execute($params);
if ($q->fetchAll(PDO::FETCH_COLUMN)!==[1]) throw new RuntimeException('Explicit historical year retained');
$db->exec('UPDATE sections SET adviser_id=8 WHERE id=11');
$q->execute($params);
if ($q->fetchAll(PDO::FETCH_COLUMN)!==[]) throw new RuntimeException('Historical reassignment must revoke adapter access');
echo 'MANAGED_SCOPES_OK';
PHP);
    $process=proc_open([PHP_BINARY,$fixture.'/scope_probe.php','teacher'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$fixture);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    if ($exit!==0 || $errors!=='' || !str_contains($output,'MANAGED_SCOPES_OK')) throw new RuntimeException('Managed scope probe failed: '.$errors.' '.$output);
    echo "Student authorization checks passed ($checks cases plus 4 managed scopes, isolated SQL database).\n";
} finally {
    foreach (array_keys($cases) as $endpoint) if (is_file($fixture.'/students/'.$endpoint.'.php')) unlink($fixture.'/students/'.$endpoint.'.php');
    foreach (['config/database.php','includes/functions.php','includes/student_access.php','includes/enrollment_access.php','includes/enrollment_repository.php','includes/school_years.php','includes/security_audit.php','includes/student_photos.php','includes/header.php','request.php','scope_probe.php'] as $file) if (is_file($fixture.'/'.$file)) unlink($fixture.'/'.$file);
    foreach (['/students','/includes','/config',''] as $dir) rmdir($fixture.$dir);
}
