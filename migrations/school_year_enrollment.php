<?php
declare(strict_types=1);
// HTTP requests cannot inventory school records or mutate enrollment history.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Resolve private local files only, outside this checkout and conventional web roots. */
function enrollmentMigrationPrivatePath(string $path, bool $existing): string
{
    if (str_contains($path,"\0") || preg_match('/\A[a-z][a-z0-9+.-]*:\/\//i',$path)) throw new InvalidArgumentException('Local private path required.');
    $parent=realpath(dirname($path));
    if ($parent===false || !is_dir($parent) || is_link($path)) throw new InvalidArgumentException('Private destination is unavailable.');
    $resolved=$parent.DIRECTORY_SEPARATOR.basename($path);
    $normalized=strtolower(str_replace('\\','/',$resolved));
    $root=strtolower(str_replace('\\','/',(string)realpath(dirname(__DIR__))));
    $documentRoot=realpath($_SERVER['DOCUMENT_ROOT']??'');
    if (str_starts_with($normalized,$root.'/') || preg_match('~/(?:htdocs|public_html|wwwroot|www)/~',$normalized)
        || ($documentRoot && str_starts_with($normalized,strtolower(str_replace('\\','/',$documentRoot)).'/'))) throw new InvalidArgumentException('Inventory and mapping files must be outside public directories.');
    if ($existing && (!is_file($resolved) || !is_readable($resolved))) throw new InvalidArgumentException('Private input is unavailable.');
    if (!$existing && file_exists($resolved)) throw new InvalidArgumentException('Private destination already exists.');
    return $resolved;
}

/** Stream lists without creating a second large encoded inventory in memory. */
function enrollmentMigrationWritePrivate(string $path, array $result): void
{
    $old=umask(0077);
    try { $file=fopen($path,'x+b'); } finally { umask($old); }
    if (!$file) throw new RuntimeException('Cannot create private export.');
    try {
        if (DIRECTORY_SEPARATOR==='/' && !chmod($path,0600)) throw new RuntimeException('Cannot restrict export permissions.');
        $write=static function(string $text) use ($file): void {
            $length=strlen($text); $offset=0;
            while ($offset<$length) { $count=fwrite($file,substr($text,$offset)); if ($count===false || $count===0) throw new RuntimeException('Private export write failed.'); $offset+=$count; }
        };
        $write('{'); $first=true;
        foreach ($result as $key=>$value) {
            $write(($first?'':',').json_encode($key,JSON_THROW_ON_ERROR).':'); $first=false;
            if (is_array($value) && array_is_list($value)) {
                $write('['); foreach ($value as $index=>$row) $write(($index?',':'').json_encode($row,JSON_THROW_ON_ERROR)); $write(']');
            } else $write(json_encode($value,JSON_THROW_ON_ERROR));
        }
        $write("}\n");
    } catch (Throwable $error) { fclose($file); unlink($path); throw $error; }
    fclose($file);
}

