<?php
require_once '../config/database.php';require_once '../includes/functions.php';require_once '../includes/database_backups.php';
requireAdmin();$db=getDB();$pageTitle='Database Backups';$rows=[];$error=null;$lastSuccess=null;
try{$rows=$db->query('SELECT * FROM database_backups ORDER BY created_at DESC')->fetchAll();$lastSuccess=$db->query("SELECT MAX(completed_at) FROM database_backups WHERE status='completed'")->fetchColumn();backupRoot();}
catch(Throwable $e){$error='Backups are not configured or the worker needs attention. Contact your deployment administrator.';}
include '../includes/header.php';include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="page-header analytics-template"><div><h1 class="page-title">Database Backups</h1><p class="page-subtitle">Complete database copies across all school years, grouped by creation date in Manila time.</p></div><form method="post" action="create.php"><?= csrfField() ?><button class="btn btn-primary">Create Backup</button></form></div>
<nav class="nav nav-tabs mb-3" aria-label="Security">
    <a class="nav-link" href="<?= BASE_URL ?>security_audit.php"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Logs</a>
    <a class="nav-link active" href="<?= BASE_URL ?>backups/index.php" aria-current="page"><i class="bi bi-database me-1" aria-hidden="true"></i>Database Backups</a>
</nav>
<?php if($error): ?><div class="alert alert-warning"><?= sanitize($error) ?></div><?php endif; ?>
<div class="alert alert-info">Last successful backup: <strong><?= sanitize($lastSuccess?:'None recorded') ?></strong> (Manila time).</div>
<p>Daily copies: 30 days. Monthly snapshots: 12 months. Photos and encryption keys are preserved separately. Queued manual jobs are processed in the background. Download and keep a protected off-server copy.</p>
<div class="card"><div class="table-responsive"><table data-list-search="Search backup date, kind or status..." data-list-title="Database Backups" class="table"><thead><tr><th>Year / month / date</th><th>Kind</th><th>Status</th><th>Size</th><th>Protection</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= sanitize(str_replace('-',' / ',substr($r['created_at'],0,10))) ?><small class="d-block"><?= sanitize(substr($r['created_at'],11)) ?></small></td><td><?= sanitize($r['kind']) ?></td><td><?= sanitize($r['status']) ?></td><td><?= $r['bytes']!==null?number_format((int)$r['bytes']/1024,1).' KB':'—' ?></td><td><?php if($r['monthly_until']): ?>Monthly until <?= sanitize($r['monthly_until']) ?><br><?php endif; ?><?php if($r['retained_until']): ?>Retained until <?= sanitize($r['retained_until']) ?><?php endif; ?></td><td><?php if($r['status']==='completed'): ?><a class="btn btn-sm btn-outline-primary" href="download.php?id=<?= sanitize($r['id']) ?>">Download</a><details><summary>Checksum / retain</summary><code><?= sanitize($r['sha256']) ?></code><form method="post" action="create.php"><?= csrfField() ?><input type="hidden" name="id" value="<?= sanitize($r['id']) ?>"><label>Retain through date</label><input type="date" name="retain_until" required class="form-control" min="<?= date('Y-m-d') ?>"><button class="btn btn-sm btn-outline-secondary mt-1">Set Retention</button></form></details><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="6">No backup jobs yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php include '../includes/footer.php'; ?>
