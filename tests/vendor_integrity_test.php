<?php
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/assets/vendor/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$lock = json_decode(file_get_contents($root . '/package-lock.json'),true,512,JSON_THROW_ON_ERROR);
$templates = ['index.php','includes/footer.php','attendance/scanner.php']; $checks=0;
foreach ($manifest as $asset) {
    if (($lock['packages']['node_modules/' . $asset['package']]['version'] ?? null) !== $asset['version']) throw new RuntimeException('Browser lock/asset version mismatch'); $checks++;
    $expected='sha384-' . base64_encode(hash_file('sha384',$root . '/assets/vendor/' . $asset['file'],true));
    if (!hash_equals($expected,$asset['integrity'])) throw new RuntimeException('Vendor file changed: '.$asset['file']); $checks++;
    $found=false;
    foreach ($templates as $template) {
        $source=file_get_contents($root . '/' . $template);
        if (str_contains($source,'assets/vendor/'.$asset['file'])) {
            if (!str_contains($source,'assets/vendor/'.$asset['file'].'" integrity="'.$expected.'" crossorigin="anonymous"')) throw new RuntimeException('Missing/mismatched SRI: '.$template);
            $found=true; $checks++;
        }
    }
    if (!$found) throw new RuntimeException('Unused manifest entry');
}
foreach ($templates as $template) {
    if (preg_match('/<script[^>]+src=["\x27]https?:/i',file_get_contents($root . '/' . $template))) throw new RuntimeException('External executable script: '.$template);
    $checks++;
}
echo "Vendor integrity checks passed ($checks checks).\n";
