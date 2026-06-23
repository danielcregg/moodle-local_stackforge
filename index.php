<?php
// Course page: choose a STACK question type/difficulty/count + a target category, then generate
// AI-drafted, oracle-validated STACK questions straight into the course question bank.
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/stackforge:generate', $context);

$PAGE->set_url(new moodle_url('/local/stackforge/index.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('generate', 'local_stackforge'));
$PAGE->set_heading($course->fullname);

// The eight STACK question types the forge can author (value => human label).
$types = [
    'differentiate'         => 'Differentiate',
    'integrate'             => 'Find an antiderivative',
    'expand'                => 'Expand',
    'factor'                => 'Factorise',
    'simplify_lowest_terms' => 'Simplify to lowest terms',
    'solve_linear'          => 'Solve a linear equation',
    'solve_quadratic'       => 'Solve a quadratic',
    'numerical'             => 'Evaluate to a decimal',
];
$difficulties = [
    'easy'   => get_string('easy', 'local_stackforge'),
    'medium' => get_string('medium', 'local_stackforge'),
    'hard'   => get_string('hard', 'local_stackforge'),
];

// Make sure the course context has at least a default question category, then list its categories.
question_get_default_category($context->id);
$categories = $DB->get_records('question_categories', ['contextid' => $context->id], 'name ASC', 'id,name');

$result = null;
if (data_submitted() && confirm_sesskey()) {
    $type       = required_param('qtype', PARAM_ALPHAEXT);
    $difficulty = optional_param('difficulty', 'easy', PARAM_ALPHA);
    $count      = min(10, max(1, optional_param('count', 1, PARAM_INT)));
    $categoryid = required_param('category', PARAM_INT);

    if (!isset($types[$type]) || !isset($categories[$categoryid])) {
        throw new moodle_exception('invalidparameter', 'error');
    }
    $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);

    try {
        $questions = \local_stackforge\generator::generate($type, $difficulty, $count);
        $made = 0;
        foreach ($questions as $q) {
            if (!empty($q['xml'])
                    && \local_stackforge\generator::import_one($q['xml'], $category, $context, $course)) {
                $made++;
            }
        }
        $result = ['n' => $made];
    } catch (moodle_exception $e) {
        $result = ['error' => $e->getMessage()];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('generateheading', 'local_stackforge'));
echo html_writer::tag('p', get_string('intro', 'local_stackforge'));

if (!get_config('local_stackforge', 'serviceurl')) {
    echo $OUTPUT->notification(get_string('notconfigured', 'local_stackforge'), 'error');
}

if ($result !== null) {
    if (isset($result['error'])) {
        echo $OUTPUT->notification(get_string('servicefail', 'local_stackforge', $result['error']), 'error');
    } else if ($result['n'] > 0) {
        echo $OUTPUT->notification(get_string('imported', 'local_stackforge', $result['n']), 'success');
        echo html_writer::tag('p', html_writer::link(
            new moodle_url('/question/edit.php', ['courseid' => $courseid]),
            get_string('backtobank', 'local_stackforge')));
    } else {
        echo $OUTPUT->notification(get_string('nonemade', 'local_stackforge', ''), 'warning');
    }
}

// --- the form (plain HTML; minimal + robust) ---
$catoptions = [];
foreach ($categories as $cat) {
    $catoptions[$cat->id] = format_string($cat->name);
}

echo html_writer::start_tag('form', ['method' => 'post',
    'action' => new moodle_url('/local/stackforge/index.php', ['courseid' => $courseid])]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('qtype', 'local_stackforge'), ['for' => 'qtype']);
echo html_writer::select($types, 'qtype', 'differentiate', false, ['id' => 'qtype']);
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

echo html_writer::tag('button', get_string('generatebtn', 'local_stackforge'),
    ['type' => 'submit', 'class' => 'btn btn-primary', 'style' => 'margin-top:.6rem']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
