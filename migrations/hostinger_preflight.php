<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Read-only deployment inventory: never print secrets, names, contacts or ciphertext.
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
require_once dirname(__DIR__).'/config/database.php';
try {
    $db=getDB(true);
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $result=['php'=>PHP_VERSION,'database'=>DB_NAME,'counts'=>[],'private'=>[],'capabilities'=>[]];
    foreach(['students','attendance','sections','school_years','student_enrollments','enrollment_sections','attendance_decisions','database_backups'] as $table) {
        $result['counts'][$table]=in_array($table,$tables,true)?(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn():null;
    }
    $private=dirname(dirname(__DIR__)).'/attendance-private';
    foreach(['hosting.php','pii_key.php','settings_key.php','setting_key.php','student-photos'] as $file) $result['private'][$file]=is_readable($private.'/'.$file);
    foreach(['proc_open','proc_close','gzopen','openssl_decrypt'] as $function) $result['capabilities'][$function]=function_exists($function);
    $result['settings_key_environment']=(getenv('SETTINGS_ENCRYPTION_KEY')?:'')!=='';
    $result['pii_key_configured_readable']=is_readable(getenv('PII_KEY_FILE')?:'');
    $result['photo_directory_configured']=is_dir(getenv('STUDENT_PHOTO_DIR')?:'');
    $result['backup_directory_configured']=is_dir(getenv('BACKUP_DIR')?:'');
    $result['dump_binaries']=['mysqldump'=>is_executable('/usr/bin/mysqldump'),'mariadb-dump'=>is_executable('/usr/bin/mariadb-dump')];
    $result['enrollment_model_version']=$db->query("SELECT setting_value FROM system_settings WHERE setting_key='enrollment_model_version'")->fetchColumn();
    $result['sections_by_year']=$db->query('SELECT school_year,COUNT(*) AS count FROM sections GROUP BY school_year')->fetchAll();
    $result['attendance_dates']=$db->query('SELECT MIN(date) AS earliest,MAX(date) AS latest FROM attendance')->fetch();
    $result['key_checks']=[];
    require_once dirname(__DIR__).'/includes/pii_security.php';
    require_once dirname(__DIR__).'/includes/settings_security.php';
    foreach(['pii'=>static fn()=>piiEncryptionKey(false),'settings'=>static fn()=>settingsEncryptionKey(false)] as $name=>$check) {
        try { $check(); $result['key_checks'][$name]=true; } catch(Throwable $error) { $result['key_checks'][$name]=false; }
    }
    if (in_array('security_change_history',$tables,true)) {
        $sample=$db->query('SELECT event_id,change_ciphertext FROM security_change_history LIMIT 1')->fetch();
        if ($sample) {
            try { decryptPII($sample['change_ciphertext'],'security-change:'.$sample['event_id']); $result['key_checks']['existing_history_decrypts']=true; }
            catch(Throwable $error) { $result['key_checks']['existing_history_decrypts']=false; }
        }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $error) {
    echo json_encode(['preflight_failed'=>get_class($error)],JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
