      </div><!-- /.container-fluid -->
    </div><!-- /.app-content -->

    <!-- AdminLTE v4 App Footer -->
    <footer class="app-footer border-top bg-body py-2 px-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 small">
        <div>
          <strong>Copyright &copy; <?= date('Y') ?> <a href="<?= e(url('')) ?>" target="_blank" class="text-decoration-none"><?= e(setting('site_name', APP_NAME)) ?></a>.</strong>
          All rights reserved.
        </div>
        <div class="d-flex align-items-center gap-3 text-muted">
          <a href="<?= e(admin_url('monitoring.php')) ?>" class="text-decoration-none text-muted">System Health</a>
          <span>&middot;</span>
          <span class="d-none d-sm-inline"><b>AdminLTE</b> 4.0</span>
        </div>
      </div>
    </footer>
  </main><!-- /.app-main -->
</div><!-- /.app-wrapper -->

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/script.js')) ?>?v=<?= filemtime(__DIR__ . '/../../assets/js/script.js') ?>"></script>
<?php if (function_exists('app_finish_response')) app_finish_response(); ?>
</body>
</html>
