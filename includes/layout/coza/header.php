<?php
$categories = shop_active_categories();
$cartCnt = cart_count();
$wishlistCnt = function_exists('wishlist_count') ? wishlist_count() : 0;
$headerCustomer = function_exists('customer_current') ? customer_current() : null;
$siteBrand = trim((string)setting('site_name', ''));
if ($siteBrand === '' || $siteBrand === 'PHP Admin Panel' || $siteBrand === 'Store') {
    $siteBrand = 'Jinish Potro';
}
$storeLogoUrl = store_logo_url();
$announcementText = trim((string)setting('announcement_bar_text', ''));
$announcementEnabled = (int)setting('announcement_bar_enabled', '0') === 1 && $announcementText !== '';
?>
	<!-- Header -->
	<header class="<?= !empty($headerClass) ? htmlspecialchars($headerClass, ENT_QUOTES, 'UTF-8') : 'header-v4' ?>">
		<!-- Header desktop -->
		<div class="container-menu-desktop">
			<!-- Topbar -->
			<div class="top-bar">
				<div class="content-topbar flex-sb-m h-full container">
					<div class="left-top-bar">
						<?= htmlspecialchars($announcementEnabled ? $announcementText : setting('store_tagline', 'Quality products delivered to your door'), ENT_QUOTES, 'UTF-8') ?>
					</div>

					<div class="right-top-bar flex-w h-full">
						<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 topbar-track-btn <?= route_is('track') ? 'active' : '' ?>" title="Track Order">
							<i class="fa fa-truck m-r-6"></i> Track Order
						</a>

						<?php if ($headerCustomer): ?>
							<a href="<?= htmlspecialchars(route('account'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25">
								<i class="fa fa-user m-r-6"></i> <?= htmlspecialchars($headerCustomer['name'], ENT_QUOTES, 'UTF-8') ?>
							</a>
							<a href="<?= htmlspecialchars(route('logout'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 text-danger">
								Sign Out
							</a>
						<?php else: ?>
							<a href="<?= htmlspecialchars(route('login'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 <?= route_is('login') ? 'active' : '' ?>">
								Sign In
							</a>
							<a href="<?= htmlspecialchars(route('register'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m trans-04 p-lr-25 <?= route_is('register') ? 'active' : '' ?>">
								Sign Up
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="wrap-menu-desktop how-shadow1">
				<nav class="limiter-menu-desktop container">
					
					<!-- Logo desktop -->		
					<a href="<?= htmlspecialchars(shop_url(), ENT_QUOTES, 'UTF-8') ?>" class="logo">
						<?php if ($storeLogoUrl): ?>
							<img src="<?= htmlspecialchars($storeLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 40px; width: auto; object-fit: contain;">
						<?php else: ?>
							<span class="brand-text-logo"><?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?><span class="brand-dot">.</span></span>
						<?php endif; ?>
					</a>

					<!-- Menu desktop -->
					<div class="menu-desktop">
						<ul class="main-menu">
							<li class="<?= route_is('home') ? 'active-menu' : '' ?>">
								<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
							</li>

							<li class="<?= route_is('shop') ? 'active-menu' : '' ?>">
								<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">Shop<?php if (!empty($categories)): ?> <i class="fa fa-angle-down m-l-4" style="font-size: 11px; opacity: 0.7;"></i><?php endif; ?></a>
								<?php if (!empty($categories)): ?>
									<ul class="sub-menu">
										<li><a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">All Categories</a></li>
										<?php foreach ($categories as $cat): ?>
											<li>
												<a href="<?= htmlspecialchars(route('category', ['slug' => $cat['slug']]), ENT_QUOTES, 'UTF-8') ?>">
													<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
												</a>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</li>
						</ul>
					</div>	

					<!-- Icon header -->
					<div class="wrap-icon-header flex-w flex-r-m">
						<div class="icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 js-show-modal-search" title="Search">
							<i class="zmdi zmdi-search"></i>
						</div>

						<div class="icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 icon-header-noti js-show-cart" data-notify="<?= (int)$cartCnt ?>" title="Cart">
							<i class="zmdi zmdi-shopping-cart"></i>
						</div>

						<a href="<?= htmlspecialchars(route('account_wishlist'), ENT_QUOTES, 'UTF-8') ?>" class="dis-block icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 icon-header-noti" data-notify="<?= (int)$wishlistCnt ?>" title="Wishlist">
							<i class="zmdi zmdi-favorite-outline"></i>
						</a>

						<a href="<?= htmlspecialchars(!empty($headerCustomer) ? route('account') : route('login'), ENT_QUOTES, 'UTF-8') ?>" class="dis-block icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11" title="Account">
							<i class="zmdi zmdi-account"></i>
						</a>

						<?php if ((int)setting('dark_mode_enabled', '1') === 1): ?>
						<div class="icon-header-item cl2 hov-cl1 trans-04 p-l-22 p-r-11 pointer" data-theme-toggle title="Toggle Dark/Light Mode" role="button" aria-label="Toggle Dark/Light Mode" style="cursor: pointer;">
							<i class="fa fa-sun-o theme-icon-sun"></i>
							<i class="fa fa-moon-o theme-icon-moon"></i>
						</div>
						<?php endif; ?>
					</div>
				</nav>
			</div>	
		</div>

		<!-- Header Mobile -->
		<div class="wrap-header-mobile">
			<!-- Logo mobile -->		
			<div class="logo-mobile">
				<a href="<?= htmlspecialchars(shop_url(), ENT_QUOTES, 'UTF-8') ?>">
					<?php if ($storeLogoUrl): ?>
						<img src="<?= htmlspecialchars($storeLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 34px; width: auto; object-fit: contain;">
					<?php else: ?>
						<span class="brand-text-logo"><?= htmlspecialchars($siteBrand, ENT_QUOTES, 'UTF-8') ?><span class="brand-dot">.</span></span>
					<?php endif; ?>
				</a>
			</div>

			<!-- Icon header mobile -->
			<div class="wrap-icon-header d-flex align-items-center flex-nowrap m-r-10">
				<div class="icon-header-item cl2 hov-cl1 trans-04 js-show-modal-search" title="Search">
					<i class="zmdi zmdi-search"></i>
				</div>

				<div class="icon-header-item cl2 hov-cl1 trans-04 icon-header-noti js-show-cart" data-notify="<?= (int)$cartCnt ?>" title="Cart">
					<i class="zmdi zmdi-shopping-cart"></i>
				</div>

				<a href="<?= htmlspecialchars(route('account_wishlist'), ENT_QUOTES, 'UTF-8') ?>" class="dis-block icon-header-item cl2 hov-cl1 trans-04 icon-header-noti" data-notify="<?= (int)$wishlistCnt ?>" title="Wishlist">
					<i class="zmdi zmdi-favorite-outline"></i>
				</a>

				<?php if ((int)setting('dark_mode_enabled', '1') === 1): ?>
				<div class="icon-header-item cl2 hov-cl1 trans-04 pointer" data-theme-toggle title="Toggle Dark/Light Mode" role="button" aria-label="Toggle Dark/Light Mode" style="cursor: pointer;">
					<i class="fa fa-sun-o theme-icon-sun"></i>
					<i class="fa fa-moon-o theme-icon-moon"></i>
				</div>
				<?php endif; ?>
			</div>

			<!-- Button show menu -->
			<div class="btn-show-menu-mobile hamburger hamburger--squeeze">
				<span class="hamburger-box">
					<span class="hamburger-inner"></span>
				</span>
			</div>
		</div>

		<!-- Menu Mobile -->
		<div class="menu-mobile">
			<ul class="topbar-mobile">
				<li>
					<div class="left-top-bar">
						<?= htmlspecialchars($announcementEnabled ? $announcementText : setting('store_tagline', 'Quality products delivered to your door'), ENT_QUOTES, 'UTF-8') ?>
					</div>
				</li>

				<li>
					<div class="right-top-bar flex-w h-full">
						<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 <?= route_is('track') ? 'active' : '' ?>">
							<i class="fa fa-truck m-r-5"></i> Track Order
						</a>

						<?php if ($headerCustomer): ?>
							<a href="<?= htmlspecialchars(route('account'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04">
								My Account
							</a>
							<a href="<?= htmlspecialchars(route('logout'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 text-danger">
								Sign Out
							</a>
						<?php else: ?>
							<a href="<?= htmlspecialchars(route('login'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 <?= route_is('login') ? 'active' : '' ?>">
								Sign In
							</a>
							<a href="<?= htmlspecialchars(route('register'), ENT_QUOTES, 'UTF-8') ?>" class="flex-c-m p-lr-10 trans-04 <?= route_is('register') ? 'active' : '' ?>">
								Sign Up
							</a>
						<?php endif; ?>
					</div>
				</li>
			</ul>

			<ul class="main-menu-m">
				<li class="<?= route_is('home') ? 'active-menu' : '' ?>">
					<a href="<?= htmlspecialchars(route('home'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
				</li>

				<li class="<?= route_is('shop') ? 'active-menu' : '' ?>">
					<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">Shop</a>
					<?php if (!empty($categories)): ?>
						<ul class="sub-menu-m">
							<li><a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>">All Products</a></li>
							<?php foreach ($categories as $cat): ?>
								<li>
									<a href="<?= htmlspecialchars(route('category', ['slug' => $cat['slug']]), ENT_QUOTES, 'UTF-8') ?>">
										<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
						<span class="arrow-main-menu-m">
							<i class="fa fa-angle-right" aria-hidden="true"></i>
						</span>
					<?php endif; ?>
				</li>

				<li class="<?= route_is('track') ? 'active-menu' : '' ?>">
					<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>">Track Order</a>
				</li>
			</ul>
		</div>

		<!-- Modal Search -->
		<div class="modal-search-header flex-c-m trans-04 js-hide-modal-search">
			<div class="container-search-header">
				<button type="button" class="flex-c-m btn-hide-modal-search trans-04 js-hide-modal-search">
					<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-close2.png'), ENT_QUOTES, 'UTF-8') ?>" alt="CLOSE">
				</button>

				<form action="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" method="get" class="wrap-search-header flex-w p-l-15">
					<button type="submit" class="flex-c-m trans-04">
						<i class="zmdi zmdi-search"></i>
					</button>
					<input class="plh3" type="text" name="q" placeholder="Search products..." value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
				</form>
			</div>
		</div>
	</header>
