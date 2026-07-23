<?php
/**
 * OURCR ONLINE - Admin Panel Footer
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
?>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/admin.js?v=<?= APP_VERSION ?>"></script>

    <?php renderSweetAlerts(); ?>

    <?php if (!empty($adminPageScripts)): ?>
        <script><?= $adminPageScripts ?></script>
    <?php endif; ?>
</body>
</html>
