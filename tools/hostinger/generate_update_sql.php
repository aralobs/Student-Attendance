<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__,2).'/includes/enrollment_schema.php';
require_once dirname(__DIR__,2).'/includes/adviser_workflows.php';
require_once dirname(__DIR__,2).'/includes/database_backups.php';
$destination=$argv[1]??'';
if($destination==='' || file_exists($destination))throw new RuntimeException('Choose a new SQL output file.');
$quote=static fn(string $value):string=>"'".str_replace("'","''",$value)."'";
$sql="-- ADDITIVE UPDATE ONLY: select the existing Hostinger database in phpMyAdmin.\n-- Back up first. This does not replace records or enable annual enrollment.\n-- DDL commits incrementally; fix any reported error before proceeding.\nSET NAMES utf8mb4;\n";
$sql.="CREATE TABLE IF NOT EXISTS enrollment_schema_progress (step_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
foreach(enrollmentSchemaTables() as $ddl)$sql.=$ddl.";\n";
$sql.="INSERT IGNORE INTO school_year_control(id,version) VALUES(1,1);\n";
foreach(enrollmentSchemaAlterations() as [$kind,$table,$name,$definition]) {
    $catalog=['column'=>'COLUMNS','index'=>'STATISTICS','constraint'=>'TABLE_CONSTRAINTS'][$kind];
    $field=['column'=>'COLUMN_NAME','index'=>'INDEX_NAME','constraint'=>'CONSTRAINT_NAME'][$kind];
    $ddl='ALTER TABLE `'.$table.'` ADD '.$definition;
    $sql.='SET @asam_ddl = IF(EXISTS(SELECT 1 FROM information_schema.'.$catalog.' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='.$quote($table).' AND '.$field.'='.$quote($name).'),'.$quote('SELECT 1').','.$quote($ddl).");\nPREPARE asam_update FROM @asam_ddl;\nEXECUTE asam_update;\nDEALLOCATE PREPARE asam_update;\n";
}
foreach([deliverySchemaSQL(),attendanceDecisionSchemaSQL(),interventionSchemaSQL(),backupSchemaSQL(),adviserWorkflowSchemaSQL()] as $ddl)$sql.=rtrim($ddl,"; \r\n").";\n";
$sql.="ALTER TABLE sms_logs MODIFY type ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence','missing_checkout','intervention') NOT NULL;\n";
$sql.="SELECT 'Schema update complete; annual enrollment still requires reviewed mapping and initialization' AS result;\n";
if(file_put_contents($destination,$sql)!==strlen($sql))throw new RuntimeException('Cannot write SQL update.');
echo "Additive SQL generated without student records or keys.\n";
