<?php
declare(strict_types=1);
function requireLogin():void {}
function currentUser():array {return ['id'=>1];}
function startSession():void {}
require dirname(__DIR__).'/includes/csrf.php';
define('BASE_URL','/');
ob_start();require dirname(__DIR__).'/includes/header.php';$html=ob_get_clean();
foreach(['style.css','analytics_template.css'] as $file) {
    $version=substr(hash_file('sha256',dirname(__DIR__).'/assets/css/'.$file),0,12);
    if(!str_contains($html,'assets/css/'.$file.'?v='.$version))throw new RuntimeException('Stylesheet must have a content-derived cache version: '.$file);
}
echo "Shared header versions both stylesheets by their content.\n";
