/**
 * OURCR ONLINE - Admin Panel JavaScript
 */

(function () {
    'use strict';

    // ── Sidebar Toggle ───────────────────────────────────────────────────────

    var sidebar        = document.getElementById('adminSidebar');
    var overlay        = document.getElementById('adminSidebarOverlay');
    var toggleBtn      = document.getElementById('adminSidebarToggle');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggleSidebar() {
        if (sidebar && sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Close when clicking the overlay
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Close when pressing Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSidebar();
    });

    // Close sidebar automatically when viewport widens past mobile breakpoint
    window.addEventListener('resize', function () {
        if (window.innerWidth > 991) closeSidebar();
    });

}());
