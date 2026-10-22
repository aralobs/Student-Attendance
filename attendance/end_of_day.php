<?php
require_once __DIR__ . '/../includes/end_of_day_alerts.php';
requireLogin();
if (!isTeacher()) { http_response_code(403); exit('Assigned teachers only.'); }
$pageTitle = 'End of Day Review';
$date = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
$parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) { http_response_code(400); exit('Invalid review date.'); }
$_SESSION['end_of_day_csrf'] ??= bin2hex(random_bytes(32));
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['end_of_day_csrf'], $_POST['csrf'])) { http_response_code(403); exit('Invalid form token.'); }
    try {
        $entries = $_POST['entries'] ?? [];
        if (!is_array($entries)) throw new InvalidArgumentException('Invalid review entries.');
        $result = submitEndOfDayReview($date, $entries, currentUser());
        setFlash($result['failed'] ? 'warning' : 'success', $result['saved'] . ' remarks saved; ' . $result['sent'] . ' parent SMS alerts sent.'
            . ($result['pending'] ? ' ' . $result['pending'] . ' parent SMS alerts pending with the provider; delivery is not yet confirmed. No automatic retry.' : '')
            . ($result['failed'] ? ' Alerts failed or have unconfirmed delivery for: ' . implode(', ', $result['failed_students']) . '. Contact these parents directly; ask an administrator to check SMS Logs. Alerts are not automatically retried.' : ''));
        header('Location: end_of_day.php?date=' . urlencode($date)); exit;
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { error_log('End-of-day submit: ' . $e->getMessage()); $error = 'The review could not be completed. Reload the list and ask an administrator to check SMS Logs before submitting again.'; }
}
try { $rows = endOfDayRows($date, currentUser()); }
catch (Throwable $e) { error_log('End-of-day review: ' . $e->getMessage()); $rows = []; $error = 'End-of-day review is unavailable. Ask your administrator to run the end-of-day migration.'; }
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="page-header"><div><h1 class="page-title">End of Day Review</h1><p class="page-subtitle">Students needing attention after their section's Time-Out window closes.</p></div></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<form method="get" class="d-flex gap-2 mb-3"><label for="reviewDate" class="form-label">Date</label><input id="reviewDate" type="date" name="date" value="<?= sanitize($date) ?>" max="<?= date('Y-m-d') ?>" class="form-control" style="max-width:200px"><button class="btn btn-outline-primary">View</button></form>
<div class="alert alert-info">Existing remarks exclude a student from this list. Add an explanation such as “Excused” or “Sent Home Sick”. For missing Time-Out scans, select the checkbox to send a parent SMS when you submit. Missing scans alone do not confirm a child's whereabouts.</div>
<?php if (!$rows && !$error): ?><div class="card"><div class="card-body">No students need review for closed Time-Out windows on this date.</div></div><?php endif; ?>
<?php if ($rows): ?>
<form method="post" id="endOfDayReviewForm">
<input type="hidden" name="date" value="<?= sanitize($date) ?>"><input type="hidden" name="csrf" value="<?= sanitize($_SESSION['end_of_day_csrf']) ?>">
<div class="card"><div class="card-header"><?= count($rows) ?> students need attention</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Student / parent alert</th><th>Section</th><th>Needs attention</th><th>Teacher remark</th></tr></thead><tbody>
<?php foreach ($rows as $row): $id = (int)$row['student_id']; ?>
<tr><td>
<?php if ($row['reason'] === 'incomplete' && !$row['notification_status'] && !empty($row['parent_contact'])): ?>
<div class="form-check"><input type="checkbox" class="form-check-input" id="notify<?= $id ?>" name="entries[<?= $id ?>][notify]" value="1"><label class="form-check-label" for="notify<?= $id ?>"><?= sanitize($row['first_name'] . ' ' . $row['last_name']) ?> <span class="d-block small text-muted">Send missing Time-Out SMS to parent</span></label></div>
<?php else: ?>
<?= sanitize($row['first_name'] . ' ' . $row['last_name']) ?>
<?php if ($row['reason'] === 'incomplete'): ?><div class="small text-muted"><?= $row['notification_status'] ? 'Parent alert: ' . sanitize($row['notification_status']) . ' (no automatic retry)' : 'No parent phone number on file' ?></div><?php endif; ?>
<?php endif; ?>
</td><td><?= sanitize($row['grade_level'] . ' — ' . $row['section_name']) ?></td><td><span class="badge <?= $row['reason'] === 'incomplete' ? 'bg-warning text-dark' : 'bg-danger' ?>"><?= $row['reason'] === 'incomplete' ? 'Missing Time-Out' : 'Absent — no scans' ?></span></td><td><label class="visually-hidden" for="remark<?= $id ?>">Remark for <?= sanitize($row['first_name'] . ' ' . $row['last_name']) ?></label><textarea class="form-control" id="remark<?= $id ?>" name="entries[<?= $id ?>][remarks]" rows="2" maxlength="1000" placeholder="Add a manual remark"></textarea></td></tr>
<?php endforeach; ?>
</tbody></table></div><div class="card-footer"><button class="btn btn-primary">Save remarks and send selected alerts</button></div></div></form>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
