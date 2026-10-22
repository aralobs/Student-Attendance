<?php
/**
 * Global footer — closes tags opened in sidebar.php
 */
?>
    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</div><!-- /.wrapper -->

<!-- Shared confirmation and message dialog -->
<div class="modal fade" id="actionModal" tabindex="-1" aria-labelledby="actionModalTitle" aria-describedby="actionModalMessage" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="actionModalTitle">Confirm action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="actionModalMessage"></p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" id="actionModalCancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="actionModalConfirm">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="<?= BASE_URL ?>assets/vendor/bootstrap-5.3.0.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<!-- Chart.js -->
<script src="<?= BASE_URL ?>assets/vendor/chart-4.4.0.umd.min.js" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<!-- Main JS -->
<script src="<?= BASE_URL ?>assets/js/main.js"></script>

<?php if (isset($extraJS)) echo $extraJS; ?>

<?php if (isTeacher()): ?>
<div id="endOfDayAlert" hidden role="status" aria-live="polite" class="alert alert-warning shadow mb-0"
     style="position:fixed;bottom:16px;right:16px;max-width:min(420px,calc(100vw - 32px));z-index:1040"
     data-endpoint="<?= BASE_URL ?>attendance/end_of_day_status.php" data-review="<?= BASE_URL ?>attendance/end_of_day.php">
    <a class="alert-link" href="<?= BASE_URL ?>attendance/end_of_day.php">End of Day Review</a>
</div>
<script src="<?= BASE_URL ?>assets/js/end_of_day_alerts.js"></script>
<?php endif; ?>

<!-- ─── Session heartbeat ─────────────────────────────────────
     Pings the server every 10s. If another browser has logged
     into this account, the server replies 401 and we redirect
     to the login page immediately — no manual refresh needed. -->
<script>
(function () {
    if (!document.body) return;

    const HEARTBEAT_URL = '<?= BASE_URL ?>heartbeat.php';
    const INTERVAL_MS   = 10000;   // 10 seconds
    const LOGIN_URL     = '<?= BASE_URL ?>index.php?reason=session_taken';
    let   stopped       = false;

    async function ping() {
        if (stopped || document.hidden) return;
        try {
            const res = await fetch(HEARTBEAT_URL, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (res.status === 401) {
                stopped = true;
                let data = {};
                try { data = await res.json(); } catch (e) {}
                const redirect = (data && data.redirect) ? data.redirect : LOGIN_URL;
                window.location.replace(redirect);
            }
        } catch (e) {
            // Network hiccup — ignore; we'll retry on the next tick
        }
    }

    // Regular polling
    setInterval(ping, INTERVAL_MS);

    // Ping immediately when the user returns to this tab
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) ping();
    });

    // Ping once shortly after page load so the first kick is quick
    setTimeout(ping, 2000);
})();
</script>

</body>
</html>
