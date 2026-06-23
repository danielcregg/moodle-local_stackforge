<?php
// Admin settings: where the generation service lives + its bearer token (server-side; never
// exposed to students). Same token the STACK API uses (FORGE_API_SECRET).
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_stackforge', get_string('pluginname', 'local_stackforge'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_stackforge/serviceurl',
        get_string('serviceurl', 'local_stackforge'),
        get_string('serviceurl_desc', 'local_stackforge'),
        'https://pro-approximate-watching-can.trycloudflare.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_stackforge/apitoken',
        get_string('apitoken', 'local_stackforge'),
        get_string('apitoken_desc', 'local_stackforge'),
        ''
    ));
}
