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
 * Admin-only in-process smoke test: build one known-good question, validate it against Moodle's own
 * qtype_stack + Maxima, and tear it down — proving the zero-backend path works on this site.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$run = optional_param('run', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/stackforge/smoke.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('smokeheading', 'local_stackforge'));
$PAGE->set_heading(get_string('smokeheading', 'local_stackforge'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('smokeheading', 'local_stackforge'));

$drift = \local_stackforge\local\inprocess_validator::version_warning();
if ($drift !== null) {
    echo $OUTPUT->notification(get_string('qtypeversionwarning', 'local_stackforge', (object) $drift), 'warning');
}

[$supported, $why] = \local_stackforge\local\inprocess_validator::inprocess_supported();
if (!$supported) {
    echo $OUTPUT->notification(get_string('smokeunsupported', 'local_stackforge', $why), 'error');
} else if ($run && confirm_sesskey()) {
    \core\session\manager::write_close();
    $res = \local_stackforge\local\pipeline::smoke_test();
    if ($res['ok']) {
        echo $OUTPUT->notification(
            get_string('smokepass', 'local_stackforge', (object) [
                'seeds' => $res['seeds'], 'tests' => $res['tests'], 'ms' => $res['ms'],
            ]),
            'success'
        );
    } else {
        echo $OUTPUT->notification(get_string('smokefail', 'local_stackforge', s($res['reason'])), 'error');
    }
} else {
    echo html_writer::tag('p', get_string('smokeintro', 'local_stackforge'));
    echo html_writer::start_tag('form', ['method' => 'post',
        'action' => new moodle_url('/local/stackforge/smoke.php', ['run' => 1])]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag(
        'button',
        get_string('smokerun', 'local_stackforge'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );
    echo html_writer::end_tag('form');
}

echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'local_stackforge']),
    get_string('backtosettings', 'local_stackforge')
));

echo $OUTPUT->footer();
