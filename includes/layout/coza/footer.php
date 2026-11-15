<?php
$footerCategories = shop_active_categories();
$storeAddress = trim((string)setting('store_address', 'Dhaka, Bangladesh'));
if ($storeAddress === '') $storeAddress = 'Dhaka, Bangladesh';
$storePhone   = trim((string)setting('store_phone', setting('contact_phone', '+880 1700-000000')));
if ($storePhone === '') $storePhone = '+880 1700-000000';
$storeEmail   = trim((string)setting('store_email', setting('contact_email', 'support@jinishpotro.store')));
if ($storeEmail === '') $storeEmail = 'support@jinishpotro.store';
$socialFb     = trim((string)setting('social_facebook', ''));
$socialIg     = trim((string)setting('social_instagram', ''));
$socialWa     = trim((string)setting('social_whatsapp', ''));

// Fetch CMS footer pages
$footerCmsPages = [];
try {
    $stmtCms = db()->query("SELECT slug, title FROM pages WHERE show_in_footer = 1 AND status = 'published' ORDER BY position ASC, id ASC");
    if ($stmtCms) {
        $footerCmsPages = $stmtCms->fetchAll();
    }
} catch (Throwable $e) {}
?>
	<!-- Pre-footer Trust Strip (Customer Commitments) -->
	<section class="sec-trust-bar bg0 p-t-35 p-b-35" aria-label="Customer Commitments" style="border-top: 1px solid #eef0f3; background: #fcfcfd;">
		<div class="container">
			<div class="row p-tb-25 p-lr-15" style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
				
				<!-- Item 1: Nationwide COD -->
				<div class="col-12 col-sm-6 col-lg-3 p-tb-12">
					<div class="d-flex align-items-center" style="gap: 14px;">
						<div style="width: 46px; height: 46px; border-radius: 10px; background: #f3f4f6; color: #111827; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
							<i class="zmdi zmdi-shield-check"></i>
						</div>
						<div style="flex: 1; min-width: 0;">
							<div style="font-weight: 700; font-size: 13.5px; color: #111827; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; line-height: 1.2;">
								<?= htmlspecialchars(setting('trust_item_1_title', 'Nationwide COD'), ENT_QUOTES, 'UTF-8') ?>
							</div>
							<div style="font-size: 12px; color: #6b7280; line-height: 1.4; margin: 0;">
								<?= htmlspecialchars(setting('trust_item_1_desc', 'Cash on delivery across all 64 districts'), ENT_QUOTES, 'UTF-8') ?>
							</div>
						</div>
					</div>
				</div>

				<!-- Item 2: Fast Dispatch -->
				<div class="col-12 col-sm-6 col-lg-3 p-tb-12">
					<div class="d-flex align-items-center" style="gap: 14px;">
						<div style="width: 46px; height: 46px; border-radius: 10px; background: #f3f4f6; color: #111827; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
							<i class="zmdi zmdi-truck"></i>
						</div>
						<div style="flex: 1; min-width: 0;">
							<div style="font-weight: 700; font-size: 13.5px; color: #111827; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; line-height: 1.2;">
								<?= htmlspecialchars(setting('trust_item_2_title', 'Fast Dispatch'), ENT_QUOTES, 'UTF-8') ?>
							</div>
							<div style="font-size: 12px; color: #6b7280; line-height: 1.4; margin: 0;">
								<?= htmlspecialchars(setting('trust_item_2_desc', '24h in Dhaka, 48-72h outside'), ENT_QUOTES, 'UTF-8') ?>
							</div>
						</div>
					</div>
				</div>

				<!-- Item 3: 100% Authentic -->
				<div class="col-12 col-sm-6 col-lg-3 p-tb-12">
					<div class="d-flex align-items-center" style="gap: 14px;">
						<div style="width: 46px; height: 46px; border-radius: 10px; background: #f3f4f6; color: #111827; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
							<i class="zmdi zmdi-badge-check"></i>
						</div>
						<div style="flex: 1; min-width: 0;">
							<div style="font-weight: 700; font-size: 13.5px; color: #111827; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; line-height: 1.2;">
								<?= htmlspecialchars(setting('trust_item_3_title', '100% Authentic'), ENT_QUOTES, 'UTF-8') ?>
							</div>
							<div style="font-size: 12px; color: #6b7280; line-height: 1.4; margin: 0;">
								<?= htmlspecialchars(setting('trust_item_3_desc', 'Guaranteed genuine products direct from makers'), ENT_QUOTES, 'UTF-8') ?>
							</div>
						</div>
					</div>
				</div>

				<!-- Item 4: Dedicated Support -->
				<div class="col-12 col-sm-6 col-lg-3 p-tb-12">
					<div class="d-flex align-items-center" style="gap: 14px;">
						<div style="width: 46px; height: 46px; border-radius: 10px; background: #f3f4f6; color: #111827; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
							<i class="zmdi zmdi-headset-mic"></i>
						</div>
						<div style="flex: 1; min-width: 0;">
							<div style="font-weight: 700; font-size: 13.5px; color: #111827; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 2px; line-height: 1.2;">
								<?= htmlspecialchars(setting('trust_item_4_title', 'Dedicated Support'), ENT_QUOTES, 'UTF-8') ?>
							</div>
							<div style="font-size: 12px; color: #6b7280; line-height: 1.4; margin: 0;">
								<?= htmlspecialchars(setting('trust_item_4_desc', 'Friendly phone & WhatsApp assistance'), ENT_QUOTES, 'UTF-8') ?>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>
	</section>

	<!-- Footer -->
	<footer class="bg3 p-t-75 p-b-32">
		<div class="container">
			<div class="row">
				<div class="col-sm-6 col-lg-3 p-b-50">
					<h4 class="stext-301 cl0 p-b-30">
						Categories
					</h4>

					<ul>
						<?php if (!empty($footerCategories)): ?>
							<?php foreach (array_slice($footerCategories, 0, 5) as $fc): ?>
								<li class="p-b-10">
									<a href="<?= htmlspecialchars(route('category', ['slug' => $fc['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-107 cl7 hov-cl1 trans-04">
										<?= htmlspecialchars($fc['name'], ENT_QUOTES, 'UTF-8') ?>
									</a>
								</li>
							<?php endforeach; ?>
						<?php else: ?>
							<li class="p-b-10">
								<a href="<?= htmlspecialchars(route('shop'), ENT_QUOTES, 'UTF-8') ?>" class="stext-107 cl7 hov-cl1 trans-04">All Products</a>
							</li>
						<?php endif; ?>
					</ul>
				</div>

				<div class="col-sm-6 col-lg-3 p-b-50">
					<h4 class="stext-301 cl0 p-b-30">
						Customer Care
					</h4>

					<ul>
						<li class="p-b-10">
							<a href="<?= htmlspecialchars(route('track'), ENT_QUOTES, 'UTF-8') ?>" class="stext-107 cl7 hov-cl1 trans-04">
								Track Your Order
							</a>
						</li>

						<li class="p-b-10">
							<a href="<?= htmlspecialchars(route('contact'), ENT_QUOTES, 'UTF-8') ?>" class="stext-107 cl7 hov-cl1 trans-04">
								Contact & Support
							</a>
						</li>

						<?php foreach ($footerCmsPages as $fp): ?>
							<li class="p-b-10">
								<a href="<?= htmlspecialchars(route('page', ['slug' => $fp['slug']]), ENT_QUOTES, 'UTF-8') ?>" class="stext-107 cl7 hov-cl1 trans-04">
									<?= htmlspecialchars($fp['title'], ENT_QUOTES, 'UTF-8') ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="col-sm-6 col-lg-3 p-b-50">
					<h4 class="stext-301 cl0 p-b-30">
						GET IN TOUCH
					</h4>

					<p class="stext-107 cl7 size-201">
						<?php if ($storeAddress !== ''): ?>
							<?= htmlspecialchars($storeAddress, ENT_QUOTES, 'UTF-8') ?><br>
						<?php endif; ?>
						<?php if ($storePhone !== ''): ?>
							Phone: <?= htmlspecialchars($storePhone, ENT_QUOTES, 'UTF-8') ?><br>
						<?php endif; ?>
						<?php if ($storeEmail !== ''): ?>
							Email: <?= htmlspecialchars($storeEmail, ENT_QUOTES, 'UTF-8') ?>
						<?php endif; ?>
					</p>

					<div class="p-t-27">
						<?php if ($socialFb !== ''): ?>
							<a href="<?= htmlspecialchars($socialFb, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="fs-18 cl7 hov-cl1 trans-04 m-r-16">
								<i class="fa fa-facebook"></i>
							</a>
						<?php endif; ?>

						<?php if ($socialIg !== ''): ?>
							<a href="<?= htmlspecialchars($socialIg, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="fs-18 cl7 hov-cl1 trans-04 m-r-16">
								<i class="fa fa-instagram"></i>
							</a>
						<?php endif; ?>

						<?php if ($socialWa !== ''): ?>
							<a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $socialWa), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="fs-18 cl7 hov-cl1 trans-04 m-r-16">
								<i class="fa fa-whatsapp"></i>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<div class="col-sm-6 col-lg-3 p-b-50">
					<h4 class="stext-301 cl0 p-b-30">
						Newsletter
					</h4>

					<form action="<?= htmlspecialchars(route('newsletter'), ENT_QUOTES, 'UTF-8') ?>" method="post">
						<?= csrf_field() ?>
						<div class="wrap-input1 w-full p-b-4">
							<input class="input1 bg-none plh1 stext-107 cl7" type="email" name="email" placeholder="email@example.com" required>
							<div class="focus-input1 trans-04"></div>
						</div>

						<div class="p-t-18">
							<button type="submit" class="flex-c-m stext-101 cl0 size-103 bg1 bor1 hov-btn2 p-lr-15 trans-04">
								Subscribe
							</button>
						</div>
					</form>
				</div>
			</div>

			<div class="p-t-40">
				<div class="flex-c-m flex-w p-b-18">
					<span class="m-all-1">
						<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-pay-01.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON-PAY">
					</span>
					<span class="m-all-1">
						<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-pay-02.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON-PAY">
					</span>
					<span class="m-all-1">
						<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-pay-03.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON-PAY">
					</span>
					<span class="m-all-1">
						<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-pay-04.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON-PAY">
					</span>
					<span class="m-all-1">
						<img src="<?= htmlspecialchars(url('assets/coza/images/icons/icon-pay-05.png'), ENT_QUOTES, 'UTF-8') ?>" alt="ICON-PAY">
					</span>
				</div>

				<p class="stext-107 cl6 txt-center">
					<!-- Link back to Colorlib can't be removed. Template is licensed under CC BY 3.0. -->
Copyright &copy;<?= date('Y') ?> <?= htmlspecialchars(setting('site_name', APP_NAME), ENT_QUOTES, 'UTF-8') ?>. All rights reserved | Made with <i class="fa fa-heart-o" aria-hidden="true"></i> by <a href="https://colorlib.com" target="_blank" rel="noopener">Colorlib</a> &amp; distributed by <a href="https://themewagon.com" target="_blank" rel="noopener">ThemeWagon</a>
<!-- Link back to Colorlib can't be removed. Template is licensed under CC BY 3.0. -->
				</p>
			</div>
		</div>
	</footer>

	<!-- Back to top -->
	<div class="btn-back-to-top" id="myBtn">
		<span class="symbol-btn-back-to-top">
			<i class="zmdi zmdi-chevron-up"></i>
		</span>
	</div>
