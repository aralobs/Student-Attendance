<?php
// Exercise the actual POST-to-save assignments without connecting to the database.
$root = dirname(__DIR__);
$checks = 0;
function checkEntryName(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
function readEntryName(string $file, string $variable, array $post): string {
    $_POST = $post;
    $source = file_get_contents($GLOBALS['root'] . '/' . $file);
    if ($file === 'sections/save.php') {
        preg_match('/^\s*\$id\s*=.*;/m', $source, $idAssignment);
        eval($idAssignment[0]);
    }
    if (!preg_match('/^\s*\$' . $variable . '\s*=.*;/m', $source, $match)) {
        throw new RuntimeException('Missing name assignment: ' . $file);
    }
    eval($match[0]);
    return $$variable;
}
foreach (['juan dela cruz' => 'Juan Dela Cruz', '  MARÍA peÑA  ' => 'María Peña',
    "ana-mARIE o'NEILL" => "Ana-Marie O'neill", '' => ''] as $input => $expected) {
    foreach (['firstName' => 'first_name', 'middleName' => 'middle_name', 'lastName' => 'last_name'] as $var => $field) {
        checkEntryName(readEntryName('students/add.php', $var, [$field => $input]) === $expected, 'Student ' . $field . ': ' . $input);
    }
    checkEntryName(readEntryName('users/add.php', 'fullName', ['full_name' => $input, 'role' => 'teacher']) === $expected, 'Teacher: ' . $input);
}
checkEntryName(readEntryName('users/add.php', 'fullName', ['full_name' => 'Custom ADMIN', 'role' => 'admin']) === 'Custom ADMIN', 'Other user roles keep their names');
checkEntryName(readEntryName('sections/save.php', 'sectionName', ['section_name' => '  rose SECTION  ', 'id' => 0]) === 'Rose Section', 'New section title case');
checkEntryName(readEntryName('sections/save.php', 'sectionName', ['section_name' => 'Custom SECTION', 'id' => 10]) === 'Custom SECTION', 'Existing section stays unchanged');
echo "New entry name checks passed ($checks checks).\n";
