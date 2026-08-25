<?php

defined('BASEPATH') or exit('No direct script access allowed');

// -- Transactions --
$lang['bachs_transactions']             = 'Bachs Transactions';
$lang['bachs_transactions_description'] = 'Confirmed Bachs charges recorded against invoices, via verified webhooks only.';
$lang['bachs_charge_id']                = 'Charge ID';
$lang['bachs_invoice']                  = 'Invoice';
$lang['bachs_amount']                   = 'Amount';
$lang['bachs_currency']                 = 'Currency';
$lang['bachs_status']                   = 'Status';
$lang['bachs_created']                  = 'Created';
$lang['bachs_reason']                   = 'Reason';
$lang['bachs_none_yet']                 = 'No Bachs transactions yet.';
$lang['bachs_missing_product_id']       = 'Bachs is not fully configured yet (missing product ID). Please contact support.';
$lang['bachs_unsupported_currency']     = 'This currency is not enabled for Bachs payments. Please contact support.';
$lang['bachs_checkout_failed']          = 'Could not start a Bachs checkout. Please try again or contact support.';
$lang['bachs_overlay_loading']          = 'Loading secure checkout...';
$lang['bachs_overlay_continue_link']    = 'Continue to checkout';
$lang['bachs_return_title']             = 'Payment received';
$lang['bachs_return_message']           = 'Thanks -- we\'re confirming your payment now. You\'ll be redirected shortly.';
$lang['bachs_sandbox']                  = 'Sandbox';

// -- Refunds --
$lang['bachs_refunds']                  = 'Refunds';
$lang['bachs_refund']                   = 'Refund';
$lang['bachs_refund_id']                = 'Refund ID';
$lang['bachs_refund_status']            = 'Refund Status';
$lang['bachs_refund_amount_optional']   = 'Amount (leave blank for a full refund)';
$lang['bachs_refund_full_if_blank']     = 'Full amount';
$lang['bachs_refund_reason']            = 'Reason (optional)';
$lang['bachs_refund_confirm']           = 'Issue Refund';
$lang['bachs_refund_initiated']         = 'Refund initiated. It will be marked complete once Bachs confirms it.';
$lang['bachs_refund_failed']            = 'Could not initiate the refund. Please try again or contact support.';
$lang['bachs_refund_missing_charge']    = 'No charge selected for this refund.';

// -- Disputes --
$lang['bachs_disputes']                 = 'Disputes';
$lang['bachs_dispute_id']               = 'Dispute ID';

// -- Subscriptions: admin list --
$lang['bachs_subscriptions']                 = 'Bachs Subscriptions';
$lang['bachs_subscriptions_description']     = 'Recurring billing collected through Bachs, independent of Perfex\'s native Stripe subscriptions.';
$lang['bachs_subscriptions_test_mode_notice'] = 'Bachs is currently in Sandbox mode -- subscriptions created now will not bill a real card.';
$lang['bachs_subscriptions_none_yet']        = 'No Bachs subscriptions yet.';
$lang['bachs_th_item']                       = 'Item';
$lang['bachs_th_amount']                     = 'Amount';
$lang['bachs_th_quantity']                   = 'Qty';

// -- Subscriptions: shared / form --
$lang['bachs_subscription']                       = 'Subscription';
$lang['bachs_subscription_billing']               = 'Billing';
$lang['bachs_subscription_next_billing']          = 'Next Billing';
$lang['bachs_subscription_customer_label']        = 'Customer';
$lang['bachs_subscription_product_label']         = 'Product / Plan Name';
$lang['bachs_subscription_amount']                = 'Amount (USD)';
$lang['bachs_subscription_every']                 = 'Every';
$lang['bachs_subscription_interval']              = 'Interval';
$lang['bachs_subscription_trial_length']          = 'Trial Length (optional)';
$lang['bachs_subscription_trial_interval']        = 'Trial Interval';
$lang['bachs_subscription_id_label']              = 'Bachs Subscription ID';
$lang['bachs_subscription_currency_locked']       = 'Bachs subscriptions bill in USD only.';
$lang['bachs_subscription_pricing_locked']        = 'Pricing, quantity, interval and trial are locked once this subscription is live on Bachs. Cancel and create a new one to change them.';
$lang['bachs_subscription_usd_only_notice']       = 'Bachs subscriptions can only be billed in USD -- the client\'s currency must be USD to continue.';
$lang['bachs_subscription_usd_only_client_notice'] = 'This subscription bills in USD.';
$lang['bachs_subscription_already_active']        = 'This subscription is already active on Bachs.';
$lang['bachs_subscription_delete_blocked']        = 'This subscription is live on Bachs and can\'t be deleted -- cancel it first.';
$lang['bachs_subscription_environment_mismatch']  = 'This subscription was created in a different Bachs environment (sandbox/live) than the one currently active.';

