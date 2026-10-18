/**
 * Real-time Product Availability & Live Stock Module
 * Plain Vanilla JS, ETag polling, dynamic variant selection, anti-oversell.
 */

(function () {
  'use strict';

  const container = document.querySelector('[data-product-availability]');
  if (!container) return;

  const productId = parseInt(container.dataset.productId, 10);
  if (!productId) return;

  const pollIntervalSeconds = parseInt(container.dataset.pollSeconds, 10) || 10;
  const realtimeMode = container.dataset.realtimeMode || 'poll'; // 'poll' or 'sse'
  const baseUrl = (typeof window !== 'undefined' && window.SHOP_BASE_URL ? window.SHOP_BASE_URL : '/').replace(/\/?$/, '/');

  // DOM Elements
  const priceDisplay = document.getElementById('product-price-display') || document.getElementById('pdpPrice');
  const skuDisplay = document.getElementById('product-sku-display') || document.getElementById('pdpSku');
  const stockBadge = document.getElementById('product-stock-badge') || document.getElementById('stockStatusDot');
  const stockText = document.getElementById('product-stock-text') || document.getElementById('stockStatusText');
  const mainImage = document.getElementById('product-main-image') || document.getElementById('mainGalleryImg');
  const addToCartBtn = document.getElementById('add-to-cart-btn') || document.getElementById('pdpAddBtn');
  const buyNowBtn = document.getElementById('buy-now-btn') || document.getElementById('pdpBuyNowBtn');
  const qtyInput = document.getElementById('product-qty-input') || document.getElementById('pdpQtyInput');
  const variantIdInput = document.getElementById('selected-variant-id') || document.getElementById('selectedVariantId');
  const liveNotice = document.getElementById('availability-live-notice') || document.getElementById('justSoldOutAlert');
  const ariaAnnouncer = document.getElementById('availability-announcer');

  // State
  let productType = 'simple';
  let options = [];
  let variants = [];
  let selectedValues = {}; // { optionId: valueId }
  let currentVariant = null;
  let previousVariantStatus = null;
  let pollTimer = null;
  let pollBackoff = pollIntervalSeconds;
  let lastEtag = '';
  let eventSource = null;

  function announce(msg) {
    if (ariaAnnouncer) {
      ariaAnnouncer.textContent = msg;
    }
  }

  function formatMoney(amount) {
    const symbol = container.dataset.currencySymbol || '$';
    return symbol + parseFloat(amount).toFixed(2);
  }

  // -------------------------------------------------------------
  // Parse & Update State from Availability Response
  // -------------------------------------------------------------
  function handleAvailabilityData(data) {
    productType = data.type || 'simple';
    options = data.options || [];
    variants = data.variants || [];

    if (productType === 'variable') {
      // Ensure initial selection matches a valid existing variant
      if (Object.keys(selectedValues).length === 0 && options.length > 0) {
        if (variants.length > 0 && variants[0].key) {
          const parts = variants[0].key.split('-');
          options.forEach((opt, idx) => {
            if (parts[idx]) selectedValues[opt.id] = parseInt(parts[idx], 10);
          });
        } else {
          options.forEach(opt => {
            if (opt.values && opt.values.length > 0) {
              selectedValues[opt.id] = opt.values[0].id;
            }
          });
        }
        syncOptionSelectorsUI();
      }

      evaluateSelectedVariant();
      updateOptionsAvailabilityUI();
    } else {
      // Simple product
      evaluateSimpleProduct(data);
    }
  }

  // -------------------------------------------------------------
  // Simple Product Evaluation
  // -------------------------------------------------------------
  function evaluateSimpleProduct(data) {
    const status = data.status || 'in_stock';
    const isOut = (status === 'out_of_stock');
    const label = data.label_text || (isOut ? 'Out of stock' : 'In stock');

    if (stockBadge) {
      stockBadge.className = 'badge ' + (isOut ? 'badge-outline' : (status === 'low_stock' ? 'badge-low-stock' : 'badge-stock'));
      stockBadge.textContent = label;
    }
    if (stockText) {
      stockText.textContent = data.stock !== null && data.stock !== undefined ? `${data.stock} available` : 'Ready for fast dispatch';
    }

    if (addToCartBtn) {
      addToCartBtn.disabled = isOut;
      if (isOut) {
        addToCartBtn.innerHTML = `<svg class="i me-1" aria-hidden="true"><use href="${baseUrl}assets/img/icons.svg#x"></use></svg> Out of stock`;
      } else {
        addToCartBtn.innerHTML = `<svg class="i me-1" aria-hidden="true"><use href="${baseUrl}assets/img/icons.svg#bag"></use></svg> Add to Cart`;
      }
    }
    if (buyNowBtn) {
      buyNowBtn.disabled = isOut;
    }
    if (qtyInput && data.stock !== null) {
      qtyInput.max = data.stock;
    }
  }

  // -------------------------------------------------------------
  // Variable Product: Match Variant & React
  // -------------------------------------------------------------
  function evaluateSelectedVariant() {
    if (options.length === 0) return;

    // Build key: value IDs in option order
    const keyParts = options.map(opt => selectedValues[opt.id]);
    const currentKey = keyParts.join('-');

    const matched = variants.find(v => v.key === currentKey);
    const oldStatus = currentVariant ? currentVariant.status : null;
    currentVariant = matched || null;

    if (!matched) {
      // No matching variant
      if (variantIdInput) variantIdInput.value = '';
      if (stockBadge) {
        stockBadge.className = 'badge bg-secondary';
        stockBadge.textContent = 'Unavailable combination';
      }
      if (addToCartBtn) {
        addToCartBtn.disabled = true;
        addToCartBtn.innerHTML = '<i class="bi bi-dash-circle"></i> Unavailable';
      }
      if (buyNowBtn) buyNowBtn.disabled = true;
      return;
    }

    // Set hidden variant_id input for form submission
    if (variantIdInput) {
      variantIdInput.value = matched.id;
    }

    // Detect "Just sold out" transition
    if (oldStatus && oldStatus !== 'out_of_stock' && matched.status === 'out_of_stock') {
      if (liveNotice) {
        liveNotice.innerHTML = `
          <div class="alert alert-warning alert-dismissible fade show py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>Just sold out!</strong> This variant was just purchased and is no longer available.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        `;
      }
      announce('Just sold out! This item is no longer available.');
    }

    // Price Update
    if (priceDisplay) {
      if (matched.sale_price !== null && parseFloat(matched.sale_price) > 0) {
        priceDisplay.innerHTML = `
          <span class="text-success fw-bold fs-4">${formatMoney(matched.sale_price)}</span>
          <span class="text-muted text-decoration-line-through fs-5 ms-2">${formatMoney(matched.price)}</span>
        `;
      } else {
        priceDisplay.innerHTML = `<span class="fw-bold fs-4">${formatMoney(matched.price)}</span>`;
      }
    }

    // SKU Update
    if (skuDisplay && matched.sku) {
      skuDisplay.textContent = matched.sku;
    }

    // Main Image Update
    if (mainImage && matched.image) {
      const currentSrc = mainImage.getAttribute('src');
      const uploadsBase = container.dataset.uploadsUrl || 'assets/uploads/products/';
      const targetSrc = uploadsBase + matched.image;
      if (currentSrc !== targetSrc) {
        mainImage.setAttribute('src', targetSrc);
      }
    }

    // Stock Badge & Label Update
    const isOutOfStock = (matched.status === 'out_of_stock');
    const isBackorder = (matched.status === 'backorder');
    const isLow = (matched.status === 'low_stock');

    if (stockBadge) {
      if (isOutOfStock) {
        stockBadge.className = 'badge badge-outline';
        stockBadge.textContent = 'Out of stock';
      } else if (isBackorder) {
        stockBadge.className = 'badge badge-low-stock';
        stockBadge.textContent = matched.label_text || 'Backorder';
      } else if (isLow) {
        stockBadge.className = 'badge badge-low-stock';
        stockBadge.textContent = matched.label_text || 'Low stock';
      } else {
        stockBadge.className = 'badge badge-stock';
        stockBadge.textContent = matched.label_text || '● In Stock';
      }
    }

    if (stockText) {
      stockText.textContent = (matched.stock !== null && matched.stock !== undefined) ? `${matched.stock} available` : '';
    }

    // Add to Cart Button States
    if (addToCartBtn) {
      if (isOutOfStock) {
        addToCartBtn.disabled = true;
        addToCartBtn.innerHTML = `<svg class="i me-1" aria-hidden="true"><use href="${baseUrl}assets/img/icons.svg#x"></use></svg> Out of stock`;
      } else if (isBackorder) {
        addToCartBtn.disabled = false;
        addToCartBtn.innerHTML = `<svg class="i me-1" aria-hidden="true"><use href="${baseUrl}assets/img/icons.svg#bag"></use></svg> Pre-order`;
      } else {
        addToCartBtn.disabled = false;
        addToCartBtn.innerHTML = `<svg class="i me-1" aria-hidden="true"><use href="${baseUrl}assets/img/icons.svg#bag"></use></svg> Add to Cart`;
      }
    }

    if (buyNowBtn) {
      buyNowBtn.disabled = isOutOfStock;
    }

    // Quantity Input Bounds
    if (qtyInput) {
      if (isOutOfStock) {
        qtyInput.disabled = true;
        qtyInput.value = 1;
      } else {
        qtyInput.disabled = false;
        if (matched.stock !== null && !isBackorder) {
          qtyInput.max = matched.stock;
          if (parseInt(qtyInput.value, 10) > matched.stock) {
            qtyInput.value = Math.max(1, matched.stock);
          }
        } else {
          qtyInput.removeAttribute('max');
        }
      }
    }

    announce(`${matched.label_text || matched.status}, price: ${matched.sale_price ? formatMoney(matched.sale_price) : formatMoney(matched.price)}`);
  }

  // -------------------------------------------------------------
  // Smart Option Button Availability (Dim/Strike Unavailable Combos)
  // -------------------------------------------------------------
  function updateOptionsAvailabilityUI() {
    options.forEach(opt => {
      const optId = opt.id;
      (opt.values || []).forEach(val => {
        const valId = val.id;
        // Check if ANY variant has this value and matches currently selected other options
        const hasAvailableCombo = variants.some(v => {
          if (v.status === 'out_of_stock') return false;
          // Must match this option's value
          if (v.key.indexOf(String(valId)) === -1) return false;
          // Must match other currently selected option values
          for (const otherOpt of options) {
            if (otherOpt.id !== optId) {
              const selectedValId = selectedValues[otherOpt.id];
              if (selectedValId && v.key.indexOf(String(selectedValId)) === -1) {
                return false;
              }
            }
          }
          return true;
        });

        // Update button/swatch attribute
        const btn = document.querySelector(`.option-btn[data-option-id="${optId}"][data-value-id="${valId}"]`);
        if (btn) {
          if (!hasAvailableCombo) {
            btn.classList.add('is-unavailable');
            btn.setAttribute('title', 'Currently unavailable combination');
          } else {
            btn.classList.remove('is-unavailable');
            btn.removeAttribute('title');
          }
        }
      });
    });
  }

  // -------------------------------------------------------------
  // UI Selectors Synchronization
  // -------------------------------------------------------------
  function syncOptionSelectorsUI() {
    document.querySelectorAll('.option-btn').forEach(btn => {
      const optId = parseInt(btn.dataset.optionId, 10);
      const valId = parseInt(btn.dataset.valueId, 10);
      if (selectedValues[optId] === valId) {
        btn.classList.add('active');
        btn.setAttribute('aria-checked', 'true');
      } else {
        btn.classList.remove('active');
        btn.setAttribute('aria-checked', 'false');
      }
    });

    document.querySelectorAll('.option-select').forEach(sel => {
      const optId = parseInt(sel.dataset.optionId, 10);
      if (selectedValues[optId]) {
        sel.value = selectedValues[optId];
      }
    });
  }

  // Bind clicks on option buttons/swatches
  document.querySelectorAll('.option-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const optId = parseInt(this.dataset.optionId, 10);
      const valId = parseInt(this.dataset.valueId, 10);
      selectedValues[optId] = valId;
      syncOptionSelectorsUI();
      evaluateSelectedVariant();
      updateOptionsAvailabilityUI();
    });
  });

  // Bind changes on option dropdowns
  document.querySelectorAll('.option-select').forEach(sel => {
    sel.addEventListener('change', function () {
      const optId = parseInt(this.dataset.optionId, 10);
      const valId = parseInt(this.value, 10);
      selectedValues[optId] = valId;
      syncOptionSelectorsUI();
      evaluateSelectedVariant();
      updateOptionsAvailabilityUI();
    });
  });

  // -------------------------------------------------------------
  // HTTP Availability Fetch (with ETag & 304 Caching)
  // -------------------------------------------------------------
  function fetchAvailability() {
    const headers = { 'Accept': 'application/json' };
    if (lastEtag) {
      headers['If-None-Match'] = lastEtag;
    }

    const apiUrl = `${baseUrl}api/availability.php?product_id=${productId}&_t=${Date.now()}`;

    fetch(apiUrl, { headers })
      .then(response => {
        if (response.status === 304) {
          // Data unmodified; reset backoff
          pollBackoff = pollIntervalSeconds;
          return null;
        }

        const etag = response.headers.get('ETag');
        if (etag) lastEtag = etag;

        if (response.status === 404) {
          // Check if it is JSON response from API vs accidental HTML 404
          const contentType = response.headers.get('Content-Type') || '';
          if (contentType.includes('application/json')) {
            if (liveNotice) {
              liveNotice.innerHTML = `
                <div class="alert alert-danger py-2 mb-3">
                  This product is no longer available.
                </div>
              `;
            }
            if (addToCartBtn) addToCartBtn.disabled = true;
            if (buyNowBtn) buyNowBtn.disabled = true;
            stopPolling();
          }
          return null;
        }

        if (!response.ok) {
          throw new Error(`HTTP error ${response.status}`);
        }

        pollBackoff = pollIntervalSeconds;
        return response.json();
      })
      .then(data => {
        if (data) {
          handleAvailabilityData(data);
        }
      })
      .catch(err => {
        // Exponential backoff up to 60s
        pollBackoff = Math.min(60, pollBackoff * 1.5);
      });
  }

  // -------------------------------------------------------------
  // Polling Scheduler & Visibility Handler
  // -------------------------------------------------------------
  function scheduleNextPoll() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(() => {
      if (!document.hidden) {
        fetchAvailability();
      }
      scheduleNextPoll();
    }, pollBackoff * 1000);
  }

  function stopPolling() {
    clearTimeout(pollTimer);
  }

  // Pause when tab is hidden; immediately resume + fetch on focus
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stopPolling();
    } else {
      fetchAvailability();
      scheduleNextPoll();
    }
  });

  // -------------------------------------------------------------
  // Optional SSE Connection
  // -------------------------------------------------------------
  function initSSE() {
    if (typeof EventSource === 'undefined') {
      scheduleNextPoll();
      return;
    }

    eventSource = new EventSource(`${baseUrl}api/availability_stream.php?product_id=${productId}`);

    eventSource.addEventListener('stock', function (e) {
      try {
        const payload = JSON.parse(e.data);
        if (payload && payload.variants) {
          variants = payload.variants;
          evaluateSelectedVariant();
          updateOptionsAvailabilityUI();
        }
      } catch (err) {}
    });

    eventSource.addEventListener('reconnect', function () {
      eventSource.close();
      setTimeout(initSSE, 1000);
    });

    eventSource.onerror = function () {
      eventSource.close();
      // Fallback to regular polling on error
      scheduleNextPoll();
    };
  }

  // -------------------------------------------------------------
  // Initial Boot (Non-blocking during initial render)
  // -------------------------------------------------------------
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(() => {
      fetchAvailability();
      if (realtimeMode === 'sse') initSSE(); else scheduleNextPoll();
    }, { timeout: 1500 });
  } else {
    setTimeout(() => {
      fetchAvailability();
      if (realtimeMode === 'sse') initSSE(); else scheduleNextPoll();
    }, 300);
  }

})();
