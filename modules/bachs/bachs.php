<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Bachs.io
Description: Bachs.io payment gateway integration for Perfex CRM, with its own self-contained webhook idempotency, retry, and dead-letter tracking.
Version: 1.3.0
Requires at least: 3.3.*
*/

define('BACHS_MODULE_NAME', 'bachs');

require_once __DIR__ . '/src/BachsAmounts.php';
require_once __DIR__ . '/src/BachsClient.php';
require_once __DIR__ . '/src/BachsSubscriptionsClient.php';

/**
 * Loaded at module bootstrap, not lazily, purely so class_exists() is true.
 * Bachs_gateway::process_webhook_event() gates its 'customer.subscription.*'
 * dispatch on class_exists('Bachs_subscriptions_gateway') -- and CI never
 * autoloads module libraries, so without this require the class would not
 * exist at the moment that check runs and every subscription webhook would
 * be silently dropped. CI's loader is still what instantiates it; this only
 * makes the declaration visible.
 */
require_once __DIR__ . '/libraries/Bachs_subscriptions_gateway.php';

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
    $CI->load->model('bachs/Bachs_events_model');
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

    /**
     * Sibling of the transactions item above -- the forked, Bachs-backed
     * subscriptions feature. Deliberately gated on the same 'subscriptions'
     * permission Perfex's own native Subscriptions feature uses, so staff who
     * can already manage subscriptions get this without a second permission
     * to grant. The native feature's menu item is untouched; both appear.
     */
    if (staff_can('view', 'subscriptions') || staff_can('view_own', 'subscriptions')) {
        $CI->app_menu->add_sidebar_children_item('utilities', [
            'slug'     => 'bachs-subscriptions',
            'name'     => _l('bachs_subscriptions'),
            'href'     => admin_url('bachs/bachs_subscriptions_admin'),
            'position' => 35,
        ]);
    }
}

/**
 * Shared status pill for both the admin list/edit views and the client
 * preview, so a status never renders as one colour for staff and another for
 * the client. Statuses are Bachs's own vocabulary
 * (trialing|active|past_due|unpaid|canceled|paused), plus the local-only
 * 'not_subscribed' for a record staff created that the client has not yet
 * paid for.
 */
function bachs_subscription_status_label($subscription)
{
    $status = (string) $subscription->status;

    $classes = [
        'not_subscribed' => 'default',
        'trialing'       => 'info',
        'active'         => 'success',
        'past_due'       => 'warning',
        'unpaid'         => 'danger',
        'paused'         => 'warning',
        'canceled'       => 'danger',
    ];

    $class = $classes[$status] ?? 'default';

    return '<span class="label label-' . $class . '">'
        . html_escape(_l('bachs_subscription_status_' . $status))
        . '</span>';
}
