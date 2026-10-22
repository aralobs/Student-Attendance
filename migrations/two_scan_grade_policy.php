<?php
/** Run once per deployment: php migrations/two_scan_grade_policy.php */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/schedule_rules.php';
$db = getDB();
$db->beginTransaction();
try {
    $sections = $db->query('SELECT id, grade_level, schedule_type FROM sections FOR UPDATE')->fetchAll();
    $save = $db->prepare('UPDATE sections SET schedule_type = ? WHERE id = ?');
    $count = 0;
    foreach ($sections as $section) {
        $rule = getScheduleForGrade($section['grade_level']);
        // Never overwrite an administrator or teacher's valid saved choice.
        if (!$rule || in_array($section['schedule_type'], ['am_only', 'pm_only', 'full_day'], true)) continue;
        $save->execute([$rule['default_type'], $section['id']]);
        $count += $save->rowCount();
    }
    $db->commit();
    echo "Grade policy updated for $count sections; saved time windows and attendance history preserved.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
