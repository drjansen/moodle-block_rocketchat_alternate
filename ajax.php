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
 * Server-side proxy for Rocket.Chat REST calls.
 *
 * Purpose: avoid browser CORS by calling Rocket.Chat from Moodle (same origin for browser).
 *
 * @package   block_rocketchat
 */

require_once(__DIR__ . '/../../config.php');

require_login();
// Require sesskey for ALL actions (simple & safest).
require_sesskey();

define('BLOCK_ROCKETCHAT_TEACHER_ROLE', 'app');

/** Prefix applied to Moodle-originated group/private room names in Rocket.Chat. */
define('BLOCK_ROCKETCHAT_MC_PREFIX', 'mc_');

// Use ALPHANUMEXT to be tolerant if you add action names later.
$action = required_param('action', PARAM_ALPHANUMEXT); // ping | messages | post | upload | postattachment | leaders | usersinfo | imcreate | teachersearch | subscriptions | markread

// Strict allow-list to reduce attack surface / accidental exposure.
$allowedactions = [
    'ping',
    'subscriptions',
    'markread',
    'teachersearch',
    'leaders',
    'imcreate',
    'messages',
    'post',
    'upload',
    'postattachment',
];
if (!in_array($action, $allowedactions, true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Unknown action',
    ]);
    exit;
}

/**
 * Output a JSON response and exit.
 *
 * @param int $status HTTP status code.
 * @param mixed $payload Response payload.
 */
function rocketchat_proxy_json_response(int $status, $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/**
 * Log an exception server-side without leaking details to the browser.
 *
 * @param string $context Context label.
 * @param \Throwable $e Exception
 * @return void
 */
function rocketchat_proxy_log_exception(string $context, \Throwable $e): void {
    // Intentionally avoid returning sensitive details to clients.
    // Use debugging() so this appears in developer debug logs when enabled.
    debugging('block_rocketchat ajax.php ' . $context . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
}

/**
 * Case-insensitive role membership check.
 *
 * @param mixed $roles Roles array.
 * @param string $needle Role name to find.
 * @return bool
 */
function rocketchat_has_role_ci($roles, string $needle): bool {
    if (!is_array($roles)) {
        return false;
    }
    $needle = strtolower($needle);
    foreach ($roles as $r) {
        if (strtolower((string)$r) === $needle) {
            return true;
        }
    }
    return false;
}

/**
 * Determine whether a Rocket.Chat room belongs to the MC (Moodle Chat) scope.
 *
 * Rules:
 * - Direct-message rooms ('d') are always MC scope (teacher–student DMs from Moodle).
 * - Group/private rooms are MC scope when the room name begins with BLOCK_ROCKETCHAT_MC_PREFIX.
 *
 * @param string $type Rocket.Chat room type ('c'|'p'|'g'|'d').
 * @param string $name Rocket.Chat room name or fname.
 * @return bool
 */
function rocketchat_is_mc_room(string $type, string $name): bool {
    if ($type === 'd') {
        // All DMs originating from Moodle are teacher–student (MC scope).
        return true;
    }
    return strncmp($name, BLOCK_ROCKETCHAT_MC_PREFIX, strlen(BLOCK_ROCKETCHAT_MC_PREFIX)) === 0;
}

/**
 * Strip the MC prefix from a room name for display purposes.
 *
 * The full prefixed name must still be used for all Rocket.Chat API calls.
 *
 * @param string $name Room name.
 * @return string Room name without the MC prefix.
 */
function rocketchat_strip_mc_prefix(string $name): string {
    $len = strlen(BLOCK_ROCKETCHAT_MC_PREFIX);
    if (strncmp($name, BLOCK_ROCKETCHAT_MC_PREFIX, $len) === 0) {
        return substr($name, $len);
    }
    return $name;
}

/**
 * Cache configuration
 * - teachers_cache_* is the directory of teachers (Rocket.Chat role "app")
 * - users_cache_* is the full directory (teachers + students) used by teachers to search anyone
 *
 * Stored in Moodle config table under component 'block_rocketchat'.
 */

/**
 * Teachers cache TTL.
 *
 * @return int
 */
function rocketchat_teachers_cache_ttl(): int {
    // 1 hour.
    return 3600;
}

/**
 * Users cache TTL.
 *
 * @return int
 */
function rocketchat_users_cache_ttl(): int {
    // 24 hours (fetch once/day).
    return 86400;
}

/**
 * Get cached teachers directory.
 *
 * @return array
 */
function rocketchat_get_cached_teachers(): array {
    $raw = (string)get_config('block_rocketchat', 'teachers_cache_json');
    $ts = (int)get_config('block_rocketchat', 'teachers_cache_ts');

    if ($raw === '' || $ts <= 0) {
        return [];
    }
    if ((time() - $ts) > rocketchat_teachers_cache_ttl()) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = trim((string)($u['username'] ?? ''));
        if ($uname === '') {
            continue;
        }
        $out[] = [
            'username' => $uname,
            'name' => (string)($u['name'] ?? $uname),
        ];
    }
    return $out;
}

/**
 * Store cached teachers directory.
 *
 * @param array $teachers Teachers array.
 * @return void
 */
function rocketchat_set_cached_teachers(array $teachers): void {
    $clean = [];
    $seen = [];
    foreach ($teachers as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = trim((string)($u['username'] ?? ''));
        if ($uname === '') {
            continue;
        }
        $key = strtolower($uname);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $clean[] = [
            'username' => $uname,
            'name' => (string)($u['name'] ?? $uname),
        ];
    }

    set_config('teachers_cache_json', json_encode(array_values($clean)), 'block_rocketchat');
    set_config('teachers_cache_ts', (string)time(), 'block_rocketchat');
}

/**
 * Get cached users directory.
 *
 * @return array
 */
function rocketchat_get_cached_users(): array {
    $raw = (string)get_config('block_rocketchat', 'users_cache_json');
    $ts = (int)get_config('block_rocketchat', 'users_cache_ts');

    if ($raw === '' || $ts <= 0) {
        return [];
    }
    if ((time() - $ts) > rocketchat_users_cache_ttl()) {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = trim((string)($u['username'] ?? ''));
        if ($uname === '') {
            continue;
        }
        $out[] = [
            'username' => $uname,
            'name' => (string)($u['name'] ?? $uname),
        ];
    }
    return $out;
}

/**
 * Store cached users directory.
 *
 * @param array $users Users array.
 * @return void
 */
function rocketchat_set_cached_users(array $users): void {
    $clean = [];
    $seen = [];
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = trim((string)($u['username'] ?? ''));
        if ($uname === '') {
            continue;
        }
        $key = strtolower($uname);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $clean[] = [
            'username' => $uname,
            'name' => (string)($u['name'] ?? $uname),
        ];
    }

    set_config('users_cache_json', json_encode(array_values($clean)), 'block_rocketchat');
    set_config('users_cache_ts', (string)time(), 'block_rocketchat');
}

