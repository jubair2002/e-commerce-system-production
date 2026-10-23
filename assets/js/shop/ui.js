/**
 * Jinispotro Storefront Standalone UI Engine (Phase B5)
 * Pure Vanilla JavaScript — zero dependencies, zero Bootstrap JS.
 * Handles: Drawers, Modals, Dropdowns, Tabs, Accordions, Toasts, Focus Traps, and Dark Theme.
 */
(() => {
  'use strict';

  // -------------------------------------------------------------
  // Theme Manager (Dark / Light)
  // -------------------------------------------------------------
  const initTheme = () => {
    const root = document.documentElement;
    const storedTheme = (() => {
      try { return localStorage.getItem('jinispotro_theme'); } catch (_) { return null; }
    })();

    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const activeTheme = storedTheme || (prefersDark ? 'dark' : 'light');
    root.setAttribute('data-theme', activeTheme);

    const updateToggleButtons = (theme) => {
      document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
        btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
        btn.title = theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode';
        const sun = btn.querySelector('.theme-icon-sun');
        const moon = btn.querySelector('.theme-icon-moon');
        if (sun && moon) {
          if (theme === 'dark') {
            sun.style.display = 'inline-block';
            moon.style.display = 'none';
          } else {
            sun.style.display = 'none';
            moon.style.display = 'inline-block';
          }
        }
      });
    };

    updateToggleButtons(activeTheme);

    document.addEventListener('click', (e) => {
      const toggle = e.target.closest('[data-theme-toggle]');
      if (!toggle) return;
      e.preventDefault();
      const current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      const next = current === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('jinispotro_theme', next); } catch (_) {}
      updateToggleButtons(next);
      window.ShopUI && window.ShopUI.toast(`Switched to ${next} theme`, 'info');
    });
  };

  // -------------------------------------------------------------
  // Focus Trap & Scroll Lock Utilities
  // -------------------------------------------------------------
  let scrollLockCount = 0;
  const lockScroll = () => {
    scrollLockCount++;
    if (scrollLockCount === 1) {
      document.body.classList.add('overflow-hidden');
    }
  };

  const unlockScroll = () => {
    scrollLockCount = Math.max(0, scrollLockCount - 1);
    if (scrollLockCount === 0) {
      document.body.classList.remove('overflow-hidden');
    }
  };

  // -------------------------------------------------------------
  // Drawer / Offcanvas System
  // -------------------------------------------------------------
  const openDrawer = (drawerId) => {
    const drawer = document.getElementById(drawerId);
    if (!drawer) return;
    drawer.classList.add('is-open', 'open');
    drawer.setAttribute('aria-hidden', 'false');
    lockScroll();

    let backdrop = document.querySelector(`.drawer-backdrop[data-drawer="${drawerId}"]`) || document.getElementById('miniCartBackdrop');
    if (backdrop) {
      backdrop.classList.add('is-open', 'open');
      backdrop.onclick = () => closeDrawer(drawerId);
    } else {
      backdrop = document.createElement('div');
      backdrop.className = 'drawer-backdrop is-open open';
      backdrop.setAttribute('data-drawer', drawerId);
      document.body.appendChild(backdrop);
      backdrop.addEventListener('click', () => closeDrawer(drawerId));
    }

    const firstFocusable = drawer.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (firstFocusable) firstFocusable.focus();
    drawer.dispatchEvent(new CustomEvent('drawer:opened', { detail: { id: drawerId } }));
  };

  const closeDrawer = (drawerId) => {
    const drawer = drawerId ? document.getElementById(drawerId) : document.querySelector('.shop-drawer.is-open, .shop-drawer.open');
    if (drawer) {
      drawer.classList.remove('is-open', 'open');
      drawer.setAttribute('aria-hidden', 'true');
    }

    // Always clean up all backdrops and drawer elements
    document.querySelectorAll('.shop-drawer').forEach(d => {
      d.classList.remove('is-open', 'open');
      d.setAttribute('aria-hidden', 'true');
    });
    document.querySelectorAll('.drawer-backdrop, .mini-cart-backdrop').forEach(b => {
      b.classList.remove('is-open', 'open');
    });
    unlockScroll();

    if (drawer) {
      drawer.dispatchEvent(new CustomEvent('drawer:closed', { detail: { id: drawer.id } }));
    }
  };

  // -------------------------------------------------------------
  // Modal System
  // -------------------------------------------------------------
  const openModal = (modalId) => {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    lockScroll();
    const firstFocus = modal.querySelector('button, [href], input, select, textarea');
    if (firstFocus) firstFocus.focus();
    modal.dispatchEvent(new CustomEvent('modal:opened', { detail: { id: modalId } }));
  };

  const closeModal = (modalId) => {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    unlockScroll();
    modal.dispatchEvent(new CustomEvent('modal:closed', { detail: { id: modalId } }));
  };

  // -------------------------------------------------------------
  // Toast Notification System
  // -------------------------------------------------------------
  let toastContainer = null;
  const showToast = (message, type = 'info', duration = 3500) => {
    if (!toastContainer) {
      toastContainer = document.getElementById('shopToastContainer');
      if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'shopToastContainer';
        toastContainer.className = 'toast-container';
        toastContainer.setAttribute('role', 'region');
        toastContainer.setAttribute('aria-live', 'polite');
        document.body.appendChild(toastContainer);
      }
    }

    const toast = document.createElement('div');
    toast.className = `shop-toast toast-${type}`;
    
    let iconName = 'info';
    if (type === 'success' || type === 'ok') iconName = 'check-circle';
    if (type === 'error' || type === 'danger') iconName = 'alert-circle';
    if (type === 'warn' || type === 'warning') iconName = 'info';

    toast.innerHTML = `
      <div class="toast-content">
        <svg class="i toast-icon" aria-hidden="true"><use href="${window.SHOP_BASE_URL || '/'}assets/img/icons.svg#${iconName}"></use></svg>
        <span class="toast-message">${message}</span>
      </div>
      <button type="button" class="toast-close" aria-label="Close notification">
        <svg class="i" aria-hidden="true"><use href="${window.SHOP_BASE_URL || '/'}assets/img/icons.svg#x"></use></svg>
      </button>
    `;

    toastContainer.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-visible'));

    const dismiss = () => {
      toast.classList.remove('is-visible');
      toast.remove();
    };

    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    if (duration > 0) {
      setTimeout(dismiss, duration);
    }
  };

  // -------------------------------------------------------------
  // Global Event Delegation (Clicks, ESC Key, Outside Clicks)
  // -------------------------------------------------------------
  document.addEventListener('click', (e) => {
    // Alert / Banner dismiss buttons (handles [data-bs-dismiss="alert"], .btn-close, .alert-close)
    const alertCloseBtn = e.target.closest('[data-bs-dismiss="alert"], .btn-close, .alert-close');
    if (alertCloseBtn) {
      const alertBox = alertCloseBtn.closest('.alert, .announcement-bar, [role="alert"]');
      if (alertBox) {
        e.preventDefault();
        alertBox.remove();
        return;
      }
    }

    // Search bar close trigger
    const searchCloseBtn = e.target.closest('[data-search-close]');
    if (searchCloseBtn) {
      e.preventDefault();
      const sBar = document.getElementById('searchExpandBar');
      if (sBar) sBar.classList.remove('active', 'is-open');
      return;
    }

    // Drawer open triggers
    const drawerOpenBtn = e.target.closest('[data-drawer-open], [data-cart-drawer-trigger]');
    if (drawerOpenBtn) {
      e.preventDefault();
      const target = drawerOpenBtn.getAttribute('data-drawer-open') || 'miniCartDrawer';
      openDrawer(target);
      return;
    }

    // Drawer close triggers
    const drawerCloseBtn = e.target.closest('[data-drawer-close], [data-cart-drawer-close], .drawer-close');
    if (drawerCloseBtn) {
      e.preventDefault();
      const drawer = drawerCloseBtn.closest('.shop-drawer') || drawerCloseBtn.closest('[data-drawer-id]');
      if (drawer) {
        closeDrawer(drawer.id);
      } else {
        closeDrawer();
      }
      return;
    }

    // Modal open triggers
    const modalOpenBtn = e.target.closest('[data-modal-open]');
    if (modalOpenBtn) {
      e.preventDefault();
      const target = modalOpenBtn.getAttribute('data-modal-open');
      openModal(target);
      return;
    }

    // Modal close triggers
    const modalCloseBtn = e.target.closest('[data-modal-close], [data-bs-dismiss="modal"]');
    if (modalCloseBtn) {
      e.preventDefault();
      const modal = modalCloseBtn.closest('.shop-modal, .modal');
      if (modal) {
        closeModal(modal.id);
      }
      return;
    }

    // Click on modal backdrop
    const modalBackdrop = e.target.closest('.shop-modal, .modal');
    if (modalBackdrop && e.target === modalBackdrop) {
      closeModal(modalBackdrop.id);
      return;
    }

    // Click on drawer backdrop
    const drawerBackdrop = e.target.closest('.drawer-backdrop, .mini-cart-backdrop');
    if (drawerBackdrop && e.target === drawerBackdrop) {
      closeDrawer();
      return;
    }

    // Dropdown toggles
    const dropdownToggle = e.target.closest('[data-dropdown-toggle]');
    if (dropdownToggle) {
      e.preventDefault();
      const parent = dropdownToggle.closest('.shop-dropdown');
      if (parent) {
        const isOpen = parent.classList.toggle('is-open');
        dropdownToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      }
      return;
    }

    // Close open dropdowns when clicking outside
    document.querySelectorAll('.shop-dropdown.is-open').forEach(dd => {
      if (!dd.contains(e.target)) {
        dd.classList.remove('is-open');
        const btn = dd.querySelector('[data-dropdown-toggle]');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      }
    });

    // Accordion triggers
    const accordionBtn = e.target.closest('[data-accordion-toggle]');
    if (accordionBtn) {
      e.preventDefault();
      const item = accordionBtn.closest('.accordion-item') || accordionBtn.parentElement;
      const isOpen = item.classList.toggle('is-open');
      accordionBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      const content = item.querySelector('.accordion-content');
      if (content) {
        if (isOpen) {
          content.style.maxHeight = content.scrollHeight + 'px';
        } else {
          content.style.maxHeight = '0px';
        }
      }
      return;
    }

    // Tabs
    const tabBtn = e.target.closest('[data-tab-target]');
    if (tabBtn) {
      e.preventDefault();
      const targetId = tabBtn.getAttribute('data-tab-target');
      const container = tabBtn.closest('.tabs-container') || document;
      container.querySelectorAll('[data-tab-target]').forEach(b => {
        b.classList.remove('is-active');
        b.setAttribute('aria-selected', 'false');
      });
      container.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('is-active'));

      tabBtn.classList.add('is-active');
      tabBtn.setAttribute('aria-selected', 'true');
      const targetPane = document.getElementById(targetId);
      if (targetPane) targetPane.classList.add('is-active');
      return;
    }

    // Back to top button
    const backToTop = e.target.closest('[data-back-to-top]');
    if (backToTop) {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }
  });

  // Global ESC Key Listener
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      // Close open modals
      document.querySelectorAll('.shop-modal.is-open').forEach(m => closeModal(m.id));
      // Close open drawers
      document.querySelectorAll('.shop-drawer.is-open').forEach(d => closeDrawer(d.id));
      // Close open dropdowns
      document.querySelectorAll('.shop-dropdown.is-open').forEach(dd => {
        dd.classList.remove('is-open');
        const btn = dd.querySelector('[data-dropdown-toggle]');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      });
    }
  });

  // Expose Global ShopUI API
  window.ShopUI = {
    openDrawer,
    closeDrawer,
    openModal,
    closeModal,
    toast: showToast,
    initTheme
  };

  // Auto-initialize on load
  document.addEventListener('DOMContentLoaded', () => {
    initTheme();
  });
})();
