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

</body>
</html>