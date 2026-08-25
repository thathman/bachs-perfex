<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Bachs-backed fork of application/controllers/Subscription.php -- the
 * singular, client-facing half. Same shape as the native one: hash-gated, no
 * login required, a preview page the client reads and one Subscribe action
 * that builds a checkout and redirects. The client never picks a plan; staff
 * pre-created the subscription against exactly one price.
 *
 * Subscribe is a plain GET link, not a POST form, so no csrf_exclude_uris
 * entry is needed (unlike the webhook receiver, which does have one).
 */
class Bachs_subscription extends ClientsController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bachs/bachs_subscriptions_model');
        $this->load->library('bachs/bachs_subscriptions_gateway');
    }

    public function index($hash = '')
    {
        $subscription = $this->require_subscription($hash);

        load_client_language($subscription->clientid);

        $this->disableNavigation();
        $this->disableSubMenu();

        $data['subscription'] = $subscription;
        $data['gateway']      = $this->bachs_subscriptions_gateway;
        $data['hash']         = $hash;
        $data['title']        = $subscription->name;
        $data['bodyclass']    = 'bachs-subscription-html';

        // A record made against sandbox keys cannot be paid with live keys
        // (Bachs sandbox and production share no products, customers or
        // subscriptions at all), so the Subscribe button is hidden rather
        // than allowed to fail at the API.
        $data['environment_mismatch'] = ((int) $subscription->in_test_environment === 1)
            !== $this->bachs_subscriptions_gateway->is_test_mode();

        $data['can_subscribe'] = empty($subscription->bachs_subscription_id)
            && !$data['environment_mismatch'];

        $this->data($data);
        $this->view('subscription_preview');
        $this->layout();
    }

    public function subscribe($hash = '')
    {
        $subscription = $this->require_subscription($hash);

        $back = site_url('bachs/bachs_subscription/index/' . $hash);

        // Never build a second checkout for a subscription Bachs is already
        // billing -- that would create a duplicate, parallel subscription for
        // the same client.
        if (!empty($subscription->bachs_subscription_id)) {
            set_alert('warning', _l('bachs_subscription_already_active'));
            redirect($back);
        }

        try {
            $checkoutUrl = $this->bachs_subscriptions_gateway->create_checkout($subscription);
        } catch (\Throwable $e) {
            log_activity('Bachs subscription checkout creation failed [ID: ' . $subscription->id . ']: ' . $e->getMessage());
            set_alert('danger', _l('bachs_subscription_checkout_failed'));
            redirect($back);

            return;
        }

        redirect($checkoutUrl);
    }

    /**
     * Where Bachs sends the browser after a successful checkout. Deliberately
     * NOT treated as confirmation -- the same hard rule the invoice flow
     * follows: only a signature-verified webhook ever marks a subscription
     * active. This page only says thank you and sends them back to the
     * preview, which will show the real status once the webhook lands.
     */
    public function success($hash = '')
    {
        $this->require_subscription($hash);

        set_alert('success', _l('bachs_subscription_success_message'));

        redirect(site_url('bachs/bachs_subscription/index/' . $hash));
    }

    private function require_subscription($hash)
    {
        $subscription = $hash ? $this->bachs_subscriptions_model->get_by_hash($hash) : null;

        if (!$subscription) {
            show_404();
        }

        return $subscription;
    }
}
