<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Bachs.io
Description: Bachs payment gateway integration for Perfex CRM, following the paystack/flutterwave module pattern, built on integration_runtime for webhook idempotency.
Version: 1.0.0
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
 * Without this, integration_runtime's cron sweep and its admin "replay"
 * button both fire 'integration_runtime_process_bachs' for any
 * failed/dead-lettered Bachs event -- with nothing listening, a
 * transiently-failed webhook (network blip, brief DB error) would sit in
 * 'failed' status forever instead of ever being retried automatically.
 */
hooks()->add_action('integration_runtime_process_bachs', 'bachs_process_integration_event');

function bachs_process_integration_event($event)
{
    $CI = &get_instance();
    $CI->load->model('integration_runtime/integration_events_model');
    $CI->load->library('bachs_gateway');

    $envelope = json_decode($event->payload, true);

    if (!is_array($envelope)) {
        $CI->integration_events_model->mark_failed($event->id, 'stored payload is not valid JSON');
        return;
    }

    try {
        $CI->bachs_gateway->process_webhook_event($envelope);
        $CI->integration_events_model->mark_processed($event->id);
    } catch (\Throwable $e) {
        $CI->integration_events_model->mark_failed($event->id, $e->getMessage());
    }
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
