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
 * Course page: pick a STACK question type/difficulty/count and a target category, then queue a job
 * that drafts and validates STACK questions (in-process against Moodle's own Maxima, or via the
 * external service) and adds them to the course question bank.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

use local_stackforge\local\pipeline;

$courseid = required_param('courseid', PARAM_INT);
$jobid = optional_param('job', 0, PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/stackforge:generate', $context);

$PAGE->set_url(new moodle_url('/local/stackforge/index.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('generate', 'local_stackforge'));
$PAGE->set_heading($course->fullname);

// The eight STACK question types the forge can author (type code => lang string id for the label).
$types = [
    'differentiate'         => 'type_differentiate',
    'integrate'             => 'type_integrate',
    'expand'                => 'type_expand',
    'factor'                => 'type_factor',
    'simplify_lowest_terms' => 'type_simplify',
    'solve_linear'          => 'type_solvelinear',
    'solve_quadratic'       => 'type_solvequadratic',
    'numerical'             => 'type_numerical',
];
$typemenu = [];
foreach ($types as $code => $stringid) {
    $typemenu[$code] = get_string($stringid, 'local_stackforge');
}
$difficulties = [
    'easy'   => get_string('easy', 'local_stackforge'),
    'medium' => get_string('medium', 'local_stackforge'),
    'hard'   => get_string('hard', 'local_stackforge'),
];

// Make sure the course context has at least a default question category, then list its categories.
question_get_default_category($context->id);
$categories = $DB->get_records('question_categories', ['contextid' => $context->id], 'name ASC', 'id,name');

$mode = pipeline::resolve_mode();
$hasservice = trim((string) get_config('local_stackforge', 'serviceurl')) !== '';
$result = null;

if (data_submitted() && confirm_sesskey()) {
    // Record the user's explicit acceptance of the AI policy (enables core-AI drafting), then reload.
    // Handled before the generate params (the accept form sends no category/type).
    if (optional_param('acceptaipolicy', 0, PARAM_INT)) {
        require_capability('moodle/ai:acceptpolicy', context_user::instance($USER->id));
        $accepted = \local_stackforge\local\core_ai::record_policy((int) $USER->id, $context);
        redirect(
            $PAGE->url,
            get_string($accepted ? 'policyaccepted' : 'policyacceptfailed', 'local_stackforge'),
            null,
            $accepted ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }

    $categoryid = required_param('category', PARAM_INT);
    if (!isset($categories[$categoryid])) {
        throw new moodle_exception('invalidparameter', 'error');
    }
    $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);

    if (optional_param('buildquiz', 0, PARAM_INT)) {
        // Building an RL-sequenced quiz uses the external service's /sequence (Phase 3) and creates a
        // course activity: require the core capabilities for both, plus a configured service.
        require_capability('moodle/question:add', $context);
        require_capability('moodle/course:manageactivities', $context);
        $seqcount = min(16, max(2, optional_param('seqcount', 10, PARAM_INT)));
        try {
            $steps = \local_stackforge\generator::sequence($seqcount);
            $built = \local_stackforge\generator::build_rl_set(
                $course,
                $context,
                $category,
                $steps,
                get_string('quizname', 'local_stackforge', userdate(time(), '%d %b %H:%M'))
            );
            $result = ['build' => $built];
        } catch (moodle_exception $e) {
            $result = ['error' => $e->getMessage()];
        }
    } else {
        // Queue an asynchronous generation job (CAS validation + AI retries are too slow for a request).
        require_capability('moodle/question:add', $context);
        $type       = required_param('qtype', PARAM_ALPHAEXT);
        $difficulty = optional_param('difficulty', 'easy', PARAM_ALPHA);
        $count      = min(10, max(1, optional_param('count', 1, PARAM_INT)));
        if (!isset($types[$type])) {
            throw new moodle_exception('invalidparameter', 'error');
        }

        $job = new stdClass();
        $job->courseid = $courseid;
        $job->userid = $USER->id;
        $job->categoryid = (int) $categoryid;
        $job->scratchcatid = 0;
        $job->jobtype = 'generate';
        $job->qtype = $type;
        $job->difficulty = $difficulty;
        $job->numrequested = $count;
        $job->nummade = 0;
        $job->mode = $mode;
        $job->status = 'queued';
        $job->qids = null;
        $job->cmid = 0;
        $job->errors = null;
        $job->timecreated = time();
        $job->timemodified = time();
        $job->id = $DB->insert_record('local_stackforge_jobs', $job);

        $task = new \local_stackforge\task\generate_questions_task();
        $task->set_custom_data(['jobid' => $job->id]);
        $task->set_component('local_stackforge');
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        redirect(new moodle_url('/local/stackforge/index.php', ['courseid' => $courseid, 'job' => $job->id]));
    }
}

// Load the job we are viewing (if any) so we can refresh the page while it runs.
$job = null;
if ($jobid) {
    $job = $DB->get_record('local_stackforge_jobs', ['id' => $jobid, 'courseid' => $courseid]);
    if ($job && in_array($job->status, ['queued', 'running'], true)) {
        $PAGE->set_periodic_refresh_delay(4);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('generateheading', 'local_stackforge'));
echo html_writer::tag('p', get_string('intro', 'local_stackforge'));

// Show the resolved mode so authors know which path will run.
$modelabel = get_string('mode_' . $mode, 'local_stackforge');
echo $OUTPUT->notification(get_string('modebanner', 'local_stackforge', $modelabel), 'info');

if ($mode === pipeline::MODE_EXTERNAL && !$hasservice) {
    echo $OUTPUT->notification(get_string('notconfigured', 'local_stackforge'), 'error');
}

// In-process + core-AI drafting needs the AI policy accepted: inform the author and offer to accept.
// (Without it, generation still works using the deterministic template expressions.)
if ($mode === pipeline::MODE_INPROCESS
        && \local_stackforge\local\ai_client::resolve_backend($context) === 'core'
        && \local_stackforge\local\core_ai::available()
        && !\local_stackforge\local\core_ai::policy_accepted((int) $USER->id)
        && has_capability('moodle/ai:acceptpolicy', context_user::instance($USER->id))) {
    echo $OUTPUT->notification(get_string('aipolicynotice', 'local_stackforge'), 'warning');
    $policytext = \local_stackforge\local\core_ai::policy_text();
    if ($policytext !== '') {
        echo html_writer::tag('div', nl2br(s($policytext)), ['class' => 'local-stackforge-policy']);
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag(
        'button',
        get_string('policyaccept', 'local_stackforge'),
        ['type' => 'submit', 'name' => 'acceptaipolicy', 'value' => '1', 'class' => 'btn btn-primary']
    );
    echo html_writer::end_tag('form');
}

// Status of the job being viewed.
if ($job) {
    if ($job->status === 'queued') {
        echo $OUTPUT->notification(get_string('jobqueued', 'local_stackforge'), 'info');
    } else if ($job->status === 'running') {
        echo $OUTPUT->notification(
            get_string('jobrunning', 'local_stackforge', (object) ['made' => $job->nummade, 'total' => $job->numrequested]),
            'info'
        );
    } else if ($job->status === 'done') {
        echo $OUTPUT->notification(get_string('imported', 'local_stackforge', $job->nummade), 'success');
        $link = html_writer::link(
            new moodle_url('/question/edit.php', ['courseid' => $courseid]),
            get_string('backtobank', 'local_stackforge')
        );
        echo html_writer::tag('p', $link);
    } else if ($job->status === 'failed') {
        echo $OUTPUT->notification(get_string('jobfailed', 'local_stackforge'), 'error');
    }
    // Per-question progress log.
    if (!empty($job->errors)) {
        echo html_writer::tag('pre', s($job->errors), ['class' => 'local-stackforge-joblog']);
    }
}

if (isset($result['error'])) {
    echo $OUTPUT->notification(get_string('servicefail', 'local_stackforge', $result['error']), 'error');
} else if (isset($result['build'])) {
    $b = $result['build'];
    if ($b['made'] > 0) {
        echo $OUTPUT->notification(get_string('builtset', 'local_stackforge', $b['made']), 'success');
        if (!empty($b['cmid'])) {
            $link = html_writer::link(
                new moodle_url('/mod/quiz/view.php', ['id' => $b['cmid']]),
                get_string('openquiz', 'local_stackforge'),
                ['class' => 'btn btn-primary']
            );
            echo html_writer::tag('p', $link);
        } else {
            echo $OUTPUT->notification(get_string('quiznotbuilt', 'local_stackforge'), 'warning');
        }
    } else {
        echo $OUTPUT->notification(get_string('nonemade', 'local_stackforge', ''), 'warning');
    }
}

// The generate form (plain HTML, minimal and robust).
$catoptions = [];
foreach ($categories as $cat) {
    $catoptions[$cat->id] = format_string($cat->name);
}

echo html_writer::start_tag('form', ['method' => 'post',
    'action' => new moodle_url('/local/stackforge/index.php', ['courseid' => $courseid])]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('qtype', 'local_stackforge'), ['for' => 'qtype']);
echo html_writer::select($typemenu, 'qtype', 'differentiate', false, ['id' => 'qtype']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('difficulty', 'local_stackforge'), ['for' => 'difficulty']);
echo html_writer::select($difficulties, 'difficulty', 'easy', false, ['id' => 'difficulty']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('count', 'local_stackforge'), ['for' => 'count']);
echo html_writer::select(array_combine(range(1, 10), range(1, 10)), 'count', 3, false, ['id' => 'count']);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('category', 'local_stackforge'), ['for' => 'category']);
echo html_writer::select($catoptions, 'category', array_key_first($catoptions), false, ['id' => 'category']);
echo html_writer::end_div();

echo html_writer::tag(
    'button',
    get_string('generatebtn', 'local_stackforge'),
    ['type' => 'submit', 'class' => 'btn btn-primary local-stackforge-gen']
);

echo html_writer::end_tag('form');

// Build a full RL-sequenced quiz — external service only (Phase 3 policy lives in the backend).
if ($hasservice) {
    echo html_writer::empty_tag('hr', ['class' => 'local-stackforge-sep']);
    echo html_writer::tag('h4', get_string('buildquizheading', 'local_stackforge'));
    echo html_writer::tag('p', get_string('buildquizintro', 'local_stackforge'));
    echo html_writer::start_tag('form', ['method' => 'post',
        'action' => new moodle_url('/local/stackforge/index.php', ['courseid' => $courseid])]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('category', 'local_stackforge'), ['for' => 'rlcategory']);
    echo html_writer::select($catoptions, 'category', array_key_first($catoptions), false, ['id' => 'rlcategory']);
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::tag('label', get_string('seqcount', 'local_stackforge'), ['for' => 'seqcount']);
    echo html_writer::select(array_combine(range(4, 16), range(4, 16)), 'seqcount', 10, false, ['id' => 'seqcount']);
    echo html_writer::end_div();
    echo html_writer::tag(
        'button',
        get_string('buildquizbtn', 'local_stackforge'),
        ['type' => 'submit', 'name' => 'buildquiz', 'value' => '1', 'class' => 'btn btn-secondary local-stackforge-build']
    );
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
