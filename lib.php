<?php
// Adds a "Generate STACK questions" link to a course's navigation for users who can generate.
defined('MOODLE_INTERNAL') || die();

/**
 * Course navigation hook (classic callback, widely supported).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
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
