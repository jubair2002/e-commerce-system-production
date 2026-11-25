<?php
require_once __DIR__ . '/../../shop_bootstrap.php';

$pageTitle = $pageTitle ?? '';
$siteName  = setting('site_name', APP_NAME);
$seoDefaultTitle = setting('seo_default_title', setting('meta_title', ''));
if ($pageTitle === '' || $pageTitle === 'Home') {
    $fullTitle = $seoDefaultTitle ?: ($pageTitle !== '' ? "$pageTitle · $siteName" : $siteName);
} else {
    $fullTitle = "$pageTitle · $siteName";
}

// SEO Metadata
$metaDescContent = !empty($pageDescription) ? $pageDescription : setting('seo_default_description', setting('meta_description', setting('site_about', 'Shop unique Chinese imported products in Bangladesh at Jinishpotro.store.')));
$metaKeywordsContent = !empty($pageKeywords) ? $pageKeywords : setting('seo_default_keywords', setting('meta_keywords', ''));
$robotsAllow = (int)setting('robots_allow', setting('allow_crawlers', '1')) === 1;

// Store Favicon
$storeFavicon = setting('store_favicon');
$favUrl = store_favicon_url($storeFavicon ?? null);

$themeAccent = setting('theme_accent_color', setting('theme_accent', '#717fe0'));
$darkModeEnabled = (int)setting('dark_mode_enabled', '1') === 1;
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
	<meta name="robots" content="<?= $robotsAllow ? 'index, follow' : 'noindex, nofollow' ?>">

	<!-- Theme Flash Prevention -->
	<script>
		(function() {
			try {
				<?php if ($darkModeEnabled): ?>
				var t = localStorage.getItem('jinispotro_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
				document.documentElement.setAttribute('data-theme', t);
				<?php else: ?>
				document.documentElement.setAttribute('data-theme', 'light');
				<?php endif; ?>
			} catch (e) {}
		})();
	</script>

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
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/main.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= file_exists(__DIR__ . '/../../../assets/coza/css/main.css') ? filemtime(__DIR__ . '/../../../assets/coza/css/main.css') : time() ?>">
	<link rel="stylesheet" type="text/css" href="<?= htmlspecialchars(url('assets/coza/css/coza-custom.css'), ENT_QUOTES, 'UTF-8') ?>?v=<?= file_exists(__DIR__ . '/../../../assets/coza/css/coza-custom.css') ? filemtime(__DIR__ . '/../../../assets/coza/css/coza-custom.css') : time() ?>">
<!--===============================================================================================-->

	<script>
		window.SHOP_BASE_URL = "<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>";
		window.SHOP_CART_URL = "<?= htmlspecialchars(route('cart'), ENT_QUOTES, 'UTF-8') ?>";
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

		/* Dynamic Theme Accent Color */
		:root {
			--theme-accent: <?= htmlspecialchars($themeAccent, ENT_QUOTES, 'UTF-8') ?>;
			--accent-color: <?= htmlspecialchars($themeAccent, ENT_QUOTES, 'UTF-8') ?>;
		}
		.bg1, .btn-primary, .pdp-btn-buy, .btn-card-buynow {
			background-color: var(--accent-color) !important;
			border-color: var(--accent-color) !important;
		}
		.btn-card-buynow:hover, .pdp-btn-buy:hover {
			filter: brightness(0.9) !important;
		}
		.hov-bg1:hover {
			background-color: var(--accent-color) !important;
		}
		.cl1, .hov-cl1:hover, a:hover {
			color: var(--accent-color) !important;
		}
		.border-accent {
			border-color: var(--accent-color) !important;
		}
		.icon-header-noti::after {
			background-color: var(--accent-color) !important;
		}

		/* Product card quick-action buttons: Centered from middle & equal width */
		.block2-pic {
			overflow: hidden !important;
			position: relative !important;
		}
		.block2-action-wrap {
			position: absolute !important;
			left: 0 !important;
			right: 0 !important;
			bottom: 10px !important;
			width: 100% !important;
			max-width: 100% !important;
			padding: 0 10px !important;
			box-sizing: border-box !important;
			display: flex !important;
			justify-content: center !important;
			align-items: center !important;
			z-index: 10 !important;
			pointer-events: none !important;
			opacity: 0 !important;
			transform: translateY(8px) !important;
			transition: transform 0.25s ease, opacity 0.25s ease !important;
			margin: 0 auto !important;
		}
		.block2:hover .block2-action-wrap,
		.block2-pic:hover .block2-action-wrap {
			opacity: 1 !important;
			pointer-events: auto !important;
			transform: translateY(0) !important;
		}
		.block2-action-wrap .d-flex {
			width: 100% !important;
			display: flex !important;
			justify-content: center !important;
			align-items: center !important;
			gap: 8px !important;
			margin: 0 auto !important;
		}
		.block2-action-wrap .btn-card-action {
			flex: 1 1 0% !important;
			width: 50% !important;
			max-width: 50% !important;
			display: inline-flex !important;
			align-items: center !important;
			justify-content: center !important;
			text-align: center !important;
			box-sizing: border-box !important;
			height: 38px !important;
			border-radius: 8px !important;
			font-size: 13px !important;
			font-weight: 600 !important;
			padding: 0 6px !important;
			white-space: nowrap !important;
			text-decoration: none !important;
			box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12) !important;
		}
		.block2-action-wrap .btn-card-cart {
			background: #e8e0d5 !important;
			color: #433126 !important;
			border: 1px solid rgba(67, 49, 38, 0.15) !important;
		}
		.block2-action-wrap .btn-card-cart:hover {
			background: #ded4c5 !important;
			color: #2b1f17 !important;
		}
		.block2-action-wrap .btn-card-buynow {
			background: var(--accent-color, #7a5636) !important;
			color: #ffffff !important;
			border: 1px solid var(--accent-color, #7a5636) !important;
		}
		.block2-action-wrap .btn-card-buynow:hover {
			filter: brightness(0.9) !important;
		}
		.block2-action-wrap .btn-card-soldout {
			width: 100% !important;
			max-width: 100% !important;
			background: #6c757d !important;
			color: #ffffff !important;
		}
	</style>
</head>
<body class="animsition">
