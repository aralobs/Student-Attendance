<?php
// Read-only overview. Each sheet load keeps the existing date and adviser checks.
$gradeLevel = trim((string)($_GET['grade'] ?? ''));
$typeFilter = (string)($_GET['type'] ?? '');
$typeOptions = ['full_day'=>'Full Day','partial'=>'Partial','absent'=>'Absent','late'=>'Late',
    'pending'=>'Pending participation','review_needed'=>'Needs review','remarked'=>'Manual remark',
    'not_recorded'=>'Not recorded','not_eligible'=>'No class','future'=>'Future date'];
if ($typeFilter !== '' && !isset($typeOptions[$typeFilter])) throw new InvalidArgumentException('Invalid attendance type.');
$grades = array_values(array_unique(array_column($sections, 'grade_level')));
if ($gradeLevel !== '' && !in_array($gradeLevel, $grades, true)) throw new InvalidArgumentException('Grade access denied.');
$sectionMap = array_column($sections, null, 'id');
$allSections = $sections;
if ($sectionId && !isset($sectionMap[$sectionId])) throw new DomainException('Section access denied.');
$sections = array_values(array_filter($sections, static fn($s) => $gradeLevel === '' || $s['grade_level'] === $gradeLevel));
// A previously selected section may no longer belong to the newly selected grade.
if ($sectionId && !in_array($sectionId, array_column($sections, 'id'))) $sectionId = 0;
$dayCounts = ['total'=>0,'full_day'=>0,'partial'=>0,'absent'=>0,'late'=>0];
foreach ($sections as $section) {
    if ($sectionId && (int)$section['id'] !== $sectionId) continue;
    foreach (loadAttendanceSheet($db, currentUser(), $yearId, (int)$section['id'], $date) as $row) {
        $status = $row['classification']['status'];
        $row['grade_level'] = $section['grade_level'];
        $row['section_name'] = $section['section_name'];
        $row['schedule_type'] = $section['schedule_type'];
        $row['overview_type'] = match ($status) {
            'present','late' => 'full_day',
            'absent_unexcused','absent_excused' => 'absent',
            default => $status
        };
        $row['overview_late'] = in_array($status, ['present','late','partial'], true)
            && ($status === 'late' || in_array('late', [$row['scan']['am_status'] ?? null,$row['scan']['pm_status'] ?? null], true));
        $dayCounts['total']++;
        if (isset($dayCounts[$row['overview_type']])) $dayCounts[$row['overview_type']]++;
        if ($row['overview_late']) $dayCounts['late']++;
        if ($typeFilter === '' || ($typeFilter === 'late' ? $row['overview_late'] : $row['overview_type'] === $typeFilter)) $rows[] = $row;
    }
}
usort($rows, static fn($a,$b) => [$a['grade_level'],$a['section_name'],$a['last_name'],$a['first_name']]
    <=> [$b['grade_level'],$b['section_name'],$b['last_name'],$b['first_name']]);
