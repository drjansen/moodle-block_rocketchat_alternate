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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/vendor/autoload.php');

use RocketChat\Client;

/**
 * Return user status data.
 *
 * @param  array $data
 * @return array
 */
function block_rocketchat_get_presence(array $data): array {
    $info = new Client();
    $status = $info->me()->status;

    $tmp = [
        'status-online' => $status === 'online',
        'status-away' => $status === 'away',
        'status-busy' => $status === 'busy',
        'status-offline' => $status === 'offline',
    ];

    $data['user'][] = $tmp;

    return $data;
}

/**
 * Return private and public channels data.
 *
 * @param  array $tmpdata
 * @return array
 */
function block_rocketchat_get_channels(array $tmpdata): array {
    $api = new Client();

    if (!empty($private = $api->list_groups())) {
        foreach ($private as $i => $pri) {
            $tmp = [
                'id' => $private[$i]->id,
                'name' => $private[$i]->name,
                'href' => ROCKET_CHAT_INSTANCE . '/group/',
                'layout' => '?layout=embedded',
            ];

            $tmpdata['private'][] = $tmp;
        }
    }

    if (!empty($public = $api->list_channels())) {
        foreach ($public as $i => $pub) {
            $tmp = [
                'id' => $public[$i]->id,
                'name' => $public[$i]->name,
                'href' => ROCKET_CHAT_INSTANCE . '/channel/',
                'layout' => '?layout=embedded',
            ];

            $tmpdata['public'][] = $tmp;
        }
    }

    return $tmpdata;
}

/**
 * Clear all Rocket.Chat credentials stored in Moodle user preferences.
 *
 * Safe to call when preferences are not set.
 *
 * @return void
 */
function block_rocketchat_clear_credentials(): void {
    unset_user_preference('local_rocketchat_external_token');
    unset_user_preference('local_rocketchat_external_user');
    unset_user_preference('local_rocketchat_external_userid');
}

/**
 * Render the Rocket.Chat panel (room list + chat iframe) as HTML.
 *
 * This is shared by:
 *  - block_rocketchat.php (block rendering)
 *  - view.php (drawer iframe landing page)
 *
 * Production/security notes:
 *  - TLS verification is NOT disabled (no verify => false).
 *  - Inputs are escaped with Moodle's s().
 *  - Rocket.Chat API responses are treated as untrusted.
 *
 * @param \moodle_page $page
 * @param int $courseid Moodle course id (0 for site context)
 * @return string HTML
 */
