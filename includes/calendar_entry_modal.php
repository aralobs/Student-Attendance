<?php
// Included by School Calendar after its scheduling context has been loaded.
$canScheduleDelivery = $calendarDeliveryYear && $calendarDeliverySections && !$deliveryLoadError;
$defaultDeliveryDate = $canScheduleDelivery
    ? max($calendarDeliveryYear['starts_on'], min(date('Y-m-d'), $calendarDeliveryYear['ends_on'])) : date('Y-m-d');
?>
<div class="modal fade" id="calendarModal" tabindex="-1" aria-labelledby="modalTitle" aria-hidden="true"
     data-default-kind="<?= isAdmin() ? 'event' : 'delivery' ?>" data-default-date="<?= sanitize($defaultDeliveryDate) ?>">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><form id="calendarEntryForm" class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title fw-700" id="modalTitle">Add Calendar Entry</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
            <div class="modal-body">
                <input type="hidden" id="entryCsrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label for="entryKind" class="form-label">Entry category</label>
                    <select id="entryKind" class="form-select">
                        <?php if (isAdmin()): ?><option value="event">School event</option><?php endif; ?>
                        <option value="delivery" <?= !$canScheduleDelivery ? 'disabled' : '' ?>>Class delivery</option>
                    </select>
                </div>
                <?php if (isAdmin()): ?>
                <div id="eventFields">
                    <div class="mb-3"><label for="entryDate" class="form-label">Date</label><input type="date" id="entryDate" class="form-control" required></div>
                    <div class="mb-3"><label for="entryTitle" class="form-label">Title</label><input type="text" id="entryTitle" class="form-control" required placeholder="e.g. Christmas Day, No Class - Storm Signal"></div>
                    <div class="mb-3"><label for="entryType" class="form-label">Type</label><select id="entryType" class="form-select">
                        <option value="holiday">Holiday</option><option value="no_class">No Class / School Closure</option>
                        <option value="special_event">Special Event</option><option value="school_day">Regular School Day</option>
                    </select></div>
                    <div class="mb-3"><label for="entryDescription" class="form-label">Description (optional)</label><textarea id="entryDescription" class="form-control" rows="2"></textarea></div>
                </div>
                <?php endif; ?>
                <div id="deliveryFields" hidden>
                    <?php if ($canScheduleDelivery): ?>
                    <p class="small text-muted">School year <?= sanitize($calendarDeliveryYear['label']) ?>. Holidays and no-class dates are skipped. Section overrides take priority.</p>
                    <div class="mb-3"><label for="deliverySection" class="form-label">Scope</label><select id="deliverySection" class="form-select" required>
                        <?php if (isAdmin()): ?><option value="0">Whole school</option><?php endif; ?>
                        <?php foreach ($calendarDeliverySections as $section): ?><option value="<?= (int)$section['id'] ?>"><?= sanitize($section['section_name']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="row g-3 mb-3">
                        <?php foreach (['deliveryFrom'=>'From date','deliveryTo'=>'Through date'] as $id=>$label): ?>
                        <div class="col-sm-6"><label for="<?= $id ?>" class="form-label"><?= $label ?></label><input type="date" id="<?= $id ?>" class="form-control" required min="<?= sanitize($calendarDeliveryYear['starts_on']) ?>" max="<?= sanitize($calendarDeliveryYear['ends_on']) ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3"><label for="deliveryMode" class="form-label">Delivery mode</label><select id="deliveryMode" class="form-select"><?php foreach (deliveryLabels() as $mode=>$label): ?><option value="<?= $mode ?>"><?= $label ?></option><?php endforeach; ?></select></div>
                    <div id="deliveryDeadlineGroup" class="mb-3" hidden><label for="deliveryDeadline" class="form-label">Modular deadline (Manila time)</label><input type="datetime-local" id="deliveryDeadline" class="form-control"><div class="form-text">Participation is credited to the learning date.</div></div>
                    <?php else: ?>
                    <p class="text-muted">Delivery scheduling is unavailable. An active school year and an authorized section are required.</p>
                    <?php endif; ?>
                </div>
                <div id="deliveryPreview" class="alert alert-info" hidden role="status" aria-live="polite"></div>
                <div id="modalMsg" role="status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" id="calendarEntrySubmit" class="btn btn-primary btn-sm">Save Entry</button>
            </div>
    </form></div>
</div>
