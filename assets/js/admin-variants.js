/**
 * Admin Product Variants & Options Builder
 * Bootstrap 5 + Vanilla JS
 */

(function () {
  'use strict';

  // State
  let options = [];
  let variants = [];
  let presets = [];
  let galleryImages = [];

  // DOM Elements
  const typeSimple = document.getElementById('type_simple');
  const typeVariable = document.getElementById('type_variable');
  const simpleStockWrapper = document.getElementById('simple_stock_wrapper');
  const basePriceHint = document.getElementById('base_price_hint');
  const optionsBuilderCard = document.getElementById('options_builder_card');
  const variantsCard = document.getElementById('variants_card');
  const optionsContainer = document.getElementById('options_container');
  const addOptionBtn = document.getElementById('add_option_btn');
  const generateCombosBtn = document.getElementById('generate_combos_btn');
  const variantsTbody = document.getElementById('variants_tbody');
  const variantsCountBadge = document.getElementById('variants_count_badge');
  const optionsJsonInput = document.getElementById('options_json');
  const variantsJsonInput = document.getElementById('variants_json');
  const productForm = document.getElementById('product_form');
  const baseSkuInput = document.getElementById('sku');
  const basePriceInput = document.getElementById('price');
  const baseSalePriceInput = document.getElementById('sale_price');
  const presetsDatalist = document.getElementById('presets_datalist');

  // Load Presets from server
  function loadPresets() {
    fetch('ajax/option_presets.php?action=list')
      .then(r => r.json())
      .then(data => {
        if (data.ok && Array.isArray(data.presets)) {
          presets = data.presets;
          if (presetsDatalist) {
            presetsDatalist.innerHTML = presets
              .map(p => `<option value="${escapeHtml(p.name)}">`)
              .join('');
          }
        }
      })
      .catch(err => console.error('Failed to load presets:', err));
  }

  // Escape HTML helper
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Hash option values (md5 equivalent client-side sort for matching)
  function computeHash(optValIds) {
    const sorted = [...optValIds].map(n => parseInt(n, 10)).sort((a, b) => a - b);
    return sorted.join('-');
  }

  // Toggle UI according to Product Type
  function updateProductTypeUI() {
    const isVariable = typeVariable && typeVariable.checked;
    if (simpleStockWrapper) {
      simpleStockWrapper.style.display = isVariable ? 'none' : 'block';
    }
    if (basePriceHint) {
      basePriceHint.style.display = isVariable ? 'block' : 'none';
    }
    if (optionsBuilderCard) {
      optionsBuilderCard.style.display = isVariable ? 'block' : 'none';
    }
    if (variantsCard) {
      variantsCard.style.display = isVariable ? 'block' : 'none';
    }
  }

  if (typeSimple) typeSimple.addEventListener('change', updateProductTypeUI);
  if (typeVariable) typeVariable.addEventListener('change', updateProductTypeUI);

  // -------------------------------------------------------------
  // Options Builder
  // -------------------------------------------------------------
  function renderOptions() {
    if (!optionsContainer) return;
    optionsContainer.innerHTML = '';

    if (options.length === 0) {
      optionsContainer.innerHTML = `
        <div class="text-center py-4 text-muted border rounded bg-light">
          <i class="bi bi-diagram-3 fs-2 d-block mb-2 text-secondary"></i>
          No options defined yet. Click <strong>"Add option"</strong> (e.g. Size, Color) to get started.
        </div>
      `;
      if (addOptionBtn) addOptionBtn.disabled = false;
      syncJsonInputs();
      return;
    }

    if (addOptionBtn) {
      addOptionBtn.disabled = options.length >= 3;
    }

    options.forEach((opt, optIndex) => {
      const isSwatch = opt.display_type === 'swatch';
      const optCard = document.createElement('div');
      optCard.className = 'card mb-3 border option-item-card shadow-sm';
      optCard.dataset.index = optIndex;

      optCard.innerHTML = `
        <div class="card-header d-flex justify-content-between align-items-center py-2 bg-body-tertiary">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-indigo">Option ${optIndex + 1}</span>
            <strong>${escapeHtml(opt.name || 'Untitled')}</strong>
            <span class="badge bg-secondary text-capitalize">${escapeHtml(opt.display_type)}</span>
          </div>
          <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary opt-move-up" ${optIndex === 0 ? 'disabled' : ''} title="Move Up">
              <i class="bi bi-arrow-up"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary opt-move-down" ${optIndex === options.length - 1 ? 'disabled' : ''} title="Move Down">
              <i class="bi bi-arrow-down"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger opt-delete" title="Delete Option">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-md-5">
              <label class="form-label small fw-semibold">Option name</label>
              <input type="text" class="form-control opt-name-input" list="presets_datalist" value="${escapeHtml(opt.name)}" placeholder="e.g. Size, Color, Storage" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Display type</label>
              <select class="form-select opt-type-select">
                <option value="button" ${opt.display_type === 'button' ? 'selected' : ''}>Buttons</option>
                <option value="dropdown" ${opt.display_type === 'dropdown' ? 'selected' : ''}>Dropdown</option>
                <option value="swatch" ${opt.display_type === 'swatch' ? 'selected' : ''}>Color swatch</option>
                <option value="radio" ${opt.display_type === 'radio' ? 'selected' : ''}>Radio</option>
              </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <button type="button" class="btn btn-outline-primary w-100 opt-load-preset" title="Fill values from existing presets">
                <i class="bi bi-magic"></i> Load preset
              </button>
            </div>
          </div>

          <label class="form-label small fw-semibold">Option values (type and press Enter or comma)</label>
          <div class="values-tag-container d-flex flex-wrap align-items-center gap-2 p-2 border rounded bg-white">
            ${(opt.values || []).map((val, valIndex) => `
              <span class="badge border text-dark d-flex align-items-center gap-1 p-2 bg-light val-badge" data-val-index="${valIndex}">
                ${isSwatch ? `
                  <input type="color" class="form-control form-control-color p-0 border-0 val-color-picker"
                         value="${val.swatch_hex || '#3b82f6'}" style="width:20px;height:20px;cursor:pointer;" title="Pick color">
                ` : ''}
                <span class="val-text">${escapeHtml(val.value)}</span>
                <button type="button" class="btn-close btn-close-sm val-remove-btn" aria-label="Remove" style="font-size:0.65rem;"></button>
              </span>
            `).join('')}
            <input type="text" class="border-0 flex-grow-1 val-input" placeholder="+ Add value..." style="min-width:120px; outline:none;">
          </div>
          <div class="form-text small text-muted">Press <kbd>Enter</kbd> or <kbd>,</kbd> to add each value.</div>
        </div>
      `;

      // Event Listeners for Option Item
      const nameInput = optCard.querySelector('.opt-name-input');
      nameInput.addEventListener('change', (e) => {
        opt.name = e.target.value.trim();
        renderOptions();
      });

      const typeSelect = optCard.querySelector('.opt-type-select');
      typeSelect.addEventListener('change', (e) => {
        opt.display_type = e.target.value;
        renderOptions();
      });

      const loadPresetBtn = optCard.querySelector('.opt-load-preset');
      loadPresetBtn.addEventListener('click', () => {
        const found = presets.find(p => p.name.toLowerCase() === (opt.name || '').toLowerCase());
        if (found && found.values) {
          opt.display_type = found.display_type;
          opt.values = found.values.map(v => ({
            id: null,
            value: v.value,
            swatch_hex: v.swatch_hex || (found.display_type === 'swatch' ? '#000000' : null)
          }));
          renderOptions();
        } else {
          alert(`No presets found matching "${opt.name}". Check presets in admin settings.`);
        }
      });

      const moveUpBtn = optCard.querySelector('.opt-move-up');
      moveUpBtn.addEventListener('click', () => {
        if (optIndex > 0) {
          const temp = options[optIndex - 1];
          options[optIndex - 1] = options[optIndex];
          options[optIndex] = temp;
          renderOptions();
        }
      });

      const moveDownBtn = optCard.querySelector('.opt-move-down');
      moveDownBtn.addEventListener('click', () => {
        if (optIndex < options.length - 1) {
          const temp = options[optIndex + 1];
          options[optIndex + 1] = options[optIndex];
          options[optIndex] = temp;
          renderOptions();
        }
      });

      const deleteBtn = optCard.querySelector('.opt-delete');
      deleteBtn.addEventListener('click', () => {
        if (confirm(`Delete option "${opt.name}"? Any matching variant combinations will be regenerated.`)) {
          options.splice(optIndex, 1);
          renderOptions();
        }
      });

      // Tag input for values
      const valInput = optCard.querySelector('.val-input');
      const addTag = (valStr) => {
        valStr = valStr.trim();
        if (!valStr) return;
        // Check case-insensitive duplicate
        const exists = (opt.values || []).some(v => v.value.toLowerCase() === valStr.toLowerCase());
        if (exists) {
          alert(`Value "${valStr}" already exists for option "${opt.name}".`);
          return;
        }
        opt.values = opt.values || [];
        opt.values.push({
          id: null,
          value: valStr,
          swatch_hex: opt.display_type === 'swatch' ? '#111111' : null
        });
        valInput.value = '';
        renderOptions();
      };

      valInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ',') {
          e.preventDefault();
          addTag(valInput.value);
        }
      });

      valInput.addEventListener('blur', () => {
        if (valInput.value.trim()) {
          addTag(valInput.value);
        }
      });

      // Remove tag buttons
      optCard.querySelectorAll('.val-remove-btn').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          const badge = e.target.closest('.val-badge');
          const valIdx = parseInt(badge.dataset.valIndex, 10);
          opt.values.splice(valIdx, 1);
          renderOptions();
        });
      });

      // Color picker for swatch
      optCard.querySelectorAll('.val-color-picker').forEach((picker) => {
        picker.addEventListener('change', (e) => {
          const badge = e.target.closest('.val-badge');
          const valIdx = parseInt(badge.dataset.valIndex, 10);
          if (opt.values[valIdx]) {
            opt.values[valIdx].swatch_hex = e.target.value;
            syncJsonInputs();
          }
        });
      });

      optionsContainer.appendChild(optCard);
    });

    syncJsonInputs();
  }

  if (addOptionBtn) {
    addOptionBtn.addEventListener('click', () => {
      if (options.length >= 3) {
        alert('You can add up to 3 options (e.g. Size, Color, Material).');
        return;
      }
      options.push({
        id: null,
        name: '',
        display_type: 'button',
        values: []
      });
      renderOptions();
    });
  }

  // -------------------------------------------------------------
  // Combinations & Variants Generator (Cartesian Product)
  // -------------------------------------------------------------
  function generateCartesian(optArray) {
    if (!optArray || optArray.length === 0) return [];
    const validOpts = optArray.filter(o => o.values && o.values.length > 0);
    if (validOpts.length === 0) return [];

    let totalCombos = 1;
    validOpts.forEach(o => { totalCombos *= o.values.length; });
    if (totalCombos > 100) {
      alert(`Warning: This generates ${totalCombos} combinations, which exceeds the limit of 100. Please reduce option values.`);
      return null;
    }

    let results = [[]];
    for (const opt of validOpts) {
      const current = [];
      for (const res of results) {
        for (const val of opt.values) {
          current.push([...res, {
            option_id: opt.id,
            option_name: opt.name,
            value_id: val.id,
            value: val.value,
            swatch_hex: val.swatch_hex
          }]);
        }
      }
      results = current;
    }
    return results;
  }

  function generateCombinations() {
    const combos = generateCartesian(options);
    if (!combos) return;

    if (combos.length === 0) {
      alert('Please define at least one option with values before generating variants.');
      return;
    }

    const baseSku = (baseSkuInput ? baseSkuInput.value.trim() : '') || 'PROD';
    const basePrice = basePriceInput ? basePriceInput.value : '';
    const baseSale = baseSalePriceInput ? baseSalePriceInput.value : '';

    const newVariants = [];
    const existingByLabel = new Map();
    variants.forEach(v => {
      if (v.label) existingByLabel.set(v.label.toLowerCase().trim(), v);
    });

    combos.forEach((comboList, idx) => {
      const label = comboList.map(c => `${c.option_name}: ${c.value}`).join(' / ');
      const labelKey = label.toLowerCase().trim();
      const existing = existingByLabel.get(labelKey);

      // Auto SKU suggestion: BASE-SIZE-COLOR
      const skuSuffix = comboList.map(c => c.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase()).join('-');
      const defaultSku = `${baseSku}-${skuSuffix}`;

      if (existing) {
        // Retain existing data
        newVariants.push({
          ...existing,
          position: idx + 1,
          combo: comboList,
          label: label
        });
      } else {
        // Create new combo
        newVariants.push({
          id: null,
          label: label,
          sku: defaultSku,
          price: '',
          sale_price: '',
          stock: 0,
          weight_g: '',
          image: null,
          status: 'active',
          barcode: '',
          position: idx + 1,
          combo: comboList
        });
      }
    });

    variants = newVariants;
    renderVariants();
  }

  if (generateCombosBtn) {
    generateCombosBtn.addEventListener('click', generateCombinations);
  }

  // -------------------------------------------------------------
  // Render Variants Table
  // -------------------------------------------------------------
  function renderVariants() {
    if (!variantsTbody) return;
    variantsTbody.innerHTML = '';

    if (variantsCountBadge) {
      variantsCountBadge.textContent = `${variants.length} variant${variants.length === 1 ? '' : 's'}`;
    }

    if (variants.length === 0) {
      variantsTbody.innerHTML = `
        <tr>
          <td colspan="9" class="text-center text-muted py-4">
            No variants generated yet. Define options above and click <strong>"Generate combinations"</strong>.
          </td>
        </tr>
      `;
      syncJsonInputs();
      return;
    }

    const basePrice = basePriceInput ? basePriceInput.value : '0.00';
    const baseSale = baseSalePriceInput ? baseSalePriceInput.value : '';

    variants.forEach((v, vIndex) => {
      const tr = document.createElement('tr');
      tr.className = `variant-row ${v.status === 'inactive' ? 'table-light text-muted' : ''}`;
      tr.dataset.index = vIndex;

      tr.innerHTML = `
        <td class="text-center" style="width:40px;">
          <input type="checkbox" class="form-check-input var-select-checkbox" data-index="${vIndex}">
        </td>
        <td style="width:50px;" class="text-center">
          <div class="form-check form-switch m-0 d-inline-block">
            <input class="form-check-input var-active-switch" type="checkbox" ${v.status === 'active' ? 'checked' : ''} title="Active / Inactive">
          </div>
        </td>
        <td>
          <div class="fw-semibold">${escapeHtml(v.label)}</div>
          <small class="text-muted">${v.id ? `ID #${v.id}` : '<span class="badge bg-success-subtle text-success">New</span>'}</small>
        </td>
        <td style="min-width:130px;">
          <input type="text" class="form-control form-control-sm var-sku" value="${escapeHtml(v.sku || '')}" placeholder="SKU">
        </td>
        <td style="min-width:110px;">
          <input type="number" step="0.01" min="0" class="form-control form-control-sm var-price" value="${escapeHtml(v.price || '')}" placeholder="${escapeHtml(basePrice)}">
        </td>
        <td style="min-width:110px;">
          <input type="number" step="0.01" min="0" class="form-control form-control-sm var-sale-price" value="${escapeHtml(v.sale_price || '')}" placeholder="${escapeHtml(baseSale || '—')}">
        </td>
        <td style="min-width:90px;">
          <input type="number" min="0" class="form-control form-control-sm var-stock fw-bold" value="${parseInt(v.stock, 10) || 0}" required>
        </td>
        <td style="min-width:90px;">
          <input type="number" min="0" class="form-control form-control-sm var-weight" value="${escapeHtml(v.weight_g || '')}" placeholder="g">
        </td>
        <td style="min-width:120px;">
          <select class="form-select form-select-sm var-image-select">
            <option value="">No image</option>
            ${galleryImages.map(img => `
              <option value="${escapeHtml(img.filename)}" ${v.image === img.filename ? 'selected' : ''}>
                ${escapeHtml(img.filename.substring(0, 16))}...
              </option>
            `).join('')}
          </select>
        </td>
      `;

      // Event handlers for variant row inputs
      tr.querySelector('.var-active-switch').addEventListener('change', (e) => {
        v.status = e.target.checked ? 'active' : 'inactive';
        tr.className = `variant-row ${v.status === 'inactive' ? 'table-light text-muted' : ''}`;
        syncJsonInputs();
      });

      tr.querySelector('.var-sku').addEventListener('input', (e) => {
        v.sku = e.target.value.trim();
        syncJsonInputs();
      });

      tr.querySelector('.var-price').addEventListener('input', (e) => {
        v.price = e.target.value.trim();
        syncJsonInputs();
      });

      tr.querySelector('.var-sale-price').addEventListener('input', (e) => {
        v.sale_price = e.target.value.trim();
        syncJsonInputs();
      });

      tr.querySelector('.var-stock').addEventListener('input', (e) => {
        v.stock = Math.max(0, parseInt(e.target.value, 10) || 0);
        syncJsonInputs();
      });

      tr.querySelector('.var-weight').addEventListener('input', (e) => {
        v.weight_g = e.target.value.trim();
        syncJsonInputs();
      });

      tr.querySelector('.var-image-select').addEventListener('change', (e) => {
        v.image = e.target.value || null;
        syncJsonInputs();
      });

      variantsTbody.appendChild(tr);
    });

    syncJsonInputs();
  }

  // -------------------------------------------------------------
  // Bulk Edit Actions
  // -------------------------------------------------------------
  const selectAllCheckbox = document.getElementById('select_all_variants');
  if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', (e) => {
      const checked = e.target.checked;
      document.querySelectorAll('.var-select-checkbox').forEach(cb => {
        cb.checked = checked;
      });
    });
  }

  function getSelectedVariants() {
    const selectedIndices = [];
    document.querySelectorAll('.var-select-checkbox:checked').forEach(cb => {
      selectedIndices.push(parseInt(cb.dataset.index, 10));
    });
    // If none explicitly checked, apply to all variants
    if (selectedIndices.length === 0) {
      return variants.map((_, i) => i);
    }
    return selectedIndices;
  }

  const bulkSetPriceBtn = document.getElementById('bulk_set_price_btn');
  const bulkPriceInput = document.getElementById('bulk_price_input');
  if (bulkSetPriceBtn && bulkPriceInput) {
    bulkSetPriceBtn.addEventListener('click', () => {
      const val = bulkPriceInput.value.trim();
      const targets = getSelectedVariants();
      targets.forEach(idx => { if (variants[idx]) variants[idx].price = val; });
      renderVariants();
    });
  }

  const bulkSetStockBtn = document.getElementById('bulk_set_stock_btn');
  const bulkStockInput = document.getElementById('bulk_stock_input');
  if (bulkSetStockBtn && bulkStockInput) {
    bulkSetStockBtn.addEventListener('click', () => {
      const val = Math.max(0, parseInt(bulkStockInput.value, 10) || 0);
      const targets = getSelectedVariants();
      targets.forEach(idx => { if (variants[idx]) variants[idx].stock = val; });
      renderVariants();
    });
  }

  const bulkAddStockBtn = document.getElementById('bulk_add_10_stock_btn');
  if (bulkAddStockBtn) {
    bulkAddStockBtn.addEventListener('click', () => {
      const targets = getSelectedVariants();
      targets.forEach(idx => {
        if (variants[idx]) {
          variants[idx].stock = (parseInt(variants[idx].stock, 10) || 0) + 10;
        }
      });
      renderVariants();
    });
  }

  // -------------------------------------------------------------
  // Synchronize Hidden JSON Inputs
  // -------------------------------------------------------------
  function syncJsonInputs() {
    if (optionsJsonInput) {
      optionsJsonInput.value = JSON.stringify(options);
    }
    if (variantsJsonInput) {
      variantsJsonInput.value = JSON.stringify(variants);
    }
  }

  // -------------------------------------------------------------
  // Form Submit Validation
  // -------------------------------------------------------------
  if (productForm) {
    productForm.addEventListener('submit', (e) => {
      syncJsonInputs();

      if (typeVariable && typeVariable.checked) {
        if (options.length === 0) {
          e.preventDefault();
          alert('You have chosen "Product with options". Please define at least one option or switch to "Simple product".');
          return;
        }
        if (variants.length === 0) {
          e.preventDefault();
          alert('Please click "Generate combinations" to create product variants before saving.');
          return;
        }

        // Validate SKU uniqueness across variants
        const skuSet = new Set();
        for (const v of variants) {
          if (v.sku) {
            const lowerSku = v.sku.toLowerCase();
            if (skuSet.has(lowerSku)) {
              e.preventDefault();
              alert(`Duplicate SKU "${v.sku}" found across variants. Each variant SKU must be unique.`);
              return;
            }
            skuSet.add(lowerSku);
          }
        }
      }
    });
  }

  // -------------------------------------------------------------
  // Initialization from Window Data
  // -------------------------------------------------------------
  window.initProductVariantsBuilder = function (initialOptions, initialVariants, initialGallery) {
    options = Array.isArray(initialOptions) ? initialOptions : [];
    variants = Array.isArray(initialVariants) ? initialVariants : [];
    galleryImages = Array.isArray(initialGallery) ? initialGallery : [];

    loadPresets();
    updateProductTypeUI();
    renderOptions();
    renderVariants();
  };

})();
