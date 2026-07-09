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
 * Rocket.Chat login handler.
 *
 * @package   block_rocketchat
 */

namespace block_rocketchat;

use coding_exception;
use core\notification;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Mime;
use Httpful\Request;
use local_rocketchat\client;

class login {
    public bool $error = false;

    /**
     * @throws coding_exception
     */
    public function __construct() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->login_with_form();

            // PRG: avoid "Confirm Form Resubmission" on refresh/navigation.
            redirect(new \moodle_url(qualified_me()));
        }
    }

    /**
     * @throws coding_exception
     */
    private function login_with_form(): void {
        require_sesskey();

        $username = required_param('rocketchat_username', PARAM_USERNAME);
        $password = required_param('rocketchat_password', PARAM_RAW);

        if (empty($username) || empty($password)) {
            $this->error = true;
        }

        if (empty($username) && empty($password)) {
            notification::info(get_string('credentialserror', 'block_rocketchat'));
            return;
        }

        if (empty($username)) {
            notification::warning(get_string('usernameerror', 'block_rocketchat'));
            return;
        }

        if (empty($password)) {
            notification::warning(get_string('passworderror', 'block_rocketchat'));
            return;
        }

        $this->verify_login($username, $password);
    }

    /**
     * Login by stored user token.
     *
     * @param string $token
     * @return bool
     * @throws ConnectionErrorException
     */
    public function login_with_token(string $token): bool {
        $instance = '';
        try {
            $instance = (string)(new client())->get_instance_url();
        } catch (\Throwable $e) {
            $instance = '';
        }

        $instance = rtrim($instance, '/');
        if ($instance === '') {
            // Can't even attempt resume; keep creds but treat as not logged in.
            return false;
        }

        $url = $instance . '/api/v1/login';

        $response = Request::post($url)
            ->body(['resume' => $token], Mime::JSON)
            ->send();

        if ($response->code == 200 && isset($response->body->status) && $response->body->status === 'success') {
            $tmp = Request::init()
                ->addHeader('X-Auth-Token', $response->body->data->authToken)
                ->addHeader('X-User-Id', $response->body->data->userId);
            Request::ini($tmp);

            if (!empty($response->body->data->userId)) {
                set_user_preference('local_rocketchat_external_userid', (string)$response->body->data->userId);
            }

            return true;
        }

        // Resume failed => token likely invalid; clear it so UI shows login.
        $this->clear_saved_credentials();
        return false;
    }

    /**
     * @throws coding_exception
     */
    private function verify_login(string $username, string $password): void {
        $rocketchat = new client();
        $response = $rocketchat->authenticate($username, $password);

        if (is_null($response) || ($response->status ?? null) === 'error') {
            notification::error(get_string('validationerror', 'block_rocketchat'));
            return;
        }

        if (($response->status ?? null) === 'success') {
            set_user_preference('local_rocketchat_external_user', $username);
            set_user_preference('local_rocketchat_external_token', (string)$response->data->authToken);
            set_user_preference('local_rocketchat_external_userid', (string)$response->data->userId);

            notification::success(get_string('validationsuccess', 'block_rocketchat'));
        }
    }

    private function clear_saved_credentials(): void {
        set_user_preference('local_rocketchat_external_token', '');
        set_user_preference('local_rocketchat_external_userid', '');
        set_user_preference('local_rocketchat_external_user', '');
    }
}
