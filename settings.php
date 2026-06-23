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
 * Admin settings: where the external generation service lives and its bearer token.
 *
 * Both default to empty: the plugin does nothing and contacts nothing until a site administrator
 * configures a trusted endpoint. The token is stored server-side and never exposed to the browser.
 *
 * @package    local_stackforge
 * @copyright  2026 Daniel Cregg
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_stackforge', get_string('pluginname', 'local_stackforge'));
    $ADMIN->add('localplugins', $settings);

    // PARAM_RAW_TRIMMED (not PARAM_URL): the endpoint may legitimately be an internal host such as
    // http://generate:8092, which PARAM_URL would strip. The URL is validated where it is used
    // (generator::base_url(): http/https scheme, host required, no embedded credentials).
    $settings->add(new admin_setting_configtext(
        'local_stackforge/serviceurl',
        get_string('serviceurl', 'local_stackforge'),
        get_string('serviceurl_desc', 'local_stackforge'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_stackforge/apitoken',
        get_string('apitoken', 'local_stackforge'),
        get_string('apitoken_desc', 'local_stackforge'),
        ''
    ));
}
