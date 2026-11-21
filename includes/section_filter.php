<?php
/** Grade-qualified options; retain other grades so the browser can restore them. */
function renderSectionFilterOptions(array $sections, int $sectionId, string $grade = ''): void
{
    foreach ($sections as $section) {
        $id = (int)$section['id'];
        $sectionGrade = (string)$section['grade_level'];
        $matches = $grade === '' || $sectionGrade === $grade;
        echo '<option value="'.$id.'" data-grade="'.htmlspecialchars($sectionGrade, ENT_QUOTES, 'UTF-8').'"';
        if (!$matches) echo ' hidden disabled';
        if ($matches && $sectionId === $id) echo ' selected';
        echo '>'.htmlspecialchars($sectionGrade.' — '.$section['section_name'], ENT_QUOTES, 'UTF-8').'</option>';
    }
}