/**
 * Check whether a username exists in cached teachers.
 *
 * @param string $username Username to check.
 * @param array $teachers Teachers list.
 * @return bool
 */
function rocketchat_is_username_in_cached_teachers(string $username, array $teachers): bool {
    $u = strtolower(trim($username));
    if ($u === '') {
        return false;
    }
    foreach ($teachers as $t) {
        if (!is_array($t)) {
            continue;
        }
        if (strtolower((string)($t['username'] ?? '')) === $u) {
            return true;
        }
    }
    return false;
}

/**
 * Filter users list by query.
 *
 * @param array $users Users.
 * @param string $q Query.
 * @param int $limit Max results.
 * @return array
 */
function rocketchat_filter_users_by_query(array $users, string $q, int $limit = 20): array {
    $q = trim(mb_strtolower($q));
    if ($q === '') {
        return [];
    }

    $out = [];
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = (string)($u['username'] ?? '');
        $name = (string)($u['name'] ?? $uname);
        if ($uname === '') {
            continue;
        }

        $hay = mb_strtolower($uname . ' ' . $name);
        if (mb_strpos($hay, $q) === false) {
            continue;
        }

        $out[] = [
            'username' => $uname,
            'name' => $name,
        ];

        if (count($out) >= $limit) {
            break;
        }
    }

    usort($out, function($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $out;
}

/**
 * Determine Rocket.Chat base url from config (or fall back to local_rocketchat client).
 *
 * @return string
 */
function rocketchat_service_base_url(): string {
    $host = trim((string)get_config('local_rocketchat', 'host'));
    $port = trim((string)get_config('local_rocketchat', 'port'));
    $proto = (string)get_config('local_rocketchat', 'protocol'); // 0=https, 1=http

    $fallback = (new local_rocketchat\client())->get_instance_url();

    if ($host === '') {
        return rtrim((string)$fallback, '/');
    }

    $scheme = ($proto === '1') ? 'http' : 'https';

    if (preg_match('#^https?://#i', $host)) {
        $base = rtrim($host, '/');
    } else {
        $base = $scheme . '://' . $host;
    }

    if ($port !== '' && preg_match('#^https?://[^/]+:\d+#i', $base) === 0) {
        $base .= ':' . $port;
    }

    return rtrim($base, '/');
}

/**
 * Service login as Rocket.Chat admin/service user (used for directory/search).
 *
 * @param \GuzzleHttp\Client $client HTTP client.
 * @return array|null
 */
function rocketchat_service_login(\GuzzleHttp\Client $client): ?array {
    $username = trim((string)get_config('local_rocketchat', 'username'));
    $password = (string)get_config('local_rocketchat', 'password');

    if ($username === '' || $password === '') {
        return null;
    }

    $base = rocketchat_service_base_url();
    $url = $base . '/api/v1/login';

    try {
        $resp = $client->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'timeout' => 12.0,
            'json' => [
                'user' => $username,
                'password' => $password,
            ],
        ]);

        $body = json_decode((string)$resp->getBody(), true);
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        if (!is_array($data)) {
            return null;
        }

        $token = (string)($data['authToken'] ?? '');
        $userId = (string)($data['userId'] ?? '');

        if ($token === '' || $userId === '') {
            return null;
        }

        return ['token' => $token, 'userId' => $userId, 'base' => $base];
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('service_login', $e);
        return null;
    }
}

/**
 * List roles from Rocket.Chat (service account).
 *
 * @param \GuzzleHttp\Client $client HTTP client.
 * @param array $service Service auth data.
 * @return array
 */
