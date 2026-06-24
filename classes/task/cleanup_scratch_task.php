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
 * Scheduled cleanup of stale STACK Forge scratch categories.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_stackforge\task;

use local_stackforge\local\scratch_importer;

/**
 * Removes any scratch validation category older than six hours. The in-process validator cleans up
 * after itself, but a PHP fatal or timeout can bypass try/finally, so this is the safety net.
 */
class cleanup_scratch_task extends \core\task\scheduled_task {
    /**
     * The task display name.
     *
     * @return string The localized name.
     */
    public function get_name() {
        return get_string('cleanuptask', 'local_stackforge');
    }

    /**
     * Delete scratch categories older than six hours.
     *
     * @return void
     */
    public function execute() {
        $removed = scratch_importer::cleanup_stale(6 * HOURSECS);
        if ($removed > 0) {
            mtrace('local_stackforge: removed ' . $removed . ' stale scratch categor(y/ies).');
        }
    }
}
