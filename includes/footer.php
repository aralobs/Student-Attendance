<?php
/**
 * Global footer — closes tags opened in sidebar.php
 */
?>
    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</div><!-- /.wrapper -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Main JS -->
<script src="<?= BASE_URL ?>assets/js/main.js"></script>

<?php if (isset($extraJS)) echo $extraJS; ?>

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