<?php
$monthEntryDates=array_unique(array_merge(array_keys($calendarEntries),array_keys($savedDeliveryEntries)));
sort($monthEntryDates);
$monthEntryCount=count($calendarEntries)+array_sum(array_map('count',$savedDeliveryEntries));
?>
<div class="card">
    <div class="card-header fw-700 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2 text-primary"></i><?= date('F',mktime(0,0,0,$month,1,$year)) ?> Entries</span>
        <span class="badge bg-secondary"><?= $monthEntryCount ?></span>
    </div>
    <div class="card-body p-0" style="max-height:420px;overflow-y:auto">
        <?php if (!$monthEntryCount): ?>
        <div class="text-center text-muted py-3 small"><?= $deliveryLoadError ? 'Delivery entries could not be loaded. Please reload.' : 'No entries this month' ?></div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($monthEntryDates as $entryDate): ?>
            <?php if (isset($calendarEntries[$entryDate])): $entry=$calendarEntries[$entryDate]; ?>
            <li class="list-group-item py-2 px-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-600 small"><?= sanitize($entry['title']) ?></div>
                    <div class="text-muted" style="font-size:0.72rem"><?= date('l, j',strtotime($entryDate)) ?></div>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-<?= entryColor($entry['type']) ?>"><?= ucfirst(str_replace('_',' ',$entry['type'])) ?></span>
                    <?php if (isAdmin()): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1" aria-label="Delete school event"
                            data-entry-date="<?= sanitize($entryDate) ?>" data-entry-title="<?= sanitize($entry['title']) ?>"
                            onclick="deleteEntry(this.dataset.entryDate, this.dataset.entryTitle)" style="font-size:0.7rem"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                </div>
            </li>
            <?php endif; ?>
            <?php foreach ($savedDeliveryEntries[$entryDate] ?? [] as $delivery): ?>
            <li class="list-group-item py-2 px-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-600 small"><?= sanitize(deliveryLabels()[$delivery['mode']]) ?></div>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge bg-primary">Delivery</span>
                        <?php if ($delivery['year_state']==='active' && (isAdmin() || ((int)$delivery['scope_section_id'] && (int)$delivery['section_active']))): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger p-0 px-1" aria-label="Delete delivery schedule" title="Delete delivery schedule"
                                data-delivery-id="<?= (int)$delivery['id'] ?>" onclick="deleteDeliverySchedule(this)" style="font-size:0.7rem"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="small"><?= sanitize($delivery['section_name'] ?? 'Whole school') ?><?= (int)$delivery['scope_section_id'] ? ' (section override)' : '' ?></div>
                <div class="text-muted" style="font-size:0.72rem"><?= date('l, j',strtotime($entryDate)) ?></div>
                <?php if ($delivery['deadline_at']): ?>
                <div class="small">Deadline: <?= sanitize($delivery['deadline_at']) ?> (Manila time)</div>
                <?php endif; ?>
                <?php if (!$delivery['eligible']): ?>
                <div class="small text-warning-emphasis">Not applied: <?= sanitize(match($delivery['calendar_type']) {'holiday'=>'Holiday','no_class'=>'No class',default=>'Non-school day'}) ?></div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
