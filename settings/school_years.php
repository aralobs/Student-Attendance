<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/school_years.php';
requireAdmin();
$db = getDB(); $pageTitle = 'School Years';
$years = $db->query('SELECT * FROM school_years ORDER BY starts_on DESC')->fetchAll();
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="page-header"><div><h1 class="page-title">School Years</h1><p class="page-subtitle">Verify the school calendar dates before saving. Creating a year leaves it planned.</p></div><a href="settings.php" class="btn btn-outline-secondary">Settings</a></div>
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
<?php else: ?><p class="mb-0"><?= sanitize($year['starts_on']) ?> through <?= sanitize($year['ends_on']) ?></p><?php endif; ?>
</div></div><?php endforeach; ?>
<?php include '../includes/footer.php'; ?>
