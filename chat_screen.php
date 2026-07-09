<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Fragment-friendly Rocket.Chat chat screen for block embedding.
 *
 * NOTE: This script is loaded inside an iframe in the block so it can execute JS.
 * It intentionally outputs only a fragment (no header/footer) so it can be embedded.
 *
 * @package   block_rocketchat
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Validate inputs. Room IDs are Rocket.Chat IDs (typically [A-Za-z0-9]).
// Use ALPHANUMEXT to avoid accepting arbitrary characters.
$roomid = required_param('roomid', PARAM_ALPHANUMEXT);
$roomtype = required_param('roomtype', PARAM_ALPHA);
$roomname = optional_param('roomname', '', PARAM_RAW_TRIMMED);
// Strip mc_ prefix if somehow passed with it (defence-in-depth; panel strips it before navigation).
if (strncmp($roomname, 'mc_', 3) === 0) {
    $roomname = substr($roomname, 3);
}
$courseid = optional_param('courseid', 0, PARAM_INT);

require_login();

// Set the most relevant context if courseid is provided.
$context = context_system::instance();
if ($courseid > 0) {
    try {
        $course = get_course($courseid);
        $context = context_course::instance($course->id);
    } catch (dml_missing_record_exception $e) {
        // Ignore invalid courseid and fall back to system context.
        $courseid = 0;
    }
}
$PAGE->set_context($context);

// Normalize and validate roomtype.
$roomtype = strtolower($roomtype);
if ($roomtype === 'g') {
    $roomtype = 'p';
}

$endpointmap = [
    'c' => 'channels.messages',
    'p' => 'groups.messages',
    'd' => 'im.messages',
];

if (!isset($endpointmap[$roomtype])) {
    http_response_code(400);
    echo html_writer::div('Invalid room type. Supported values: c, p, d, g.', 'alert alert-danger');
    exit;
}

// Require RC credentials in Moodle prefs.
$instanceurl = (new local_rocketchat\client())->get_instance_url();
$authtoken = (string)get_user_preferences('local_rocketchat_external_token', '');
$rcuserid = (string)get_user_preferences('local_rocketchat_external_userid', '');

if (empty($instanceurl) || empty($authtoken) || empty($rcuserid)) {
    echo html_writer::div(get_string('notloggedin', 'moodle'), 'alert alert-warning');
    exit;
}

$widgetid = 'rocketchat-chat-' . uniqid('', true);

// Note: we do NOT expose Rocket.Chat auth token to the browser. All calls go via ajax.php proxy.
// Include sesskey on ALL ajax.php requests (ajax.php now requires sesskey globally).
$jsconfig = [
    'roomId' => $roomid,
    'roomType' => $roomtype,
    'roomName' => $roomname,
    'messagesEndpoint' => $endpointmap[$roomtype],
    'widgetId' => $widgetid,
    'proxyBase' => $CFG->wwwroot . '/blocks/rocketchat/ajax.php',
    'rcBaseUrl' => rtrim($instanceurl, '/'),
    'sesskey' => sesskey(),
    // Rocket.Chat "X-User-Id" (NOT Moodle user id). Used to color own vs other.
    'currentUserId' => $rcuserid,
];
?>
<div id="<?php echo s($widgetid); ?>" class="rocketchat-chat-fragment">
    <div class="rc-chat">
        <div class="rc-chat__top">
            <div class="rc-chat__top-left">
                <button type="button" class="rc-chat__backbtn" id="rc-chat-back" aria-label="Back to room list" title="Back to room list">&#x21A9;</button>
                <div class="rc-chat__roomname" title="<?php echo s($roomname !== '' ? $roomname : $roomid); ?>">
                    <?php echo s($roomname !== '' ? $roomname : $roomid); ?>
                </div>
            </div>

            <div class="rc-chat__top-right">
                <form class="rc-chat__search" data-role="search-form" autocomplete="off">
                    <input type="text" class="rc-chat__searchinput" data-role="search-input" placeholder="Search messages…">
                    <button type="submit" class="rc-chat__searchgo" data-role="search-go" aria-label="Search" title="Search">✓</button>

                    <div class="rc-chat__searchmeta" data-role="search-meta" style="display:none;">
                        <span class="rc-chat__searchcount" data-role="search-count">0 matches</span>
                        <button type="button" class="rc-chat__navbtn" data-role="search-prev" aria-label="Previous match" title="Previous">↑</button>
                        <button type="button" class="rc-chat__navbtn" data-role="search-next" aria-label="Next match" title="Next">↓</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="rc-chat__status" data-role="status" style="display:none;"></div>
        <div class="rc-chat__history" data-role="history" aria-live="polite"></div>

        <form class="rc-chat__composer" data-role="send-form">
            <input type="file" data-role="file-input" class="rc-chat__file" style="display:none;" />

            <div class="rc-chat__composer-row">
                <input type="text" data-role="input" class="rc-chat__input" placeholder="Type a message…" autocomplete="off">
                <button type="submit" data-role="send-button" class="rc-chat__send">Send</button>
            </div>

            <div class="rc-chat__composer-actions">
                <button type="button" class="rc-chat__actionbtn" data-role="attach-button" aria-label="Attach file" title="Attach file">Attach</button>
                <button type="button" class="rc-chat__actionbtn" data-role="emoji-button" aria-label="Emoji" title="Emoji">Emoji</button>
            </div>

            <div class="rc-emoji" data-role="emoji-panel" style="display:none;">
                <button type="button" class="rc-emoji__btn">👍</button>
                <button type="button" class="rc-emoji__btn">😂</button>
                <button type="button" class="rc-emoji__btn">❤️</button>
                <button type="button" class="rc-emoji__btn">🎉</button>
                <button type="button" class="rc-emoji__btn">🙏</button>
                <button type="button" class="rc-emoji__btn">🔥</button>
                <button type="button" class="rc-emoji__btn">😊</button>
                <button type="button" class="rc-emoji__btn">😢</button>
                <button type="button" class="rc-emoji__btn">😡</button>
                <button type="button" class="rc-emoji__btn">😅</button>
            </div>
        </form>
    </div>
