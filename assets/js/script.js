// Sidebar toggle (mobile) + theme toggle.
(function () {
    const sidebar  = document.getElementById('sidebar');
    const toggle   = document.getElementById('sidebarToggle');
    const backdrop = document.getElementById('sidebarBackdrop');

    const setOpen = (open) => {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        if (backdrop) backdrop.classList.toggle('show', open);
        document.body.style.overflow = open ? 'hidden' : '';
    };

    // Restore desktop sidebar collapsed state
    if (window.innerWidth >= 992 && localStorage.getItem('sidebar_collapsed') === '1') {
        document.body.classList.add('sidebar-collapsed');
    }

    if (toggle && sidebar) {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (window.innerWidth < 992) {
                setOpen(!sidebar.classList.contains('open'));
            } else {
                const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebar_collapsed', isCollapsed ? '1' : '0');
            }
        });
        if (backdrop) {
            backdrop.addEventListener('click', () => setOpen(false));
        }
        // Close any open sidebar on link click (so navigating from mobile feels right).
        sidebar.querySelectorAll('a.nav-link').forEach((a) => {
            a.addEventListener('click', () => {
                if (window.innerWidth < 992) setOpen(false);
            });
        });
        // Close when window grows past breakpoint.
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992) setOpen(false);
        });
    }

    const themeBtn = document.getElementById('themeToggle');
    const root = document.documentElement;
    const stored = localStorage.getItem('theme');
    if (stored) root.setAttribute('data-bs-theme', stored);

    if (themeBtn) {
        const updateIcon = () => {
            const dark = root.getAttribute('data-bs-theme') === 'dark';
            themeBtn.querySelector('i').className =
                dark ? 'bi bi-sun' : 'bi bi-moon-stars';
        };
        updateIcon();
        themeBtn.addEventListener('click', () => {
            const next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            updateIcon();
        });
    }

    // Confirm-delete buttons.
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            const msg = el.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(msg)) e.preventDefault();
        });
    });
})();
