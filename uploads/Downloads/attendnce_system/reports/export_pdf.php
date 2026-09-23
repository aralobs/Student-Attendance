<?php
/**
 * SF2 PDF Export — uses inline HTML converted to PDF via print dialog
 * OR simple HTML output for printing.
 * For a proper PDF library, install TCPDF via composer or download manually.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$month     = (int)($_GET['month']   ?? date('n'));
$year      = (int)($_GET['year']    ?? date('Y'));
$sectionId = (int)($_GET['section'] ?? 0);

$db           = getDB();
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthLabel   = date('F Y', mktime(0,0,0,$month,1,$year));
$schoolName   = getSetting('school_name');
$schoolYear   = getSetting('school_year');

$secStmt = $db->prepare("SELECT * FROM sections WHERE id = ?");
$secStmt->execute([$sectionId]);
$section = $secStmt->fetch();

$students = $db->prepare("SELECT * FROM students WHERE section_id = ? AND is_active = 1 ORDER BY last_name, first_name");
$students->execute([$sectionId]);
$students = $students->fetchAll();

$attMatrix = [];
$stmt = $db->prepare("SELECT student_id, DAY(date) AS day, status FROM attendance WHERE MONTH(date)=? AND YEAR(date)=? AND student_id IN (SELECT id FROM students WHERE section_id=? AND is_active=1)");
$stmt->execute([$month, $year, $sectionId]);
foreach ($stmt->fetchAll() as $r) {
    $attMatrix[$r['student_id']][$r['day']] = $r['status'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SF2 — <?= htmlspecialchars($monthLabel) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; font-size: 9px; padding: 10px; }
        h1, h2, h3 { text-align: center; }
        h1 { font-size: 12px; }
        h2 { font-size: 11px; }
        h3 { font-size: 10px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #333; padding: 2px 3px; text-align: center; }
        th { background: #e0e0e0; font-weight: bold; }
        .name-col { text-align: left; min-width: 100px; }
        .weekend { background: #888; color: #fff; }
        .absent { color: red; font-weight: bold; }
        .late   { color: orange; font-weight: bold; }
        .sig-block { margin-top: 30px; display: flex; justify-content: space-between; }
        .sig-line  { border-top: 1px solid #000; width: 200px; text-align: center; padding-top: 4px; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom:10px;text-align:center">
    <button onclick="window.print()" style="padding:6px 16px;background:#1a56db;color:#fff;border:none;border-radius:4px;cursor:pointer">
        🖨️ Print / Save as PDF
    </button>
    <button onclick="window.close()" style="padding:6px 16px;margin-left:8px;border:1px solid #ccc;border-radius:4px;cursor:pointer">
        ✕ Close
    </button>
</div>

<h1>Republic of the Philippines — Department of Education</h1>
<h2><?= htmlspecialchars($schoolName) ?> — Kindergarten Department</h2>
<h3>
    SCHOOL FORM 2 (SF2) — Daily Attendance Record<br>
    Section: <?= htmlspecialchars($section['section_name'] ?? '') ?> &nbsp;|&nbsp;
    <?= htmlspecialchars($monthLabel) ?> &nbsp;|&nbsp;
    S.Y. <?= htmlspecialchars($schoolYear) ?>
</h3>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th class="name-col">Name (Last, First)</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $isWE = in_array(date('N', mktime(0,0,0,$month,$d,$year)), [6,7]);
            ?>
            <th class="<?= $isWE ? 'weekend' : '' ?>"><?= $d ?></th>
            <?php endfor; ?>
            <th>P</th><th>L</th><th>A</th><th>E</th>
        </tr>
    </thead>
    <tbody>
        <?php $totP=$totL=$totA=$totE=0; ?>
        <?php foreach ($students as $idx => $stu): ?>
        <?php $p=$l=$a=$e=0; ?>
        <tr>
            <td><?= $idx + 1 ?></td>
            <td class="name-col">
                <?= htmlspecialchars($stu['last_name'] . ', ' . $stu['first_name']) ?>
            </td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $isWE = in_array(date('N', mktime(0,0,0,$month,$d,$year)), [6,7]);
                $stat = $attMatrix[$stu['id']][$d] ?? null;
                $cls  = $isWE ? 'weekend' : ($stat === 'absent' ? 'absent' : ($stat === 'late' ? 'late' : ''));
                $val  = $isWE ? '/' : match($stat) {
                    'present' => 'P', 'late' => 'L', 'absent' => 'A', 'excused' => 'E', default => ''
                };
                if (!$isWE) {
                    match($stat) { 'present'=>$p++, 'late'=>$l++, 'absent'=>$a++, 'excused'=>$e++, default=>null };
                }
            ?>
            <td class="<?= $cls ?>"><?= $val ?></td>
            <?php endfor; ?>
            <td><?= $p ?></td><td><?= $l ?></td><td><?= $a ?></td><td><?= $e ?></td>
            <?php $totP+=$p;$totL+=$l;$totA+=$a;$totE+=$e; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr style="background:#e0e0e0;font-weight:bold">
            <td colspan="2">TOTAL</td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?><td></td><?php endfor; ?>
            <td><?= $totP ?></td><td><?= $totL ?></td><td><?= $totA ?></td><td><?= $totE ?></td>
        </tr>
    </tfoot>
</table>

<div class="sig-block">
    <div>
        <div class="sig-line">
            <strong>Class Adviser</strong><br>
            <small><?= htmlspecialchars($section['section_name'] ?? '') ?></small>
        </div>
    </div>
    <div>
        <div class="sig-line">
            <strong>School Principal</strong><br>
            <small><?= htmlspecialchars($schoolName) ?></small>
        </div>
    </div>
</div>

</body>
</html>