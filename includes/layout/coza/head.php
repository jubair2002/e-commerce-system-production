<?php
require_once __DIR__ . '/../../shop_bootstrap.php';

$pageTitle = $pageTitle ?? '';
$siteName  = setting('site_name', APP_NAME);
$fullTitle = $pageTitle !== '' ? "$pageTitle · $siteName" : $siteName;

// SEO Metadata
$metaDescContent = !empty($pageDescription) ? $pageDescription : setting('seo_default_description', setting('site_about', 'Quality essentials delivered to your door.'));
$metaKeywordsContent = !empty($pageKeywords) ? $pageKeywords : setting('seo_default_keywords', '');

// Store Favicon
$storeFavicon = setting('store_favicon');
$favUrl = store_favicon_url($storeFavicon ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') ?></title>
	<meta name="description" content="<?= htmlspecialchars($metaDescContent, ENT_QUOTES, 'UTF-8') ?>">
	<?php if (!empty($metaKeywordsContent)): ?>
	<meta name="keywords" content="<?= htmlspecialchars($metaKeywordsContent, ENT_QUOTES, 'UTF-8') ?>">
	<?php endif; ?>

	<?php if ($favUrl): ?>
	<link rel="icon" type="image/png" href="<?= htmlspecialchars($favUrl, ENT_QUOTES, 'UTF-8') ?>">
	<?php else: ?>
	<link rel="icon" type="image/png" href="<?= htmlspecialchars(url('assets/coza/images/icons/favicon.png'), ENT_QUOTES, 'UTF-8') ?>">
	<?php endif; ?>

<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/bootstrap/css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/fonts/font-awesome-4.7.0/css/font-awesome.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/fonts/iconic/css/material-design-iconic-font.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/fonts/linearicons-v1.0.0/icon-font.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/animate/animate.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/css-hamburgers/hamburgers.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/animsition/css/animsition.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/select2/select2.min.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/daterangepicker/daterangepicker.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/slick/slick.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/MagnificPopup/magnific-popup.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/vendor/perfect-scrollbar/perfect-scrollbar.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/util.css'), ENT_QUOTES, 'UTF-8') ?>">
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/main.css'), ENT_QUOTES, 'UTF-8') ?>">
<!--===============================================================================================-->

	<script>
		window.SHOP_BASE_URL = "<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>";
		window.SHOP_CHECKOUT_URL = "<?= htmlspecialchars(route('checkout'), ENT_QUOTES, 'UTF-8') ?>";
		window.SHOP_CSRF_TOKEN = "<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>";
		window.CUSTOMER_LOGGED_IN = <?= (function_exists('customer_current') && customer_current() !== null) ? 'true' : 'false' ?>;
	</script>
	<style>
		/* Progressive enhancement: Instant paint, prevent white flash */
		body.animsition, .animsition {
			opacity: 1 !important;
			-webkit-animation: none !important;
			animation: none !important;
		}
		.animsition-loading-1 {
			display: none !important;
		}
	</style>
</head>
<body class="animsition">
