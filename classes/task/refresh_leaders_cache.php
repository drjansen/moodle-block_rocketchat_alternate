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
 * Scheduled task: refresh cached Rocket.Chat teachers list and (optionally) full user directory.
 *
 * Teachers are identified by the global-scope "app" role.
 * The full directory is used so teachers can search/contact anyone without live service searches.
 *
 * @package   block_rocketchat
 */

namespace block_rocketchat\task;

defined('MOODLE_INTERNAL') || die();

class refresh_leaders_cache extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_refresh_leaders_cache', 'block_rocketchat');
    }

    public function execute(): void {
        $username = trim((string)get_config('local_rocketchat', 'username'));
        $password = (string)get_config('local_rocketchat', 'password');
        if ($username === '' || $password === '') {
            mtrace('[block_rocketchat] Cache refresh skipped: missing local_rocketchat username/password.');
            return;
        }

        $rolename = 'app';

        try {
            $base = $this->service_base_url();
            if ($base === '') {
                mtrace('[block_rocketchat] Cache refresh skipped: could not determine Rocket.Chat base URL.');
                return;
            }

            $client = new \GuzzleHttp\Client([
                'verify' => false,
                'timeout' => 30.0,
            ]);

            // Login as service.
            $login = $client->request('POST', rtrim($base, '/') . '/api/v1/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 12.0,
                'json' => [
                    'user' => $username,
                    'password' => $password,
                ],
            ]);

            $loginbody = json_decode((string)$login->getBody(), true);
            $data = is_array($loginbody) ? ($loginbody['data'] ?? null) : null;
            if (!is_array($data)) {
                mtrace('[block_rocketchat] Cache refresh failed: invalid login response.');
                return;
            }

            $token = (string)($data['authToken'] ?? '');
            $userId = (string)($data['userId'] ?? '');
            if ($token === '' || $userId === '') {
                mtrace('[block_rocketchat] Cache refresh failed: missing authToken/userId in login response.');
                return;
            }

            $headers = [
                'X-Auth-Token' => $token,
                'X-User-Id' => $userId,
                'Accept' => 'application/json',
            ];

            // ---- Teachers cache (role app) ----
            $teachers = $this->fetch_teachers_by_role($client, $base, $headers, $rolename);
            set_config('teachers_cache_json', json_encode(array_values($teachers)), 'block_rocketchat');
            set_config('teachers_cache_ts', (string)time(), 'block_rocketchat');
            mtrace('[block_rocketchat] Teachers cache refreshed (' . $rolename . '): ' . count($teachers) . ' user(s).');

            // ---- Full users cache (teachers + students) ----
            $users = $this->fetch_all_users($client, $base, $headers);
            set_config('users_cache_json', json_encode(array_values($users)), 'block_rocketchat');
            set_config('users_cache_ts', (string)time(), 'block_rocketchat');
            mtrace('[block_rocketchat] Users cache refreshed (users.list): ' . count($users) . ' user(s).');

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            mtrace('[block_rocketchat] Cache refresh failed: HTTP ' . $status . ' ' . $e->getMessage());
            if ($body !== '') {
                mtrace('[block_rocketchat] Response: ' . $this->truncate_one_line($body, 300));
            }
        } catch (\Throwable $e) {
            mtrace('[block_rocketchat] Cache refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetch teachers by Rocket.Chat role name (e.g. "app") and return normalized list:
     *  [ ['username' => '...', 'name' => '...'], ... ]
     */
    private function fetch_teachers_by_role(\GuzzleHttp\Client $client, string $base, array $headers, string $rolename): array {
        // roles.list
        $rolesresp = $client->request('GET', rtrim($base, '/') . '/api/v1/roles.list', [
            'headers' => $headers,
            'timeout' => 12.0,
        ]);
        $rolesbody = json_decode((string)$rolesresp->getBody(), true);

        $roles = [];
        if (is_array($rolesbody)) {
            $roles = $rolesbody['roles'] ?? ($rolesbody['data']['roles'] ?? []);
        }
        if (!is_array($roles)) {
            $roles = [];
        }

        // Find role id for $rolename.
        $roleid = '';
        $rolescope = '';
        foreach ($roles as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = (string)($r['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (strtolower($name) !== strtolower($rolename)) {
                continue;
            }
            $roleid = trim((string)($r['_id'] ?? ($r['id'] ?? '')));
            $rolescope = (string)($r['scope'] ?? '');
            break;
        }

        if ($roleid === '') {
            throw new \RuntimeException('Could not resolve role id for "' . $rolename . '" from roles.list.');
        }

        if ($rolescope !== '' && strtolower($rolescope) !== 'users') {
            mtrace('[block_rocketchat] WARNING: role "' . $rolename . '" has scope "' . $rolescope . '", expected "Users".');
        }

        $rawteachers = $this->get_users_in_role_by_id($client, $base, $headers, $roleid);

        $out = [];
        $seen = [];
        foreach ($rawteachers as $u) {
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

            $out[] = [
                'username' => $uname,
                'name' => (string)($u['name'] ?? $uname),
            ];
        }

        usort($out, function($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $out;
    }

    /**
     * Fetch all users from Rocket.Chat (paged) via users.list.
     * Returns normalized list: [ ['username' => '...', 'name' => '...'], ... ]
     */
    private function fetch_all_users(\GuzzleHttp\Client $client, string $base, array $headers): array {
        $all = [];
        $seen = [];
        $offset = 0;
        $count = 100;
        $safety = 0;

        while (true) {
            $safety++;
            if ($safety > 50) {
                break;
            }

            $url = rtrim($base, '/') . '/api/v1/users.list';

            $resp = $client->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 20.0,
                'query' => [
                    'count' => $count,
                    'offset' => $offset,
                    // We intentionally do NOT pass "query" so we get the full directory.
                ],
            ]);

            $body = json_decode((string)$resp->getBody(), true);
            if (!is_array($body) || (($body['success'] ?? null) !== true)) {
                break;
            }

            $page = $body['users'] ?? [];
            if (!is_array($page) || count($page) === 0) {
                break;
            }

            foreach ($page as $u) {
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

                $all[] = [
                    'username' => $uname,
                    'name' => (string)($u['name'] ?? $uname),
                ];
            }

            $total = isset($body['total']) ? (int)$body['total'] : null;
            $offset += $count;

            if ($total !== null && $offset >= $total) {
                break;
            }

            // If no total is returned, still progress until we hit an empty page.
        }

        usort($all, function($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $all;
    }

    private function get_users_in_role_by_id(\GuzzleHttp\Client $client, string $base, array $headers, string $roleid): array {
        $users = [];
        $offset = 0;
        $count = 100;
        $safety = 0;

        while (true) {
            $safety++;
            if ($safety > 50) {
                break;
            }

            $url = rtrim($base, '/') . '/api/v1/roles.getUsersInRole?role=' . rawurlencode($roleid) .
                '&offset=' . $offset . '&count=' . $count;

            $resp = $client->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 12.0,
            ]);

            $body = json_decode((string)$resp->getBody(), true);
            if (!is_array($body) || (($body['success'] ?? null) !== true)) {
                break;
            }

            $page = $body['users'] ?? [];
            if (!is_array($page) || count($page) === 0) {
                break;
            }

            foreach ($page as $u) {
                if (is_array($u)) {
                    $users[] = $u;
                }
            }

            $total = isset($body['total']) ? (int)$body['total'] : null;
            $offset += $count;

            if ($total !== null && $offset >= $total) {
                break;
            }
        }

        return $users;
    }

    private function truncate_one_line(string $s, int $max): string {
        $s = preg_replace('/\s+/', ' ', $s);
        $s = trim((string)$s);
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max - 3) . '...';
    }

    private function service_base_url(): string {
        try {
            $instance = trim((string)(new \local_rocketchat\client())->get_instance_url());
            if ($instance !== '') {
                return rtrim($instance, '/');
            }
        } catch (\Throwable $e) {
            // Ignore and fall back.
        }

        $host = trim((string)get_config('local_rocketchat', 'host'));
        $port = trim((string)get_config('local_rocketchat', 'port'));
        $proto = (string)get_config('local_rocketchat', 'protocol'); // 0=https, 1=http

        if ($host === '') {
            return '';
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
}
