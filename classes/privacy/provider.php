<?php
// This plugin stores no personal data (it only relays a question type/difficulty to the
// generation service), so it implements the null provider.
namespace local_stackforge\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
