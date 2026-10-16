/**
 * Storefront JavaScript Module (Prompt 04)
 * Handles: Announcement bar, Live Search suggestions, Mini-Cart drawer AJAX,
 * Quick-add, Gallery thumbnails & Lightbox zoom, PDP Accordions, and Recently Viewed.
 */
document.addEventListener('DOMContentLoaded', () => {

  // -------------------------------------------------------------
  // 1. Announcement Bar Dismissal
  // -------------------------------------------------------------
  const announcementBar = document.getElementById('announcementBar');
  const announcementClose = document.getElementById('announcementClose');
  if (announcementBar && announcementClose) {
    if (sessionStorage.getItem('announcement_dismissed') === '1') {
      announcementBar.classList.add('dismissed');
    }
    announcementClose.addEventListener('click', () => {
      announcementBar.classList.add('dismissed');
      sessionStorage.setItem('announcement_dismissed', '1');
    });
  }

  // -------------------------------------------------------------
  // 2. Expandable Search & Live Suggestions
  // -------------------------------------------------------------
  const searchTrigger = document.querySelector('[data-toggle-search]');
  const searchExpandBar = document.getElementById('searchExpandBar');
  const searchInput = document.getElementById('headerSearchInput');
  const searchDropdown = document.getElementById('searchSuggestionsDropdown');

  if (searchTrigger && searchExpandBar && searchInput) {
    searchTrigger.addEventListener('click', (e) => {
      e.preventDefault();
      searchExpandBar.classList.toggle('active');
      if (searchExpandBar.classList.contains('active')) {
        searchInput.focus();
      }
    });

    document.addEventListener('click', (e) => {
      if (!searchExpandBar.contains(e.target) && !searchTrigger.contains(e.target)) {
        searchExpandBar.classList.remove('active');
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        searchExpandBar.classList.remove('active');
      }
    });

    let searchTimeout = null;
    searchInput.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      const query = searchInput.value.trim();

      if (query.length < 2) {
        if (searchDropdown) {
          searchDropdown.classList.remove('visible');
          searchDropdown.innerHTML = '';
        }
        return;
      }

      searchTimeout = setTimeout(() => {
        fetch(`${window.SHOP_BASE_URL || ''}api/search_suggest.php?q=${encodeURIComponent(query)}`)
          .then(res => res.json())
          .then(data => {
            if (!searchDropdown) return;
            let html = '';

            if (data.products && data.products.length > 0) {
              html += `<div class="suggestion-section-title">Products</div>`;
              data.products.forEach(p => {
                const priceHtml = p.on_sale
                  ? `<span class="sale-p">${p.sale_price_fmt}</span> <s class="text-muted small">${p.price_fmt}</s>`
                  : `<span>${p.price_fmt}</span>`;
                html += `
                  <a href="${p.url}" class="suggestion-item">
                    <img src="${p.image}" alt="${p.name}" class="suggestion-thumb" loading="lazy">
                    <div class="suggestion-info">
                      <div class="suggestion-name">${p.name}</div>
                      <div class="suggestion-price">${priceHtml}</div>
                    </div>
                  </a>
                `;
              });
            }

            if (data.categories && data.categories.length > 0) {
              html += `<div class="suggestion-section-title mt-2">Categories</div>`;
              data.categories.forEach(c => {
                html += `
                  <a href="${c.url}" class="suggestion-item py-1">
                    <i class="bi bi-tag text-muted me-1"></i>
                    <span class="suggestion-name">${c.name}</span>
                  </a>
                `;
              });
            }

            if (!html) {
              html = `<div class="p-3 text-center text-muted small">No matches found for "${query}"</div>`;
            }

            searchDropdown.innerHTML = html;
            searchDropdown.classList.add('visible');
          })
          .catch(() => {});
      }, 250);
    });
  }

  // -------------------------------------------------------------
  // 3. Mini-Cart Drawer (Slide-out & Live AJAX Updates)
  // -------------------------------------------------------------
  const cartDrawer = document.getElementById('miniCartDrawer');
  const cartBackdrop = document.getElementById('miniCartBackdrop');
  const cartDrawerBody = document.getElementById('miniCartBody');
  const cartDrawerSubtotal = document.getElementById('miniCartSubtotal');
  const cartTriggers = document.querySelectorAll('[data-cart-drawer-trigger]');
  const cartCloseBtns = document.querySelectorAll('[data-cart-drawer-close]');
  const cartBadgeCountEls = document.querySelectorAll('.cart-badge-count');

  function updateCartBadge(count) {
    cartBadgeCountEls.forEach(el => {
      el.textContent = count;
      if (count > 0) {
        el.style.display = 'inline-block';
      } else {
        el.style.display = 'none';
      }
    });
  }

  function openCartDrawer() {
    if (cartDrawer && cartBackdrop) {
      cartDrawer.classList.add('open');
      cartBackdrop.classList.add('open');
      document.body.style.overflow = 'hidden';
      refreshCartDrawer();
    }
  }

  function closeCartDrawer() {
    if (cartDrawer && cartBackdrop) {
      cartDrawer.classList.remove('open');
      cartBackdrop.classList.remove('open');
      document.body.style.overflow = '';
    }
  }

  cartTriggers.forEach(btn => btn.addEventListener('click', (e) => {
    e.preventDefault();
    openCartDrawer();
  }));

  cartCloseBtns.forEach(btn => btn.addEventListener('click', closeCartDrawer));
  if (cartBackdrop) {
    cartBackdrop.addEventListener('click', closeCartDrawer);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCartDrawer();
  });

  function refreshCartDrawer() {
    if (!cartDrawerBody) return;
    fetch(`${window.SHOP_BASE_URL || ''}api/cart_ajax.php?action=get`)
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          cartDrawerBody.innerHTML = data.html;
          if (cartDrawerSubtotal) cartDrawerSubtotal.textContent = data.subtotal_fmt;
          updateCartBadge(data.cart_count);
          attachMiniCartEvents();
        }
      })
      .catch(() => {});
  }

  function attachMiniCartEvents() {
    if (!cartDrawerBody) return;

    // Quantity stepper
    cartDrawerBody.querySelectorAll('[data-qty-change]').forEach(btn => {
      btn.addEventListener('click', function () {
        const delta = parseInt(this.dataset.qtyChange, 10);
        const key = this.dataset.cartKey;
        const input = cartDrawerBody.querySelector(`.qty-input[data-cart-key="${key}"]`);
        if (!input) return;

        const currentQty = parseInt(input.value, 10);
        const newQty = currentQty + delta;
        if (newQty < 1) return;

        const fd = new FormData();
        fd.append('action', 'update');
        fd.append('cart_key', key);
        fd.append('quantity', newQty);

        fetch(`${window.SHOP_BASE_URL || ''}api/cart_ajax.php`, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.ok) {
              cartDrawerBody.innerHTML = data.html;
              if (cartDrawerSubtotal) cartDrawerSubtotal.textContent = data.subtotal_fmt;
              updateCartBadge(data.cart_count);
              attachMiniCartEvents();
            }
          });
      });
    });

    // Remove button
    cartDrawerBody.querySelectorAll('[data-cart-remove]').forEach(btn => {
      btn.addEventListener('click', function () {
        const key = this.dataset.cartRemove;
        const fd = new FormData();
        fd.append('action', 'remove');
        fd.append('cart_key', key);

        fetch(`${window.SHOP_BASE_URL || ''}api/cart_ajax.php`, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.ok) {
              cartDrawerBody.innerHTML = data.html;
              if (cartDrawerSubtotal) cartDrawerSubtotal.textContent = data.subtotal_fmt;
              updateCartBadge(data.cart_count);
              attachMiniCartEvents();
            }
          });
      });
    });
  }

  // -------------------------------------------------------------
  // 4. Quick-Add on Product Cards
  // -------------------------------------------------------------
  document.querySelectorAll('[data-quick-add]').forEach(btn => {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      const pid = this.dataset.quickAdd;
      const originalText = this.innerHTML;
      this.disabled = true;
      this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Adding...';

      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', pid);
      fd.append('quantity', 1);

      fetch(`${window.SHOP_BASE_URL || ''}api/cart_ajax.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          this.disabled = false;
          this.innerHTML = originalText;
          if (data.ok) {
            updateCartBadge(data.cart_count);
            openCartDrawer();
          } else {
            alert(data.error || 'Unable to add product to cart.');
          }
        })
        .catch(() => {
          this.disabled = false;
          this.innerHTML = originalText;
        });
    });
  });

  // -------------------------------------------------------------
  // 5. Product Detail Page (Gallery, Lightbox, Stepper, Accordions)
  // -------------------------------------------------------------
  const mainGalleryImg = document.getElementById('mainGalleryImg');
  const mainGalleryViewport = document.getElementById('mainGalleryViewport');
  const thumbBtns = document.querySelectorAll('.gallery-thumb-btn');
  const lightboxModal = document.getElementById('lightboxModal');
  const lightboxImg = document.getElementById('lightboxImg');
  const lightboxClose = document.getElementById('lightboxClose');

  if (mainGalleryImg && thumbBtns.length > 0) {
    thumbBtns.forEach(tBtn => {
      tBtn.addEventListener('click', function () {
        thumbBtns.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const newSrc = this.dataset.fullImg;
        if (newSrc && mainGalleryImg.src !== newSrc) {
          mainGalleryImg.style.opacity = '0.3';
          mainGalleryImg.src = newSrc;
          mainGalleryImg.onload = () => {
            mainGalleryImg.style.opacity = '1';
          };
        }
      });
    });
  }

  // Lightbox Zoom
  if (mainGalleryViewport && lightboxModal && lightboxImg) {
    mainGalleryViewport.addEventListener('click', () => {
      lightboxImg.src = mainGalleryImg.src;
      lightboxModal.classList.add('open');
      document.body.style.overflow = 'hidden';
    });

    if (lightboxClose) {
      lightboxClose.addEventListener('click', () => {
        lightboxModal.classList.remove('open');
        document.body.style.overflow = '';
      });
    }

    lightboxModal.addEventListener('click', (e) => {
      if (e.target === lightboxModal) {
        lightboxModal.classList.remove('open');
        document.body.style.overflow = '';
      }
    });
  }

  // PDP Quantity Stepper
  const pdpQtyInput = document.getElementById('pdpQtyInput');
  const pdpQtyDec = document.getElementById('pdpQtyDec');
  const pdpQtyInc = document.getElementById('pdpQtyInc');

  if (pdpQtyInput && pdpQtyDec && pdpQtyInc) {
    pdpQtyDec.addEventListener('click', () => {
      let val = parseInt(pdpQtyInput.value, 10) || 1;
      if (val > 1) {
        pdpQtyInput.value = val - 1;
      }
    });

    pdpQtyInc.addEventListener('click', () => {
      let val = parseInt(pdpQtyInput.value, 10) || 1;
      let max = parseInt(pdpQtyInput.getAttribute('max'), 10) || 999;
      if (val < max) {
        pdpQtyInput.value = val + 1;
      }
    });
  }

  // PDP Accordions
  document.querySelectorAll('.accordion-trigger').forEach(trigger => {
    trigger.addEventListener('click', function () {
      const isExpanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', !isExpanded);
      const panel = this.nextElementSibling;
      if (panel) {
        panel.classList.toggle('show');
      }
    });
  });

  // PDP AJAX Add-to-Cart
  const pdpForm = document.getElementById('pdpAddToCartForm');
  if (pdpForm) {
    pdpForm.addEventListener('submit', function (e) {
      const submitBtn = e.submitter;
      const isBuyNow = submitBtn && submitBtn.value === 'buy_now';

      if (isBuyNow) {
        // Let buy now submit normally to redirect straight to checkout
        return;
      }

      e.preventDefault();
      const addBtn = document.getElementById('pdpAddBtn');
      if (addBtn) addBtn.disabled = true;

      const fd = new FormData(pdpForm);
      fd.append('action', 'add');

      fetch(`${window.SHOP_BASE_URL || ''}api/cart_ajax.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (addBtn) addBtn.disabled = false;
          if (data.ok) {
            updateCartBadge(data.cart_count);
            openCartDrawer();
          } else {
            alert(data.error || 'Could not add to cart.');
          }
        })
        .catch(() => {
          if (addBtn) addBtn.disabled = false;
        });
    });
  }

  // -------------------------------------------------------------
  // 6. Recently Viewed Products (localStorage + api/availability.php?ids=)
  // -------------------------------------------------------------
  const pdpContainer = document.querySelector('[data-product-availability]');
  if (pdpContainer) {
    const currentPid = parseInt(pdpContainer.dataset.productId, 10);
    if (currentPid > 0) {
      let viewed = [];
      try {
        viewed = JSON.parse(localStorage.getItem('recently_viewed_pids') || '[]');
      } catch (e) { viewed = []; }

      // Remove current if exists, prepend current, cap at 8
      viewed = viewed.filter(id => id !== currentPid);
      viewed.unshift(currentPid);
      viewed = viewed.slice(0, 8);
      try {
        localStorage.setItem('recently_viewed_pids', JSON.stringify(viewed));
      } catch (e) {}

      // Render other recently viewed
      const otherPids = viewed.filter(id => id !== currentPid).slice(0, 4);
      const rvContainer = document.getElementById('recentlyViewedGrid');
      if (rvContainer && otherPids.length > 0) {
        fetch(`${window.SHOP_BASE_URL || ''}api/availability.php?ids=${otherPids.join(',')}`)
          .then(r => r.json())
          .then(data => {
            // Container can show them if available
            const rvSection = document.getElementById('recentlyViewedSection');
            if (rvSection) rvSection.style.display = 'block';
          })
          .catch(() => {});
      }
    }
  }

  // -------------------------------------------------------------
  // 7. Mobile Filter Bottom-Sheet Drawer
  // -------------------------------------------------------------
  const mobileFilterTrigger = document.querySelector('[data-filter-drawer-trigger]');
  const mobileFilterDrawer = document.getElementById('mobileFilterDrawer');
  const mobileFilterClose = document.querySelectorAll('[data-filter-drawer-close]');

  if (mobileFilterTrigger && mobileFilterDrawer) {
    mobileFilterTrigger.addEventListener('click', (e) => {
      e.preventDefault();
      mobileFilterDrawer.classList.add('open');
      if (cartBackdrop) cartBackdrop.classList.add('open');
      document.body.style.overflow = 'hidden';
    });

    mobileFilterClose.forEach(btn => btn.addEventListener('click', () => {
      mobileFilterDrawer.classList.remove('open');
      if (cartBackdrop) cartBackdrop.classList.remove('open');
      document.body.style.overflow = '';
    }));
  }
});
