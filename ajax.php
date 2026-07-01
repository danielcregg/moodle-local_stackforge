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
 * On-device generation endpoint. The author's browser drafts a source expression with a local WebLLM
 * model and posts only {type, difficulty, expr}; the SERVER builds the STACK XML from the deterministic
 * template, runs the in-process oracle, and imports the question on success. The browser NEVER supplies
 * XML — there is no answer to leak because the template's own Maxima computes it server-side.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

use local_stackforge\local\pipeline;
use local_stackforge\local\scratch_importer;
use local_stackforge\local\inprocess_validator;

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', 'validateexpr', PARAM_ALPHA);

// Access control: a real course context, and the user must be allowed to generate and add questions.
$course = get_course($courseid);
require_login($course);
require_sesskey();
$context = context_course::instance($courseid);
require_capability('local/stackforge:generate', $context);
require_capability('moodle/question:add', $context);

global $DB;
header('Content-Type: application/json; charset=utf-8');

// The only action: validate one browser-proposed expression through the oracle and import it on success.
if ($action !== 'validateexpr') {
    echo json_encode(['ok' => false, 'reason' => get_string('ondeviceunavailable', 'local_stackforge')]);
    die();
}

$type = required_param('type', PARAM_ALPHAEXT);
$difficulty = optional_param('difficulty', 'easy', PARAM_ALPHA);
// The raw expression is repaired and gated by the allow-list server-side; it is never trusted as-is.
$expr = core_text::substr(required_param('expr', PARAM_RAW), 0, 400);
$categoryid = required_param('category', PARAM_INT);

// The target category must belong to THIS course context — never import into an arbitrary category.
$category = $DB->get_record(
    'question_categories',
    ['id' => $categoryid, 'contextid' => $context->id],
    '*',
    IGNORE_MISSING
);
if (!$category) {
    echo json_encode(['ok' => false, 'reason' => get_string('ondevicebadcategory', 'local_stackforge')]);
    die();
}

// On-device generation is only meaningful for the two expr-driven types and needs in-process validation.
if (!\local_stackforge\local\ai_client::uses_expr($type)) {
    echo json_encode(['ok' => false, 'reason' => get_string('ondevicenotexprtype', 'local_stackforge')]);
    die();
}
[$supported, $why] = inprocess_validator::inprocess_supported();
if (!$supported) {
    echo json_encode(['ok' => false, 'reason' => $why]);
    die();
}

// A per-call scratch category holds the draft while it is validated, then is torn down. The scheduled
// cleanup task is the backstop for any category a fatal/timeout leaves behind.
$scratch = scratch_importer::create_category($context, random_int(1000000, 9999999));
try {
    $res = pipeline::validate_candidate($type, $difficulty, $expr, $context, $course, $scratch);
    if (!empty($res['ok']) && !empty($res['xml'])) {
        $imported = \local_stackforge\generator::import_one($res['xml'], $category, $context, $course);
        echo json_encode([
            'ok' => (bool) $imported,
            'imported' => (bool) $imported,
            'name' => $res['name'] ?? '',
            'source' => 'ondevice',
            'reason' => $imported ? '' : get_string('ondeviceimportfailed', 'local_stackforge'),
        ]);
    } else {
        echo json_encode([
            'ok' => false,
            'imported' => false,
            'source' => 'ondevice',
            'reason' => $res['reason'] ?? '',
            'hint' => $res['hint'] ?? '',
        ]);
    }
} catch (\Throwable $e) {
    debugging('local_stackforge validateexpr failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode(['ok' => false, 'reason' => get_string('ondeviceunavailable', 'local_stackforge')]);
} finally {
    scratch_importer::delete_category((int) $scratch->id);
}
