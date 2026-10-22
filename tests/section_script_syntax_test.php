<?php
// Generate the real section-page footer without an authenticated school request.
$source = file_get_contents(__DIR__ . '/../sections/sections.php');
$start = strpos($source, '$gradeDefaultsJson =');
$end = strpos($source, "include '../includes/footer.php';", $start);
if ($start === false || $end === false) throw new RuntimeException('Section script footer not found.');
function isAdmin(): bool { return $GLOBALS['script_admin']; }
function getSetting(string $key): ?string { return $key === 'school_year' ? '2026-2027' : null; }
$gradeDefaults = ['Grade 1'=>['default_type'=>'am_only']];
$selectedYearId = 42;
$checks = 0;
foreach ([false,true] as $managedEnrollment) foreach ([false,true] as $script_admin) {
    eval(substr($source,$start,$end-$start));
    preg_match_all('~<script\b[^>]*>(.*?)</script>~s',$extraJS,$scripts);
    if (!$scripts[1]) throw new RuntimeException('Section scripts did not render.');
    $process = proc_open([getenv('NODE_BINARY') ?: 'node','--check','--input-type=commonjs'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],implode("\n",$scripts[1])); fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
    if ($exit !== 0 || str_contains($extraJS,'<?')) throw new RuntimeException('Rendered section JavaScript fails syntax validation (managed='.(int)$managedEnrollment.', admin='.(int)$script_admin.'): '.$errors.$output);
    $checks++;
}
echo "Section scripts pass generated JavaScript syntax checks ($checks mode/role cases).\n";
