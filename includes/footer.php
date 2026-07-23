<?php
/**
 * OURCR ONLINE - User Dashboard Footer
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
?>


    <!-- Scripts -->
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

    <!-- App JS -->
    <script src="<?= APP_URL ?>/assets/js/app.js?v=<?= APP_VERSION ?>"></script>

    <?php renderSweetAlerts(); ?>

    <!-- Inline page scripts -->
    <?php if (!empty($pageScripts)): ?>
        <script><?= $pageScripts ?></script>
    <?php endif; ?>

</body>
</html>
