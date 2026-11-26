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
  // Options Builder & Color Helpers
  // -------------------------------------------------------------
  const COLOR_PALETTE = {
    'black': '#000000',
    'white': '#ffffff',
    'red': '#ef4444',
    'blue': '#3b82f6',
    'navy': '#1e3a8a',
    'green': '#10b981',
    'dark green': '#065f46',
    'yellow': '#eab308',
    'orange': '#f97316',
    'purple': '#a855f7',
    'pink': '#ec4899',
    'rose gold': '#fb7185',
    'gray': '#6b7280',
    'grey': '#6b7280',
    'silver': '#e2e8f0',
    'gold': '#eab308',
    'brown': '#78350f',
    'beige': '#f5f5dc',
    'teal': '#14b8a6',
    'cyan': '#06b6d4',
    'violet': '#8b5cf6',
    'lavender': '#c084fc',
    'midnight green': '#14532d',
    'space gray': '#4b5563',
    'graphite': '#374151',
    'sierra blue': '#93c5fd',
    'deep purple': '#581c87',
    'starlight': '#fafaf9'
  };

  function colorNameToHex(name) {
    if (!name) return null;
    const clean = name.toLowerCase().trim();
    if (COLOR_PALETTE[clean]) return COLOR_PALETTE[clean];
    for (const [k, v] of Object.entries(COLOR_PALETTE)) {
      if (clean.includes(k)) return v;
    }
    return '#3b82f6';
  }

  function renderOptions(focusOptIndex = null) {
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
              <input type="text" class="form-control opt-name-input" list="presets_datalist" value="${escapeHtml(opt.name)}" placeholder="e.g. Model, Size, Color" required>
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

          <label class="form-label small fw-semibold">Option values (type or paste multiple values separated by commas)</label>
          <div class="values-tag-container d-flex flex-wrap align-items-center gap-2 p-2 border rounded bg-white">
            ${(opt.values || []).map((val, valIndex) => {
              const valHex = val.swatch_hex || colorNameToHex(val.value) || '#3b82f6';
              const isWhite = ['#ffffff', '#fff', '#fafafa', '#f8fafc'].includes(valHex.toLowerCase());
              return `
              <span class="badge border text-dark d-inline-flex align-items-center gap-1 p-2 bg-light val-badge position-relative" data-val-index="${valIndex}">
                ${isSwatch ? `
                  <span class="val-swatch-circle rounded-circle shadow-xs me-1 position-relative"
                        style="width:22px;height:22px;background-color:${valHex};border:1.5px solid ${isWhite ? '#94a3b8' : 'rgba(0,0,0,0.25)'};display:inline-block;flex-shrink:0;cursor:pointer;overflow:hidden;"
                        title="Click to change color (${valHex})">
                    <input type="color" class="val-color-picker"
                           value="${valHex}"
                           style="position:absolute;top:-4px;left:-4px;width:32px;height:32px;opacity:0.01;cursor:pointer;padding:0;border:0;"
                           title="Change color (${escapeHtml(val.value)})">
                  </span>
                ` : ''}
                <span class="val-text fw-medium">${escapeHtml(val.value)}</span>
                <button type="button" class="btn-close btn-close-sm val-remove-btn ms-1" aria-label="Remove" style="font-size:0.65rem;"></button>
              </span>
            `;}).join('')}
            <input type="text" class="border-0 flex-grow-1 val-input" placeholder="+ Add value (or paste multiple, comma-separated)..." style="min-width:160px; outline:none;">
          </div>
          ${isSwatch ? `
            <!-- Manual Color Insertion Panel -->
            <div class="card mt-2 border-primary-subtle bg-light-subtle shadow-xs opt-manual-color-box">
              <div class="card-body p-2 d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle d-flex align-items-center gap-1">
                  <i class="bi bi-eyedropper"></i> Add Custom Color
                </span>
                <div class="input-group input-group-sm" style="max-width: 250px;">
                  <span class="input-group-text"><i class="bi bi-tag"></i></span>
                  <input type="text" class="form-control opt-manual-title" placeholder="Color name (e.g. Midnight Green)" aria-label="Color title">
                </div>
                <div class="d-flex align-items-center gap-1">
                  <input type="color" class="form-control form-control-color form-control-sm p-1 opt-manual-picker" value="#10b981" title="Choose color" style="width: 38px; height: 31px; cursor: pointer;">
                  <input type="text" class="form-control form-control-sm opt-manual-hex" value="#10b981" placeholder="#10b981" style="width: 82px; font-family: monospace; text-transform: uppercase;">
                </div>
                <button type="button" class="btn btn-sm btn-primary d-flex align-items-center gap-1 opt-manual-add-btn">
                  <i class="bi bi-plus-circle"></i> Add Color
                </button>
              </div>
            </div>

            <div class="d-flex align-items-center flex-wrap gap-1 mt-2 p-2 bg-light rounded border">
              <small class="text-muted fw-semibold me-1"><i class="bi bi-palette"></i> Quick Presets:</small>
              ${[
                'Black', 'White', 'Midnight Green', 'Deep Purple', 'Navy', 'Royal Blue', 'Sierra Blue',
                'Green', 'Rose Gold', 'Pink', 'Gold', 'Silver', 'Space Gray', 'Red', 'Yellow', 'Orange'
              ].map(c => `
                <button type="button" class="btn btn-sm p-0 rounded-circle border opt-preset-color"
                        data-color-name="${c}" data-hex="${COLOR_PALETTE[c.toLowerCase()] || '#000000'}"
                        style="width:22px;height:22px;background-color:${COLOR_PALETTE[c.toLowerCase()] || '#000000'};cursor:pointer;border:1px solid rgba(0,0,0,0.2) !important;"
                        title="Add ${c}"></button>
              `).join('')}
            </div>
          ` : ''}
          <div class="form-text small text-muted">Press <kbd>Enter</kbd> or <kbd>,</kbd> to add. You can paste comma-separated lists like <em>"iPhone 11, iPhone 12, iPhone 13"</em>.</div>
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
        if (opt.display_type === 'swatch' && Array.isArray(opt.values)) {
          opt.values.forEach(v => {
            if (!v.swatch_hex) v.swatch_hex = colorNameToHex(v.value) || '#3b82f6';
          });
        }
        renderOptions(optIndex);
      });

      const loadPresetBtn = optCard.querySelector('.opt-load-preset');
      loadPresetBtn.addEventListener('click', () => {
        const found = presets.find(p => p.name.toLowerCase() === (opt.name || '').toLowerCase());
        if (found && found.values) {
          opt.display_type = found.display_type;
          opt.values = found.values.map(v => ({
            id: null,
            value: v.value,
            swatch_hex: v.swatch_hex || (found.display_type === 'swatch' ? colorNameToHex(v.value) || '#000000' : null)
          }));
          renderOptions(optIndex);
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
      const addTags = (rawInput, preserveFocus = true) => {
        if (!rawInput) return;
        const tokens = rawInput.split(/[,;\n\r\t]+/).map(s => s.trim()).filter(Boolean);
        if (!tokens.length) return;

        opt.values = opt.values || [];
        let addedCount = 0;
        tokens.forEach(valStr => {
          const exists = opt.values.some(v => v.value.toLowerCase() === valStr.toLowerCase());
          if (!exists) {
            opt.values.push({
              id: null,
              value: valStr,
              swatch_hex: opt.display_type === 'swatch' ? (colorNameToHex(valStr) || '#3b82f6') : null
            });
            addedCount++;
          }
        });

        valInput.value = '';
        renderOptions(preserveFocus ? optIndex : null);
      };

      valInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ',') {
          e.preventDefault();
          addTags(valInput.value, true);
        }
      });

      valInput.addEventListener('input', () => {
        if (valInput.value.includes(',') || valInput.value.includes('\n')) {
          addTags(valInput.value, true);
        }
      });

      valInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const pasteText = (e.clipboardData || window.clipboardData).getData('text');
        addTags(pasteText, true);
      });

      valInput.addEventListener('blur', () => {
        if (valInput.value.trim()) {
          addTags(valInput.value, false);
        }
      });

      // Quick Color Presets Click
      optCard.querySelectorAll('.opt-preset-color').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const cName = btn.dataset.colorName;
          const cHex = btn.dataset.hex;
          const exists = (opt.values || []).some(v => v.value.toLowerCase() === cName.toLowerCase());
          if (!exists) {
            opt.values = opt.values || [];
            opt.values.push({
              id: null,
              value: cName,
              swatch_hex: cHex
            });
            renderOptions(optIndex);
          }
        });
      });

      // Remove tag buttons
      optCard.querySelectorAll('.val-remove-btn').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          const badge = e.target.closest('.val-badge');
          const valIdx = parseInt(badge.dataset.valIndex, 10);
          opt.values.splice(valIdx, 1);
          renderOptions(optIndex);
        });
      });

      // Color picker for swatch tag
      optCard.querySelectorAll('.val-color-picker').forEach((picker) => {
        const updateColor = (newHex) => {
          const badge = picker.closest('.val-badge');
          const circle = badge.querySelector('.val-swatch-circle');
          const valIdx = parseInt(badge.dataset.valIndex, 10);
          if (opt.values[valIdx]) {
            opt.values[valIdx].swatch_hex = newHex;
            if (circle) {
              circle.style.backgroundColor = newHex;
              circle.title = `Click to change color (${newHex})`;
            }
            syncJsonInputs();
          }
        };
        picker.addEventListener('input', (e) => updateColor(e.target.value));
        picker.addEventListener('change', (e) => updateColor(e.target.value));
      });

      // Manual Color Insertion Handler
      if (isSwatch) {
        const manualTitle = optCard.querySelector('.opt-manual-title');
        const manualPicker = optCard.querySelector('.opt-manual-picker');
        const manualHex = optCard.querySelector('.opt-manual-hex');
        const manualAddBtn = optCard.querySelector('.opt-manual-add-btn');

        if (manualTitle && manualPicker && manualHex && manualAddBtn) {
          manualTitle.addEventListener('input', () => {
            const suggested = colorNameToHex(manualTitle.value.trim());
            if (suggested && suggested !== '#3b82f6') {
              manualPicker.value = suggested;
              manualHex.value = suggested.toUpperCase();
            }
          });

          manualPicker.addEventListener('input', (e) => {
            manualHex.value = e.target.value.toUpperCase();
          });

          manualHex.addEventListener('input', (e) => {
            let val = e.target.value.trim();
            if (!val.startsWith('#') && val.length > 0) val = '#' + val;
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
              manualPicker.value = val;
            }
          });

          const doAddManualColor = () => {
            const title = manualTitle.value.trim();
            if (!title) {
              manualTitle.focus();
              return;
            }
            let hex = manualHex.value.trim() || manualPicker.value;
            if (!hex.startsWith('#')) hex = '#' + hex;
            if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) {
              hex = manualPicker.value || '#3b82f6';
            }

            opt.values = opt.values || [];
            const existing = opt.values.find(v => v.value.toLowerCase() === title.toLowerCase());
            if (existing) {
              existing.swatch_hex = hex;
            } else {
              opt.values.push({
                id: null,
                value: title,
                swatch_hex: hex
              });
            }
            manualTitle.value = '';
            renderOptions(optIndex);
          };

          manualAddBtn.addEventListener('click', (e) => {
            e.preventDefault();
            doAddManualColor();
          });

          manualTitle.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              doAddManualColor();
            }
          });

          manualHex.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              doAddManualColor();
            }
          });
        }
      }

      optionsContainer.appendChild(optCard);
    });

    syncJsonInputs();

    if (focusOptIndex !== null) {
      setTimeout(() => {
        const targetCard = optionsContainer.querySelector(`.option-item-card[data-index="${focusOptIndex}"]`);
        if (targetCard) {
          const targetInp = targetCard.querySelector('.val-input');
          if (targetInp) targetInp.focus();
        }
      }, 20);
    }
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
  // Visual Variant Image Picker Helpers
  // -------------------------------------------------------------
  function getImageMeta(filename) {
    if (!filename) return { url: '', label: 'No image' };
    const found = (galleryImages || []).find(img => img.filename === filename);
    if (found) {
      const fallbackName = (found.filename || '').split('/').pop() || found.filename;
      return {
        url: found.image_url || '',
        label: found.display_name || found.original_name || fallbackName || 'Image'
      };
    }
    return {
      url: '',
      label: filename.split('/').pop() || filename
    };
  }

  let pickerModalEl = null;
  let pickerModalInst = null;
  let activePickerVariantIndex = null;

  function ensureImagePickerModal() {
    if (pickerModalEl) return pickerModalEl;
    pickerModalEl = document.createElement('div');
    pickerModalEl.className = 'modal fade';
    pickerModalEl.id = 'variantImagePickerModal';
    pickerModalEl.tabIndex = -1;
    pickerModalEl.innerHTML = `
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header py-2">
            <div>
              <h6 class="modal-title mb-0 fw-bold"><i class="bi bi-images me-1 text-primary"></i> Select Variant Image</h6>
              <small class="text-muted" id="vip_variant_subtitle"></small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="vip_batch_apply_wrap" class="alert alert-light border py-2 px-3 mb-3 d-none">
              <div class="small fw-semibold mb-1"><i class="bi bi-magic text-primary me-1"></i> Also apply selected image to all variants sharing:</div>
              <div id="vip_batch_options" class="d-flex flex-wrap gap-3"></div>
            </div>
            <div id="vip_image_grid" class="row g-2"></div>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(pickerModalEl);
    if (window.bootstrap && window.bootstrap.Modal) {
      pickerModalInst = new window.bootstrap.Modal(pickerModalEl);
    }
    return pickerModalEl;
  }

  function openVariantImagePicker(vIndex) {
    const v = variants[vIndex];
    if (!v) return;
    ensureImagePickerModal();
    activePickerVariantIndex = vIndex;

    const subtitle = pickerModalEl.querySelector('#vip_variant_subtitle');
    if (subtitle) subtitle.textContent = v.label || v.sku || `Variant #${vIndex + 1}`;

    const batchWrap = pickerModalEl.querySelector('#vip_batch_apply_wrap');
    const batchOpts = pickerModalEl.querySelector('#vip_batch_options');
    if (batchWrap && batchOpts) {
      batchOpts.innerHTML = '';
      if (Array.isArray(v.combo) && v.combo.length > 0) {
        batchWrap.classList.remove('d-none');
        v.combo.forEach((c, cIdx) => {
          const isColorOpt = (c.option_name || '').toLowerCase().includes('color');
          const id = `vip_batch_cb_${cIdx}`;
          batchOpts.innerHTML += `
            <div class="form-check form-check-inline m-0">
              <input class="form-check-input vip-batch-cb" type="checkbox" id="${id}"
                     data-opt-name="${escapeHtml(c.option_name)}" data-opt-val="${escapeHtml(c.value)}"
                     ${isColorOpt ? 'checked' : ''}>
              <label class="form-check-label small" for="${id}">
                All <strong>${escapeHtml(c.option_name)}: ${escapeHtml(c.value)}</strong>
              </label>
            </div>
          `;
        });
      } else {
        batchWrap.classList.add('d-none');
      }
    }

    const grid = pickerModalEl.querySelector('#vip_image_grid');
    grid.innerHTML = '';

    // "No image" option card
    const isNoneActive = !v.image;
    grid.innerHTML += `
      <div class="col-6 col-sm-4 col-md-3">
        <button type="button" class="btn w-100 h-100 p-2 border rounded text-center vip-pick-item ${isNoneActive ? 'border-primary bg-primary-subtle' : 'bg-light'}" data-filename="">
          <div class="d-flex align-items-center justify-content-center rounded bg-white border mb-2" style="height:92px;">
            <i class="bi bi-slash-circle text-muted fs-3"></i>
          </div>
          <div class="small fw-semibold text-dark text-truncate">No image</div>
          <div class="text-muted" style="font-size:11px;">Use product default</div>
        </button>
      </div>
    `;

    (galleryImages || []).forEach(img => {
      const fn = img.filename || '';
      const meta = getImageMeta(fn);
      const isSelected = v.image === fn;
      grid.innerHTML += `
        <div class="col-6 col-sm-4 col-md-3">
          <button type="button" class="btn w-100 h-100 p-2 border rounded text-center vip-pick-item ${isSelected ? 'border-primary bg-primary-subtle shadow-sm' : 'bg-white'}"
                  data-filename="${escapeHtml(fn)}" title="${escapeHtml(meta.label)}">
            <div class="overflow-hidden rounded bg-light border mb-2" style="height:92px;">
              ${meta.url
                ? `<img src="${escapeHtml(meta.url)}" alt="" class="w-100 h-100" style="object-fit:cover;">`
                : `<div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted"><i class="bi bi-image fs-3"></i></div>`
              }
            </div>
            <div class="small fw-semibold text-dark text-truncate">${escapeHtml(meta.label)}</div>
          </button>
        </div>
      `;
    });

    grid.querySelectorAll('.vip-pick-item').forEach(btn => {
      btn.addEventListener('click', () => {
        const chosenFile = btn.dataset.filename || null;
        readVariantsFromDOM();
        if (variants[activePickerVariantIndex]) {
          variants[activePickerVariantIndex].image = chosenFile;
        }
        // Apply to all variants sharing any checked option (e.g., same Color)
        const checkedFilters = [];
        pickerModalEl.querySelectorAll('.vip-batch-cb:checked').forEach(cb => {
          checkedFilters.push({
            optName: (cb.dataset.optName || '').toLowerCase(),
            optVal: (cb.dataset.optVal || '').toLowerCase()
          });
        });
        if (checkedFilters.length > 0) {
          variants.forEach(item => {
            if (!Array.isArray(item.combo)) return;
            const matchesAny = checkedFilters.some(f =>
              item.combo.some(c =>
                (c.option_name || '').toLowerCase() === f.optName &&
                (c.value || '').toLowerCase() === f.optVal
              )
            );
            if (matchesAny) {
              item.image = chosenFile;
            }
          });
        }

        renderVariants();
        if (pickerModalInst) pickerModalInst.hide();
      });
    });

    if (pickerModalInst) {
      pickerModalInst.show();
    }
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
          <td colspan="10" class="text-center text-muted py-4">
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
      const imgMeta = getImageMeta(v.image);

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
        <td style="min-width:165px;">
          <input type="hidden" class="var-image-select" value="${escapeHtml(v.image || '')}">
          <button type="button" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-2 w-100 text-start var-image-picker-btn"
                  style="max-width: 195px;" title="${escapeHtml(imgMeta.label)}">
            ${imgMeta.url
              ? `<img src="${escapeHtml(imgMeta.url)}" alt="" class="rounded border flex-shrink-0" style="width:26px;height:26px;object-fit:cover;">`
              : `<span class="rounded border bg-light d-inline-flex align-items-center justify-content-center flex-shrink-0 text-muted" style="width:26px;height:26px;"><i class="bi bi-image" style="font-size:0.75rem;"></i></span>`
            }
            <span class="text-truncate small flex-grow-1">${escapeHtml(imgMeta.label)}</span>
            <i class="bi bi-chevron-down small text-muted flex-shrink-0"></i>
          </button>
        </td>
        <td class="text-center" style="width:40px;">
          <button type="button" class="btn btn-outline-danger btn-sm p-0 px-1 border-0 var-delete-btn" title="Remove this variant" data-index="${vIndex}">
            <i class="bi bi-trash fs-6"></i>
          </button>
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

      const priceInp = tr.querySelector('.var-price');
      priceInp.addEventListener('input', (e) => {
        v.price = e.target.value.trim();
        syncJsonInputs();
      });
      priceInp.addEventListener('change', (e) => {
        v.price = e.target.value.trim();
        syncJsonInputs();
      });

      const saleInp = tr.querySelector('.var-sale-price');
      saleInp.addEventListener('input', (e) => {
        v.sale_price = e.target.value.trim();
        syncJsonInputs();
      });
      saleInp.addEventListener('change', (e) => {
        v.sale_price = e.target.value.trim();
        syncJsonInputs();
      });

      const stockInp = tr.querySelector('.var-stock');
      stockInp.addEventListener('input', (e) => {
        v.stock = Math.max(0, parseInt(e.target.value, 10) || 0);
        syncJsonInputs();
      });
      stockInp.addEventListener('change', (e) => {
        v.stock = Math.max(0, parseInt(e.target.value, 10) || 0);
        syncJsonInputs();
      });

      const weightInp = tr.querySelector('.var-weight');
      weightInp.addEventListener('input', (e) => {
        v.weight_g = e.target.value.trim();
        syncJsonInputs();
      });
      weightInp.addEventListener('change', (e) => {
        v.weight_g = e.target.value.trim();
        syncJsonInputs();
      });

      tr.querySelector('.var-image-picker-btn').addEventListener('click', () => {
        openVariantImagePicker(vIndex);
      });

      tr.querySelector('.var-delete-btn').addEventListener('click', (e) => {
        const rowIdx = parseInt(e.currentTarget.dataset.index, 10);
        const targetV = variants[rowIdx];
        if (confirm(`Remove variant "${targetV.label || targetV.sku || 'this combination'}"?`)) {
          variants.splice(rowIdx, 1);
          renderVariants();
        }
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
      readVariantsFromDOM();
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
      readVariantsFromDOM();
      const val = Math.max(0, parseInt(bulkStockInput.value, 10) || 0);
      const targets = getSelectedVariants();
      targets.forEach(idx => { if (variants[idx]) variants[idx].stock = val; });
      renderVariants();
    });
  }

  const bulkAddStockBtn = document.getElementById('bulk_add_10_stock_btn');
  if (bulkAddStockBtn) {
    bulkAddStockBtn.addEventListener('click', () => {
      readVariantsFromDOM();
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
  // Read Current Live Values from DOM
  // -------------------------------------------------------------
  function readVariantsFromDOM() {
    if (!variantsTbody) return;
    const rows = variantsTbody.querySelectorAll('.variant-row');
    rows.forEach(tr => {
      const idx = parseInt(tr.dataset.index, 10);
      if (variants[idx]) {
        const skuInp = tr.querySelector('.var-sku');
        const priceInp = tr.querySelector('.var-price');
        const saleInp = tr.querySelector('.var-sale-price');
        const stockInp = tr.querySelector('.var-stock');
        const weightInp = tr.querySelector('.var-weight');
        const imgSel = tr.querySelector('.var-image-select');
        const switchInp = tr.querySelector('.var-active-switch');

        if (skuInp) variants[idx].sku = skuInp.value.trim();
        if (priceInp) variants[idx].price = priceInp.value.trim();
        if (saleInp) variants[idx].sale_price = saleInp.value.trim();
        if (stockInp) variants[idx].stock = Math.max(0, parseInt(stockInp.value, 10) || 0);
        if (weightInp) variants[idx].weight_g = weightInp.value.trim();
        if (imgSel) variants[idx].image = imgSel.value || null;
        if (switchInp) variants[idx].status = switchInp.checked ? 'active' : 'inactive';
      }
    });
  }

  // -------------------------------------------------------------
  // Synchronize Hidden JSON Inputs
  // -------------------------------------------------------------
  function syncJsonInputs(readDOM = false) {
    if (readDOM) {
      readVariantsFromDOM();
    }
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
      syncJsonInputs(true);

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
    let rawVariants = Array.isArray(initialVariants) ? initialVariants : [];

    // Auto-resolve missing or white-default swatch_hex for swatch options
    options.forEach(opt => {
      if (opt.display_type === 'swatch' && Array.isArray(opt.values)) {
        opt.values.forEach(v => {
          if (!v.swatch_hex || (v.swatch_hex.toLowerCase() === '#ffffff' && !v.value.toLowerCase().includes('white'))) {
            v.swatch_hex = colorNameToHex(v.value) || v.swatch_hex || '#3b82f6';
          }
        });
      }
    });

    // Reconstruct or ensure combo is present for all existing variants
    rawVariants.forEach(v => {
      if ((!v.combo || !Array.isArray(v.combo) || v.combo.length === 0) && v.option_value_ids && options.length > 0) {
        const reconstructed = [];
        options.forEach(opt => {
          const valId = v.option_value_ids[opt.id];
          if (valId) {
            const valObj = (opt.values || []).find(val => parseInt(val.id, 10) === parseInt(valId, 10));
            if (valObj) {
              reconstructed.push({
                option_id: opt.id,
                option_name: opt.name,
                value_id: valObj.id,
                value: valObj.value,
                swatch_hex: valObj.swatch_hex || null
              });
            }
          }
        });
        if (reconstructed.length > 0) {
          v.combo = reconstructed;
        }
      }
    });

    // Filter out orphan variants whose option values or option count no longer match defined options
    if (options.length > 0) {
      const validValNames = new Set();
      options.forEach(opt => {
        (opt.values || []).forEach(v => {
          if (v && v.value) validValNames.add(v.value.toLowerCase().trim());
        });
      });

      rawVariants = rawVariants.filter(v => {
        if (v.combo && Array.isArray(v.combo) && v.combo.length > 0) {
          if (v.combo.length !== options.length) return false;
          return v.combo.every(c => validValNames.has((c.value || '').toLowerCase().trim()));
        }
        return true;
      });
    }

    variants = rawVariants;
    galleryImages = Array.isArray(initialGallery) ? initialGallery : [];

    loadPresets();
    updateProductTypeUI();
    renderOptions();
    renderVariants();
  };

})();
