<?php
declare(strict_types=1);

/** Quote metadata identifiers, never interpolate data as SQL. */
function dumpSQLIdentifier(string $name):string { return '`'.str_replace('`','``',$name).'`'; }

/** SHOW CREATE qualifies views with their source schema. Make restores portable,
 * while retaining schema-like text inside SQL string literals verbatim. */
function dumpPortableDefinition(string $sql,string $database):string {
    $parts=preg_split("~('(?:''|\\\\.|[^'\\\\])*')~s",$sql,-1,PREG_SPLIT_DELIM_CAPTURE);
    if($parts===false)throw new RuntimeException('Cannot encode database definition.');
    foreach($parts as $index=>&$part)if($index%2===0)$part=str_replace(dumpSQLIdentifier($database).'.','',$part);
    unset($part);return implode('',$parts);
}

/** Consistent InnoDB snapshot for hosts that disable proc_open. No external
 * process, credential file, provider call or school-record output is involved.
 * Restores target an EMPTY database; original triggers/routines/events are kept.
 * Schema changes must be excluded while a backup runs, as with mysqldump. */
function dumpDatabaseWithPDO(PDO $db,string $output):void {
    if($db->inTransaction() || file_exists($output) || is_link($output))throw new DomainException('Choose a new private dump file outside a transaction.');
    $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    if($database==='')throw new DomainException('Select the database to export.');
    $oldTimezone=(string)$db->query('SELECT @@session.time_zone')->fetchColumn();
    $oldBuffered=$db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $file=fopen($output,'xb');if(!$file)throw new RuntimeException('Cannot create dump.');
    chmod($output,0600);
    $write=static function(string $sql)use($file):void {
        $length=strlen($sql);$offset=0;
        while($offset<$length){$count=fwrite($file,substr($sql,$offset));if($count===false||$count===0)throw new RuntimeException('Dump write failed.');$offset+=$count;}
    };
    try {
        $db->exec("SET time_zone='+00:00'");
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        $catalog=$db->query("SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        foreach($catalog as $table)if($table['TABLE_TYPE']==='BASE TABLE' && $table['ENGINE']!=='InnoDB')throw new DomainException('Consistent PHP backup requires InnoDB tables.');
        $write("-- ASAM PHP database dump\nSET NAMES utf8mb4;\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        foreach($catalog as $table) {
            if($table['TABLE_TYPE']!=='BASE TABLE')continue;
            $identifier=dumpSQLIdentifier($table['TABLE_NAME']);
            $ddl=$db->query('SHOW CREATE TABLE '.$identifier)->fetch(PDO::FETCH_NUM);
            $write($ddl[1].";\n");
            $columns=$db->query('SHOW FULL COLUMNS FROM '.$identifier)->fetchAll(PDO::FETCH_ASSOC);
            $columns=array_values(array_filter($columns,fn($c)=>!preg_match('/(?:VIRTUAL|STORED) GENERATED/i',$c['Extra'])));
            if(!$columns)continue;
            $names=implode(',',array_map(fn($c)=>dumpSQLIdentifier($c['Field']),$columns));
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
            $rows=$db->query('SELECT '.$names.' FROM '.$identifier);
            try {
                while($row=$rows->fetch(PDO::FETCH_NUM)) {
                    $values=[];
                    foreach($row as $index=>$value) {
                        if($value===null){$values[]='NULL';continue;}
                        if($value===''){$values[]="''";continue;}
                        $hex='0x'.bin2hex((string)$value);
                        $values[]=preg_match('/^(?:tinyblob|blob|mediumblob|longblob|binary|varbinary|bit)\b/i',$columns[$index]['Type'])?$hex:'CONVERT('.$hex.' USING utf8mb4)';
                    }
                    $write('INSERT INTO '.$identifier.' ('.$names.') VALUES ('.implode(',',$values).");\n");
                }
            } finally { $rows->closeCursor();$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$oldBuffered); }
        }
        foreach($catalog as $table)if($table['TABLE_TYPE']==='VIEW') {
            $ddl=$db->query('SHOW CREATE VIEW '.dumpSQLIdentifier($table['TABLE_NAME']))->fetch(PDO::FETCH_ASSOC);
            $write(dumpPortableDefinition($ddl['Create View'],$database).";\n");
        }
        $objects=[];
        foreach($db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger)$objects[]=['TRIGGER',$trigger['Trigger'],'SQL Original Statement'];
        foreach(['PROCEDURE','FUNCTION'] as $type) {
            $q=$db->query('SHOW '.$type.' STATUS WHERE Db='.$db->quote($database));
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $routine)$objects[]=[$type,$routine['Name'],'Create '.ucfirst(strtolower($type))];
        }
        foreach($db->query('SHOW EVENTS')->fetchAll(PDO::FETCH_ASSOC) as $event)$objects[]=['EVENT',$event['Name'],'Create Event'];
        foreach($objects as [$type,$name,$field]) {
            $ddl=$db->query('SHOW CREATE '.$type.' '.dumpSQLIdentifier($name))->fetch(PDO::FETCH_ASSOC);
            if(empty($ddl[$field]))throw new RuntimeException('Cannot read complete database object.');
            $definition=dumpPortableDefinition($ddl[$field],$database);
            // mysql/mariadb clients limit custom delimiters to 15 characters.
            do{$delimiter='D'.bin2hex(random_bytes(6));}while(str_contains($definition,$delimiter));
            $write('SET SQL_MODE='.$db->quote((string)($ddl['sql_mode']??'')).";\nDELIMITER ".$delimiter."\n".$definition.$delimiter."\nDELIMITER ;\n");
        }
        $db->commit();
        $write("SET FOREIGN_KEY_CHECKS=1;\n-- ASAM dump complete\n");
        if(!fflush($file))throw new RuntimeException('Dump flush failed.');
    } finally {
        if($db->inTransaction())$db->rollBack();
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$oldBuffered);
        $db->exec('SET time_zone='.$db->quote($oldTimezone));
        fclose($file);
    }
}
