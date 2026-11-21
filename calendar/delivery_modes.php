<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/delivery_modes.php';
requireStaff(); guardCsrfPage();
$db=getDB(); $pageTitle='Class Delivery Schedule'; $preview=null; $error=null; $year=null; $sections=[]; $rows=[];
try {
    requirePanelFeatures($db); $year=getActiveSchoolYear($db);
    $sections=getAllowedSections();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $command=['year_id'=>(int)$year['id'],'section_id'=>(int)($_POST['section_id']??0),'from'=>$_POST['from']??'',
            'to'=>$_POST['to']??'','mode'=>$_POST['mode']??'','deadline_at'=>!empty($_POST['deadline_at'])?str_replace('T',' ',$_POST['deadline_at']).':00':null,
            'confirm_token'=>$_POST['confirm_token']??''];
        $preview=saveDeliveryModes($db,currentUser(),$command);
        if ($preview['saved']) { setFlash('success','Delivery schedule saved.'); header('Location: delivery_modes.php'); exit; }
    }
    [$scope,$bindings]=enrollmentSectionScope('sec',currentUser(),true);
    $q=$db->prepare("SELECT d.*,sec.section_name FROM delivery_modes d LEFT JOIN sections sec ON sec.id=d.scope_section_id WHERE d.school_year_id=? AND (d.scope_section_id=0 OR ($scope)) ORDER BY learning_date DESC");
    $q->execute(array_merge([(int)$year['id']],$bindings)); $rows=$q->fetchAll();
} catch (DomainException|InvalidArgumentException $e) { $error=$e->getMessage(); }
catch (Throwable $e) { $error='Unable to load or save the delivery schedule. Please try again.'; }
include '../includes/header.php'; include '../includes/sidebar.php';
$deletableSectionIds=array_map('intval',array_column($sections,'id'));
?>
<?php showFlash(); ?>
<div class="page-header analytics-template"><div><h1 class="page-title">Class Delivery Schedule</h1><p>School-wide modes and section overrides. Holidays remain no-class days.</p></div><a href="calendar.php" class="btn btn-outline-secondary">School Calendar</a></div>
<?php if ($error): ?><div class="alert alert-warning"><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($year && $sections): ?><div class="mb-4"><a class="btn btn-primary" href="calendar.php?entry=delivery">Add / change delivery schedule</a></div><?php endif; ?>
<div class="card"><div class="card-body"><div class="table-responsive"><table data-list-search="Search date, section or delivery mode..." data-list-title="Scheduled Dates" class="table">
<thead><tr><th>Learning date</th><th>Scope</th><th>Mode</th><th>Modular deadline</th><th>Actions</th></tr></thead>
<tbody><?php foreach($rows as $r): ?><tr>
<td><?= sanitize($r['learning_date']) ?></td><td><?= sanitize($r['section_name']??'Whole school') ?></td>
<td><?= deliveryLabels()[$r['mode']] ?></td><td><?= sanitize($r['deadline_at']??'—') ?></td>
<td><?php if(isAdmin() || in_array((int)$r['scope_section_id'],$deletableSectionIds,true)): ?>
<button type="button" class="btn btn-sm btn-outline-danger" aria-label="Delete delivery schedule" title="Delete delivery schedule" data-delivery-id="<?= (int)$r['id'] ?>" onclick="deleteDeliverySchedule(this)"><i class="bi bi-trash"></i></button>
<?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div></div></div>
<?php
$extraJS='<script src="'.BASE_URL.'assets/js/delivery_delete.js"></script>';
include '../includes/footer.php';
?>
