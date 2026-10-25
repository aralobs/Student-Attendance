<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/school_years.php';
requireAdmin();
$db = getDB(); $pageTitle = 'School Years';
$activation=null; $activationError=null; $managed=enrollmentModelEnabled($db);
if ($managed && isset($_GET['activate'])) {
    try {
        $target=filter_var($_GET['activate'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($target===false) throw new DomainException('Choose a valid planned school year.');
        $activation=schoolYearActivationPreview($db,currentUser(),$target);
    } catch (DomainException|InvalidArgumentException $error) { $activationError=$error->getMessage(); }
    catch (Throwable $error) { $activationError='Unable to check readiness. Try again.'; }
}
$years = $db->query('SELECT * FROM school_years ORDER BY starts_on DESC')->fetchAll();
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="page-header"><div><h1 class="page-title">School Years</h1><p class="page-subtitle">Verify the school calendar dates before saving. Creating a year leaves it planned.</p></div><a href="settings.php" class="btn btn-outline-secondary">Settings</a></div>
<?php if ($activationError): ?><div class="alert alert-danger"><?= sanitize($activationError) ?></div><?php endif; ?>
<?php if ($activation): ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5">Activation readiness: <?= sanitize($activation['target_year']['label']) ?></h2>
<p>Verified dates: <?= sanitize($activation['target_year']['starts_on']) ?> through <?= sanitize($activation['target_year']['ends_on']) ?>.</p>
<p>Activating this year will close <?= sanitize($activation['source_year']['label']) ?> (<?= sanitize($activation['source_year']['starts_on']) ?> through <?= sanitize($activation['source_year']['ends_on']) ?>). Attendance remains available in its original year.</p>
<dl class="row">
<?php foreach(['outgoing'=>'Outgoing enrollments','prepared'=>'Prepared destinations','promoted'=>'Promoted','retained'=>'Retained','graduated'=>'Graduated','cancelled'=>'Cancelled target enrollments','unassigned'=>'Unassigned students — follow-up required'] as $key=>$label): ?>
<dt class="col-sm-8"><?= sanitize($label) ?></dt><dd class="col-sm-4"><?= (int)$activation['counts'][$key] ?></dd>
<?php endforeach; ?></dl>
<p>Only prepared destinations activate. Unassigned students retain their history and need an explicit enrollment or outcome decision. Future attendance starts on the verified enrollment dates. No parent notifications are sent.</p>
<?php if ($activation['blocking_issues']): ?><div class="alert alert-danger"><strong>Resolve these mappings before activation</strong><ul>
<?php foreach($activation['blocking_issues'] as $issue): ?><li><?= sanitize($issue['record_type']) ?> #<?= (int)$issue['record_id'] ?>: <?= sanitize(str_replace('_',' ',$issue['code'])) ?></li><?php endforeach; ?>
</ul></div><?php endif; ?>
<?php if ($activation['follow_up']): ?><details><summary>Unassigned students (<?= count($activation['follow_up']) ?>)</summary><ul><?php foreach($activation['follow_up'] as $item): ?><li>Student #<?= (int)$item['student_id'] ?>, enrollment #<?= (int)$item['enrollment_id'] ?>: no prepared destination or outcome.</li><?php endforeach; ?></ul></details><?php endif; ?>
<?php if ($activation['historical_issues']): ?><details><summary>Historical mapping notices (<?= count($activation['historical_issues']) ?>)</summary><ul><?php foreach($activation['historical_issues'] as $issue): ?><li><?= sanitize($issue['record_type']) ?> #<?= (int)$issue['record_id'] ?>: <?= sanitize(str_replace('_',' ',$issue['code'])) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php if ($activation['ready']): ?><form method="post" action="activate_school_year.php" class="mt-3"><?= csrfField() ?><input type="hidden" name="target_year_id" value="<?= (int)$activation['target_year']['id'] ?>"><input type="hidden" name="expected_fingerprint" value="<?= sanitize($activation['fingerprint']) ?>"><button class="btn btn-warning">Confirm activation of <?= sanitize($activation['target_year']['label']) ?></button></form><?php endif; ?>
</div></div><?php endif; ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5">Create planned school year</h2>
<form method="post" action="save_school_year.php" class="row g-3"><?= csrfField() ?>
<div class="col-md-4"><label class="form-label">Label (YYYY-YYYY)</label><input name="label" pattern="[0-9]{4}-[0-9]{4}" maxlength="20" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Verified start date</label><input name="starts_on" type="date" class="form-control" required></div>
<div class="col-md-3"><label class="form-label">Verified end date</label><input name="ends_on" type="date" class="form-control" required></div><div class="col-md-2 align-self-end"><button class="btn btn-primary">Create year</button></div></form></div></div>
<?php foreach ($years as $year): ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5"><?= sanitize($year['label']) ?> <span class="badge bg-secondary"><?= sanitize($year['state']) ?></span></h2>
<?php if ($year['state'] === 'planned'): ?><form method="post" action="save_school_year.php" class="row g-3"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$year['id'] ?>"><input type="hidden" name="expected_version" value="<?= (int)$year['version'] ?>">
<div class="col-md-4"><label class="form-label">Label</label><input name="label" class="form-control" required value="<?= sanitize($year['label']) ?>"></div>
<div class="col-md-3"><label class="form-label">Verified start date</label><input name="starts_on" type="date" class="form-control" required value="<?= sanitize($year['starts_on']) ?>"></div>
<div class="col-md-3"><label class="form-label">Verified end date</label><input name="ends_on" type="date" class="form-control" required value="<?= sanitize($year['ends_on']) ?>"></div><div class="col-md-2 align-self-end"><button class="btn btn-outline-primary">Save planned year</button></div></form>
<?php if ($managed): ?><a href="school_years.php?activate=<?= (int)$year['id'] ?>" class="btn btn-outline-warning mt-3">Review activation readiness</a><?php endif; ?>
<?php else: ?><p class="mb-0"><?= sanitize($year['starts_on']) ?> through <?= sanitize($year['ends_on']) ?></p><?php endif; ?>
</div></div><?php endforeach; ?>
<?php include '../includes/footer.php'; ?>
