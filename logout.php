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
 * Rocket.Chat block logout handler.
 *
 * Clears all stored Rocket.Chat credentials from Moodle user preferences
 * and redirects the user back to a sensible location.
 *
 * @package   block_rocketchat
 * @copyright 2019 Adrian Perez <me@adrianperez.me> {@link https://adrianperez.me}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();
require_sesskey();

$courseid = optional_param('courseid', 0, PARAM_INT);

// Clear all stored Rocket.Chat credentials.
block_rocketchat_clear_credentials();

// Redirect to course view if a valid course ID was given, otherwise site home.
if ($courseid > 0) {
    $redirect = new moodle_url('/course/view.php', ['id' => $courseid]);
} else {
    $redirect = new moodle_url('/');
}

redirect($redirect);
