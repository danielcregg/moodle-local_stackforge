<?php
// Capability: who may generate questions (course editing teachers + managers).
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/stackforge:generate' => [
        'riskbitmask'  => RISK_SPAM,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
