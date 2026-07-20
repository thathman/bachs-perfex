<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Bachs.io
Description: Bachs.io payment gateway integration for Perfex CRM, with its own self-contained webhook idempotency, retry, and dead-letter tracking.
Version: 1.2.0
Requires at least: 3.3.*
*/

define('BACHS_MODULE_NAME', 'bachs');

require_once __DIR__ . '/src/BachsAmounts.php';
require_once __DIR__ . '/src/BachsClient.php';

register_language_files(BACHS_MODULE_NAME, [BACHS_MODULE_NAME]);

register_activation_hook(BACHS_MODULE_NAME, 'bachs_module_activation_hook');

function bachs_module_activation_hook()
{
    $CI = &get_instance();
    require_once __DIR__ . '/install.php';
}

register_payment_gateway('bachs_gateway', 'bachs');

/**
 * Sweeps webhook events due for retry on every Perfex cron run (the same
 * mechanism goals/surveys/backup already use) -- self-contained, no
 * separate shared-runtime module or cross-module hook involved.
 */
hooks()->add_action('after_cron_run', 'bachs_retry_sweep');

function bachs_retry_sweep()
{
    $CI = &get_instance();
    $CI->load->model('bachs/bachs_events_model');
    $CI->bachs_events_model->retry_due_events();
}

hooks()->add_action('admin_init', 'bachs_module_init_menu_items');

function bachs_module_init_menu_items()
{
    $CI = &get_instance();

    if (staff_can('view', 'invoices')) {
        $CI->app_menu->add_sidebar_children_item('utilities', [
            'slug'     => 'bachs-transactions',
            'name'     => _l('bachs_transactions'),
            'href'     => admin_url('bachs/bachs_admin'),
            'position' => 34,
        ]);
    }
}
