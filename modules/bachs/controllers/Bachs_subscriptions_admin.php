<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Bachs-backed fork of application/controllers/admin/Subscriptions.php.
 * Same plural-controller shape, same one-shared-view-for-create-and-edit
 * arrangement, same cancel/resume/sync/delete verbs -- but backed by
 * tblbachs_subscriptions and the Bachs API instead of tblsubscriptions and
 * Stripe. Perfex's native Subscriptions feature is untouched; the two run
 * side by side.
 */
class Bachs_subscriptions_admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bachs/bachs_subscriptions_model');
        $this->load->library('bachs/bachs_subscriptions_gateway');
    }

    public function index()
    {
        if (staff_cant('view', 'subscriptions') && staff_cant('view_own', 'subscriptions')) {
            access_denied('Bachs Subscriptions View');
        }

        $where = [];
        if (staff_cant('view', 'subscriptions')) {
            $where['created_from'] = get_staff_user_id();
        }

        $data['title']         = _l('bachs_subscriptions');
        $data['subscriptions'] = $this->bachs_subscriptions_model->get($where);
        $data['gateway']       = $this->bachs_subscriptions_gateway;

        $this->load->view('subscriptions_manage', $data);
    }

    public function create()
    {
        if (staff_cant('create', 'subscriptions')) {
            access_denied('Bachs Subscriptions Create');
        }

        if ($this->input->post()) {
            $insert_id = $this->bachs_subscriptions_model->create($this->form_payload());

            set_alert('success', _l('added_successfully', _l('bachs_subscription')));
            redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $insert_id));
        }

        $data['title']       = _l('add_new', _l('bachs_subscription'));
        $data['gateway']     = $this->bachs_subscriptions_gateway;
        $data['customer_id'] = $this->input->get('customer_id') ?: '';
        $data['bodyclass']   = 'bachs-subscription';

        $this->load->view('subscriptions_form', $data);
    }

    public function edit($id)
    {
        if (staff_cant('view', 'subscriptions') && staff_cant('view_own', 'subscriptions')) {
            access_denied('Bachs Subscriptions View');
        }

        $subscription = $this->bachs_subscriptions_model->get_by_id($id);

        if (!$subscription
            || (staff_cant('view', 'subscriptions') && $subscription->created_from != get_staff_user_id())) {
            show_404();
        }

        if ($this->input->post()) {
            if (staff_cant('edit', 'subscriptions')) {
                access_denied('Bachs Subscriptions Edit');
            }

            $update = $this->form_payload();

            // Once Bachs is actually billing this, neither the client nor the
            // price is ours to move -- the native feature locks the client and
            // the first billing date for the same reason, and the price has to
            // be locked too because a recurring Bachs product IS its price:
            // changing it would silently orphan the product the live
            // subscription is billing against. The form renders these fields
            // disabled, so they never post back at all; unsetting them here is
            // what stops that absence being written as zeroes.
            if (!empty($subscription->bachs_subscription_id)) {
                unset(
                    $update['clientid'],
                    $update['date'],
                    $update['amount'],
                    $update['quantity'],
                    $update['currency'],
                    $update['billing_interval'],
                    $update['billing_frequency'],
                    $update['trial_interval'],
                    $update['trial_frequency']
                );
            }

            if ($this->bachs_subscriptions_model->update($id, $update)) {
                set_alert('success', _l('updated_successfully', _l('bachs_subscription')));
            }

            redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $id));
        }

        // A record created while the gateway was in sandbox mode is
        // meaningless against live keys (and vice versa) -- Bachs sandbox and
        // production share nothing: not products, not customers, not
        // subscriptions. Warn rather than silently show stale data.
        $data['environment_mismatch'] = !empty($subscription->bachs_subscription_id)
            && ((int) $subscription->in_test_environment === 1) !== $this->bachs_subscriptions_gateway->is_test_mode();

        $data['remote_error'] = null;

        if (!empty($subscription->bachs_subscription_id) && !$data['environment_mismatch']) {
            try {
                $data['remote'] = $this->bachs_subscriptions_gateway->client()
                    ->getSubscription($subscription->bachs_subscription_id);
            } catch (\Throwable $e) {
                $data['remote_error'] = $this->bachs_subscriptions_gateway->api_scope_hint($e->getMessage());
            }
        }

        $data['subscription'] = $subscription;
        $data['title']        = $subscription->name;
        $data['gateway']      = $this->bachs_subscriptions_gateway;
        $data['customer_id']  = $subscription->clientid;
        $data['bodyclass']    = 'bachs-subscription';

        $this->load->view('subscriptions_form', $data);
    }

    public function cancel($id)
    {
        if (staff_cant('edit', 'subscriptions')) {
            access_denied('Cancel Bachs Subscription');
        }

        $subscription = $this->bachs_subscriptions_model->get_by_id($id);

        if (!$subscription) {
            show_404();
        }

        try {
            $type = $this->input->get('type');

            if ($type !== 'immediately' && $type !== 'at_period_end') {
                throw new Exception('Invalid cancelation type', 1);
            }

            $this->bachs_subscriptions_gateway->cancel($subscription, $type === 'at_period_end');
            set_alert('success', _l('bachs_subscription_canceled'));
        } catch (\Throwable $e) {
            log_activity('Bachs subscription cancel failed [ID: ' . $id . ']: ' . $e->getMessage());
            set_alert('danger', $this->bachs_subscriptions_gateway->api_scope_hint($e->getMessage()));
        }

        redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $id));
    }

    public function resume($id)
    {
        if (staff_cant('edit', 'subscriptions')) {
            access_denied('Resume Bachs Subscription');
        }

        $subscription = $this->bachs_subscriptions_model->get_by_id($id);

        if (!$subscription) {
            show_404();
        }

        try {
            $this->bachs_subscriptions_gateway->resume($subscription);
            set_alert('success', _l('bachs_subscription_resumed'));
        } catch (\Throwable $e) {
            log_activity('Bachs subscription resume failed [ID: ' . $id . ']: ' . $e->getMessage());
            set_alert('danger', $this->bachs_subscriptions_gateway->api_scope_hint($e->getMessage()));
        }

        redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $id));
    }

    /**
     * Pull-based reconciliation for one record -- the fallback for a webhook
     * that was never delivered or that dead-lettered.
     */
    public function sync($id)
    {
        if (staff_cant('edit', 'subscriptions')) {
            access_denied('Sync Bachs Subscription');
        }

        $subscription = $this->bachs_subscriptions_model->get_by_id($id);

        if (!$subscription) {
            show_404();
        }

        try {
            $this->bachs_subscriptions_gateway->sync($subscription);
            set_alert('success', _l('bachs_subscription_synced'));
        } catch (\Throwable $e) {
            log_activity('Bachs subscription sync failed [ID: ' . $id . ']: ' . $e->getMessage());
            set_alert('danger', $this->bachs_subscriptions_gateway->api_scope_hint($e->getMessage()));
        }

        redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $id));
    }

    /**
     * Opens Bachs's own hosted customer portal for the linked customer, where
     * they can update the card on file. The URL is short-lived and
     * pre-authenticated, so it is generated per click and never stored.
     */
    public function portal($id)
    {
        if (staff_cant('edit', 'subscriptions')) {
            access_denied('Bachs Customer Portal');
        }

        $subscription = $this->bachs_subscriptions_model->get_by_id($id);

        if (!$subscription) {
            show_404();
        }

        try {
            redirect($this->bachs_subscriptions_gateway->portal_url($subscription));
        } catch (\Throwable $e) {
            set_alert('danger', $this->bachs_subscriptions_gateway->api_scope_hint($e->getMessage()));
            redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $id));
        }
    }

    public function send_to_email($id)
    {
        if (staff_cant('view', 'subscriptions')) {
            access_denied('Bachs Subscription Send To Email');
        }

        $subscription = $this->bachs_subscriptions_model->get_by_id($id);

        if (!$subscription) {
            show_404();
        }

        // Deliberately WhatsApp rather than a forked Perfex email template:
        // the native subscription_send_to_customer template hard-codes
        // Stripe-shaped merge fields, and the Baileys bridge is already the
        // live notification channel for this install.
        if (function_exists('baileys_send_to_client')) {
            $name    = function_exists('baileys_client_first_name')
                ? baileys_client_first_name($subscription->clientid)
                : 'there';
            $signoff = function_exists('baileys_signoff') ? baileys_signoff() : '';

            $msg = "Hello " . $name . ",\n\n"
                 . "Here is the subscription we've set up for you on your Airix Media account.\n\n"
                 . "Subscription: " . $subscription->name . "\n"
                 . "Billing: " . $this->bachs_subscriptions_gateway->amount_label($subscription) . "\n\n"
                 . "You can review and start it here:\n"
                 . site_url('bachs/bachs_subscription/index/' . $subscription->hash)
                 . $signoff;

            baileys_send_to_client($subscription->clientid, $msg, 'subscription_sent', [
                'related_type' => 'bachs_subscription',
                'related_id'   => (int) $subscription->id,
            ]);

            $this->bachs_subscriptions_model->update($id, ['last_sent_at' => date('Y-m-d H:i:s')]);
            set_alert('success', _l('bachs_subscription_sent_success'));
        } else {
            set_alert('warning', _l('bachs_subscription_sent_unavailable'));
        }

        redirect(admin_url('bachs/bachs_subscriptions_admin/edit/' . $id));
    }

    public function delete($id)
    {
        if (staff_cant('delete', 'subscriptions')) {
            access_denied('Bachs Subscriptions Delete');
        }

        if ($this->bachs_subscriptions_model->delete($id)) {
            set_alert('success', _l('deleted', _l('bachs_subscription')));
        } else {
            set_alert('warning', _l('bachs_subscription_delete_blocked'));
        }

        redirect(admin_url('bachs/bachs_subscriptions_admin'));
    }

    /**
     * Currency is never read from the form. Bachs subscriptions are USD-only
     * today, and the API does NOT reject a non-USD recurring product
     * (confirmed: an NGN one was created successfully in sandbox), so nothing
     * downstream would catch the mistake -- the lock has to be here.
     */
    private function form_payload(): array
    {
        $interval = $this->input->post('billing_interval');
        if (!in_array($interval, Bachs_subscriptions_gateway::INTERVALS, true)) {
            $interval = 'month';
        }

        $trialInterval = $this->input->post('trial_interval');
        if (!in_array($trialInterval, Bachs_subscriptions_gateway::INTERVALS, true)) {
            $trialInterval = null;
        }

        $trialFrequency = (int) $this->input->post('trial_frequency');

        return [
            'name'                => $this->input->post('name'),
            'description'         => nl2br($this->input->post('description')),
            'description_in_item' => $this->input->post('description_in_item') ? 1 : 0,
            'date'                => $this->input->post('date') ? to_sql_date($this->input->post('date')) : null,
            'clientid'            => (int) $this->input->post('clientid'),
            'project_id'          => (int) $this->input->post('project_id'),
            'amount'              => (float) $this->input->post('amount'),
            'currency'            => Bachs_subscriptions_gateway::SUPPORTED_CURRENCY,
            'quantity'            => max(1, (int) $this->input->post('quantity')),
            'billing_interval'    => $interval,
            'billing_frequency'   => max(1, (int) $this->input->post('billing_frequency')),
            'trial_interval'      => $trialFrequency > 0 ? $trialInterval : null,
            'trial_frequency'     => $trialFrequency > 0 && $trialInterval ? $trialFrequency : null,
            'terms'               => nl2br($this->input->post('terms')),
        ];
    }
}