function rocketchat_service_roles_list(\GuzzleHttp\Client $client, array $service): array {
    $url = rtrim((string)$service['base'], '/') . '/api/v1/roles.list';

    $resp = $client->request('GET', $url, [
        'headers' => [
            'X-Auth-Token' => (string)$service['token'],
            'X-User-Id'    => (string)$service['userId'],
            'Accept'       => 'application/json',
        ],
        'timeout' => 12.0,
    ]);

    $body = json_decode((string)$resp->getBody(), true);
    if (!is_array($body)) {
        return [];
    }

    $roles = $body['roles'] ?? ($body['data']['roles'] ?? null);
    return is_array($roles) ? $roles : [];
}

/**
 * Find role ID by role name (case-insensitive).
 *
 * @param array $roles Roles.
 * @param string $name Role name.
 * @return string Role ID or ''.
 */
function rocketchat_find_role_id_ci(array $roles, string $name): string {
    $want = strtolower($name);
    foreach ($roles as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rname = (string)($r['name'] ?? '');
        if ($rname !== '' && strtolower($rname) === $want) {
            // Prefer _id but accept id too.
            $rid = (string)($r['_id'] ?? '');
            if ($rid === '') {
                $rid = (string)($r['id'] ?? '');
            }
            return $rid;
        }
    }
    return '';
}

/**
 * Get users in role by role ID.
 *
 * @param \GuzzleHttp\Client $client HTTP client.
 * @param array $service Service auth data.
 * @param string $roleid Role ID.
 * @return array
 */
function rocketchat_service_get_users_in_role_by_id(\GuzzleHttp\Client $client, array $service, string $roleid): array {
    $base = rtrim((string)$service['base'], '/') . '/api/v1/';
    $headers = [
        'X-Auth-Token' => (string)$service['token'],
        'X-User-Id'    => (string)$service['userId'],
        'Accept'       => 'application/json',
    ];

    $all = [];
    $offset = 0;
    $count = 100;
    $safety = 0;

    while (true) {
        $safety++;
        if ($safety > 50) {
            break;
        }

        $url = $base . 'roles.getUsersInRole?role=' . rawurlencode((string)$roleid) .
            '&offset=' . $offset . '&count=' . $count;

        $resp = $client->request('GET', $url, [
            'headers' => $headers,
            'timeout' => 12.0,
        ]);

        $body = json_decode((string)$resp->getBody(), true);
        if (!is_array($body)) {
            break;
        }
        if (isset($body['success']) && $body['success'] !== true) {
            break;
        }

        $users = $body['users'] ?? [];
        if (!is_array($users) || count($users) === 0) {
            break;
        }

        foreach ($users as $u) {
            if (is_array($u)) {
                $all[] = $u;
            }
        }

        $total = isset($body['total']) ? (int)$body['total'] : null;
        $offset += $count;

        if ($total !== null && $offset >= $total) {
            break;
        }
    }

    if (empty($all)) {
        return [];
    }

    $seen = [];
    $deduped = [];
    foreach ($all as $u) {
        $uname = strtolower((string)($u['username'] ?? ''));
        if ($uname === '' || isset($seen[$uname])) {
            continue;
        }
        $seen[$uname] = true;
        $deduped[] = $u;
    }
    return $deduped;
}

/**
 * Refresh teachers cache from Rocket.Chat using global role "app".
 * Returns array of teachers (each: ['username' => ..., 'name' => ...]).
 *
 * This may throw exceptions; caller should handle.
 *
 * @return array
 */
