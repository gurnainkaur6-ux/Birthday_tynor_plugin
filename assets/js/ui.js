/**
 * ui.js — professional icon layer.
 *
 * Replaces the emoji used throughout the UI with crisp Lucide SVG icons.
 * Runs only if the Lucide CDN loaded, so if the network is unavailable the
 * original emoji remain (graceful degradation — nothing disappears).
 *
 * Load order per page (before </body>):
 *   <script src="https://unpkg.com/lucide@latest"></script>
 *   <script src="<?= ASSETS_URL ?>/js/ui.js"></script>
 */
(function () {
  // emoji / symbol → Lucide icon name
  var SYM = {
    '🎂': 'cake', '🏠': 'layout-dashboard', '👥': 'users', '📊': 'bar-chart-3',
    '✉': 'mail', '📧': 'mail', '🩺': 'activity', '📡': 'satellite-dish',
    '🚀': 'send', '👤': 'user-round', '📋': 'clipboard-list', '⇅': 'arrow-down-up',
    '⬇': 'download', '⬆': 'upload', '➕': 'plus', '+': 'plus', '→': 'arrow-right',
    '🔍': 'search', '👁': 'eye', '🎈': 'party-popper', '🎁': 'gift',
    '🏭': 'factory', '📅': 'calendar-days', '⚙': 'settings', '🔔': 'bell',
    '✅': 'check-circle-2', '❌': 'circle-x', '⏭': 'skip-forward', '⭐': 'star',
    '🏢': 'building-2', '🎨': 'palette', '📧': 'mail', '💌': 'mail-heart', '📨': 'mail'
  };

  function makeIcon(name) {
    var i = document.createElement('i');
    i.setAttribute('data-lucide', name);
    return i;
  }

  // Replace a leading emoji/symbol inside an element's first text node.
  function swapLeading(el) {
    for (var n = 0; n < el.childNodes.length; n++) {
      var node = el.childNodes[n];
      if (node.nodeType !== 3) continue;               // text nodes only
      var raw = node.nodeValue;
      var lead = raw.replace(/^\s+/, '');
      if (!lead) continue;

      var cp = lead.codePointAt(0);
      var char = String.fromCodePoint(cp);
      var rest = lead.slice(char.length);
      if (rest.charCodeAt(0) === 0xFE0F) rest = rest.slice(1); // strip variation selector

      var icon = SYM[char];
      if (!icon) return false;

      rest = rest.replace(/^\s+/, '');
      node.nodeValue = rest ? ' ' + rest : '';
      el.insertBefore(makeIcon(icon), node);
      return true;
    }
    return false;
  }

  function run() {
    if (!window.lucide || typeof window.lucide.createIcons !== 'function') return; // keep emoji

    // Elements whose leading emoji should become an icon.
    var selectors = [
      '.topbar-brand .logo-icon',
      '.topbar-nav .nav-link',
      '.topbar-user .user-name',
      '.action-icon',
      '.page-header h1',
      '.stat-card .icon',
      '.tab-btn',
      '.panel h2', '.panel h3',
      '.bday-tag',
      '.trend-pill',
      '.badge',
      '.btn-primary', '.btn-secondary', '.btn-danger'
    ];
    selectors.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        if (el.querySelector('i[data-lucide], svg.lucide')) return; // already done
        swapLeading(el);
      });
    });

    // Logout link has no emoji — give it an icon for consistency.
    document.querySelectorAll('.btn-logout').forEach(function (el) {
      if (el.querySelector('i[data-lucide], svg.lucide')) return;
      if (/^\s*Logout/i.test(el.textContent)) {
        el.insertBefore(makeIcon('log-out'), el.firstChild);
        if (el.firstChild.nextSibling && el.firstChild.nextSibling.nodeType === 3) {
          el.firstChild.nextSibling.nodeValue = ' ' + el.firstChild.nextSibling.nodeValue.replace(/^\s+/, '');
        }
      }
    });

    window.lucide.createIcons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
