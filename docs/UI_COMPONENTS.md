# Jinispotro Storefront UI Component Documentation (V2/V3)

This design system is built with pure Vanilla CSS tokens and lightweight Vanilla JS. All components support both Light and Dark themes via `[data-theme="dark"]`, meet WCAG AA contrast, and respect `prefers-reduced-motion`.

---

## 1. Buttons
```html
<!-- Primary Button -->
<button type="button" class="btn btn-primary">Primary Action</button>

<!-- Secondary Outline Button -->
<button type="button" class="btn btn-secondary">Secondary Action</button>

<!-- Ghost Button -->
<button type="button" class="btn btn-ghost">Ghost Action</button>

<!-- Full Width Button -->
<button type="button" class="btn btn-primary btn-w-full">Full Width CTA</button>

<!-- Icon Button -->
<button type="button" class="btn btn-secondary btn-icon" aria-label="Search">
  <svg class="i" aria-hidden="true"><use href="/assets/img/icons.svg#search"></use></svg>
</button>
```

---

## 2. Form Inputs & Floating Labels
```html
<div class="form-group">
  <label for="email" class="form-label">Email Address</label>
  <input type="email" id="email" class="form-control" placeholder="name@domain.com" required>
  <span class="form-text">We never share your email with third parties.</span>
</div>

<div class="form-group">
  <label for="password" class="form-label">Password</label>
  <div class="input-password-wrap">
    <input type="password" id="password" class="form-control" placeholder="••••••••">
    <button type="button" class="btn-password-toggle" data-toggle-password="password" aria-label="Toggle password visibility">
      <svg class="i" aria-hidden="true"><use href="/assets/img/icons.svg#eye"></use></svg>
    </button>
  </div>
</div>
```

---

## 3. Product Card
```html
<div class="product-card">
  <div class="product-card-media">
    <img src="/assets/uploads/products/tee.jpg" alt="Classic Tee" class="product-card-img" loading="lazy">
    <span class="badge badge-sale product-card-badge">Sale</span>
    <button type="button" class="product-card-wishlist" data-wishlist-id="42" aria-label="Add to wishlist">
      <svg class="i" aria-hidden="true"><use href="/assets/img/icons.svg#heart"></use></svg>
    </button>
  </div>
  <div class="product-card-body">
    <span class="product-card-category">Apparel</span>
    <h3 class="product-card-title"><a href="/product/classic-tee">Classic Cotton Tee</a></h3>
    <div class="product-card-footer">
      <div class="price-wrap">
        <span class="price-current">৳1,200</span>
        <span class="price-strike">৳1,500</span>
      </div>
      <button type="button" class="btn btn-secondary btn-sm" data-quick-add="42">Add</button>
    </div>
  </div>
</div>
```

---

## 4. Drawers (Slide-in Offcanvas)
```html
<!-- Trigger Button -->
<button type="button" class="btn btn-secondary" data-drawer-open="miniCartDrawer">Open Cart</button>

<!-- Drawer Markup -->
<div class="shop-drawer drawer-right" id="miniCartDrawer" aria-hidden="true">
  <div class="drawer-header">
    <h3 class="drawer-title">Shopping Bag</h3>
    <button type="button" class="drawer-close" data-drawer-close aria-label="Close cart">
      <svg class="i" aria-hidden="true"><use href="/assets/img/icons.svg#x"></use></svg>
    </button>
  </div>
  <div class="drawer-body" id="miniCartBody">
    <!-- Populated via AJAX -->
  </div>
  <div class="drawer-footer">
    <a href="/checkout" class="btn btn-primary btn-w-full">Proceed to Checkout</a>
  </div>
</div>
```

---

## 5. Modal Dialogs
```html
<div class="shop-modal" id="sizeGuideModal" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-header">
      <h3 class="modal-title">Size Guide</h3>
      <button type="button" class="drawer-close" data-modal-close aria-label="Close size guide">
        <svg class="i" aria-hidden="true"><use href="/assets/img/icons.svg#x"></use></svg>
      </button>
    </div>
    <div class="modal-body">
      <!-- Size guide table / content -->
    </div>
  </div>
</div>
```

---

## 6. Toasts (AJAX Notifications)
```javascript
// Triggered via ShopUI JavaScript API
ShopUI.toast('Item added to shopping bag', 'success');
ShopUI.toast('This coupon code has expired', 'error');
ShopUI.toast('Switched to dark theme', 'info');
```

---

## 7. Rating Stars
```html
<div class="rating-summary">
  <div class="rating-stars">
    <svg class="i"><use href="/assets/img/icons.svg#star"></use></svg>
    <svg class="i"><use href="/assets/img/icons.svg#star"></use></svg>
    <svg class="i"><use href="/assets/img/icons.svg#star"></use></svg>
    <svg class="i"><use href="/assets/img/icons.svg#star"></use></svg>
    <svg class="i"><use href="/assets/img/icons.svg#star-empty"></use></svg>
  </div>
  <span class="rating-score">4.8</span>
  <span class="text-muted">(12 reviews)</span>
</div>
```

---

## 8. Quantity Stepper
```html
<div class="quantity-stepper">
  <button type="button" class="stepper-btn" data-stepper-down aria-label="Decrease quantity">−</button>
  <input type="number" class="stepper-input" value="1" min="1" max="99" aria-label="Quantity">
  <button type="button" class="stepper-btn" data-stepper-up aria-label="Increase quantity">+</button>
</div>
```
