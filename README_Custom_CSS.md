/* -----------------------------
   Rocket.Chat / Blocks drawer
   Make right drawer wider
-------------------------------- */
#theme_boost-drawers-blocks.drawer.drawer-right {
    width: 400px !important;
    max-width: 400px !important;
}

/* Ensure the hidden/offscreen position matches the width */
#theme_boost-drawers-blocks.drawer.drawer-right:not(.show) {
    right: -400px !important;
}

/* Tighten ONLY the Rocket.Chat block container */
section.block.block_rocketchat {
  padding: 0 !important;
  margin: 0 !important;
  border-radius: 0 !important;
  box-shadow: none !important;
}

/* Often the real padding is inside the block’s body/content wrapper */
section.block.block_rocketchat .card-body,
section.block.block_rocketchat .block-content,
section.block.block_rocketchat .content {
  padding: 0 !important;
  margin: 0 !important;
}

/* ===== Rocket.Chat drawer + navbar button ===== */

/* Drawer */
#rc-drawer {
  position: fixed;
  top: 0;
  right: 0;
  height: 100vh;
  width: min(420px, 92vw);
  background: #fff;
  border-left: 1px solid rgba(0,0,0,0.08);
  transform: translateX(100%);
  transition: transform 200ms ease;
  z-index: 2001;
  display: flex;
  flex-direction: column;
  box-shadow: -10px 0 30px rgba(0,0,0,0.18);
}
#rc-drawer.is-open { transform: translateX(0); }

#rc-drawer .rc-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 12px;
  border-bottom: 1px solid rgba(0,0,0,0.08);
  background: #f8fafc;
}
#rc-drawer .rc-drawer-title { font-weight: 800; font-size: 14px; }

#rc-drawer .rc-drawer-close {
  border: 1px solid #4f46e5;
  background: #4f46e5;
  color: #fff;
  border-radius: 10px;
  padding: 6px 10px;
  cursor: pointer;
  font-weight: 800;
  line-height: 1;
}
#rc-drawer .rc-drawer-close:hover { filter: brightness(0.95); }
#rc-drawer .rc-drawer-close:active { filter: brightness(0.90); }

#rc-drawer-iframe { width: 100%; height: 100%; border: 0; display: block; }

/* Navbar button (force visibility) */
#rc-top-icon,
#rc-top-icon * {
  opacity: 1 !important;
  visibility: visible !important;
  filter: none !important;
}

#rc-top-icon {
  display: inline-flex !important;
  align-items: center !important;
}

#rc-top-icon-link.nav-link {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  padding: 0 !important;
  margin: 0 6px !important;
  text-decoration: none !important;
  background: transparent !important;
  color: #fff !important;
  font-size: 14px !important;
  line-height: 1 !important;

  /* NEW: required so the unread badge can be positioned on top of the icon */
  position: relative !important;
  overflow: visible !important;
}

#rc-top-icon-link.nav-link:focus {
  outline: 2px solid rgba(79,70,229,0.35);
  outline-offset: 2px;
  border-radius: 999px;
}

#rc-top-icon .rc-navbtn {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 34px !important;
  height: 34px !important;
  border-radius: 999px !important;
  background-color: #4f46e5 !important;
  color: #fff !important;
  box-shadow: 0 6px 18px rgba(79,70,229,0.18) !important;
}

#rc-top-icon .rc-navbtn__icon {
  width: 18px !important;
  height: 18px !important;
  display: block !important;
}

/* NEW: unread message badge on the navbar icon */
#rc-top-unread-badge {
  position: absolute;
  top: 4px;
  right: -4px;
  display: none;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: #e53935;
  color: #fff;
  font-size: 11px;
  font-weight: 900;
  line-height: 18px;
  text-align: center;
  pointer-events: none;
  z-index: 3;
  box-shadow: 0 6px 18px rgba(229,57,53,0.25);
}

#rc-top-unread-badge.is-visible {
  display: inline-block;
}
