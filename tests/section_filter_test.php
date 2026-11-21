<?php
require_once dirname(__DIR__).'/includes/section_filter.php';
$sections = [
    ['id'=>11, 'grade_level'=>'Grade 3', 'section_name'=>'Faith & Hope'],
    ['id'=>16, 'grade_level'=>'Grade 6', 'section_name'=>'Faith'],
];
ob_start();
renderSectionFilterOptions($sections, 16, 'Grade 3');
$html = ob_get_clean();
$doc = new DOMDocument();
$doc->loadHTML('<meta charset="UTF-8"><select><option value="0">All Sections</option>'.$html.'</select>');
$x = new DOMXPath($doc);
function expectSectionFilter(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
expectSectionFilter($x->query('//option[@value="11"]')->item(0)->textContent === 'Grade 3 — Faith & Hope', 'Grade and escaped section label render');
expectSectionFilter($x->query('//option[@value="16" and @hidden and @disabled]')->length === 1, 'Other grades are unavailable before JavaScript runs');
expectSectionFilter($x->query('//option[@selected]')->length === 0, 'A mismatched section is not selected');
ob_start(); renderSectionFilterOptions($sections, 16); $html = ob_get_clean();
$doc->loadHTML('<meta charset="UTF-8"><select>'.$html.'</select>');
$x = new DOMXPath($doc);
expectSectionFilter($x->query('//option[@hidden or @disabled]')->length === 0, 'All grades restores every section');
expectSectionFilter($x->query('//option[@value="16" and @selected]')->length === 1, 'Matching selection is retained');
echo "Section filter rendering passed (5 checks).\n";