// -- Subscriptions: status panel / lifecycle --
$lang['bachs_subscription_status_panel']          = 'Subscription Status';
$lang['bachs_subscription_remote_snapshot']       = 'Live snapshot from Bachs';
$lang['bachs_subscription_remote_unavailable']    = 'Could not reach Bachs for a live status update -- showing the last known state.';
$lang['bachs_subscription_current_period']        = 'Current Period';
$lang['bachs_subscription_ends_on']               = 'Ends On';
$lang['bachs_subscription_ending']                = 'Ending';
$lang['bachs_subscription_trial']                 = 'Trial';
$lang['bachs_subscription_subscribed_on']         = 'Subscribed On';
$lang['bachs_subscription_recurring_total']       = 'Recurring Total';

// -- Subscriptions: actions --
$lang['bachs_subscription_sync']                       = 'Sync with Bachs';
$lang['bachs_subscription_synced']                     = 'Subscription synced with Bachs.';
$lang['bachs_subscription_resume']                     = 'Resume';
$lang['bachs_subscription_resumed']                    = 'Subscription resumed.';
$lang['bachs_subscription_cancel_at_period_end']       = 'Cancel at period end';
$lang['bachs_subscription_cancel_immediately']         = 'Cancel immediately';
$lang['bachs_subscription_confirm_cancel_now']         = 'Cancel this subscription immediately? This can\'t be undone.';
$lang['bachs_subscription_confirm_cancel_period_end']  = 'Cancel this subscription at the end of the current billing period?';
$lang['bachs_subscription_canceled']                   = 'Subscription canceled.';
$lang['bachs_subscription_portal']                     = 'Manage Billing';
$lang['bachs_subscription_send_to_client']             = 'Send to Client';
$lang['bachs_subscription_sent_success']               = 'Subscription link sent to the client.';
$lang['bachs_subscription_sent_unavailable']           = 'Could not send the subscription link. Please try again.';
$lang['bachs_subscription_client_link']                = 'Client Link';
$lang['bachs_subscription_client_view']                = 'View as client';

// -- Subscriptions: client-facing preview --
$lang['bachs_subscription_secure_checkout_notice']     = 'You\'ll be taken to a secure Bachs checkout to confirm your subscription.';
$lang['bachs_subscription_subscribe_button']           = 'Subscribe';
$lang['bachs_subscription_checkout_failed']            = 'Could not start checkout for this subscription. Please try again or contact us.';
$lang['bachs_subscription_success_message']            = 'You\'re subscribed! You\'ll receive a receipt shortly.';
$lang['bachs_subscription_unavailable_right_now']      = 'This subscription link isn\'t available right now. Please contact us.';

// -- Subscription status pill values (bachs_subscription_status_label()) --
$lang['bachs_subscription_status_not_subscribed'] = 'Not Subscribed';
$lang['bachs_subscription_status_trialing']       = 'Trialing';
$lang['bachs_subscription_status_active']         = 'Active';
$lang['bachs_subscription_status_past_due']       = 'Past Due';
$lang['bachs_subscription_status_unpaid']         = 'Unpaid';
$lang['bachs_subscription_status_paused']         = 'Paused';
$lang['bachs_subscription_status_canceled']       = 'Canceled';
