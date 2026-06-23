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
 * Library callbacks for local_stackforge.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds a "Generate STACK questions" link to a course's navigation for users who may generate.
 *
 * @param navigation_node $navigation The course navigation node to extend.
 * @param stdClass $course The course record.
 * @param context_course $context The course context.
 * @return void
 */
function local_stackforge_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/stackforge:generate', $context)) {
        return;
    }
    $url = new moodle_url('/local/stackforge/index.php', ['courseid' => $course->id]);
    $node = navigation_node::create(
        get_string('generate', 'local_stackforge'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_stackforge',
        new pix_icon('i/questions', '')
    );
    $navigation->add_node($node);
}