function rocketchat_refresh_teachers_cache(): array {
    $client = new \GuzzleHttp\Client([
        // Production: do NOT disable TLS verification.
        'timeout' => 30.0,
    ]);

    $service = rocketchat_service_login($client);
    if (!$service) {
        throw new \RuntimeException('Service login failed. Check local_rocketchat username/password settings.');
    }

    $roles = rocketchat_service_roles_list($client, $service);

    // Global-scope role in your environment.
    $roleid = rocketchat_find_role_id_ci($roles, BLOCK_ROCKETCHAT_TEACHER_ROLE);
    if ($roleid === '') {
        throw new \RuntimeException('Could not find role id for "' . BLOCK_ROCKETCHAT_TEACHER_ROLE . '" (roles.list).');
    }

    $users = rocketchat_service_get_users_in_role_by_id($client, $service, $roleid);

    $teachers = [];
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        $uname = (string)($u['username'] ?? '');
        if (trim($uname) === '') {
            continue;
        }
        $teachers[] = [
            'username' => $uname,
            'name' => (string)($u['name'] ?? $uname),
        ];
    }

    usort($teachers, function($a, $b) {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    rocketchat_set_cached_teachers($teachers);
    return $teachers;
}

/**
 * Helper: return current user's Rocket.Chat token+userid from Moodle prefs or exit with 401.
 *
 * @return array{token:string, userid:string}
 */
function rocketchat_require_current_rc_credentials(): array {
    $token = (string)get_user_preferences('local_rocketchat_external_token');
    $userid = (string)get_user_preferences('local_rocketchat_external_userid');

    if ($token === '' || $userid === '') {
        rocketchat_proxy_json_response(401, [
            'success' => false,
            'error'   => 'Missing Rocket.Chat token/userid in Moodle user preferences',
        ]);
    }

    return ['token' => $token, 'userid' => $userid];
}

/**
 * Call Rocket.Chat /api/v1/me and return the current user's profile data.
 *
 * Returns an array with:
 *  - 'username': RC username (may contain dots, e.g. "dr.jansen")
 *  - 'roles':    array of role names assigned to the user
 *
 * Returns ['username' => '', 'roles' => []] on any failure.
 *
 * @return array{username: string, roles: array}
 */
function rocketchat_get_current_rc_me_data(): array {
    $creds = rocketchat_require_current_rc_credentials();
    $token = $creds['token'];
    $userid = $creds['userid'];

    $instance = (new local_rocketchat\client())->get_instance_url();
    $apiroot  = rtrim($instance, '/') . '/api/v1/';

    $client = new \GuzzleHttp\Client([
        // Production: do NOT disable TLS verification.
        'timeout' => 15.0,
    ]);

    try {
        $resp = $client->request('GET', $apiroot . 'me', [
            'headers' => [
                'X-Auth-Token' => $token,
                'X-User-Id'    => $userid,
                'Accept'       => 'application/json',
            ],
            'timeout' => 12.0,
        ]);

        $body = json_decode((string)$resp->getBody(), true);
        if (!is_array($body) || (($body['success'] ?? null) !== true)) {
            return ['username' => '', 'roles' => []];
        }

        // Rocket.Chat returns: { success:true, _id:"..", username:"..", name:"..", roles:[..] ... }
        $uname = trim((string)($body['username'] ?? ''));
        $roles = (isset($body['roles']) && is_array($body['roles'])) ? $body['roles'] : [];

        return ['username' => $uname, 'roles' => $roles];
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('me', $e);
        return ['username' => '', 'roles' => []];
    }
}

/**
 * Kept for backwards compatibility; wraps rocketchat_get_current_rc_me_data().
 *
 * @return string
 */
function rocketchat_get_current_rc_username_via_me(): string {
    return rocketchat_get_current_rc_me_data()['username'];
}

/**
 * Determine whether current user is teacher using RC /me and teachers cache.
 *
 * Detection order (most-to-least authoritative):
 *  1. Username found in cached teachers list (fast, no extra API call beyond /me).
 *  2. /me response includes the teacher role directly – handles stale cache and
 *     newly-assigned teachers (e.g. dr.jansen added to role "app" after last cache refresh).
 *
 * When the user is confirmed via roles but was missing from the cache, a non-blocking
 * debug notice is emitted so admins can identify that the cache needs refreshing.
 *
 * @param array $teachers Cached teachers.
 * @return bool
 */
function rocketchat_current_user_is_teacher_by_cache_and_me(array $teachers): bool {
    $me = rocketchat_get_current_rc_me_data();
    $uname = $me['username'];

    if ($uname === '') {
        // Cannot determine RC username – treat as non-teacher for safety.
        return false;
    }

    // Primary: username present in cached teachers (normalised, case-insensitive).
    if (rocketchat_is_username_in_cached_teachers($uname, $teachers)) {
        return true;
    }

    // Secondary: /me roles contain the teacher role.
    // This handles newly-assigned teachers whose username is not yet in the cache.
    if (rocketchat_has_role_ci($me['roles'], BLOCK_ROCKETCHAT_TEACHER_ROLE)) {
        debugging(
            'block_rocketchat: user "' . $uname . '" has role "' . BLOCK_ROCKETCHAT_TEACHER_ROLE . '"'
            . ' but is not in teachers cache – cache may be stale; consider running the cache refresh task.',
            DEBUG_DEVELOPER
        );
        return true;
    }

    return false;
}

// Quick test endpoint.
if ($action === 'ping') {
    global $USER;
    rocketchat_proxy_json_response(200, [
        'success' => true,
        'userid'  => $USER->id,
    ]);
}

/**
 * subscriptions: unread badge refresh data
 */
if ($action === 'subscriptions') {
    $creds = rocketchat_require_current_rc_credentials();
    $token = $creds['token'];
    $userid = $creds['userid'];

    $instance = (new local_rocketchat\client())->get_instance_url();
    $apiroot  = rtrim($instance, '/') . '/api/v1/';

    $client = new \GuzzleHttp\Client([
        // Production: do NOT disable TLS verification.
        'timeout' => 15.0,
    ]);

    try {
        $resp = $client->request('GET', $apiroot . 'subscriptions.get', [
            'headers' => [
                'X-Auth-Token' => $token,
                'X-User-Id'    => $userid,
                'Accept'       => 'application/json',
            ],
            'timeout' => 15.0,
        ]);

        $body = json_decode((string)$resp->getBody(), true);
        if (!is_array($body)) {
            rocketchat_proxy_json_response(500, [
                'success' => false,
                'error' => 'Invalid subscriptions response',
            ]);
        }

        // Rocket.Chat may return either 'update' or 'subscriptions' depending on version.
        $subs = [];
        if (isset($body['update']) && is_array($body['update'])) {
            $subs = $body['update'];
        } else if (isset($body['subscriptions']) && is_array($body['subscriptions'])) {
            $subs = $body['subscriptions'];
        }

        $rooms = [];
        foreach ($subs as $s) {
            if (!is_array($s)) {
                continue;
            }
            if (!isset($s['rid'], $s['t'])) {
                continue;
            }

            $type = (string)$s['t'];
            $rawname = (string)($s['fname'] ?? $s['name'] ?? '');

            // Only include MC-scope rooms in the Moodle panel.
            if (!rocketchat_is_mc_room($type, $rawname)) {
                continue;
            }

            $rooms[] = [
                'rid' => (string)$s['rid'],
                't' => $type,
                // Strip mc_ prefix for display; full name kept in Rocket.Chat API calls.
                'name' => rocketchat_strip_mc_prefix($rawname),
                'unread' => (int)($s['unread'] ?? 0),
                'alert' => !empty($s['alert']),
            ];
        }

        rocketchat_proxy_json_response(200, [
            'success' => true,
            'rooms' => $rooms,
            'count' => count($rooms),
        ]);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        rocketchat_proxy_log_exception('subscriptions', $e);
        $status = $e->getResponse() ? (int)$e->getResponse()->getStatusCode() : 500;

        rocketchat_proxy_json_response($status ?: 500, [
            'success' => false,
            'error'   => 'Rocket.Chat request failed (subscriptions)',
        ]);
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('subscriptions', $e);
        rocketchat_proxy_json_response(500, [
            'success' => false,
            'error'   => 'Server error (subscriptions)',
        ]);
    }
}

/**
 * markread: mark a room subscription as read for the current user.
 *
 * Called by chat_screen.php on first load and after posting messages to clear unread badges/alerts.
 * Implements Rocket.Chat endpoint: POST /api/v1/subscriptions.read  { "rid": "<roomid>" }
 */
if ($action === 'markread') {
    // Room IDs are Rocket.Chat IDs (string). Validate.
    $roomid = required_param('roomid', PARAM_ALPHANUMEXT);
    if (trim($roomid) === '') {
        rocketchat_proxy_json_response(400, [
            'success' => false,
            'error' => 'Missing roomid',
        ]);
    }

    $creds = rocketchat_require_current_rc_credentials();
    $token = $creds['token'];
    $userid = $creds['userid'];

    $instance = (new local_rocketchat\client())->get_instance_url();
    $apiroot  = rtrim($instance, '/') . '/api/v1/';

    $client = new \GuzzleHttp\Client([
        'timeout' => 15.0,
    ]);

    try {
        $resp = $client->request('POST', $apiroot . 'subscriptions.read', [
            'headers' => [
                'X-Auth-Token' => $token,
                'X-User-Id'    => $userid,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 12.0,
            'json' => [
                'rid' => $roomid,
            ],
        ]);

        $body = json_decode((string)$resp->getBody(), true);
        if (!is_array($body)) {
            rocketchat_proxy_json_response(500, [
                'success' => false,
                'error' => 'Invalid markread response',
            ]);
        }

        // Rocket.Chat usually returns { success: true }.
        if (($body['success'] ?? null) !== true) {
            rocketchat_proxy_json_response(500, [
                'success' => false,
                'error' => 'Mark read failed',
            ]);
        }

        rocketchat_proxy_json_response(200, [
            'success' => true,
        ]);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        rocketchat_proxy_log_exception('markread', $e);
        $status = $e->getResponse() ? (int)$e->getResponse()->getStatusCode() : 500;

        rocketchat_proxy_json_response($status ?: 500, [
            'success' => false,
            'error'   => 'Rocket.Chat request failed (markread)',
        ]);
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('markread', $e);
        rocketchat_proxy_json_response(500, [
            'success' => false,
            'error'   => 'Server error (markread)',
        ]);
    }
}

/**
 * teachersearch:
 * - Teachers: search cached full user list (users_cache_json) when present; fallback to service search.
 * - Students: only search cached teachers.
 */
if ($action === 'teachersearch') {
    $q = trim((string)required_param('q', PARAM_RAW_TRIMMED));
    if ($q === '' || mb_strlen($q) < 2) {
        rocketchat_proxy_json_response(200, [
            'success' => true,
            'results' => [],
            'count' => 0,
        ]);
    }

    $teachers = rocketchat_get_cached_teachers();
    if (empty($teachers)) {
        try {
            $teachers = rocketchat_refresh_teachers_cache();
        } catch (\Throwable $e) {
            rocketchat_proxy_log_exception('refresh_teachers_cache', $e);
            $teachers = [];
        }
    }

    // Teacher detection now uses RC /me + teachers cache.
    $initiatorIsTeacher = rocketchat_current_user_is_teacher_by_cache_and_me($teachers);

    if (!$initiatorIsTeacher) {
        $results = rocketchat_filter_users_by_query($teachers, $q, 20);
        rocketchat_proxy_json_response(200, [
            'success' => true,
            'results' => $results,
            'count' => count($results),
            'scope' => 'teachers-only',
            'source' => 'cache',
        ]);
    }

    // Teachers: prefer cached full directory (users_cache_json).
    $cachedusers = rocketchat_get_cached_users();
    if (!empty($cachedusers)) {
        $results = rocketchat_filter_users_by_query($cachedusers, $q, 20);
        rocketchat_proxy_json_response(200, [
            'success' => true,
            'results' => $results,
            'count' => count($results),
            'scope' => 'all-users',
            'source' => 'users-cache',
        ]);
    }

    // No cached full directory yet: fall back to live Rocket.Chat service search.
    $client = new \GuzzleHttp\Client([
        'timeout' => 30.0,
    ]);

    $service = rocketchat_service_login($client);
    if (!$service) {
        $results = rocketchat_filter_users_by_query($teachers, $q, 20);
        rocketchat_proxy_json_response(200, [
            'success' => true,
            'results' => $results,
            'count' => count($results),
            'scope' => 'teachers-only',
            'source' => 'cache-fallback',
            'warning' => 'Service login failed and users cache is empty; falling back to cached teachers.',
        ]);
    }

    try {
        // Prefer users.autocomplete.
        $candidates = [];
        try {
            $base = rtrim((string)$service['base'], '/') . '/api/v1/';
            $resp = $client->request('GET', $base . 'users.autocomplete', [
                'headers' => [
                    'X-Auth-Token' => (string)$service['token'],
                    'X-User-Id'    => (string)$service['userId'],
                    'Accept'       => 'application/json',
                ],
                'timeout' => 12.0,
                'query' => [
                    'selector' => json_encode(['term' => $q]),
                    'count' => 20,
                ],
            ]);
            $body = json_decode((string)$resp->getBody(), true);
            $items = $body['items'] ?? ($body['users'] ?? ($body['data']['items'] ?? null));
            $candidates = is_array($items) ? $items : [];
        } catch (\Throwable $e) {
            rocketchat_proxy_log_exception('teachersearch_autocomplete', $e);
            $candidates = [];
        }

        if (!is_array($candidates) || count($candidates) === 0) {
            // Fallback: users.list query
            $base = rtrim((string)$service['base'], '/') . '/api/v1/';
            $escaped = preg_quote($q, '/');
            $regex = '.*' . $escaped . '.*';

            $query = [
                '$or' => [
                    ['username' => ['$regex' => $regex, '$options' => 'i']],
                    ['name' => ['$regex' => $regex, '$options' => 'i']],
                ],
            ];

            $resp = $client->request('GET', $base . 'users.list', [
                'headers' => [
                    'X-Auth-Token' => (string)$service['token'],
                    'X-User-Id'    => (string)$service['userId'],
                    'Accept'       => 'application/json',
                ],
                'timeout' => 12.0,
                'query' => [
                    'query' => json_encode($query),
                    'count' => 20,
                    'offset' => 0,
                ],
            ]);

            $body = json_decode((string)$resp->getBody(), true);
            $candidates = $body['users'] ?? ($body['data']['users'] ?? []);
            if (!is_array($candidates)) {
                $candidates = [];
            }
        }

        $results = [];
        $seen = [];

        foreach ($candidates as $u) {
            if (!is_array($u)) {
                continue;
            }

            $uname = (string)($u['username'] ?? '');
            if ($uname === '') {
                continue;
            }

            $key = strtolower($uname);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $name = (string)($u['name'] ?? $uname);

            $results[] = [
                'username' => $uname,
                'name' => $name,
            ];

            if (count($results) >= 20) {
                break;
            }
        }

        usort($results, function($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        rocketchat_proxy_json_response(200, [
            'success' => true,
            'results' => $results,
            'count' => count($results),
            'scope' => 'all-users',
            'source' => 'service',
            'warning' => 'users_cache_json is empty; using live Rocket.Chat search instead.',
        ]);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        rocketchat_proxy_log_exception('teachersearch', $e);
        $status = $e->getResponse() ? (int)$e->getResponse()->getStatusCode() : 500;

        rocketchat_proxy_json_response($status ?: 500, [
            'success' => false,
            'error' => 'Rocket.Chat request failed (teachersearch)',
        ]);
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('teachersearch', $e);
        rocketchat_proxy_json_response(500, [
            'success' => false,
            'error' => 'Server error (teachersearch)',
        ]);
    }
}

/**
 * teachers directory endpoint (cached).
 * Kept action name "leaders" for backward compatibility with frontend.
 */
if ($action === 'leaders') {
    $teachers = rocketchat_get_cached_teachers();
    if (empty($teachers)) {
        try {
            $teachers = rocketchat_refresh_teachers_cache();
        } catch (\Throwable $e) {
            rocketchat_proxy_log_exception('leaders_refresh', $e);
            rocketchat_proxy_json_response(500, [
                'success' => false,
                'error' => 'Could not refresh teachers cache. Check local_rocketchat service credentials and Rocket.Chat role assignments.',
            ]);
        }
    }

    rocketchat_proxy_json_response(200, [
        'success' => true,
        'leaders' => $teachers,
        'count' => count($teachers),
        'source' => 'cache',
        'role' => BLOCK_ROCKETCHAT_TEACHER_ROLE,
    ]);
}

/**
 * imcreate:
 * - Teachers can DM anyone
 * - Students can DM only cached teachers
 */
if ($action === 'imcreate') {
    // Use PARAM_RAW_TRIMMED to preserve Rocket.Chat usernames that contain dots
    // (e.g. "dr.jansen"). PARAM_ALPHANUMEXT would silently strip the dot and break
    // the teacher-lookup, preventing the student from messaging that teacher.
    $targetusername = required_param('username', PARAM_RAW_TRIMMED);

    // Validate: RC usernames may contain letters, digits, dots, underscores, hyphens.
    // Leading/trailing hyphens are valid per Rocket.Chat's own rules; we mirror that permissiveness
    // here so we do not accidentally reject any real username.
    // Reject anything outside that set to prevent injection via the RC API.
    if (!preg_match('/^[a-zA-Z0-9._-]{1,200}$/', $targetusername)) {
        rocketchat_proxy_json_response(400, [
            'success' => false,
            'error'   => 'Invalid username',
        ]);
    }

    $token  = (string)get_user_preferences('local_rocketchat_external_token');
    $userid = (string)get_user_preferences('local_rocketchat_external_userid');
    if ($token === '' || $userid === '') {
        rocketchat_proxy_json_response(401, [
            'success' => false,
            'error'   => 'Missing Rocket.Chat token/userid in Moodle user preferences',
        ]);
    }

    $teachers = rocketchat_get_cached_teachers();
    if (empty($teachers)) {
        try {
            $teachers = rocketchat_refresh_teachers_cache();
        } catch (\Throwable $e) {
            rocketchat_proxy_log_exception('imcreate_refresh_teachers', $e);
            $teachers = [];
        }
    }

    $initiatorIsTeacher = rocketchat_current_user_is_teacher_by_cache_and_me($teachers);

    if (!$initiatorIsTeacher) {
        if (!rocketchat_is_username_in_cached_teachers($targetusername, $teachers)) {
            // Target not found in cache. If cache was populated (not empty), try a one-time
            // refresh – this handles the case where the target was recently granted the teacher
            // role (e.g. dr.jansen added to "app" role after the last cache build).
            if (!empty($teachers)) {
                try {
                    $refreshed = rocketchat_refresh_teachers_cache();
                    if (!empty($refreshed) && rocketchat_is_username_in_cached_teachers($targetusername, $refreshed)) {
                        // Target confirmed as teacher after cache refresh; DM is allowed.
                    } else {
                        // Either the refresh returned no teachers (empty directory – target cannot
                        // be a teacher) or the target was still not found after the refresh.
                        // In both cases the DM is not permitted for a non-teacher initiator.
                        rocketchat_proxy_json_response(403, [
                            'success' => false,
                            'error' => 'You can only contact teachers.',
                        ]);
                    }
                } catch (\Throwable $e) {
                    rocketchat_proxy_log_exception('imcreate_target_refresh', $e);
                    rocketchat_proxy_json_response(403, [
                        'success' => false,
                        'error' => 'You can only contact teachers.',
                    ]);
                }
            } else {
                rocketchat_proxy_json_response(403, [
                    'success' => false,
                    'error' => 'You can only contact teachers.',
                ]);
            }
        }
    }

    $client = new \GuzzleHttp\Client([
        'timeout' => 30.0,
    ]);

    try {
        $instance = (new local_rocketchat\client())->get_instance_url();
        $apiroot  = rtrim($instance, '/') . '/api/v1/';

        $resp = $client->request('POST', $apiroot . 'im.create', [
            'headers' => [
                'X-Auth-Token' => $token,
                'X-User-Id'    => $userid,
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'username' => $targetusername,
            ],
        ]);

        $body = json_decode((string)$resp->getBody(), true);
        if (!is_array($body) || (($body['success'] ?? null) !== true)) {
            rocketchat_proxy_json_response(500, [
                'success' => false,
                'error' => 'DM create failed',
            ]);
        }

        $room = $body['room'] ?? $body;
        $rid = $room['rid'] ?? ($room['_id'] ?? null);
        $name = $room['fname'] ?? ($room['name'] ?? $targetusername);

        if (!$rid) {
            rocketchat_proxy_json_response(500, [
                'success' => false,
                'error' => 'Could not determine DM room id',
            ]);
        }

        rocketchat_proxy_json_response(200, [
            'success' => true,
            'room' => [
                'rid' => (string)$rid,
                'name' => (string)$name,
            ],
        ]);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        rocketchat_proxy_log_exception('imcreate', $e);
        $status = $e->getResponse() ? (int)$e->getResponse()->getStatusCode() : 500;

        rocketchat_proxy_json_response($status ?: 500, [
            'success' => false,
            'error'   => 'Rocket.Chat request failed (imcreate)',
        ]);
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('imcreate', $e);
        rocketchat_proxy_json_response(500, [
            'success' => false,
            'error'   => 'Server error (imcreate)',
        ]);
    }
}

// ---- Room-based actions ----
// Only require roomid for the actions that actually need it.
$roomactions = ['messages', 'post', 'upload', 'postattachment'];
if (in_array($action, $roomactions, true)) {
    $roomid = required_param('roomid', PARAM_ALPHANUMEXT);

    $token  = (string)get_user_preferences('local_rocketchat_external_token');
    $userid = (string)get_user_preferences('local_rocketchat_external_userid');

    if ($token === '' || $userid === '') {
        rocketchat_proxy_json_response(401, [
            'success' => false,
            'error'   => 'Missing Rocket.Chat token/userid in Moodle user preferences',
        ]);
    }

    $instance = (new local_rocketchat\client())->get_instance_url();
    $apiroot  = rtrim($instance, '/') . '/api/v1/';

    $client = new \GuzzleHttp\Client([
        'timeout' => 30.0,
    ]);

    try {
        if ($action === 'messages') {
            $roomtype = required_param('roomtype', PARAM_ALPHA); // c|p|d|g
            $count = optional_param('count', 100, PARAM_INT);
            if ($count < 1) {
                $count = 1;
            } else if ($count > 200) {
                $count = 200;
            }

            if ($roomtype === 'g') {
                $roomtype = 'p';
            }

            $endpointmap = [
                'c' => 'channels.messages',
                'p' => 'groups.messages',
                'd' => 'im.messages',
            ];

            if (!isset($endpointmap[$roomtype])) {
                rocketchat_proxy_json_response(400, ['success' => false, 'error' => 'Invalid roomtype']);
            }

            $url = $apiroot . $endpointmap[$roomtype];

            $resp = $client->request('GET', $url, [
                'headers' => [
                    'X-Auth-Token' => $token,
                    'X-User-Id'    => $userid,
                    'Accept'       => 'application/json',
                ],
                'query' => [
                    'roomId' => $roomid,
                    'count'  => $count,
                ],
            ]);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($resp->getStatusCode());
            echo (string)$resp->getBody();
            exit;
        }

        if ($action === 'post') {
            // State-changing action; sesskey is already required globally.
            $text = required_param('text', PARAM_RAW);

            // Basic abuse guard.
            if (core_text::strlen($text) > 4000) {
                rocketchat_proxy_json_response(400, [
                    'success' => false,
                    'error' => 'Message too long',
                ]);
            }

            $url = $apiroot . 'chat.postMessage';

            $resp = $client->request('POST', $url, [
                'headers' => [
                    'X-Auth-Token' => $token,
                    'X-User-Id'    => $userid,
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'roomId' => $roomid,
                    'text'   => $text,
                ],
            ]);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($resp->getStatusCode());
            echo (string)$resp->getBody();
            exit;
        }

        if ($action === 'upload') {
            if (empty($_FILES) || empty($_FILES['file'])) {
                rocketchat_proxy_json_response(400, [
                    'success' => false,
                    'error' => 'No file uploaded (expected multipart field name "file")',
                ]);
            }

            $file = $_FILES['file'];

            if (!empty($file['error'])) {
                rocketchat_proxy_json_response(400, [
                    'success' => false,
                    'error' => 'Upload error: ' . (string)$file['error'],
                ]);
            }

            if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                rocketchat_proxy_json_response(400, [
                    'success' => false,
                    'error' => 'Invalid upload (missing tmp file)',
                ]);
            }

            $filename = !empty($file['name']) ? (string)$file['name'] : 'upload.bin';

            $url = $apiroot . 'rooms.media/' . rawurlencode($roomid);

            $multipart = [
                [
                    'name'     => 'roomId',
                    'contents' => $roomid,
                ],
                [
                    'name'     => 'file',
                    'contents' => fopen($file['tmp_name'], 'rb'),
                    'filename' => $filename,
                ],
            ];

            $msg = optional_param('msg', '', PARAM_RAW);
            if ($msg !== '') {
                if (core_text::strlen($msg) > 4000) {
                    $msg = core_text::substr($msg, 0, 4000);
                }
                $multipart[] = [
                    'name'     => 'msg',
                    'contents' => $msg,
                ];
            }

            $resp = $client->request('POST', $url, [
                'headers' => [
                    'X-Auth-Token' => $token,
                    'X-User-Id'    => $userid,
                    'Accept'       => 'application/json',
                ],
                'multipart' => $multipart,
            ]);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($resp->getStatusCode());
            echo (string)$resp->getBody();
            exit;
        }

        if ($action === 'postattachment') {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            if (!is_array($data)) {
                rocketchat_proxy_json_response(400, [
                    'success' => false,
                    'error' => 'Invalid JSON body',
                ]);
            }

            $text = isset($data['text']) ? (string)$data['text'] : '';
            $attachments = (isset($data['attachments']) && is_array($data['attachments']))
                ? $data['attachments']
                : [];

            $attachments = array_values(array_filter($attachments, function($a) {
                return is_array($a);
            }));

            if (core_text::strlen($text) > 4000) {
                $text = core_text::substr($text, 0, 4000);
            }

            $url = $apiroot . 'chat.postMessage';

            $resp = $client->request('POST', $url, [
                'headers' => [
                    'X-Auth-Token' => $token,
                    'X-User-Id'    => $userid,
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'roomId' => $roomid,
                    'text' => $text,
                    'attachments' => $attachments,
                ],
            ]);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($resp->getStatusCode());
            echo (string)$resp->getBody();
            exit;
        }

        rocketchat_proxy_json_response(400, ['success' => false, 'error' => 'Unknown action']);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        rocketchat_proxy_log_exception('room_action_' . $action, $e);
        $status = $e->getResponse() ? (int)$e->getResponse()->getStatusCode() : 500;

        rocketchat_proxy_json_response($status ?: 500, [
            'success' => false,
            'error'   => 'Rocket.Chat request failed',
        ]);
    } catch (\Throwable $e) {
        rocketchat_proxy_log_exception('room_action_' . $action, $e);
        rocketchat_proxy_json_response(500, [
            'success' => false,
            'error'   => 'Server error',
        ]);
    }
}

rocketchat_proxy_json_response(400, ['success' => false, 'error' => 'Unknown action']);
