<?php
/**
 * Save Section — Add or Edit
 * Times come from the form (manual entry, pre-filled with grade defaults).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
requireCsrfPost();
if (!isAdmin() && !isTeacher()) { http_response_code(403); exit('Section editing is restricted to administrators and teachers.'); }


$id           = (int)($_POST['id']            ?? 0);
$sectionName  = trim($_POST['section_name']   ?? '');
$gradeLevel   = trim($_POST['grade_level']    ?? '');
$rule = getScheduleForGrade($gradeLevel);
$scheduleType = $_POST['schedule_type'] ?? ($rule['default_type'] ?? 'full_day');
$adviserId    = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;
$schoolYear   = trim($_POST['school_year']    ?? '2026-2027');

$allowedGrades    = getGradeLevels();
$allowedSchedules = ['full_day', 'am_only', 'pm_only'];

// Teachers may change schedules for their own active sections.
if (isTeacher() && !isAdmin()) {
    if ($id <= 0 || !canAccessSection($id)) { http_response_code(403); exit('Access denied to this section.'); }
    $existingSection = getSection($id);
    if (!$existingSection || empty($existingSection['is_active'])) { http_response_code(403); exit('Access denied to this section.'); }
    $sectionName = $existingSection['section_name'];
    $gradeLevel = $existingSection['grade_level'];
    $adviserId = $existingSection['adviser_id'];
    $schoolYear = $existingSection['school_year'];
}

// ── Validate ────────────────────────────────────────────────
if ($sectionName === '') {
    setFlash('danger', 'Section name is required.');
    header('Location: sections.php');
    exit;
}
if (!in_array($gradeLevel, $allowedGrades, true)) {
    setFlash('danger', 'Invalid grade level.');
    header('Location: sections.php');
    exit;
}
if (!in_array($scheduleType, $allowedSchedules, true)) {
    setFlash('danger', 'Invalid schedule type.');
    header('Location: sections.php');
    exit;
}

// ── Time fields with safe defaults ──────────────────────────
function timeOrDefault(string $key, string $default): string {
    $val = trim($_POST[$key] ?? '');
    if ($val === '') return $default;
    if (preg_match('/^\d{2}:\d{2}$/', $val)) return $val . ':00';
    return $val;
}

$amInStart       = timeOrDefault('am_in_start', '08:00:00');
$amInEnd         = timeOrDefault('am_in_end', '08:30:00');
$amLateThreshold = timeOrDefault('am_late_threshold', '08:01:00');
$amOutStart      = timeOrDefault('am_out_start', '11:30:00');
$amOutEnd        = timeOrDefault('am_out_end', '12:00:00');
$pmInStart       = timeOrDefault('pm_in_start', '13:00:00');
$pmInEnd         = timeOrDefault('pm_in_end',         '13:30:00');
$pmLateThreshold = timeOrDefault('pm_late_threshold', '13:01:00');
$pmOutStart      = timeOrDefault('pm_out_start', '15:30:00');
$pmOutEnd        = timeOrDefault('pm_out_end', '16:00:00');

$outStart = $scheduleType === 'am_only' ? $amOutStart : $pmOutStart;
$outEnd = $scheduleType === 'am_only' ? $amOutEnd : $pmOutEnd;
$inStart = $scheduleType === 'pm_only' ? $pmInStart : $amInStart;
$inEnd = $scheduleType === 'pm_only' ? $pmInEnd : $amInEnd;
$lateThreshold = $scheduleType === 'pm_only' ? $pmLateThreshold : $amLateThreshold;
$validTimes = true;
foreach ([$inStart, $inEnd, $lateThreshold, $outStart, $outEnd] as $time) {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $time)) $validTimes = false;
}
if (!$validTimes || $inStart > $inEnd
    || ($scheduleType === 'pm_only' ? $inStart < '12:00:00' : $inEnd >= '12:00:00')
    || $lateThreshold < $inStart || $lateThreshold > $inEnd
    || $outStart > $outEnd || $inEnd >= $outStart
    || ($scheduleType === 'am_only' ? $outEnd > '12:00:00' : $outStart < '12:00:00')) {
    setFlash('danger', 'Use a Time-In window and a later Time-Out window matching the selected schedule. Check the late threshold and time order.');
    header('Location: sections.php');
    exit;
}

$db = getDB();

if ($id > 0) {
    // ── Update ──────────────────────────────────────────────
    $stmt = $db->prepare("
        UPDATE sections SET
            section_name      = ?,
            grade_level       = ?,
            schedule_type     = ?,
            adviser_id        = ?,
            school_year       = ?,
            am_in_start       = ?,
            am_in_end         = ?,
            am_late_threshold = ?,
            am_out_start      = ?,
            am_out_end        = ?,
            pm_in_start       = ?,
            pm_in_end         = ?,
            pm_late_threshold = ?,
            pm_out_start      = ?,
            pm_out_end        = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $sectionName, $gradeLevel, $scheduleType, $adviserId, $schoolYear,
        $amInStart, $amInEnd, $amLateThreshold, $amOutStart, $amOutEnd,
        $pmInStart, $pmInEnd, $pmLateThreshold, $pmOutStart, $pmOutEnd,
        $id
    ]);
    setFlash('success', "Section '{$sectionName}' updated successfully.");

} else {
    // ── Insert ──────────────────────────────────────────────
    $stmt = $db->prepare("
        INSERT INTO sections (
            section_name, grade_level, schedule_type, adviser_id, school_year,
            am_in_start, am_in_end, am_late_threshold, am_out_start, am_out_end,
            pm_in_start, pm_in_end, pm_late_threshold, pm_out_start, pm_out_end,
            is_active
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
    ");
    $stmt->execute([
        $sectionName, $gradeLevel, $scheduleType, $adviserId, $schoolYear,
        $amInStart, $amInEnd, $amLateThreshold, $amOutStart, $amOutEnd,
        $pmInStart, $pmInEnd, $pmLateThreshold, $pmOutStart, $pmOutEnd,
    ]);
    setFlash('success', "Section '{$sectionName}' created successfully.");
}

header('Location: sections.php');
exit;