<script>
(function() {
  'use strict';

  var DRAWER_ID = 'rc-drawer';
  var IFRAME_ID = 'rc-drawer-iframe';
  var BTN_ID = 'rc-top-icon';
  var BTN_LINK_ID = 'rc-top-icon-link';
  var BADGE_ID = 'rc-top-unread-badge';

  var STORAGE_OPEN = 'rcDrawer:isOpen';
  var STORAGE_SRC  = 'rcDrawer:iframeSrc';

  // Polling
  var POLL_MS = 15000;
  var pollTimer = null;

  // Set true for console debugging.
  var DEBUG = false;

  function log() {
    if (!DEBUG) return;
    try { console.log.apply(console, arguments); } catch (e) {}
  }

  function clearDrawerState() {
    try { sessionStorage.removeItem(STORAGE_OPEN); } catch (e) {}
    try { sessionStorage.removeItem(STORAGE_SRC); } catch (e) {}
  }

  function looksLoggedIn() {
    if (document.body && document.body.classList.contains('notloggedin')) return false;
    return !!document.querySelector('#usernavigation [data-region="usermenu"]');
  }

  function getSesskey() {
    try {
      if (window.M && M.cfg && typeof M.cfg.sesskey === 'string' && M.cfg.sesskey) {
        return M.cfg.sesskey;
      }
    } catch (e) {}
    return '';
  }

  function ensureDrawer() {
    if (document.getElementById(DRAWER_ID)) return;

    var drawer = document.createElement('div');
    drawer.id = DRAWER_ID;
    drawer.setAttribute('aria-hidden', 'true');
    drawer.innerHTML =
      '<div class="rc-drawer-header">' +
        '<div class="rc-drawer-title">Rocket.Chat</div>' +
        '<button type="button" class="rc-drawer-close" aria-label="Close">Close</button>' +
      '</div>' +
      '<iframe id="' + IFRAME_ID + '" src="about:blank" loading="lazy"></iframe>';

    drawer.querySelector('.rc-drawer-close').addEventListener('click', function() {
      closeDrawer();
    });

    document.body.appendChild(drawer);
  }

  function setIframeSrc(src) {
    var iframe = document.getElementById(IFRAME_ID);
    if (!iframe) return;

    var next = src || '/blocks/rocketchat/view.php';
    iframe.src = next;

    try { sessionStorage.setItem(STORAGE_SRC, next); } catch (e) {}
  }

  function focusToggleButton() {
    var btn = document.getElementById(BTN_LINK_ID);
    if (btn && typeof btn.focus === 'function') {
      btn.focus({ preventScroll: true });
      return true;
    }
    return false;
  }

  function openDrawer() {
    ensureDrawer();

    var drawer = document.getElementById(DRAWER_ID);
    var iframe = document.getElementById(IFRAME_ID);

    drawer.setAttribute('aria-hidden', 'false');

    var savedSrc = null;
    try { savedSrc = sessionStorage.getItem(STORAGE_SRC); } catch (e) {}

    if (iframe && (!iframe.src || iframe.src === 'about:blank')) {
      setIframeSrc(savedSrc || '/blocks/rocketchat/view.php');
    }

    drawer.classList.add('is-open');
    try { sessionStorage.setItem(STORAGE_OPEN, '1'); } catch (e) {}
  }

  function closeDrawer() {
    var drawer = document.getElementById(DRAWER_ID);
    if (!drawer) return;

    try {
      var active = document.activeElement;
      if (active && drawer.contains(active)) {
        if (!focusToggleButton() && active && typeof active.blur === 'function') active.blur();
      }
    } catch (e) {}

    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    try { sessionStorage.setItem(STORAGE_OPEN, '0'); } catch (e) {}
  }

  function toggleDrawer() {
    ensureDrawer();
    var drawer = document.getElementById(DRAWER_ID);
    if (!drawer.classList.contains('is-open')) openDrawer();
    else closeDrawer();
  }

  function ensureBadgeElement() {
    var link = document.getElementById(BTN_LINK_ID);
    if (!link) return null;

    var badge = document.getElementById(BADGE_ID);
    if (badge) return badge;

    badge = document.createElement('span');
    badge.id = BADGE_ID;
    badge.setAttribute('aria-hidden', 'true');
    link.appendChild(badge);
    return badge;
  }

  function setBadgeCount(n) {
    var badge = ensureBadgeElement();
    if (!badge) return;

    n = Number(n || 0);
    if (n > 0) {
      badge.textContent = (n > 99) ? '99+' : String(n);
      badge.classList.add('is-visible');
    } else {
      badge.textContent = '';
      badge.classList.remove('is-visible');
    }
  }

  // Choice A: count rooms needing attention.
  // A room needs attention if unread > 0 OR alert === true.
  function computeAttentionCount(rooms) {
    if (!Array.isArray(rooms)) return 0;
    var count = 0;
    for (var i = 0; i < rooms.length; i++) {
      var r = rooms[i] || {};
      var unread = Number(r.unread || 0);
      var alert = !!r.alert;
      if (unread > 0 || alert) count++;
    }
    return count;
  }

  async function fetchAttentionCountAndUpdateBadge() {
    if (!looksLoggedIn()) {
      setBadgeCount(0);
      return;
    }

    var sesskey = getSesskey();
    if (!sesskey) {
      // If sesskey isn't available yet, just skip; the "wait" loop will retry.
      return;
    }

    var url = '/blocks/rocketchat/ajax.php?action=subscriptions&sesskey=' + encodeURIComponent(sesskey);
    var resp = await fetch(url, { credentials: 'same-origin' });
    var data = await resp.json().catch(function(){ return null; });

    if (!resp.ok || !data || data.success === false) {
      throw new Error('subscriptions failed: HTTP ' + resp.status);
    }

    var rooms = data.rooms || [];
    var attention = computeAttentionCount(rooms);
    log('[rc] attention rooms:', attention);
    setBadgeCount(attention);
  }

  function startPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }

    // Immediate refresh
    fetchAttentionCountAndUpdateBadge().catch(function(e){ log('[rc] initial fetch failed', e); });

    pollTimer = setInterval(function() {
      fetchAttentionCountAndUpdateBadge().catch(function(e){ log('[rc] poll fetch failed', e); });
    }, POLL_MS);

    window.addEventListener('focus', function() {
      fetchAttentionCountAndUpdateBadge().catch(function(){});
    });

    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) fetchAttentionCountAndUpdateBadge().catch(function(){});
    });
  }

  function startPollingWhenSesskeyReady() {
    var attempts = 0;
    var maxAttempts = 40; // 10s

    var t = setInterval(function() {
      attempts++;

      if (!looksLoggedIn()) {
        clearInterval(t);
        setBadgeCount(0);
        return;
      }

      var sk = getSesskey();
      if (sk) {
        clearInterval(t);
        startPolling();
        return;
      }

      if (attempts >= maxAttempts) {
        clearInterval(t);
        log('[rc] sesskey never became available; badge disabled');
      }
    }, 250);
  }

  function addRocketChatButton() {
    if (!looksLoggedIn()) {
      clearDrawerState();
      setBadgeCount(0);
      return false;
    }

    var usernav = document.querySelector('#usernavigation');
    if (!usernav) return false;

    if (document.getElementById(BTN_ID)) return true;

    var wrapper = document.createElement('div');
    wrapper.className = 'popover-region collapsed';
    wrapper.id = BTN_ID;

    var toggle = document.createElement('a');
    toggle.id = BTN_LINK_ID;
    toggle.className = 'nav-link icon-no-margin';
    toggle.href = '#';
    toggle.title = 'Rocket.Chat';
    toggle.setAttribute('aria-label', 'Rocket.Chat');

    toggle.innerHTML =
      '<span class="rc-navbtn" aria-hidden="true">' +
        '<svg class="rc-navbtn__icon" viewBox="0 0 24 24" role="img" focusable="false" aria-hidden="true">' +
          '<path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-4.5 3a1 1 0 0 1-1.5-.86V6a2 2 0 0 1 2-2z" fill="currentColor"></path>' +
        '</svg>' +
      '</span>' +
      '<span class="sr-only">Rocket.Chat</span>';

    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      toggleDrawer();
    });

    wrapper.appendChild(toggle);

    var usermenu = usernav.querySelector('[data-region="usermenu"]');
    if (usermenu) usernav.insertBefore(wrapper, usermenu);
    else usernav.appendChild(wrapper);

    ensureBadgeElement();
    startPollingWhenSesskeyReady();

    return true;
  }

  function restoreDrawerStateIfNeeded() {
    if (!looksLoggedIn()) {
      clearDrawerState();
      setBadgeCount(0);
      return;
    }

    var shouldOpen = false;
    try { shouldOpen = sessionStorage.getItem(STORAGE_OPEN) === '1'; } catch (e) {}
    if (shouldOpen) openDrawer();
  }

  if (!window.__rcDrawerEscBound) {
    window.__rcDrawerEscBound = true;
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeDrawer();
    });
  }

  var tries = 0;
  var timer = setInterval(function() {
    tries++;
    if (addRocketChatButton() || tries > 80) {
      clearInterval(timer);
      restoreDrawerStateIfNeeded();
    }
  }, 250);
})();
</script>
