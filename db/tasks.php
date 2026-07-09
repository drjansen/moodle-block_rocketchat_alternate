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
 * Scheduled tasks for the Rocket.Chat block plugin.
 *
 * @package   block_rocketchat
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'block_rocketchat\task\refresh_leaders_cache',
        'blocking'  => 0,
        // Run once per day at 02:15 server time.
        'minute'    => '15',
        'hour'      => '2',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];
