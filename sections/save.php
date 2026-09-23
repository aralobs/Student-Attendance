<?php
/**
 * Save Section — Add or Edit
 * Times come from the form (manual entry, pre-filled with grade defaults).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: sections.php');
    exit;
}

$id           = (int)($_POST['id']            ?? 0);
$sectionName  = trim($_POST['section_name']   ?? '');
$gradeLevel   = trim($_POST['grade_level']    ?? '');
$scheduleType = $_POST['schedule_type']       ?? 'full_day';
$adviserId    = !empty($_POST['adviser_id']) ? (int)$_POST['adviser_id'] : null;
$schoolYear   = trim($_POST['school_year']    ?? '2026-2027');

$allowedGrades    = getGradeLevels();
$allowedSchedules = ['full_day', 'am_only', 'pm_only'];

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

$amInStart       = timeOrDefault('am_in_start',       '06:00:00');
$amInEnd         = timeOrDefault('am_in_end',         '08:00:00');
$amLateThreshold = timeOrDefault('am_late_threshold', '07:31:00');
$amOutStart      = timeOrDefault('am_out_start',      '11:00:00');
$amOutEnd        = timeOrDefault('am_out_end',        '12:00:00');
$pmInStart       = timeOrDefault('pm_in_start',       '12:00:00');
$pmInEnd         = timeOrDefault('pm_in_end',         '13:30:00');
$pmLateThreshold = timeOrDefault('pm_late_threshold', '12:31:00');
$pmOutStart      = timeOrDefault('pm_out_start',      '17:00:00');
$pmOutEnd        = timeOrDefault('pm_out_end',        '18:00:00');

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