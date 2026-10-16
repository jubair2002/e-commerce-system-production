# 02 — Admin: one product, many options (Size, Color, anything) + per-variant stock/price

> Paste `00_MASTER_CONTEXT_and_ORDER.md` first. Prompt 01 must be done.

## Role
Senior PHP/JS engineer. Extend `admin/product_form.php` and `admin/products.php` **without changing the admin look** (Bootstrap 5, indigo, dark mode).

## Goal
In admin, I create ONE product ("Classic Tee") and define any number of options — Size (S, M, L, XL), Color (Black, Red…), Material, Storage… — then get a table of every combination where I set **price, sale price, stock, SKU, weight, image, active/inactive per combination**. Everything is configurable; nothing is hard-coded to size/color.

## UI spec for `admin/product_form.php`
Keep the existing fields. Add:

1. **Product type switch** (radio): *Simple product* (current behaviour) / *Product with options*.
   - Simple → show the current Price / Sale / Stock fields.
   - With options → hide product Stock field (stock is per variant), keep base Price/Sale as defaults.
2. **Inventory box** (both types): `track_inventory` toggle, `allow_backorder` toggle, `low_stock_threshold`, `show_stock_count` toggle, weight, brand, tags, meta title/description.
3. **Options builder** (only for "with options"):
   - "Add option" → name input with autocomplete from `option_presets` (Size, Color, Material, Storage) **and** free text for custom names.
   - Display type select: Buttons / Dropdown / Color swatch / Radio.
   - Values as a **tag input** (type + Enter / comma). For swatch type each value gets a color picker (`swatch_hex`). "Load preset values" button fills from `option_preset_values`.
   - Drag to reorder options and values (vanilla JS, HTML5 drag & drop; or up/down buttons if simpler). Limit: max 3 options, max 100 combinations — show a clear error beyond that.
   - Delete option/value with confirm; warn if existing variants/orders reference it (orders keep text snapshot so deletion is safe, but variant rows are removed).
4. **Variants table** (auto-generated, live in browser):
   - Button "Generate combinations" builds the Cartesian product of option values. **Never delete existing variants silently**: matching combos (same `option_hash`) are kept with their data; new ones are added; removed ones are listed in a confirm dialog.
   - Columns: ☑ active, combination label, SKU (auto suggestion `BASE-SKU-M-RED`, editable), price override, sale price, **stock**, weight, image picker (choose from product gallery), barcode.
   - **Bulk edit bar**: set price / stock / SKU prefix for all or selected rows; "+10 stock to selected".
   - Inline validation: negative numbers, sale ≥ price, duplicate SKU (check against products + variants).
5. **Gallery** (`product_images`): multi-upload, drag reorder, set primary, alt text, optional "belongs to variant". Reuse the existing upload validation (mime + 4 MB) and store in `assets/uploads/products/`. Keep `products.image` in sync with the primary image for old pages.

## Server-side spec
- Save everything in **one transaction**: product row → options → option values → variants → variant_values → images. Roll back fully on any error and re-show the form with `set_old()`.
- Use stable IDs: update existing option/value/variant rows by id; insert new; delete missing only after the confirm flag is posted. Do NOT delete-and-recreate (it would break `order_items.variant_id`, analytics and carts).
- Compute `option_hash` via `variant_hash()`; unique key `(product_id, option_hash)` is the final guard.
- If product_type switches variable → simple: require confirm, sum nothing silently; keep variants inactive instead of deleting if orders reference them.
- Server must re-validate everything the JS sends; JS is convenience only. Post the builder state as a single hidden JSON field (`options_json`, `variants_json`) and validate/decode strictly (types, lengths, max counts).
- `log_activity('product.save', ...)` including counts of variants changed.
- **Stock edits** from this form go through `stock_adjust()` with reason `manual_adjust` (compute delta vs current DB stock) so the ledger stays correct.

## `admin/products.php` list changes
- New column "Stock": simple → number; variable → total + "(N variants)"; red badge if ≤ threshold, grey "Out of stock" if 0.
- Column "Type" badge. Filter: type, stock status (in / low / out), category, status.
- Row action "Quick stock" opens a modal listing variants with +/- inputs (AJAX POST to `admin/ajax/stock_update.php`, CSRF-protected, uses `stock_adjust()`).
- Bulk actions: activate/deactivate/delete selected (confirm).

## New files
`admin/ajax/stock_update.php`, `admin/ajax/option_presets.php` (CRUD for presets, small modal in the product form), `assets/js/admin-variants.js`.

## Edge cases to handle
- Product with 1 option only (just Size). Product with options but zero active variants → shows as out of stock.
- Variant price empty → inherits product price; show inherited value as placeholder.
- Duplicate option values with different case ("red" vs "Red") → reject.
- Very long labels, unicode (Bangla) values — keep utf8mb4 safe, no `strtolower` without `mb_`.
- Concurrent admin edits: include `updated_at` hidden field; if changed on save, warn "someone else edited".

## Acceptance tests (manual list to output)
1. Create "Tee" with Size S/M/L × Color Black/Red → 6 variants, set stock 5 each, save, reload → data intact.
2. Add Size XL → Generate → only 2 new rows appear, old stock untouched.
3. Set Red-L inactive → storefront (later step) hides/disables it.
4. Try two variants with same SKU → blocked.
5. Quick stock modal +3 → `inventory_movements` row created with reason `manual_adjust`.
6. Switch product to simple with orders existing → variants preserved inactive.
