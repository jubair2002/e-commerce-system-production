    </div><!-- /.app-content -->

    <footer class="app-footer">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <small class="text-muted">
          &copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?>. All rights reserved.
        </small>
        <div class="d-flex align-items-center gap-3 small text-muted">
          <a href="<?= e(admin_url('monitoring.php')) ?>" class="footer-link">System Health</a>
          <span>&middot;</span>
          <a href="<?= e(url('')) ?>" target="_blank" class="footer-link">Storefront</a>
        </div>
      </div>
    </footer>
  </main><!-- /.app-main -->
</div><!-- /.app -->

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/script.js')) ?>?v=<?= filemtime(__DIR__ . '/../../assets/js/script.js') ?>"></script>
<?php if (function_exists('app_finish_response')) app_finish_response(); ?>
</body>
</html>
