<?php
/**
 * 500 Server Error Page.
 */
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/config.php';
}
http_response_code(500);
$siteName = function_exists('setting') ? setting('site_name', APP_NAME) : APP_NAME;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>500 — Server Error · <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8f9fa; color: #111; }
    .error-card { max-width: 500px; margin: 10vh auto; background: #fff; padding: 2.5rem; border-radius: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,0.06); text-align: center; }
  </style>
</head>
<body>
  <div class="container">
    <div class="error-card">
      <div class="fs-1 text-danger mb-3"><i class="bi bi-exclamation-octagon"></i></div>
      <h1 class="h3 fw-bold mb-2">Something went wrong</h1>
      <p class="text-muted small mb-4">
        Our servers encountered an unexpected issue while processing your request. Please try refreshing the page or return to our homepage.
      </p>
      <div class="d-flex justify-content-center gap-2">
        <a href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-dark btn-sm px-4">
          Return to Storefront
        </a>
      </div>
    </div>
  </div>
</body>
</html>