$commandValidated=false;
try {
    $options=[];
    foreach (array_slice($argv,1) as $argument) {
        if (!preg_match('/\A--(schema|inventory|apply-map|actor-id|readiness|initialize-preview|initialize|fingerprint|output)(?:=(.*))?\z/s',$argument,$parts) || isset($options[$parts[1]])) throw new InvalidArgumentException('Invalid command options.');
        $options[$parts[1]]=$parts[2]??true;
    }
    $modes=array_intersect(['schema','inventory','apply-map','readiness','initialize-preview','initialize'],array_keys($options));
    if (count($modes)!==1) throw new InvalidArgumentException('Choose exactly one mode.');
    $mode=array_values($modes)[0];
    $allowed=match($mode) {'schema'=>['schema'],'inventory'=>['inventory','output'],'apply-map'=>['apply-map','actor-id'],'readiness'=>['readiness','output'],'initialize-preview'=>['initialize-preview','actor-id','output'],'initialize'=>['initialize','actor-id','fingerprint']};
    if (array_diff(array_keys($options),$allowed)) throw new InvalidArgumentException('Unexpected command option.');
    $output=null; $input=null;
    if (in_array($mode,['inventory','readiness','initialize-preview'],true)) {
        if (!is_string($options['output']??null)) throw new InvalidArgumentException('Private --output destination is required.');
        $output=enrollmentMigrationPrivatePath($options['output'],false);
    }
    if ($mode==='apply-map') {
        if (!is_string($options['apply-map']) || !is_string($options['actor-id']??null)) throw new InvalidArgumentException('Private map and administrator attribution are required.');
        $input=enrollmentMigrationPrivatePath($options['apply-map'],true);
    }
    if (in_array($mode,['schema','inventory'],true) && $options[$mode]!==true) throw new InvalidArgumentException('Invalid command flag.');
    if (in_array($mode,['initialize-preview','initialize'],true)) {
        foreach ([$options[$mode],$options['actor-id']??null] as $id) {
            if (!is_string($id) || !preg_match('/\A[1-9][0-9]*\z/',$id) || filter_var($id,FILTER_VALIDATE_INT)===false) throw new InvalidArgumentException('Year and administrator IDs are required.');
        }
        if ($mode==='initialize' && (!is_string($options['fingerprint']??null) || !preg_match('/\A[0-9a-f]{64}\z/',$options['fingerprint']))) throw new InvalidArgumentException('Initialization fingerprint is required.');
    }
    $commandValidated=true;
    require_once dirname(__DIR__) . '/config/database.php';
    require_once dirname(__DIR__) . '/includes/enrollment_migration.php';
    $db=getDB(true);
    if ($mode==='schema') {
        require_once dirname(__DIR__) . '/includes/enrollment_schema.php';
        ensureEnrollmentSchema($db);
        fwrite(STDOUT,"Annual enrollment schema ready. Legacy records remain unmapped.\n");
    } elseif ($mode==='apply-map') {
        $mapping=json_decode(file_get_contents($input),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($mapping)) throw new InvalidArgumentException('Invalid private mapping.');
        $result=applyEnrollmentMappings($db,['id'=>enrollmentMigrationId($options['actor-id'])],$mapping);
        fwrite(STDOUT,'Mapped enrollments='.count($result['enrollments']).' assignments='.count($result['assignments']).".\n");
    } elseif ($mode==='initialize') {
        initializeEnrollmentModel($db,['id'=>enrollmentMigrationId($options['actor-id']),'role'=>'admin'],enrollmentMigrationId($options['initialize']),$options['fingerprint']);
        fwrite(STDOUT,"Annual enrollment initialized; identical completed commands are unchanged.\n");
    } elseif ($mode==='initialize-preview') {
        $result=enrollmentInitializationPreview($db,['id'=>enrollmentMigrationId($options['actor-id']),'role'=>'admin'],enrollmentMigrationId($options['initialize-preview']));
        enrollmentMigrationWritePrivate($output,$result);
        fwrite(STDOUT,'Initialization preview exported; ready='.(int)$result['ready'].' blocking='.count($result['blocking_issues']).' historical='.count($result['historical_issues']).".\n");
    } else {
        $result=$mode==='inventory' ? inventoryEnrollmentHistory($db) : enrollmentMigrationReadiness($db,enrollmentMigrationId($options['readiness']));
        enrollmentMigrationWritePrivate($output,$result);
        fwrite(STDOUT,$mode==='inventory' ? 'Inventory exported; issues='.count($result['issues']).".\n" : 'Readiness exported; ready='.(int)$result['ready'].' blocking='.count($result['blocking_issues']).' historical='.count($result['historical_issues']).".\n");
    }
} catch (Throwable $error) {
    // Never print raw JSON, paths, SQL, student records, or connection details.
    fwrite(STDERR,($commandValidated ? 'Enrollment migration command failed. Schema progress is retained on DDL failure. ' : '')."Usage: php migrations/school_year_enrollment.php --schema | --inventory --output=<private-path> | --apply-map=<private-json-path> --actor-id=<admin-id> | --readiness=<year-id> --output=<private-path> | --initialize-preview=<year-id> --actor-id=<admin-id> --output=<private-path> | --initialize=<year-id> --fingerprint=<hash> --actor-id=<admin-id>\n");
    exit($commandValidated ? 1 : 2);
}
