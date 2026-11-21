<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/student_access.php';
require_once '../includes/adviser_workflows.php';
requireStaff();
$db=getDB();$pageTitle='Learner Change Requests';$error=null;$requests=[];$learner=null;
$studentId=(int)($_GET['student_id']??$_POST['student_id']??0);
$available=panelTableAvailable($db,'learner_change_requests');
try {
    if (!$available) throw new DomainException('The administrator must install the adviser permissions migration before requests can be submitted.');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireCsrfPost();
        if (isset($_POST['request_id'])) {
            reviewLearnerChangeRequest($db,currentUser(),(int)$_POST['request_id'],(string)($_POST['status']??''),(string)($_POST['review_note']??''));
            setFlash('success','Request review saved.');
        } else {
            createLearnerChangeRequest($db,currentUser(),$studentId,(string)($_POST['kind']??''),(string)($_POST['details']??''));
            setFlash('success','Change request submitted for administrator review.');
        }
        header('Location: change_requests.php');exit;
    }
    if ($studentId) {
        [$scope,$params]=studentRecordScope('s');
        $q=$db->prepare("SELECT s.id,s.first_name,s.last_name FROM students s WHERE s.id=? AND s.is_active=1 AND ($scope)");$q->execute(array_merge([$studentId],$params));$learner=$q->fetch();
        if (!$learner) { http_response_code(403);exit('Learner access denied.'); }
    }
    $requests=loadLearnerChangeRequests($db,currentUser());
} catch (DomainException|InvalidArgumentException $e) { http_response_code(400);$error=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503);$error='Unable to load or save requests. Please try again.'; }
include '../includes/header.php';include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="card mb-4"><div class="card-header"><h1 class="h5 mb-0">Learner Change Requests</h1></div><div class="card-body">
<p>Request corrections to learner details, enrollment or attendance. Administrators verify and apply changes using the relevant form before marking a request resolved.</p>
<?php if($error): ?><div class="alert alert-warning"><?= sanitize($error) ?></div><?php endif; ?>
<?php if($learner): ?>
<h2 class="h6"><?= sanitize($learner['last_name'].', '.$learner['first_name']) ?></h2>
<form method="post"><?= csrfField() ?><input type="hidden" name="student_id" value="<?= $studentId ?>">
<label class="form-label" for="requestKind">Category</label><select id="requestKind" name="kind" class="form-select mb-2"><option value="profile">Learner / guardian details</option><option value="enrollment">Enrollment / placement</option><option value="attendance">Attendance correction</option></select>
<label class="form-label" for="requestDetails">Proposed change and reason</label><textarea id="requestDetails" name="details" class="form-control mb-2" maxlength="4000" required></textarea>
<p class="small text-muted">For attendance corrections, include the learning date and intended status. Include only details needed to verify the change.</p><button class="btn btn-primary">Submit Request</button></form>
<?php elseif(!isAdmin()): ?><p class="mb-0">Open a learner profile and select <strong>Request Change</strong> to submit a request.</p><?php endif; ?>
</div></div>
<div class="card"><div class="card-header">Recent requests (up to 200)</div><div class="card-body">
<?php foreach($requests as $r): ?>
<article class="border-bottom pb-3 mb-3"><h2 class="h6"><?= sanitize($r['last_name'].', '.$r['first_name'].' — '.$r['section_name']) ?> <span class="badge bg-secondary"><?= sanitize(ucfirst($r['status'])) ?></span></h2>
<p class="small text-muted"><?= sanitize(ucfirst($r['kind']).' · '.$r['created_at'].' · Staff #'.$r['requested_by']) ?></p><p style="white-space:pre-wrap"><?= sanitize($r['details']) ?></p>
<?php if($r['review_note']): ?><p style="white-space:pre-wrap"><strong>Administrator review:</strong> <?= sanitize($r['review_note']) ?></p><?php endif; ?>
<?php if(isAdmin() && $r['status']==='pending'): ?>
<div class="d-flex flex-wrap gap-2 mb-2"><a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int)$r['student_id'] ?>">Edit Profile</a><a class="btn btn-sm btn-outline-primary" href="enrollment_corrections.php?student_id=<?= (int)$r['student_id'] ?>">Enrollment Corrections</a><a class="btn btn-sm btn-outline-primary" href="../attendance/manual.php?section=<?= (int)$r['section_id'] ?>">Manual Attendance</a></div>
<form method="post"><?= csrfField() ?><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><label class="form-label" for="review-<?= (int)$r['id'] ?>">Review note</label><textarea id="review-<?= (int)$r['id'] ?>" name="review_note" class="form-control mb-2" maxlength="4000" required></textarea><button name="status" value="resolved" class="btn btn-sm btn-success">Mark Resolved</button> <button name="status" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button></form>
<?php endif; ?></article>
<?php endforeach; ?>
<?php if(!$requests): ?><p class="text-muted mb-0">No accessible requests.</p><?php endif; ?>
</div></div>
<?php include '../includes/footer.php'; ?>
