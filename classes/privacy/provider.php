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
 * Privacy Subsystem implementation for local_stackforge.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * This plugin stores no personal data. It relays only the chosen question type and difficulty to
 * the generation service to draft questions; nothing about any user is transmitted or stored.
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Get the language string identifier explaining why this plugin stores no personal data.
     *
     * @return string The string identifier.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