function block_rocketchat_render_panel(\moodle_page $page, int $courseid = 0): string {
    global $CFG;

    // Renderer + login state.
    $renderer = $page->get_renderer('block_rocketchat');
    $block = new \block_rocketchat\output\block();

    $login = new \block_rocketchat\login();
    $token = (string)get_user_preferences('local_rocketchat_external_token');
    $userid = (string)get_user_preferences('local_rocketchat_external_userid');

    if ($login->error || $token === '' || $userid === '') {
        return $renderer->render_login($block);
    }

    $instanceurl = (new \local_rocketchat\client())->get_instance_url();
    $apiroot = '/api/v1/';

    // --- Fetch subscriptions (room list) from Rocket.Chat ---
    $apifail = false;
    $roomsbytype = [
        'p' => [],
        'c' => [],
        'd' => [],
        'g' => [],
    ];

    try {
        $client = new \GuzzleHttp\Client([
            // Production: keep TLS verification enabled (default verify=true).
            'timeout' => 8.0,
        ]);

        $url = rtrim($instanceurl, '/') . $apiroot . 'subscriptions.get';
        $response = $client->request('GET', $url, [
            'headers' => [
                'X-Auth-Token' => $token,
                'X-User-Id'    => $userid,
                'Accept'       => 'application/json',
            ],
        ]);

        $body = json_decode((string)$response->getBody(), true);

        // Rocket.Chat versions differ:
        // - Some return { subscriptions: [...] }
        // - Some return { update: [...], remove: [...] } (when using "since")
        $subs = [];
        if (is_array($body)) {
            if (!empty($body['update']) && is_array($body['update'])) {
                $subs = $body['update'];
            } else if (!empty($body['subscriptions']) && is_array($body['subscriptions'])) {
                $subs = $body['subscriptions'];
            }
        }

        foreach ($subs as $s) {
            if (!isset($s['t'], $s['rid'])) {
                continue;
            }

            $type = (string)$s['t'];
            $rawname = (string)($s['fname'] ?? $s['name'] ?? 'unknown');

            // Only include MC-scope rooms (rooms whose names begin with the deployment-specific
            // prefix, or all direct-message rooms). Update the prefix literal below if your
            // deployment uses a different room-name prefix (see BLOCK_ROCKETCHAT_MC_PREFIX in ajax.php).
            if ($type === 'd') {
                $displayname = $rawname; // DMs use the other user's name; no prefix to strip.
            } else if (strncmp($rawname, 'mc_', 3) === 0) {
                $displayname = substr($rawname, 3); // Hide mc_ prefix in Moodle UI.
            } else {
                continue; // Non-MC room: skip.
            }

            $room = [
                'id'     => (string)$s['rid'],
                'name'   => $displayname,
                'type'   => $type,
                'unread' => (int)($s['unread'] ?? 0),
            ];

            if (isset($roomsbytype[$room['type']])) {
                $roomsbytype[$room['type']][] = $room;
            }
        }
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $code = $e->getResponse() ? (int)$e->getResponse()->getStatusCode() : 0;
        if ($code === 401 || $code === 403) {
            // Token invalid/expired -> show login.
            // Optional: clear stored values (depends on your auth flow).
            // unset_user_preference('local_rocketchat_external_token');
            // unset_user_preference('local_rocketchat_external_userid');
            return $renderer->render_login($block);
        }
        $apifail = true;
    } catch (\Throwable $e) {
        $apifail = true;
    }

    // Flatten all rooms into one list (no headings).
    $allrooms = [];
    foreach (['p', 'c', 'g', 'd'] as $t) {
        foreach ($roomsbytype[$t] as $r) {
            $allrooms[] = $r;
        }
    }

    // Sort rooms: unread first, then alphabetical.
    usort($allrooms, function($a, $b) {
        $ua = (int)($a['unread'] ?? 0);
        $ub = (int)($b['unread'] ?? 0);
        if ($ua !== $ub) {
            return $ub <=> $ua;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    // UI ids.
    $widgetid = 'rocketchat_' . uniqid('', true);
    $shellid = $widgetid . '_shell';
    $backid = $widgetid . '_back';
    $iframeid = $widgetid . '_iframe';
    $roomfilterid = $widgetid . '_roomfilter';
    $newchatid = $widgetid . '_newchat';
    $modalid = $widgetid . '_modal';
    $modalfilterid = $widgetid . '_modalfilter';
    $modalcloseid = $widgetid . '_modalclose';
    $modallistid = $widgetid . '_modallist';
    $warnid = $widgetid . '_warn';
    $logoutid = $widgetid . '_logout';

    // NEW: global unread badge id.
    $globalbadgeid = $widgetid . '_globalbadge';

    $chat_screen_url = $CFG->wwwroot . '/blocks/rocketchat/chat_screen.php';
    $ajaxurl = $CFG->wwwroot . '/blocks/rocketchat/ajax.php';

    // ---- Build HTML (copied from your existing block_rocketchat.php panel) ----
    $html = '<div id="' . s($shellid) . '" class="rocketchat-shell">';

    if ($apifail) {
        $html .= '<div class="alert alert-warning" style="color:#b22222;background:#fff4f4;margin-bottom:1em;">Could not fetch Rocket.Chat rooms. Please re-login if required.</div>';
    }

    $html .= '<style>
        .rocketchat-shell { width: 100%; }

        /* Hide header entirely unless in chat mode. */
        .rocketchat-header { display:none; }
        .rocketchat-shell.is-chat .rocketchat-header { display:flex; }

        .rocketchat-header {
            align-items:center;
            justify-content:space-between;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid #c7d2fe;
            border-radius: 12px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfbfd 100%);
            box-shadow: 0 8px 20px rgba(79,70,229,0.08);
            margin-bottom: 10px;
        }

        .rocketchat-header-left {
            display:flex;
            align-items:center;
            gap: 8px;
            min-width: 0;
        }

        .rocketchat-header-right {
            display:flex;
            align-items:center;
            gap: 8px;
        }

        .rocketchat-back {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            border: 1px solid #c7d2fe;
            background: #eef2ff;
            color: #3730a3;
            cursor:pointer;
            box-shadow: 0 6px 18px rgba(79,70,229,0.15);
            transition: background 120ms ease, border-color 120ms ease, transform 120ms ease, box-shadow 120ms ease;
        }
        .rocketchat-back:hover {
            background: #e0e7ff;
            border-color: #a5b4fc;
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(79,70,229,0.18);
        }
        .rocketchat-back:active {
            transform: translateY(0);
            box-shadow: 0 6px 18px rgba(79,70,229,0.14);
        }

        .rocketchat-logout {
            border: 1px solid #c7d2fe;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 10px;
            padding: 6px 10px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }
        .rocketchat-logout:hover {
            background: #e0e7ff;
            border-color: #a5b4fc;
        }

        /* NEW: global unread badge */
        .rocketchat-globalbadge {
            display:none;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            background: #e53935;
            color: #fff;
            font-size: 12px;
            font-weight: 900;
            line-height: 22px;
            text-align: center;
            box-shadow: 0 6px 18px rgba(229,57,53,0.25);
            user-select: none;
        }
        .rocketchat-globalbadge.is-visible { display:inline-block; }

        /* Room toolbar (search + new chat) */
        .rocketchat-toolbar {
            display:flex;
            gap: 8px;
            align-items:center;
            margin-bottom: 10px;
        }

        .rocketchat-roomfilter {
            flex: 1;
            min-width: 0;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 13px;
            outline: none;
            background: #fff;
        }
        .rocketchat-roomfilter:focus {
            border-color: #a5b4fc;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }

        .rocketchat-newchat {
            border: 1px solid #c7d2fe;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            white-space: nowrap;
        }

        .rocketchat-roomlist {
            border: 1px solid #eee;
            border-radius: 12px;
            padding: 10px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfbff 100%);
        }

        .rocketchat-room {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            cursor:pointer;
            user-select:none;
            transition: background 120ms ease, transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
            line-height: 1.25;
            margin: 6px 0;
            border: 1px solid #eef0f4;
            background: #ffffff;
            box-shadow: 0 1px 0 rgba(0,0,0,0.02);
        }

        .rocketchat-room:hover {
            background: #f2f6ff;
            border-color: #dbeafe;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37,99,235,0.08);
        }

        .rocketchat-room::before {
            content: "";
            display: block;
            width: 6px;
            height: 22px;
            border-radius: 999px;
            background: #a5b4fc;
            opacity: 0.9;
            flex: 0 0 auto;
        }

        .rocketchat-room[data-rc-roomtype="d"]::before { background: #fda4af; }
        .rocketchat-room[data-rc-roomtype="p"]::before { background: #86efac; }
        .rocketchat-room[data-rc-roomtype="c"]::before { background: #93c5fd; }
        .rocketchat-room[data-rc-roomtype="g"]::before { background: #fdba74; }

        .rocketchat-room-name {
            color: #111827;
            font-size: 13px;
            overflow:hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
            flex:  1;
            padding-left: 2px;
        }

        .rocketchat-unread-badge {
            background:#e53935;
            color:white;
            font-size:12px;
            border-radius:999px;
            padding: 2px 8px;
            min-width: 24px;
            text-align:center;
            box-shadow: 0 1px 0 rgba(0,0,0,0.05);
        }

        /* Dot-only indicator when alert=true but unread=0 */
        .rocketchat-unread-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: #e53935;
            display: inline-block;
            box-shadow: 0 1px 0 rgba(0,0,0,0.05);
        }

        .rocketchat-empty { color: #9ca3af; font-size: 12px; padding: 8px 10px; text-align:center; }

        .rocketchat-chatview {
            display:none;
            border: 1px solid #eee;
            border-radius: 12px;
            overflow:hidden;
            background:#fff;
        }

        .rocketchat-chatframe { width: 100%; height: 720px; border: 0; display:block; }

        .rocketchat-shell.is-chat .rocketchat-toolbar { display:none; }
        .rocketchat-shell.is-chat .rocketchat-roomlist { display:none; }
        .rocketchat-shell.is-chat .rocketchat-chatview { display:block; }

        /* Modal */
        .rocketchat-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(17,24,39,0.55);
            z-index: 9999;
        }
        .rocketchat-modal.is-open { display:flex; }

        .rocketchat-modal__panel {
            width: min(520px, 96vw);
            max-height: min(680px, 90vh);
            overflow: hidden;
            border-radius: 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 30px 80px rgba(0,0,0,0.25);
            display:flex;
            flex-direction: column;
        }

        .rocketchat-modal__top {
            display:flex;
            gap: 8px;
            align-items:center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid #eef0f4;
            background: linear-gradient(180deg, #ffffff 0%, #fbfbfd 100%);
        }
        .rocketchat-modal__title { font-weight: 900; font-size: 14px; color: #111827; }

        .rocketchat-modal__close {
            border: 1px solid #c7d2fe;
            background: #eef2ff;
            color: #3730a3;
            font-weight: 800;
            border-radius: 10px;
            padding: 6px 10px;
            cursor: pointer;
        }

        .rocketchat-modal__body {
            padding: 10px 12px;
            display:flex;
            flex-direction: column;
            gap: 10px;
            overflow: auto;
        }

        .rocketchat-modal__warn {
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 12px;
            display:none;
        }

        .rocketchat-modal__filter {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 8px 10px;
            font-size: 13px;
            outline: none;
        }

        .rocketchat-contact {
            display:flex;
            align-items:center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border:  1px solid #eef0f4;
            cursor: pointer;
            user-select:none;
            background: #fff;
        }
        .rocketchat-contact:hover { background: #f9fafb; border-color: #e5e7eb; }
        .rocketchat-contact__name {
            font-weight: 800;
            font-size: 13px;
            color: #111827;
            overflow:hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .rocketchat-contact__user {
            font-size: 12px;
            color: #6b7280;
            overflow:hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 40%;
        }

        .rocketchat-modal__empty { color: #9ca3af; font-size: 12px; text-align:center; padding: 10px 0; }
    </style>';

    $html .= '
        <div class="rocketchat-header">
            <div class="rocketchat-header-left">
                <button type="button" id="' . s($backid) . '" class="rocketchat-back" aria-label="Back" title="Back">
                    <span style="font-size:20px;line-height:1;">&#8592;</span>
                </button>
            </div>
            <div class="rocketchat-header-right">
                <button type="button" id="' . s($logoutid) . '" class="rocketchat-logout" aria-label="Logout" title="Logout from Rocket.Chat">Logout</button>
                <span id="' . s($globalbadgeid) . '" class="rocketchat-globalbadge" aria-label="Unread messages" title="Unread messages"></span>
            </div>
        </div>
    ';

    $html .= '
        <div class="rocketchat-toolbar">
            <input id="' . s($roomfilterid) . '" class="rocketchat-roomfilter" type="text" placeholder="Search rooms…">
            <button type="button" id="' . s($newchatid) . '" class="rocketchat-newchat">New chat</button>
        </div>
    ';

    $html .= '<div class="rocketchat-roomlist">';

    if (count($allrooms) === 0) {
        $html .= '<div class="rocketchat-empty">No conversations</div>';
    } else {
        foreach ($allrooms as $room) {
            $badge = ((int)($room['unread'] ?? 0) > 0)
                ? '<span class="rocketchat-unread-badge">' . (int)$room['unread'] . '</span>'
                : '';

            $html .= '<div class="rocketchat-room" data-rc-roomid="' . s($room['id']) . '" data-rc-roomtype="' . s($room['type']) . '" data-rc-roomname="' . s($room['name']) . '">'
                . '<div class="rocketchat-room-name">' . s($room['name']) . '</div>'
                . $badge
                . '</div>';
        }
    }

    $html .= '</div>';

    $html .= '<div class="rocketchat-chatview">'
        . '<iframe id="' . s($iframeid) . '" class="rocketchat-chatframe" src="about:blank" loading="lazy"></iframe>'
        . '</div>';

    $html .= '
        <div id="' . s($modalid) . '" class="rocketchat-modal" aria-hidden="true">
            <div class="rocketchat-modal__panel" role="dialog" aria-modal="true" aria-label="New chat">
                <div class="rocketchat-modal__top">
                    <div class="rocketchat-modal__title">Start a new chat</div>
                    <button type="button" id="' . s($modalcloseid) . '" class="rocketchat-modal__close" aria-label="Close">Close</button>
                </div>
                <div class="rocketchat-modal__body">
                    <div class="rocketchat-modal__warn" id="' . s($warnid) . '"></div>

                    <input id="' . s($modalfilterid) . '" class="rocketchat-modal__filter" type="text" placeholder="Search people… (name or username)">
                    <div id="' . s($modallistid) . '">
                        <div class="rocketchat-modal__empty">Type to search…</div>
                    </div>
                </div>
            </div>
        </div>
    ';

    $js = [
        'shellId' => $shellid,
        'backId' => $backid,
        'iframeId' => $iframeid,
        'chatScreenUrl' => $chat_screen_url,
        'courseId' => $courseid,
        'ajaxUrl' => $ajaxurl,
        'sesskey' => sesskey(),
        'roomFilterId' => $roomfilterid,
        'newChatId' => $newchatid,
        'modalId' => $modalid,
        'modalCloseId' => $modalcloseid,
        'modalFilterId' => $modalfilterid,
        'modalListId' => $modallistid,
        'warnId' => $warnid,
        'stateKey' => 'rc:lastchat:' . $widgetid,

        // Poll frequently enough to feel "live" but not hammer the server.
        'unreadPollMs' => 15000,

        // NEW: global badge element id.
        'globalBadgeId' => $globalbadgeid,

        // Logout button.
        'logoutId' => $logoutid,
        'logoutUrl' => $CFG->wwwroot . '/blocks/rocketchat/logout.php',

        'debugUnread' => false,
    ];

    $html .= '<script>(function(){'
        . 'var cfg=' . json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
        . 'var shell=document.getElementById(cfg.shellId);'
        . 'if(!shell){return;}'
        . 'var backBtn=document.getElementById(cfg.backId);'
        . 'var iframe=document.getElementById(cfg.iframeId);'
        . 'var roomFilter=document.getElementById(cfg.roomFilterId);'
        . 'var newChatBtn=document.getElementById(cfg.newChatId);'
        . 'var modal=document.getElementById(cfg.modalId);'
        . 'var modalClose=document.getElementById(cfg.modalCloseId);'
        . 'var modalFilter=document.getElementById(cfg.modalFilterId);'
        . 'var modalList=document.getElementById(cfg.modalListId);'
        . 'var warnEl=document.getElementById(cfg.warnId);'
        . 'var globalBadge=document.getElementById(cfg.globalBadgeId);'
        . 'var logoutBtn=document.getElementById(cfg.logoutId);'

        . 'var roomListEl=shell.querySelector(".rocketchat-roomlist");'

        . 'function showWarn(msg){ if(!warnEl){return;} warnEl.style.display = msg ? "block" : "none"; warnEl.textContent = msg || ""; }'

        . 'function setGlobalUnread(total){'
        . 'total = Number(total||0);'
        . 'if(!globalBadge){ return; }'
        . 'if(total > 0){'
        . 'globalBadge.textContent = String(total);'
        . 'globalBadge.classList.add("is-visible");'
        . '} else {'
        . 'globalBadge.textContent = "";'
        . 'globalBadge.classList.remove("is-visible");'
        . '}'
        . '}'

        . 'function saveLastChat(roomid, roomtype, roomname){'
        . 'try{ sessionStorage.setItem(cfg.stateKey, JSON.stringify({roomid:roomid, roomtype:roomtype, roomname:roomname||""})); }catch(e){}'
        . '}'
        . 'function clearLastChat(){ try{ sessionStorage.removeItem(cfg.stateKey); }catch(e){} }'
        . 'function loadLastChat(){'
        . 'try{ var raw=sessionStorage.getItem(cfg.stateKey); if(!raw){return null;} var obj=JSON.parse(raw);'
        . 'if(!obj || !obj.roomid || !obj.roomtype){return null;} return obj; }catch(e){ return null; }'
        . '}'

        . 'function showRoomList(){'
        . 'shell.classList.remove("is-chat");'
        . 'if(iframe){iframe.src="about:blank";}'
        . 'clearLastChat();'
        . '}'

        . 'function showChat(roomid,roomtype,roomname){'
        . 'shell.classList.add("is-chat");'
        . 'saveLastChat(roomid, roomtype, roomname || "");'
        . 'var url=cfg.chatScreenUrl'
        . '+ "?roomid=" + encodeURIComponent(roomid)'
        . '+ "&roomtype=" + encodeURIComponent(roomtype)'
        . '+ "&roomname=" + encodeURIComponent(roomname||"")'
        . '+ "&courseid=" + encodeURIComponent(String(cfg.courseId));'
        . 'if(iframe){iframe.src=url;}'
        . '}'

        . 'shell.querySelectorAll(".rocketchat-room[data-rc-roomid]").forEach(function(el){'
        . 'el.addEventListener("click", function(){'
        . 'showChat(el.getAttribute("data-rc-roomid"), el.getAttribute("data-rc-roomtype"), el.getAttribute("data-rc-roomname"));'
        . '});'
        . '});'

        . 'function applyRoomFilter(){'
        . 'var q=(roomFilter && roomFilter.value ? roomFilter.value : "").toLowerCase().trim();'
        . 'shell.querySelectorAll(".rocketchat-room[data-rc-roomid]").forEach(function(el){'
        . 'var name=(el.getAttribute("data-rc-roomname")||"").toLowerCase();'
        . 'el.style.display = (!q || name.indexOf(q)!==-1) ? "" : "none";'
        . '});'
        . '}'
        . 'if(roomFilter){roomFilter.addEventListener("input", applyRoomFilter);}'

        . 'function openModal(){'
        . 'if(!modal){return;}'
        . 'modal.classList.add("is-open");'
        . 'modal.setAttribute("aria-hidden","false");'
        . 'showWarn("");'
        . 'if(modalFilter){modalFilter.value=""; modalFilter.focus();}'
        . 'if(modalList){modalList.innerHTML="<div class=\\"rocketchat-modal__empty\\">Type to search…</div>";}'
        . '}'
        . 'function closeModal(){ if(!modal){return;} modal.classList.remove("is-open"); modal.setAttribute("aria-hidden","true"); }'

        . 'if(newChatBtn){newChatBtn.addEventListener("click", openModal);}'
        . 'if(modalClose){modalClose.addEventListener("click", closeModal);}'
        . 'if(modal){modal.addEventListener("click", function(e){ if(e.target===modal){ closeModal(); } });}'
        . 'document.addEventListener("keydown", function(e){ if(e.key==="Escape"){ closeModal(); } });'

        . 'async function apiGet(params){'
        . 'var usp=new URLSearchParams(params);'
        . 'var resp=await fetch(cfg.ajaxUrl + "?" + usp.toString(), {credentials:"same-origin"});'
        . 'var data=await resp.json().catch(function(){return null;});'
        . 'if(!data){ throw new Error("Invalid response"); }'
        . 'if(!resp.ok || data.success===false){ throw new Error(data.error || ("HTTP " + resp.status)); }'
        . 'return data;'
        . '}'

        . 'function findRoomElByRid(rid){'
        . 'rid=String(rid||""); if(!rid){return null;}'
        . 'var els=shell.querySelectorAll(".rocketchat-room[data-rc-roomid]");'
        . 'for(var i=0;i<els.length;i++){ if(String(els[i].getAttribute("data-rc-roomid")||"")===rid){ return els[i]; } }'
        . 'return null;'
        . '}'

        . 'function clearIndicators(roomEl){'
        . 'if(!roomEl){return;}'
        . 'var b=roomEl.querySelector(".rocketchat-unread-badge"); if(b){b.remove();}'
        . 'var d=roomEl.querySelector(".rocketchat-unread-dot"); if(d){d.remove();}'
        . '}'

        . 'function setIndicators(roomEl, unread, alert){'
        . 'unread = Number(unread||0);'
        . 'alert = !!alert;'
        . 'if(!roomEl){return;}'
        . 'clearIndicators(roomEl);'
        . 'if(unread > 0){'
        . 'var b=document.createElement("span");'
        . 'b.className="rocketchat-unread-badge";'
        . 'b.textContent=String(unread);'
        . 'roomEl.appendChild(b);'
        . 'return;'
        . '}'
        . 'if(alert){'
        . 'var d=document.createElement("span");'
        . 'd.className="rocketchat-unread-dot";'
        . 'roomEl.appendChild(d);'
        . '}'
        . '}'

        . 'function createRoomElement(room){'
        . 'room = room || {};'
        . 'var rid = String(room.rid || room.id || "");'
        . 'var rawname = String(room.name || room.fname || room.title || "unknown");'
        // Strip mc_ prefix for display (server strips it too, but defend in depth).
        . 'var name = rawname.indexOf("mc_") === 0 ? rawname.slice(3) : rawname;'
        . 'var type = String(room.t || room.type || "d");'
        . 'if(!rid){ return null; }'
        . 'var el=document.createElement("div");'
        . 'el.className="rocketchat-room";'
        . 'el.setAttribute("data-rc-roomid", rid);'
        . 'el.setAttribute("data-rc-roomtype", type);'
        . 'el.setAttribute("data-rc-roomname", name);'
        . 'var nameEl=document.createElement("div");'
        . 'nameEl.className="rocketchat-room-name";'
        . 'nameEl.textContent=name;'
        . 'el.appendChild(nameEl);'
        . 'el.addEventListener("click", function(){ showChat(rid, type, name); });'
        . 'return el;'
        . '}'

        . 'function ensureRoomInList(room){'
        . 'if(!roomListEl){ return null; }'
        . 'var rid = String((room && (room.rid || room.id)) || "");'
        . 'if(!rid){ return null; }'
        . 'var existing = findRoomElByRid(rid);'
        . 'if(existing){'
        . 'return existing;'
        . '}'
        . 'var empty = roomListEl.querySelector(".rocketchat-empty");'
        . 'if(empty){ empty.remove(); }'
        . 'var el = createRoomElement(room);'
        . 'if(!el){ return null; }'
        . 'roomListEl.insertBefore(el, roomListEl.firstChild);'
        . 'applyRoomFilter();'
        . 'return el;'
        . '}'

        . 'async function refreshUnread(){'
        . 'try{'
        . 'var data=await apiGet({action:"subscriptions", sesskey:cfg.sesskey});'
        . 'if(cfg.debugUnread){ console.log("[rocketchat] subscriptions", data); }'
        . 'var rooms=(data && data.rooms) ? data.rooms : [];'
        . 'if(!Array.isArray(rooms)){ setGlobalUnread(0); return; }'

        // NEW: compute total unread.
        . 'var totalUnread = 0;'

        . 'for(var i=0;i<rooms.length;i++){'
        . 'var r=rooms[i];'
        . 'if(!r || !r.rid){continue;}'
        // Server-side already scopes to MC rooms; guard here for defence-in-depth.
        . 'var rtype = String(r.t || r.type || "d");'
        . 'var rname = String(r.name || r.fname || "unknown");'
        . 'if(rtype !== "d" && rname.indexOf("mc_") !== 0 && rname !== "unknown"){'
        . 'continue;'
        . '}'
        . 'totalUnread += Number(r.unread || 0);'
        . 'var el=ensureRoomInList({rid:r.rid, name:rname, t:rtype});'
        . 'setIndicators(el, r.unread, r.alert);'
        . '}'

        . 'setGlobalUnread(totalUnread);'
        . '}catch(e){'
        . 'setGlobalUnread(0);'
        . 'if(cfg.debugUnread){ console.warn("[rocketchat] refreshUnread failed", e); }'
        . '}'
        . '}'

        . 'var unreadTimer=null;'
        . 'function startUnreadPolling(){'
        . 'if(unreadTimer){clearInterval(unreadTimer);}'
        . 'refreshUnread();'
        . 'unreadTimer=setInterval(refreshUnread, Number(cfg.unreadPollMs||30000));'
        . '}'
        . 'startUnreadPolling();'
        . 'window.addEventListener("focus", function(){ refreshUnread(); });'
        . 'document.addEventListener("visibilitychange", function(){ if(!document.hidden){ refreshUnread(); } });'

        . 'var searchTimer=null;'
        . 'var searchSeq=0;'
        . 'function normalizeSearch(s){ return String(s||"").toLowerCase().trim(); }'

        . 'async function doUserSearch(){'
        . 'var q=(modalFilter && modalFilter.value ? modalFilter.value : "").trim();'
        . 'if(!modalList){return;}'
        . 'showWarn("");'
        . 'if(q.length < 2){ modalList.innerHTML="<div class=\\"rocketchat-modal__empty\\">Type at least 2 characters…</div>"; return; }'

        . 'var mySeq = ++searchSeq;'
        . 'modalList.innerHTML="<div class=\\"rocketchat-modal__empty\\">Searching…</div>";'

        . 'try{'
        . 'var data=await apiGet({action:"teachersearch", q:q, sesskey:cfg.sesskey});'
        . 'if(mySeq < searchSeq){ return; }'

        . 'if(data && data.warning){ showWarn(String(data.warning)); }'

        . 'var res=(data && data.results) ? data.results : [];'
        . 'if(!Array.isArray(res)){ res=[]; }'

        . 'var nq = normalizeSearch(modalFilter && modalFilter.value ? modalFilter.value : q);'
        . 'res = res.filter(function(u){'
        . 'var name = normalizeSearch(u && u.name);'
        . 'var user = normalizeSearch(u && u.username);'
        . 'return !nq || name.indexOf(nq)!==-1 || user.indexOf(nq)!==-1;'
        . '});'

        . 'if(!res.length){ modalList.innerHTML="<div class=\\"rocketchat-modal__empty\\">No matches.</div>"; return; }'
        . 'modalList.innerHTML="";'
        . 'res.forEach(function(u){'
        . 'var el=document.createElement("div");'
        . 'el.className="rocketchat-contact";'
        . 'el.setAttribute("data-rc-username", u.username || "");'
        . 'el.setAttribute("data-rc-name", u.name || u.username || "");'
        . 'el.innerHTML="<div class=\\"rocketchat-contact__name\\"></div><div class=\\"rocketchat-contact__user\\"></div>";'
        . 'el.querySelector(".rocketchat-contact__name").textContent=(u.name || u.username || "");'
        . 'el.querySelector(".rocketchat-contact__user").textContent="@" + (u.username || "");'
        . 'el.addEventListener("click", async function(){'
        . 'var username=el.getAttribute("data-rc-username");'
        . 'if(!username){return;}'
        . 'try{'
        . 'var created=await apiGet({action:"imcreate", username:username, sesskey:cfg.sesskey});'
        . 'closeModal();'
        . 'if(created && created.room && created.room.rid){'
        . 'ensureRoomInList({rid:created.room.rid, name:(created.room.name || el.getAttribute("data-rc-name") || username), t:"d"});'
        . 'showChat(created.room.rid, "d", (created.room.name || el.getAttribute("data-rc-name") || username));'
        . '}'
        . 'refreshUnread();'
        . '}catch(e){ showWarn(e.message || String(e)); }'
        . '});'
        . 'modalList.appendChild(el);'
        . '});'
        . '}catch(e){'
        . 'if(mySeq < searchSeq){ return; }'
        . 'showWarn(e.message || String(e));'
        . 'modalList.innerHTML="<div class=\\"rocketchat-modal__empty\\">Search failed.</div>";'
        . '}'
        . '}'

        . 'if(modalFilter){'
        . 'modalFilter.addEventListener("input", function(){'
        . 'if(searchTimer){clearTimeout(searchTimer);} searchTimer=setTimeout(doUserSearch, 250);'
        . '});'
        . 'modalFilter.addEventListener("keydown", function(e){ if(e.key==="Enter"){ e.preventDefault(); doUserSearch(); } });'
        . '}'

        . 'if(backBtn){backBtn.addEventListener("click", showRoomList);}'

        . 'window.addEventListener("message", function(e){'
        . 'if(!e.data || e.data.type !== "rocketchat-back"){return;}'
        . 'if(e.origin !== window.location.origin){return;}'
        . 'if(iframe && e.source === iframe.contentWindow){ showRoomList(); }'
        . '});'

        . 'if(logoutBtn){logoutBtn.addEventListener("click", function(){'
        . 'var form=document.createElement("form");'
        . 'form.method="POST";'
        . 'form.action=cfg.logoutUrl;'
        . 'var sk=document.createElement("input"); sk.type="hidden"; sk.name="sesskey"; sk.value=cfg.sesskey;'
        . 'form.appendChild(sk);'
        . 'var ci=document.createElement("input"); ci.type="hidden"; ci.name="courseid"; ci.value=String(cfg.courseId||0);'
        . 'form.appendChild(ci);'
        . 'document.body.appendChild(form);'
        . 'form.submit();'
        . '});}'

        . 'var last=loadLastChat();'
        . 'if(last){ showChat(last.roomid, last.roomtype, last.roomname || ""); }'
        . 'else { showRoomList(); }'
        . '})();</script>';

    $html .= '</div>';

    return $html;
}
