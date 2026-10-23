    </div><!-- /.app-content -->

    <footer class="app-footer">
      <small>
        &copy; <?= date('Y') ?> <?= e(setting('site_name', APP_NAME)) ?>.
      </small>
    </footer>
  </main><!-- /.app-main -->
</div><!-- /.app -->

<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/chartjs/chart.umd.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/script.js')) ?>?v=<?= filemtime(__DIR__ . '/../../assets/js/script.js') ?>"></script>
<?php if (function_exists('app_finish_response')) app_finish_response(); ?>
</body>
</html>
