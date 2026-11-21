<?php
/** Search control for lists paginated by SQL; retains the current filters. */
function renderListSearch(string $search, string $placeholder): void {
    ?>
    <form method="get" class="card mb-3"><div class="card-body">
        <?php foreach ($_GET as $key => $value): if (in_array($key, ['page','search'], true) || !is_scalar($value)) continue; ?>
        <input type="hidden" name="<?= sanitize($key) ?>" value="<?= sanitize((string)$value) ?>">
        <?php endforeach; ?>
        <label for="recordSearch" class="form-label small">Search list</label>
        <div class="d-flex gap-2"><input id="recordSearch" type="search" name="search" class="form-control" value="<?= sanitize($search) ?>" placeholder="<?= sanitize($placeholder) ?>"><button class="btn btn-primary">Search</button></div>
    </div></form>
    <?php
}
