<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
if (window.lucide) lucide.createIcons();

// ─── Sidebar collapse (persisted via localStorage, matches useState in DashboardLayout) ───
(function () {
  var collapsed = localStorage.getItem('srims_sidebar_collapsed') === '1';
  if (collapsed) document.body.classList.add('sidebar-collapsed');
  var icon = document.getElementById('sidebarCollapseIcon');
  if (icon) icon.setAttribute('data-lucide', collapsed ? 'chevrons-right' : 'chevrons-left');
  if (window.lucide) lucide.createIcons();

  var toggleBtn = document.getElementById('sidebarCollapseToggle');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      var isCollapsed = document.body.classList.toggle('sidebar-collapsed');
      localStorage.setItem('srims_sidebar_collapsed', isCollapsed ? '1' : '0');
      var ic = document.getElementById('sidebarCollapseIcon');
      if (ic) {
        ic.setAttribute('data-lucide', isCollapsed ? 'chevrons-right' : 'chevrons-left');
        if (window.lucide) lucide.createIcons();
      }
    });
  }

  var mobileBtn = document.getElementById('mobileMenuBtn');
  var overlay = document.getElementById('mobileSidebarOverlay');
  if (mobileBtn) {
    mobileBtn.addEventListener('click', function () {
      document.body.classList.toggle('mobile-menu-open');
      if (overlay) overlay.style.display = document.body.classList.contains('mobile-menu-open') ? 'block' : 'none';
    });
  }
})();

// ─── Sidebar collapsible submenus ───
document.querySelectorAll('.sidebar-menu-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var target = document.getElementById(btn.getAttribute('data-target'));
    if (target) target.classList.toggle('hidden');
    var chev = btn.querySelector('.sidebar-chevron');
    if (chev) chev.setAttribute('data-lucide', target && target.classList.contains('hidden') ? 'chevron-right' : 'chevron-down');
    if (window.lucide) lucide.createIcons();
  });
});

// ─── Sidebar scroll position (so navigating/refreshing doesn't jump you
// back to the top of a long, scrolled-down menu) ───
(function () {
  var nav = document.querySelector('.app-sidebar nav');
  if (!nav) return;
  var saved = sessionStorage.getItem('srims_sidebar_scroll');
  if (saved !== null) nav.scrollTop = parseInt(saved, 10) || 0;
  nav.addEventListener('scroll', function () {
    sessionStorage.setItem('srims_sidebar_scroll', String(nav.scrollTop));
  });
  // Also capture position right as a nav link is clicked, since the
  // scroll event above may not fire again before the page unloads.
  nav.querySelectorAll('a, .sidebar-menu-toggle').forEach(function (el) {
    el.addEventListener('click', function () {
      sessionStorage.setItem('srims_sidebar_scroll', String(nav.scrollTop));
    });
  });
})();

// ─── Generic dropdown toggle helper (date range / notifications / user menu) ───
function srimsToggleDropdown(btnId, panelId) {
  var btn = document.getElementById(btnId);
  var panel = document.getElementById(panelId);
  if (!btn || !panel) return;
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var isHidden = panel.classList.contains('hidden');
    document.querySelectorAll('.app-dropdown-panel').forEach(function (p) { p.classList.add('hidden'); });
    if (isHidden) panel.classList.remove('hidden');
  });
  panel.classList.add('app-dropdown-panel');
}
srimsToggleDropdown('dateRangeBtn', 'dateRangePanel');
srimsToggleDropdown('notifBellBtn', 'notifPanel');
srimsToggleDropdown('userMenuBtn', 'userMenuPanel');
document.addEventListener('click', function () {
  document.querySelectorAll('.app-dropdown-panel').forEach(function (p) { p.classList.add('hidden'); });
});
document.querySelectorAll('.app-dropdown-panel').forEach(function (p) {
  p.addEventListener('click', function (e) { e.stopPropagation(); });
});

// Date range reset/apply (client-side only in this build, matches original demo behaviour)
(function () {
  var resetBtn = document.getElementById('dateRangeReset');
  var applyBtn = document.getElementById('dateRangeApply');
  if (applyBtn) applyBtn.addEventListener('click', function () { document.getElementById('dateRangePanel').classList.add('hidden'); });
  if (resetBtn) resetBtn.addEventListener('click', function () { location.reload(); });
})();

// ─── Notifications: mark-as-read ───
document.querySelectorAll('.notif-item').forEach(function (link) {
  link.addEventListener('click', function () {
    var id = link.getAttribute('data-notif-id');
    fetch('<?= BASE_URL ?>/api/mark-notification-read.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'id=' + encodeURIComponent(id)
    });
  });
});
// ─── Requisition detail modal (shared across My/Drafts/Pending/Approved/Rejected) ───
function openReqModal(id) {
  var m = document.getElementById('req-modal-' + id);
  if (m) { m.classList.remove('hidden'); m.classList.add('flex'); }
}
function closeReqModal(id) {
  var m = document.getElementById('req-modal-' + id);
  if (m) { m.classList.add('hidden'); m.classList.remove('flex'); }
}
function setReqModalView(id, view) {
  var issuedView = document.getElementById('req-modal-' + id + '-view-issued');
  var originalView = document.getElementById('req-modal-' + id + '-view-original');
  var btnIssued = document.getElementById('req-modal-' + id + '-btn-issued');
  var btnOriginal = document.getElementById('req-modal-' + id + '-btn-original');
  if (!issuedView || !originalView) return;
  if (view === 'original') {
    issuedView.classList.add('hidden'); originalView.classList.remove('hidden');
    btnIssued.className = 'rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors text-text-secondary hover:text-text-primary';
    btnOriginal.className = 'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors bg-white text-text-primary shadow-sm';
  } else {
    issuedView.classList.remove('hidden'); originalView.classList.add('hidden');
    btnIssued.className = 'rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors bg-brand-primary text-white shadow-sm';
    btnOriginal.className = 'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors text-text-secondary hover:text-text-primary';
  }
}

var markAllBtn = document.getElementById('markAllReadBtn');
if (markAllBtn) {
  markAllBtn.addEventListener('click', function () {
    fetch('<?= BASE_URL ?>/api/mark-all-notifications-read.php', { method: 'POST' }).then(function () { location.reload(); });
  });
}
</script>
</body>
</html>
