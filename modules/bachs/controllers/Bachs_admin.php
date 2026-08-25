<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bachs/bachs_transactions_model');
        $this->load->model('bachs/bachs_refunds_model');
        $this->load->model('bachs/bachs_disputes_model');
        $this->load->library('bachs_gateway');
    }

    public function index()
    {
        if (staff_cant('view', 'invoices')) {
            access_denied('bachs_admin');
        }

        $data['title']        = _l('bachs_transactions');
        $data['transactions'] = $this->bachs_transactions_model->get_all();
        $data['refunds']      = $this->bachs_refunds_model->get_all();
        $data['disputes']     = $this->bachs_disputes_model->get_all();

        $this->load->view('manage', $data);
    }

    /**
     * Full refund by default (Bachs treats a missing amount as a full
     * refund); a partial amount can be passed via the form. Guarded by the
     * same 'edit invoices' permission the rest of Perfex uses for anything
     * that touches money, not just 'view invoices' like index().
     */
    public function refund()
    {
        if (staff_cant('edit', 'invoices')) {
            access_denied('bachs_admin_refund');
        }

        if ($this->input->method() !== 'post') {
            show_404();
        }

        $chargeId = $this->input->post('charge_id');
        $amount   = trim((string) $this->input->post('amount'));
        $reason   = trim((string) $this->input->post('reason'));

        if (empty($chargeId)) {
            set_alert('danger', _l('bachs_refund_missing_charge'));
            redirect(admin_url('bachs/bachs_admin'));
            return;
        }

        try {
            $this->bachs_gateway->create_refund($chargeId, $amount !== '' ? $amount : null, $reason);
            set_alert('success', _l('bachs_refund_initiated'));
        } catch (\Throwable $e) {
            log_activity('Bachs refund request failed for charge ' . $chargeId . ': ' . $e->getMessage());
            set_alert('danger', _l('bachs_refund_failed'));
        }

        redirect(admin_url('bachs/bachs_admin'));
    }
}
