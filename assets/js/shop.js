/**
 * Storefront JavaScript Module (Prompt 04)
 * Handles: Announcement bar, Live Search suggestions, Mini-Cart drawer AJAX,
 * Quick-add, Gallery thumbnails & Lightbox zoom, PDP Accordions, and Recently Viewed.
 */
document.addEventListener('DOMContentLoaded', () => {
  const baseUrl = (typeof window !== 'undefined' && window.SHOP_BASE_URL ? window.SHOP_BASE_URL : '/').replace(/\/?$/, '/');

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
        fetch(`${baseUrl}api/search_suggest.php?q=${encodeURIComponent(query)}`)
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
    if (cartDrawer) {
      cartDrawer.classList.add('open', 'is-open');
      cartDrawer.setAttribute('aria-hidden', 'false');
    }
    document.querySelectorAll('.mini-cart-backdrop, .drawer-backdrop').forEach(b => {
      b.classList.add('open', 'is-open');
    });
    document.body.style.overflow = 'hidden';
    refreshCartDrawer();
  }

  function closeCartDrawer() {
    if (cartDrawer) {
      cartDrawer.classList.remove('open', 'is-open');
      cartDrawer.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('.mini-cart-backdrop, .drawer-backdrop').forEach(b => {
      b.classList.remove('open', 'is-open');
    });
    document.querySelectorAll('.shop-drawer').forEach(d => {
      d.classList.remove('open', 'is-open');
    });
    document.body.style.overflow = '';
  }

  cartTriggers.forEach(btn => btn.addEventListener('click', (e) => {
    e.preventDefault();
    openCartDrawer();
  }));

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-cart-drawer-close], [data-drawer-close]')) {
      closeCartDrawer();
    }
    if (e.target.matches('.mini-cart-backdrop, .drawer-backdrop')) {
      closeCartDrawer();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCartDrawer();
  });

  function saveCartCache(data) {
    try {
      sessionStorage.setItem('mini_cart_cache', JSON.stringify({
        time: Date.now(),
        html: data.html,
        subtotal_fmt: data.subtotal_fmt,
        cart_count: data.cart_count
      }));
    } catch (e) {}
  }

  function clearCartCache() {
    try {
      sessionStorage.removeItem('mini_cart_cache');
    } catch (e) {}
  }

  function refreshCartDrawer(force = false) {
    if (!cartDrawerBody) return;
    const now = Date.now();
    if (!force) {
      try {
        const raw = sessionStorage.getItem('mini_cart_cache');
        if (raw) {
          const cached = JSON.parse(raw);
          if (cached && (now - cached.time < 60000) && cached.html) {
            cartDrawerBody.innerHTML = cached.html;
            if (cartDrawerSubtotal) cartDrawerSubtotal.textContent = cached.subtotal_fmt;
            updateCartBadge(cached.cart_count);
            attachMiniCartEvents();
            return;
          }
        }
      } catch (e) {}
    }

    fetch(`${baseUrl}api/cart_ajax.php?action=get`)
      .then(r => r.json())
      .then(data => {
        if (data.ok) {
          saveCartCache(data);
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

        fetch(`${baseUrl}api/cart_ajax.php`, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.ok) {
              saveCartCache(data);
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

        fetch(`${baseUrl}api/cart_ajax.php`, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(data => {
            if (data.ok) {
              saveCartCache(data);
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
  // 4. Quick-Add and Buy Now on Product Cards
  // -------------------------------------------------------------
  document.addEventListener('click', function (e) {
    const quickAddBtn = e.target.closest('[data-quick-add]');
    if (quickAddBtn) {
      e.preventDefault();
      e.stopPropagation();
      const pid = quickAddBtn.dataset.quickAdd;
      const originalText = quickAddBtn.innerHTML;
      quickAddBtn.disabled = true;
      quickAddBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', pid);
      fd.append('quantity', 1);

      fetch(`${baseUrl}api/cart_ajax.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          quickAddBtn.disabled = false;
          quickAddBtn.innerHTML = originalText;
          if (data.ok) {
            saveCartCache(data);
            updateCartBadge(data.cart_count);
            openCartDrawer();
          } else {
            alert(data.error || 'Unable to add product to cart.');
          }
        })
        .catch(() => {
          quickAddBtn.disabled = false;
          quickAddBtn.innerHTML = originalText;
        });
      return;
    }

    const buyNowBtn = e.target.closest('[data-buy-now]');
    if (buyNowBtn) {
      e.preventDefault();
      e.stopPropagation();
      const pid = buyNowBtn.dataset.buyNow;
      const originalText = buyNowBtn.innerHTML;
      buyNowBtn.disabled = true;
      buyNowBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

      const fd = new FormData();
      fd.append('action', 'add');
      fd.append('product_id', pid);
      fd.append('quantity', 1);

      const checkoutUrl = window.SHOP_CHECKOUT_URL || `${baseUrl}checkout`;

      fetch(`${baseUrl}api/cart_ajax.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.ok) {
            window.location.href = checkoutUrl;
          } else {
            buyNowBtn.disabled = false;
            buyNowBtn.innerHTML = originalText;
            alert(data.error || 'Unable to proceed to checkout.');
          }
        })
        .catch(() => {
          // Fallback form submit
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = `${baseUrl}cart`;
          form.innerHTML = `
            <input type="hidden" name="csrf_token" value="${window.SHOP_CSRF_TOKEN || ''}">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="${pid}">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="action_type" value="buy_now">
          `;
          document.body.appendChild(form);
          form.submit();
        });
      return;
    }
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

  // PDP AJAX Add-to-Cart & Buy Now
  const pdpForm = document.getElementById('pdpAddToCartForm');
  if (pdpForm) {
    let pdpSubmitter = null;
    pdpForm.querySelectorAll('button[type="submit"]').forEach(btn => {
      btn.addEventListener('click', function () {
        pdpSubmitter = this;
      });
    });

    pdpForm.addEventListener('submit', function (e) {
      const submitBtn = e.submitter || pdpSubmitter;
      const isBuyNow = submitBtn && (submitBtn.value === 'buy_now' || submitBtn.id === 'pdpBuyNowBtn');

      if (isBuyNow) {
        // Ensure action_type input exists and is set to buy_now for direct checkout redirect
        let actionTypeInput = pdpForm.querySelector('input[name="action_type"]');
        if (!actionTypeInput) {
          actionTypeInput = document.createElement('input');
          actionTypeInput.type = 'hidden';
          actionTypeInput.name = 'action_type';
          pdpForm.appendChild(actionTypeInput);
        }
        actionTypeInput.value = 'buy_now';
        return;
      }

      e.preventDefault();
      const addBtn = document.getElementById('pdpAddBtn');
      if (addBtn) addBtn.disabled = true;

      const fd = new FormData(pdpForm);
      fd.append('action', 'add');

      fetch(`${baseUrl}api/cart_ajax.php`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (addBtn) addBtn.disabled = false;
          if (data.ok) {
            saveCartCache(data);
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
        fetch(`${baseUrl}api/availability.php?ids=${otherPids.join(',')}`)
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

  // -------------------------------------------------------------
  // 8. Wishlist Heart Button & State Sync (Prompt 13)
  // -------------------------------------------------------------
  const wishlistButtons = document.querySelectorAll('[data-wishlist-btn]');
  if (wishlistButtons.length > 0 && window.CUSTOMER_LOGGED_IN) {
    // Initial fetch to mark saved items as active (only for logged-in customers)
    fetch(`${baseUrl}api/wishlist.php?action=list_ids`)
      .then(r => r.json())
      .then(data => {
        if (data.ok && Array.isArray(data.ids)) {
          const savedSet = new Set(data.ids);
          wishlistButtons.forEach(btn => {
            const pid = parseInt(btn.getAttribute('data-wishlist-btn'), 10);
            if (savedSet.has(pid)) {
              btn.classList.add('active');
            }
          });
        }
      })
      .catch(() => {});

    // Click handler with optimistic UI
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-wishlist-btn]');
      if (!btn) return;

      e.preventDefault();
      e.stopPropagation();

      const pid = parseInt(btn.getAttribute('data-wishlist-btn'), 10);
      if (!pid) return;

      const wasActive = btn.classList.contains('active');
      btn.classList.toggle('active');

      const csrf = window.SHOP_CSRF_TOKEN || '';
      fetch(`${baseUrl}api/wishlist.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-CSRF-Token': csrf
        },
        body: `action=toggle&product_id=${pid}&_csrf=${encodeURIComponent(csrf)}`
      })
      .then(res => res.json())
      .then(data => {
        if (!data.ok) {
          if (data.error === 'login_required' && data.login_url) {
            window.location.href = data.login_url;
            return;
          }
          if (wasActive) btn.classList.add('active');
          else btn.classList.remove('active');
        } else {
          if (data.in_wishlist) {
            btn.classList.add('active');
          } else {
            btn.classList.remove('active');
          }
        }
      })
      .catch(() => {
        if (wasActive) btn.classList.add('active');
        else btn.classList.remove('active');
      });
    });
  }

  // -------------------------------------------------------------
  // 9. Instant Page Prefetching on Hover/Touch (Eliminates Navigation Lag)
  // -------------------------------------------------------------
  const prefetchedUrls = new Set();
  const prefetchUrl = (url) => {
    if (!url || prefetchedUrls.has(url) || url.includes('/admin/') || url.includes('/auth/logout') || url.includes('#') || url.startsWith('javascript:')) return;
    prefetchedUrls.add(url);
    const link = document.createElement('link');
    link.rel = 'prefetch';
    link.href = url;
    document.head.appendChild(link);
  };

  document.addEventListener('pointerover', (e) => {
    const anchor = e.target.closest('a[href]');
    if (!anchor) return;
    const href = anchor.getAttribute('href');
    if (href && (href.startsWith('/') || href.startsWith(window.location.origin)) && !href.startsWith('//')) {
      prefetchUrl(anchor.href);
    }
  }, { passive: true });
});

