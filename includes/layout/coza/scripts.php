<!--===============================================================================================-->	
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/jquery/jquery-3.2.1.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/animsition/js/animsition.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/bootstrap/js/popper.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/bootstrap/js/bootstrap.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/select2/select2.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
		$(".js-select2").each(function(){
			$(this).select2({
				minimumResultsForSearch: 20,
				dropdownParent: $(this).next('.dropDownSelect2')
			});
		});
	</script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/daterangepicker/moment.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/daterangepicker/daterangepicker.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/slick/slick.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(url('assets/coza/js/slick-custom.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/parallax100/parallax100.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
        if (typeof $('.parallax100').parallax100 === 'function') {
            $('.parallax100').parallax100();
        }
	</script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/MagnificPopup/jquery.magnific-popup.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
		$('.gallery-lb').each(function() {
			$(this).magnificPopup({
		        delegate: 'a',
		        type: 'image',
		        gallery: { enabled: true },
		        mainClass: 'mfp-fade'
		    });
		});
	</script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/isotope/isotope.pkgd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/sweetalert/sweetalert.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/vendor/perfect-scrollbar/perfect-scrollbar.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
		$('.js-pscroll').each(function(){
			$(this).css('position','relative');
			$(this).css('overflow','hidden');
			var ps = new PerfectScrollbar(this, {
				wheelSpeed: 1,
				scrollingThreshold: 1000,
				wheelPropagation: false,
			});

			$(window).on('resize', function(){
				ps.update();
			})
		});
	</script>
<!--===============================================================================================-->
	<script src="<?= htmlspecialchars(url('assets/coza/js/main.js'), ENT_QUOTES, 'UTF-8') ?>"></script>

	<!-- Cozastore Real Storefront Integration Script -->
	<script>
	(function($) {
		'use strict';

		// Real AJAX Add to Cart handler
		window.cozaAddToCart = function(productId, qty, variantId, productName, callback) {
			productId = parseInt(productId, 10);
			qty = parseInt(qty, 10) || 1;
			variantId = variantId ? parseInt(variantId, 10) : null;

			var postData = {
				action: 'add',
				product_id: productId,
				quantity: qty,
				_csrf: window.SHOP_CSRF_TOKEN
			};
			if (variantId) {
				postData.variant_id = variantId;
			}

			$.ajax({
				url: window.SHOP_BASE_URL + 'api/cart_ajax.php',
				type: 'POST',
				data: postData,
				dataType: 'json',
				success: function(resp) {
					if (resp && resp.ok) {
						// Update badge
						$('.js-show-cart').attr('data-notify', resp.cart_count);
						// SweetAlert success
						swal(productName || 'Product', 'has been added to your cart!', 'success');
						// Trigger mini-cart refresh if available
						if (resp.items && typeof renderMiniCart === 'function') {
							renderMiniCart(resp);
						}
						if (typeof callback === 'function') callback(null, resp);
					} else {
						var msg = (resp && resp.error) ? resp.error : 'Could not add product to cart.';
						swal('Notice', msg, 'error');
						if (typeof callback === 'function') callback(msg);
					}
				},
				error: function() {
					swal('Error', 'Network or server error while adding to cart.', 'error');
					if (typeof callback === 'function') callback('Network error');
				}
			});
		};

		// Real AJAX Wishlist Toggle handler
		window.cozaToggleWishlist = function(productId, btnElem) {
			productId = parseInt(productId, 10);
			$.ajax({
				url: window.SHOP_BASE_URL + 'api/wishlist.php',
				type: 'POST',
				data: { action: 'toggle', product_id: productId, _csrf: window.SHOP_CSRF_TOKEN },
				dataType: 'json',
				success: function(resp) {
					if (resp && resp.ok) {
						if (resp.in_wishlist) {
							$(btnElem).addClass('js-addedwish-b2');
							swal('Wishlist', 'Added to your wishlist!', 'success');
						} else {
							$(btnElem).removeClass('js-addedwish-b2');
							swal('Wishlist', 'Removed from your wishlist.', 'info');
						}
						if (resp.count !== undefined) {
							$('a[title="Wishlist"]').attr('data-notify', resp.count);
						}
					} else {
						var msg = (resp && resp.error) ? resp.error : 'Could not update wishlist.';
						swal('Notice', msg, 'warning');
					}
				},
				error: function() {
					swal('Error', 'Network error updating wishlist.', 'error');
				}
			});
		};

		// Ensure isotope grid items layout properly without overlap
		$(window).on('load', function() {
			if ($('.isotope-grid').length && typeof $.fn.isotope === 'function') {
				$('.isotope-grid').isotope('layout');
			}
		});

		// Theme Manager (Dark / Light Mode)
		function updateThemeIcons(theme) {
			if (theme === 'dark') {
				$('[data-theme-toggle] .theme-icon-sun').show();
				$('[data-theme-toggle] .theme-icon-moon').hide();
				$('[data-theme-toggle]').attr('title', 'Switch to Light Mode');
			} else {
				$('[data-theme-toggle] .theme-icon-sun').hide();
				$('[data-theme-toggle] .theme-icon-moon').show();
				$('[data-theme-toggle]').attr('title', 'Switch to Dark Mode');
			}
		}

		// Initial sync on DOM ready
		$(function() {
			var currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
			updateThemeIcons(currentTheme);
		});

		$(document).on('click', '[data-theme-toggle]', function(e) {
			e.preventDefault();
			var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
			var next = current === 'dark' ? 'light' : 'dark';
			document.documentElement.setAttribute('data-theme', next);
			try {
				localStorage.setItem('jinispotro_theme', next);
			} catch (_) {}
			updateThemeIcons(next);
		});

	})(jQuery);
	</script>
</body>
</html>
