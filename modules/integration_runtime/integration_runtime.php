<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Integration Runtime
Description: Shared idempotency, retry, and dead-letter foundation for all Airix external-service integrations (Bachs, Chatwoot, WhatsApp, Documenso, Uptime Kuma).
Version: 1.0.0
Requires at least: 3.3.*
*/

define('INTEGRATION_RUNTIME_MODULE_NAME', 'integration_runtime');

register_language_files(INTEGRATION_RUNTIME_MODULE_NAME, [INTEGRATION_RUNTIME_MODULE_NAME]);

/**
 * Perfex's own App_modules::activate() only inserts the tblmodules row and
 * flips active=1 -- it never runs install.php itself. Without this hook, a
 * fresh install would activate successfully but never create
 * tblintegration_events, so every model call would fail immediately.
 */
register_activation_hook(INTEGRATION_RUNTIME_MODULE_NAME, 'integration_runtime_module_activation_hook');

function integration_runtime_module_activation_hook()
{
    $CI = &get_instance();
    require_once __DIR__ . '/install.php';
}

hooks()->add_action('admin_init', 'integration_runtime_module_init_menu_items');
hooks()->add_action('admin_init', 'integration_runtime_permissions');
hooks()->add_action('after_cron_run', 'integration_runtime_retry_sweep');

function integration_runtime_permissions()
{
    $capabilities = [
        'capabilities' => [
            'view' => _l('permission_view') . '(' . _l('permission_global') . ')',
        ],
    ];

    register_staff_capabilities('integration_runtime', $capabilities, _l('integration_events'));
}

function integration_runtime_module_init_menu_items()
{
    $CI = &get_instance();

    if (staff_can('view', 'integration_runtime')) {
        $CI->app_menu->add_sidebar_children_item('utilities', [
            'slug'     => 'integration-events',
            'name'     => _l('integration_events'),
            'href'     => admin_url('integration_runtime'),
            'position' => 30,
        ]);
    }
}

// Fires on every Perfex cron run (same mechanism goals/surveys/backup already use).
// Sweeps events that are due for retry and asks whichever module registered a
// processor for that provider to handle them again.
function integration_runtime_retry_sweep()
{
    $CI = &get_instance();
    $CI->load->model('integration_runtime/integration_events_model');
    $CI->integration_events_model->retry_due_events();
}