</div>

<style>
    .rocketchat-chat-fragment {
        height: 100%;
        width: 100%;
        background: transparent;
        font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }

    .rc-chat {
        height: 100%;
        width: 100%;
        display: flex;
        flex-direction: column;
        border: 0;
        border-radius: 0;
        background: #fff;
    }

    .rc-chat__top {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap: 8px;
        padding: 8px 10px;
        border-bottom: 1px solid #eef0f4;
        background: linear-gradient(180deg, #ffffff 0%, #fbfbfd 100%);
        position: sticky;
        top: 0;
        z-index: 2;
    }

    .rc-chat__top-left, .rc-chat__top-right { min-width: 0; }

    .rc-chat__top-left { flex: 1; display: flex; align-items: center; gap: 6px; }
    .rc-chat__top-right { flex: 0 0 auto; }

    .rc-chat__backbtn {
        flex-shrink: 0;
        background: none;
        border: none;
        cursor: pointer;
        padding: 2px 4px;
        font-size: 18px;
        line-height: 1;
        color: #374151;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s;
    }
    .rc-chat__backbtn:hover { background: #f3f4f6; }
    .rc-chat__backbtn:active { background: #e5e7eb; }

    .rc-chat__roomname {
        font-weight: 800;
        font-size: 14px;
        color: #111827;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Search UI */
    .rc-chat__search {
        display:flex;
        align-items:center;
        gap: 6px;
    }

    .rc-chat__searchinput {
        width: 160px;
        max-width: 40vw;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 6px 8px;
        font-size: 12px;
        outline: none;
    }
    .rc-chat__searchinput:focus {
        border-color: #a5b4fc;
        box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
    }

    .rc-chat__searchgo {
        border: 1px solid #c7d2fe;
        background: #eef2ff;
        color: #3730a3;
        border-radius: 10px;
        padding: 6px 8px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 800;
        line-height: 1;
    }

    .rc-chat__searchmeta {
        display:flex;
        align-items:center;
        gap: 4px;
        padding-left: 2px;
    }

    .rc-chat__searchcount {
        font-size: 12px;
        color: #374151;
        white-space: nowrap;
    }

    .rc-chat__navbtn {
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #374151;
        border-radius: 8px;
        padding: 4px 6px;
        cursor: pointer;
        font-size: 12px;
        line-height: 1;
    }
    .rc-chat__navbtn:disabled { opacity: 0.5; cursor: not-allowed; }

    .rc-chat__history {
        flex: 1;
        overflow: auto;
        padding: 6px;
        background: #f7f8fb;
        /* Helps browser anchor scrolling near bottom more reliably during re-render */
        scroll-behavior: auto;
    }

    .rc-chat__empty {
        color: #9ca3af;
        font-size: 12px;
        padding: 10px;
        text-align: center;
    }

    /* Message base */
    .rc-msg {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 8px 8px;
        border-radius: 12px;
        border: 1px solid #eef0f4;
        margin-bottom: 6px;
        box-shadow: 0 1px 0 rgba(0,0,0,0.02);
    }

    .rc-msg--own { background: #eef2ff; border-color: #c7d2fe; }
    .rc-msg--other { background: #ffffff; border-color: #eef0f4; }

    /* Highlighted search result */
    .rc-msg--match {
        outline: 2px solid #f59e0b;
        box-shadow: 0 0 0 4px rgba(245,158,11,0.18);
    }

    .rc-msg__meta {
        font-size: 12px;
        color: #6b7280;
        display:flex;
        gap: 6px;
        align-items: baseline;
        flex-wrap: wrap;
    }

    .rc-msg__sender { font-weight: 800; color: #111827; }
    .rc-msg__time { color: #6b7280; }

    .rc-msg__body {
        font-size: 13px;
        color: #111827;
        white-space: pre-wrap;
        word-break: break-word;
        line-height: 1.35;
    }

    .rc-attach { margin-top: 6px; display:flex; flex-direction: column; gap: 6px; }
    .rc-attach a { color: #2563eb; text-decoration: none; }
    .rc-attach a:hover { text-decoration: underline; }
    .rc-attach img { max-width: 100%; max-height: 240px; border-radius: 10px; border: 1px solid #e5e7eb; background: #fff; display:block; }

    .rc-chat__composer {
        display:flex;
        flex-direction: column;
        gap: 6px;
        padding: 8px 10px;
        border-top: 1px solid #eef0f4;
        background: #fff;
        position: sticky;
        bottom: 0;
        z-index: 2;
    }

    .rc-chat__composer-row { display:flex; gap: 8px; align-items: center; }
    .rc-chat__composer-actions { display:flex; gap: 8px; align-items: center; }

    .rc-chat__actionbtn {
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #374151;
        border-radius: 10px;
        padding: 6px 10px;
        font-size: 12px;
        cursor: pointer;
        transition: background 120ms ease, border-color 120ms ease, transform 120ms ease;
    }
    .rc-chat__actionbtn:hover { background:#f3f4f6; border-color:#d1d5db; transform: translateY(-1px); }
    .rc-chat__actionbtn:active { transform: translateY(0); }

    .rc-chat__input {
        flex: 1;
        min-width: 0;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 9px 10px;
        font-size: 13px;
        outline: none;
        background: #fff;
    }
    .rc-chat__input:focus {
        border-color: #a5b4fc;
        box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
    }

    .rc-chat__send {
        border: 1px solid #4f46e5;
        background: #4f46e5;
        color: #fff;
        border-radius: 12px;
        padding: 9px 12px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
        transition: filter 120ms ease, transform 120ms ease;
        white-space: nowrap;
    }
    .rc-chat__send:hover { filter: brightness(0.96); transform: translateY(-1px); }
    .rc-chat__send:active { transform: translateY(0); }
    .rc-chat__send:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }

    .rc-emoji {
        display:flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 8px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
    }

    .rc-emoji__btn {
        border: 1px solid #e5e7eb;
        background: #fff;
        border-radius: 10px;
        padding: 6px 8px;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
    }
    .rc-emoji__btn:hover { background: #f3f4f6; }
</style>

<script>
    (function() {
        var config = <?php echo json_encode($jsconfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var root = document.getElementById(config.widgetId);
        if (!root) return;

        var historyEl = root.querySelector('[data-role="history"]');
        var statusEl = root.querySelector('[data-role="status"]');
        var inputEl = root.querySelector('[data-role="input"]');
        var fileInputEl = root.querySelector('[data-role="file-input"]');
        var attachButtonEl = root.querySelector('[data-role="attach-button"]');
        var emojiButtonEl = root.querySelector('[data-role="emoji-button"]');
        var emojiPanelEl = root.querySelector('[data-role="emoji-panel"]');
        var sendButtonEl = root.querySelector('[data-role="send-button"]');
        var formEl = root.querySelector('[data-role="send-form"]');

        var searchFormEl = root.querySelector('[data-role="search-form"]');
        var searchInputEl = root.querySelector('[data-role="search-input"]');
        var searchMetaEl = root.querySelector('[data-role="search-meta"]');
        var searchCountEl = root.querySelector('[data-role="search-count"]');
        var searchPrevEl = root.querySelector('[data-role="search-prev"]');
        var searchNextEl = root.querySelector('[data-role="search-next"]');

        // Back button: sends a postMessage to the parent shell to return to the room list.
        var backBtnEl = document.getElementById('rc-chat-back');
        if (backBtnEl) {
            backBtnEl.addEventListener('click', function() {
                try {
                    window.parent.postMessage({type: 'rocketchat-back'}, window.location.origin);
                } catch (e) {
                    // If postMessage fails (e.g. not in iframe), do nothing.
                }
            });
        }

        var pollIntervalMs = 8000;
        var pollHandle = null;
        var detachObserver = null;
        var isDestroyed = false;

        // --- KakaoTalk-like autoscroll state ---
        var bottomThresholdPx = 120;
        var userPinned = false;
        var isFirstLoad = true;

        // Search state
        var searchMatches = [];
        var searchIndex = -1;
        var lastSearchTerm = '';

        var emojiMap = {
            'sweat_smile': '😅',
            'smile': '😄',
            'grinning': '😀',
            'joy': '😂',
            'laughing': '😆',
            'wink': '😉',
            'blush': '😊',
            'heart': '❤️',
            'thumbsup': '👍',
            '+1': '👍',
            'tada': '🎉',
            'fire': '🔥',
            'pray': '🙏',
            'cry': '😢',
            'angry': '😡',
            'open_mouth': '😮',
            'sunglasses': '😎'
        };

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function(ch) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
            });
        }

        function emojify(text) {
            var s = String(text || '');
            return s.replace(/:([a-zA-Z0-9_+\-]+):/g, function(_, name) {
                var key = String(name || '').toLowerCase();
                return emojiMap[key] ? emojiMap[key] : (':' + name + ':');
            });
        }

        function toAbsolute(url) {
            if (!url) return '';
            var u = String(url);
            if (u.indexOf('http://') === 0 || u.indexOf('https://') === 0) return u;
            if (u.charAt(0) === '/') return String(config.rcBaseUrl || '').replace(/\/+$/, '') + u;
            return u;
        }

        function isLikelyImageByName(name) {
            var n = String(name || '').toLowerCase();
            return n.endsWith('.png') || n.endsWith('.jpg') || n.endsWith('.jpeg') ||
                n.endsWith('.gif') || n.endsWith('.webp') || n.endsWith('.jfif');
        }

        function formatTimestamp(message) {
            var ts = message.ts && message.ts.$date ? message.ts.$date : message.ts;
            if (!ts) return '';
            var date = new Date(ts);
            if (isNaN(date.getTime())) return '';
            return date.toLocaleString();
        }

        function setStatus(text, isError) {
            if (!statusEl) return;
            statusEl.style.display = text ? '' : 'none';
            statusEl.style.color = isError ? '#b91c1c' : '#6b7280';
            statusEl.textContent = text;
        }

        function isNearBottom(el) {
            var d = el.scrollHeight - el.scrollTop - el.clientHeight;
            return d < bottomThresholdPx;
        }

        /**
         * Stronger "go to bottom" logic:
         * - immediate set
         * - next animation frame set
         * - short timeout set (covers late image/layout)
         */
        function scrollToBottomSoon() {
            if (!historyEl) return;

            historyEl.scrollTop = historyEl.scrollHeight;

            requestAnimationFrame(function() {
                historyEl.scrollTop = historyEl.scrollHeight;

                setTimeout(function() {
                    historyEl.scrollTop = historyEl.scrollHeight;
                }, 50);

                setTimeout(function() {
                    historyEl.scrollTop = historyEl.scrollHeight;
                }, 250);
            });
        }

        function clearMatchHighlight() {
            historyEl.querySelectorAll('.rc-msg--match').forEach(function(el) {
                el.classList.remove('rc-msg--match');
            });
        }

        function setSearchMeta(text, showNav) {
            if (!searchMetaEl || !searchCountEl) return;
            searchMetaEl.style.display = 'flex';
            searchCountEl.textContent = text;

            if (searchPrevEl) searchPrevEl.disabled = !showNav;
            if (searchNextEl) searchNextEl.disabled = !showNav;
        }

        function hideSearchMetaIfEmpty() {
            if (!searchMetaEl) return;
            if (!lastSearchTerm) {
                searchMetaEl.style.display = 'none';
            }
        }

        function scrollMatchIntoView(matchEl) {
            if (!matchEl) return;
            // Disable autoscroll while we jump.
            userPinned = true;

            matchEl.scrollIntoView({ behavior: 'smooth', block: 'center' });

            clearMatchHighlight();
            matchEl.classList.add('rc-msg--match');
        }

        function updateSearchUI() {
            if (!lastSearchTerm) {
                hideSearchMetaIfEmpty();
                return;
            }
            if (searchMatches.length === 0) {
                setSearchMeta('0 matches found', false);
                return;
            }
            setSearchMeta((searchIndex + 1) + '/' + searchMatches.length + ' found', searchMatches.length > 1);
        }

        function applySearch(term) {
            lastSearchTerm = (term || '').trim();
            searchMatches = [];
            searchIndex = -1;
            clearMatchHighlight();

            if (!lastSearchTerm) {
                hideSearchMetaIfEmpty();
                return;
            }

            var q = lastSearchTerm.toLowerCase();
            var msgEls = Array.from(historyEl.querySelectorAll('.rc-msg'));
            msgEls.forEach(function(msgEl) {
                var bodyEl = msgEl.querySelector('.rc-msg__body');
                var hay = bodyEl ? (bodyEl.textContent || '').toLowerCase() : '';
                if (hay.indexOf(q) !== -1) {
                    searchMatches.push(msgEl);
                }
            });

            if (searchMatches.length > 0) {
                searchIndex = 0;
                scrollMatchIntoView(searchMatches[searchIndex]);
            }

            updateSearchUI();
        }

        function gotoPrevMatch() {
            if (searchMatches.length === 0) return;
            searchIndex = (searchIndex - 1 + searchMatches.length) % searchMatches.length;
            scrollMatchIntoView(searchMatches[searchIndex]);
            updateSearchUI();
        }

        function gotoNextMatch() {
            if (searchMatches.length === 0) return;
            searchIndex = (searchIndex + 1) % searchMatches.length;
            scrollMatchIntoView(searchMatches[searchIndex]);
            updateSearchUI();
        }

        function renderAttachment(attachment) {
            var blocks = [];
            var title = attachment.title || attachment.name || '';
            var link = attachment.title_link || attachment.titleLink || attachment.link || '';
            var imageUrl = attachment.image_url || attachment.imageUrl || '';

            link = toAbsolute(link);
            imageUrl = toAbsolute(imageUrl);

            if (title || link) {
                if (link) {
                    blocks.push('<div><a href="' + escapeHtml(link) + '" target="_blank" rel="noopener noreferrer">' +
                        escapeHtml(title || link) + '</a></div>');
                } else {
                    blocks.push('<div>' + escapeHtml(title) + '</div>');
                }
            }

            if (imageUrl) {
                blocks.push('<div><a href="' + escapeHtml(imageUrl) + '" target="_blank" rel="noopener noreferrer">' +
                    '<img src="' + escapeHtml(imageUrl) + '" alt="attachment" loading="lazy"></a></div>');
            }

            return blocks.length ? '<div class="rc-attach">' + blocks.join('') + '</div>' : '';
        }

        async function proxyFetch(path, options) {
            var requestOptions = options || {};
            var finalOptions = Object.assign({ method:'GET', credentials:'same-origin' }, requestOptions);
            var url = config.proxyBase + path;

            var response = await fetch(url, finalOptions);
            var responseBody = await response.text();

            var parsedBody;
            try { parsedBody = JSON.parse(responseBody); } catch (e) { parsedBody = responseBody; }

            if (!response.ok) {
                var msg = 'Proxy request failed with HTTP ' + response.status;
                if (parsedBody && typeof parsedBody === 'object' && parsedBody.error) msg += ': ' + parsedBody.error;
                throw new Error(msg);
            }

            return parsedBody;
        }

        async function markRoomRead() {
            try {
                var q = new URLSearchParams({
                    action: 'markread',
                    roomid: config.roomId,
                    sesskey: config.sesskey
                });
                await proxyFetch('?' + q.toString());
            } catch (e) {
                // Silent by design (we don't want to break chat if read fails).
            }
        }

        async function renderMessages(messages) {
            if (!Array.isArray(messages) || messages.length === 0) {
                historyEl.innerHTML = '<div class="rc-chat__empty">No messages yet.</div>';
                isFirstLoad = false;
                userPinned = false;
                return;
            }

            var wasNearBottom = isFirstLoad ? true : isNearBottom(historyEl);
            var shouldAutoScroll = isFirstLoad || (!userPinned && wasNearBottom);

            var sorted = messages.slice().sort(function(a, b) {
                var ta = new Date(a.ts && a.ts.$date ? a.ts.$date : a.ts || 0).getTime();
                var tb = new Date(b.ts && b.ts.$date ? b.ts.$date : b.ts || 0).getTime();
                return ta - tb;
            });

            var currentUserId = (config && config.currentUserId) ? String(config.currentUserId) : '';

            var htmlBlocks = sorted.map(function(message) {
                var username = message && message.u && message.u.username ? message.u.username : '';
                var displayName = (message && message.u && message.u.name) ? message.u.name : (username || 'Unknown');

                var rawText = message.msg || '';
                var text = emojify(rawText);

                var isOwn = false;
                try {
                    if (message && message.u && message.u._id && currentUserId) {
                        isOwn = String(message.u._id) === currentUserId;
                    }
                } catch (_) {}

                var cls = 'rc-msg ' + (isOwn ? 'rc-msg--own' : 'rc-msg--other');

                var attachments = Array.isArray(message.attachments) ? message.attachments : [];
                var attachmentHtml = attachments.map(renderAttachment).join('');

                return '<div class="' + cls + '">' +
                    '<div class="rc-msg__meta"><span class="rc-msg__sender">' + escapeHtml(displayName) + '</span>' +
                    '<span class="rc-msg__time">· ' + escapeHtml(formatTimestamp(message)) + '</span></div>' +
                    '<div class="rc-msg__body">' + escapeHtml(text) + '</div>' +
                    attachmentHtml +
                    '</div>';
            });

            historyEl.innerHTML = htmlBlocks.join('');

            // Re-apply search after re-render so navigation works even after polling.
            if (lastSearchTerm) {
                applySearch(lastSearchTerm);
            }

            if (shouldAutoScroll) {
                scrollToBottomSoon();
                userPinned = false;
            }

            isFirstLoad = false;
        }

        async function loadMessages() {
            if (isDestroyed) return;

            try {
                var query = new URLSearchParams({
                    action: 'messages',
                    roomid: config.roomId,
                    roomtype: config.roomType,
                    count: '100',
                    sesskey: config.sesskey
                });

                var data = await proxyFetch('?' + query.toString());
                var messages = data && Array.isArray(data.messages) ? data.messages : [];
                await renderMessages(messages);
                setStatus('', false);
            } catch (error) {
                setStatus('Failed to load messages: ' + error.message, true);
            }
        }

        async function sendMessage(text) {
            var body = new URLSearchParams({ text: text });
            var query = new URLSearchParams({
                action: 'post',
                roomid: config.roomId,
                sesskey: config.sesskey
            });

            await proxyFetch('?' + query.toString(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body
            });

            // Since user just sent a message, make sure "read" state is cleared.
            markRoomRead();
        }

        function chooseFile() {
            if (!fileInputEl) return;
            fileInputEl.value = '';
            fileInputEl.click();
        }

        function insertAtCursor(controllerEl, text) {
            var el = controllerEl;
            if (!el) return;
            var start = el.selectionStart || 0;
            var end = el.selectionEnd || 0;
            var value = el.value || '';
            el.value = value.substring(0, start) + text + value.substring(end);
            var newPos = start + text.length;
            el.setSelectionRange(newPos, newPos);
            el.focus();
        }

        async function postAttachmentMessage(fileInfo, caption) {
            if (!fileInfo || !fileInfo.url) return;

            var relativeUrl = String(fileInfo.url);
            var filename = String(fileInfo.name || (relativeUrl.split('/').pop() || 'file'));

            var trimmedCaption = (caption || '').trim();
            var hasCaption = trimmedCaption.length > 0;
            var isImage = isLikelyImageByName(filename);

            var textToSend = '';
            if (hasCaption) textToSend = trimmedCaption;
            else if (!isImage) textToSend = filename;

            var authedUrl = toAbsolute(relativeUrl);
            var attachment = { title: filename, title_link: authedUrl };
            if (isImage) attachment.image_url = authedUrl;

            var query = new URLSearchParams({ action: 'postattachment', roomid: config.roomId, sesskey: config.sesskey });

            await proxyFetch('?' + query.toString(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ text: textToSend, attachments: [attachment] })
            });

            // Attachment message posted, clear read status.
            markRoomRead();
        }

        async function uploadFile(file) {
            var caption = (inputEl && inputEl.value) ? inputEl.value.trim() : '';
            var query = new URLSearchParams({ action: 'upload', roomid: config.roomId, sesskey: config.sesskey });

            var form = new FormData();
            form.append('file', file, file.name);
            if (caption) form.append('msg', caption);

            var uploadResp = await proxyFetch('?' + query.toString(), { method: 'POST', body: form });

            if (uploadResp && uploadResp.file && uploadResp.file.url) {
                await postAttachmentMessage(uploadResp.file, caption);
            } else if (uploadResp && uploadResp.success === true && uploadResp.url) {
                await postAttachmentMessage(uploadResp, caption);
            }

            return uploadResp;
        }

        if (historyEl) {
            historyEl.addEventListener('scroll', function() {
                userPinned = !isNearBottom(historyEl);
            }, { passive: true });
        }

        if (attachButtonEl) attachButtonEl.addEventListener('click', chooseFile);

        if (emojiButtonEl) {
            emojiButtonEl.addEventListener('click', function() {
                if (!emojiPanelEl) return;
                emojiPanelEl.style.display = (emojiPanelEl.style.display === 'none' ? 'flex' : 'none');
            });
        }

        if (emojiPanelEl) {
            emojiPanelEl.addEventListener('click', function(e) {
                var t = e.target;
                if (!t || !t.classList || !t.classList.contains('rc-emoji__btn')) return;
                insertAtCursor(inputEl, t.textContent || '');
            });
        }

        if (searchFormEl) {
            searchFormEl.addEventListener('submit', function(e) {
                e.preventDefault();
                applySearch(searchInputEl ? searchInputEl.value : '');
            });
        }

        if (searchPrevEl) searchPrevEl.addEventListener('click', gotoPrevMatch);
        if (searchNextEl) searchNextEl.addEventListener('click', gotoNextMatch);

        if (fileInputEl) {
            fileInputEl.addEventListener('change', async function() {
                if (!fileInputEl.files || !fileInputEl.files.length) return;
                var file = fileInputEl.files[0];

                sendButtonEl.disabled = true;
                try {
                    userPinned = false;
                    await uploadFile(file);
                    if (inputEl) inputEl.value = '';
                    await loadMessages();
                } finally {
                    sendButtonEl.disabled = false;
                    if (inputEl) inputEl.focus();
                }
            });
        }

        formEl.addEventListener('submit', async function(event) {
            event.preventDefault();
            var text = inputEl.value.trim();
            if (!text) return;

            sendButtonEl.disabled = true;
            try {
                userPinned = false;
                await sendMessage(text);
                inputEl.value = '';
                await loadMessages();
            } finally {
                sendButtonEl.disabled = false;
                inputEl.focus();
            }
        });

        // --- Initial load ---
        // Mark as read ASAP, then load messages and scroll to bottom reliably.
        markRoomRead().finally(function() {
            loadMessages().then(function() {
                // On first load, force bottom even if browser hasn't fully painted yet.
                scrollToBottomSoon();
            });
        });

        pollHandle = setInterval(loadMessages, pollIntervalMs);

        if (window.MutationObserver && document.body) {
            detachObserver = new MutationObserver(function() {
                if (pollHandle && !document.body.contains(root)) {
                    clearInterval(pollHandle);
                    isDestroyed = true;
                    detachObserver.disconnect();
                }
            });
            detachObserver.observe(document.body, {childList: true, subtree: true});
        }
    })();
</script>
