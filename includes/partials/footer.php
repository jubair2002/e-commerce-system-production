</main><!-- /#mainContent -->

<?php
/**
 * Global Storefront Footer (Cozastore Skin).
 */
include __DIR__ . '/../layout/coza/footer.php';
include __DIR__ . '/../layout/coza/scripts.php';

if (function_exists('app_finish_response')) {
    app_finish_response();
}
?>
