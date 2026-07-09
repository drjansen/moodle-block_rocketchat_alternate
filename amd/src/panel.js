/**
 * Rocket.Chat panel initializer.
 *
 * This AMD module wires up the UI rendered by templates/panel.mustache.
 *
 * Expected usage:
 *   $PAGE->requires->js_call_amd('block_rocketchat/panel', 'init', [$config]);
 *
 * Where $config contains:
 * {
 *   shellId, backId, iframeId,
 *   chatScreenUrl, courseId, ajaxUrl, sesskey,
 *   roomFilterId, newChatId,
 *   modalId, modalCloseId, modalFilterId, modalListId, warnId,
 *   logoutId, logoutUrl,
 *   stateKey, unreadPollMs, debugUnread
 * }
 */
define([], function() {
    'use strict';

    // Singleton poller per browser tab to avoid multiple intervals across pages/instances.
    // (Still allows different tabs to poll, but each tab only gets one timer.)
    window.__rcUnreadPoll = window.__rcUnreadPoll || {timer: null, ms: 0};

    /**
     * Initialise the Rocket.Chat panel.
     *
     * @param {Object} cfg Initialiser configuration.
     */
    function init(cfg) {
        cfg = cfg || {};

        var shell = document.getElementById(cfg.shellId);
        if (!shell) {
            return;
        }

        // Ensure we only init once per DOM node (navigation/rerender safety).
        if (shell.dataset && shell.dataset.rcInited === '1') {
            return;
        }
        if (shell.dataset) {
            shell.dataset.rcInited = '1';
        }

        var backBtn = document.getElementById(cfg.backId);
        var iframe = document.getElementById(cfg.iframeId);
        var roomFilter = document.getElementById(cfg.roomFilterId);
        var newChatBtn = document.getElementById(cfg.newChatId);
        var logoutBtn = document.getElementById(cfg.logoutId);

        var modal = document.getElementById(cfg.modalId);
        var modalClose = document.getElementById(cfg.modalCloseId);
        var modalFilter = document.getElementById(cfg.modalFilterId);
        var modalList = document.getElementById(cfg.modalListId);
        var warnEl = document.getElementById(cfg.warnId);

        /**
         * Debug helper (intentionally no-op to satisfy Moodle linting rules).
         *
         * @param {...*} args Ignored.
         */
        function debug(args) { // eslint-disable-line no-unused-vars
            // Moodle eslint forbids console.* in AMD modules (no-console).
            // Keep a stub to avoid reworking call sites.
            void args;
        }

        /**
         * Show or hide a warning message in the modal.
         *
         * @param {String} msg Message to display (empty to hide).
         */
        function showWarn(msg) {
            if (!warnEl) {
                return;
            }
            warnEl.style.display = msg ? 'block' : 'none';
            warnEl.textContent = msg || '';
        }

        /**
         * Persist last open chat to sessionStorage.
         *
         * @param {String} roomid Room id.
         * @param {String} roomtype Room type.
         * @param {String} roomname Room name.
         */
        function saveLastChat(roomid, roomtype, roomname) {
            try {
                sessionStorage.setItem(
                    cfg.stateKey,
                    JSON.stringify({roomid: roomid, roomtype: roomtype, roomname: roomname || ''})
                );
            } catch (e) {
                // ignore
            }
        }

        /**
         * Clear last open chat from sessionStorage.
         */
        function clearLastChat() {
            try {
                sessionStorage.removeItem(cfg.stateKey);
            } catch (e) {
                // ignore
            }
        }

        /**
         * Load last open chat from sessionStorage.
         *
         * @returns {Object|null} Last chat state, or null.
         */
        function loadLastChat() {
            try {
                var raw = sessionStorage.getItem(cfg.stateKey);
                if (!raw) {
                    return null;
                }
                var obj = JSON.parse(raw);
                if (!obj || !obj.roomid || !obj.roomtype) {
                    return null;
                }
                return obj;
            } catch (e) {
                return null;
            }
        }

        /**
         * Switch UI to room list.
         */
        function showRoomList() {
            shell.classList.remove('is-chat');
            if (iframe) {
                iframe.src = 'about:blank';
            }
            clearLastChat();
        }

        /**
         * Switch UI to chat view.
         *
         * @param {String} roomid Room id.
         * @param {String} roomtype Room type.
         * @param {String} roomname Room name.
         */
        function showChat(roomid, roomtype, roomname) {
            shell.classList.add('is-chat');
            saveLastChat(roomid, roomtype, roomname || '');

            var url =
                cfg.chatScreenUrl +
                '?roomid=' +
                encodeURIComponent(roomid) +
                '&roomtype=' +
                encodeURIComponent(roomtype) +
                '&roomname=' +
                encodeURIComponent(roomname || '') +
                '&courseid=' +
                encodeURIComponent(String(cfg.courseId || 0));

            if (iframe) {
                iframe.src = url;
            }
        }

        // Room click handlers.
        shell.querySelectorAll('.rocketchat-room[data-rc-roomid]').forEach(function(el) {
            el.addEventListener('click', function() {
                showChat(
                    el.getAttribute('data-rc-roomid'),
                    el.getAttribute('data-rc-roomtype'),
                    el.getAttribute('data-rc-roomname')
                );
            });
        });

        /**
         * Apply filter to rooms list.
         */
        function applyRoomFilter() {
            var q = (roomFilter && roomFilter.value ? roomFilter.value : '').toLowerCase().trim();
            shell.querySelectorAll('.rocketchat-room[data-rc-roomid]').forEach(function(el) {
                var name = (el.getAttribute('data-rc-roomname') || '').toLowerCase();
                el.style.display = !q || name.indexOf(q) !== -1 ? '' : 'none';
            });
        }

        if (roomFilter) {
            roomFilter.addEventListener('input', applyRoomFilter);
        }

        /**
         * Open the "new chat" modal.
         */
        function openModal() {
            if (!modal) {
                return;
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            showWarn('');

            if (modalFilter) {
                modalFilter.value = '';
                modalFilter.focus();
            }
            if (modalList) {
                modalList.innerHTML = '<div class="rocketchat-modal__empty">Type to search…</div>';
            }
        }

        /**
         * Close the "new chat" modal.
         */
        function closeModal() {
            if (!modal) {
                return;
            }
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }

        if (newChatBtn) {
            newChatBtn.addEventListener('click', openModal);
        }
        if (modalClose) {
            modalClose.addEventListener('click', closeModal);
        }
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        /**
         * Perform a GET request to the block ajax endpoint.
         *
         * @param {Object} params Query parameters.
         * @returns {Promise<Object>} Parsed JSON response.
         */
        async function apiGet(params) {
            var usp = new URLSearchParams(params || {});
            var resp = await fetch(cfg.ajaxUrl + '?' + usp.toString(), {credentials: 'same-origin'});

            var data = await resp.json().catch(function() {
                return null;
            });

            if (!data) {
                var err0 = new Error('Invalid response');
                err0.httpStatus = resp.status;
                throw err0;
            }

            if (!resp.ok || data.success === false) {
                var err = new Error(data.error || 'HTTP ' + resp.status);
                err.httpStatus = resp.status;
                throw err;
            }

            return data;
        }

        /**
         * Find the room element by Rocket.Chat rid.
         *
         * @param {String} rid Rocket.Chat room id.
         * @returns {HTMLElement|null} Room element, or null.
         */
        function findRoomElByRid(rid) {
            rid = String(rid || '');
            if (!rid) {
                return null;
            }
            var els = shell.querySelectorAll('.rocketchat-room[data-rc-roomid]');
            for (var i = 0; i < els.length; i++) {
                if (String(els[i].getAttribute('data-rc-roomid') || '') === rid) {
                    return els[i];
                }
            }
            return null;
        }

        /**
         * Clear unread/alert indicators from a room element.
         *
         * @param {HTMLElement} roomEl Room element.
         */
        function clearIndicators(roomEl) {
            if (!roomEl) {
                return;
            }
            var b = roomEl.querySelector('.rocketchat-unread-badge');
            if (b) {
                b.remove();
            }
            var d = roomEl.querySelector('.rocketchat-unread-dot');
            if (d) {
                d.remove();
            }
        }

        /**
         * Set unread/alert indicators on a room element.
         *
         * @param {HTMLElement} roomEl Room element.
         * @param {Number} unread Unread count.
         * @param {Boolean} alert Alert flag.
         */
        function setIndicators(roomEl, unread, alert) {
            unread = Number(unread || 0);
            alert = !!alert;

            if (!roomEl) {
                return;
            }

            clearIndicators(roomEl);

            if (unread > 0) {
                var b = document.createElement('span');
                b.className = 'rocketchat-unread-badge';
                b.textContent = String(unread);
                roomEl.appendChild(b);
                return;
            }

            if (alert) {
                var d = document.createElement('span');
                d.className = 'rocketchat-unread-dot';
                roomEl.appendChild(d);
            }
        }

        /**
         * Refresh unread state from ajax endpoint.
         *
         * @returns {Promise<void>}
         */
        async function refreshUnread() {
            try {
                var data = await apiGet({action: 'subscriptions', sesskey: cfg.sesskey});

                debug(data);

                var rooms = data && data.rooms ? data.rooms : [];
                if (!Array.isArray(rooms)) {
                    return;
                }

                for (var i = 0; i < rooms.length; i++) {
                    var r = rooms[i];
                    if (!r || !r.rid) {
                        continue;
                    }
                    var el = findRoomElByRid(r.rid);
                    if (!el) {
                        continue;
                    }
                    setIndicators(el, r.unread, r.alert);
                }
            } catch (e) {
                debug(e);

                // If we are rate-limited, back off for this tab.
                if (e && e.httpStatus === 429) {
                    // Increase interval to at least 5 minutes.
                    cfg.unreadPollMs = Math.max(Number(cfg.unreadPollMs || 0), 300000);
                    startUnreadPolling();
                }
            }
        }

        /**
         * Start unread polling, using a per-tab singleton timer.
         */
        function startUnreadPolling() {
            var ms = Number(cfg.unreadPollMs || 180000);

            // If already running at same/faster interval, do not start another timer.
            if (window.__rcUnreadPoll.timer && window.__rcUnreadPoll.ms && window.__rcUnreadPoll.ms <= ms) {
                refreshUnread();
                return;
            }

            // Replace existing timer (or start fresh).
            if (window.__rcUnreadPoll.timer) {
                clearInterval(window.__rcUnreadPoll.timer);
            }

            window.__rcUnreadPoll.ms = ms;
            refreshUnread();
            window.__rcUnreadPoll.timer = setInterval(refreshUnread, ms);
        }

        // Start polling.
        startUnreadPolling();

        // Refresh on focus/visibility changes (nice UX without extra interval pressure).
        window.addEventListener('focus', function() {
            refreshUnread();
        });
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                refreshUnread();
            }
        });

        // --- New chat search (teachersearch + imcreate) ---
        var searchTimer = null;
        var searchSeq = 0;

        /**
         * Normalise a string for case-insensitive searching.
         *
         * @param {String} s Input.
         * @returns {String} Normalised string.
         */
        function normalizeSearch(s) {
            return String(s || '').toLowerCase().trim();
        }

        /**
         * Run user search and render results in the modal.
         *
         * @returns {Promise<void>}
         */
        async function doUserSearch() {
            var q = (modalFilter && modalFilter.value ? modalFilter.value : '').trim();

            if (!modalList) {
                return;
            }

            showWarn('');

            if (q.length < 2) {
                modalList.innerHTML = '<div class="rocketchat-modal__empty">Type at least 2 characters…</div>';
                return;
            }

            var mySeq = ++searchSeq;
            modalList.innerHTML = '<div class="rocketchat-modal__empty">Searching…</div>';

            try {
                var data = await apiGet({action: 'teachersearch', q: q, sesskey: cfg.sesskey});
                if (mySeq < searchSeq) {
                    return;
                }

                // Surface warnings (e.g. service search failed and we fell back to cache).
                if (data && data.warning) {
                    showWarn(String(data.warning));
                }

                var res = data && data.results ? data.results : [];
                if (!Array.isArray(res)) {
                    res = [];
                }

                // Local filter to ensure displayed results always match current input.
                var nq = normalizeSearch(modalFilter && modalFilter.value ? modalFilter.value : q);
                res = res.filter(function(u) {
                    var name = normalizeSearch(u && u.name);
                    var user = normalizeSearch(u && u.username);
                    return !nq || name.indexOf(nq) !== -1 || user.indexOf(nq) !== -1;
                });

                if (!res.length) {
                    modalList.innerHTML = '<div class="rocketchat-modal__empty">No matches.</div>';
                    return;
                }

                modalList.innerHTML = '';
                res.forEach(function(u) {
                    var el = document.createElement('div');
                    el.className = 'rocketchat-contact';
                    el.setAttribute('data-rc-username', (u && u.username) || '');
                    el.setAttribute('data-rc-name', (u && (u.name || u.username)) || '');

                    el.innerHTML =
                        '<div class="rocketchat-contact__name"></div><div class="rocketchat-contact__user"></div>';

                    el.querySelector('.rocketchat-contact__name').textContent = (u && (u.name || u.username)) || '';
                    el.querySelector('.rocketchat-contact__user').textContent = '@' + ((u && u.username) || '');

                    el.addEventListener('click', async function() {
                        var username = el.getAttribute('data-rc-username');
                        if (!username) {
                            return;
                        }

                        try {
                            var created = await apiGet({
                                action: 'imcreate',
                                username: username,
                                sesskey: cfg.sesskey
                            });

                            closeModal();

                            // Navigate to the created/returned room.
                            // Expected shape: created.room.rid
                            var rid = created && created.room ? created.room.rid : null;
                            if (rid) {
                                showChat(rid, 'd', el.getAttribute('data-rc-name') || username);
                            }

                            refreshUnread();
                        } catch (e) {
                            showWarn((e && e.message) || String(e));
                        }
                    });

                    modalList.appendChild(el);
                });
            } catch (e) {
                if (mySeq < searchSeq) {
                    return;
                }
                showWarn((e && e.message) || String(e));
                modalList.innerHTML = '<div class="rocketchat-modal__empty">Search failed.</div>';
            }
        }

        if (modalFilter) {
            modalFilter.addEventListener('input', function() {
                if (searchTimer) {
                    clearTimeout(searchTimer);
                }
                searchTimer = setTimeout(doUserSearch, 250);
            });

            modalFilter.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    doUserSearch();
                }
            });
        }

        // Back button.
        if (backBtn) {
            backBtn.addEventListener('click', showRoomList);
        }

        // Logout button: POST to logout.php with sesskey (CSRF protection).
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = cfg.logoutUrl;

                var sk = document.createElement('input');
                sk.type = 'hidden';
                sk.name = 'sesskey';
                sk.value = cfg.sesskey;
                form.appendChild(sk);

                var ci = document.createElement('input');
                ci.type = 'hidden';
                ci.name = 'courseid';
                ci.value = String(cfg.courseId || 0);
                form.appendChild(ci);

                document.body.appendChild(form);
                form.submit();
            });
        }

        // Restore last open chat if present.
        var last = loadLastChat();
        if (last) {
            showChat(last.roomid, last.roomtype, last.roomname || '');
        } else {
            showRoomList();
        }
    }

    return {init: init};
});
