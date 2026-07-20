<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bachs/bachs_transactions_model');
        $this->load->model('bachs/bachs_events_model');
    }

    public function index()
    {
        if (staff_cant('view', 'invoices')) {
            access_denied('bachs_admin');
        }

        $data['title']        = _l('bachs_transactions');
        $data['transactions'] = $this->bachs_transactions_model->get_all();
        $data['failed']       = $this->bachs_events_model->get_failed();
        $data['dead_letters'] = $this->bachs_events_model->get_dead_letters();

        $this->load->view('manage', $data);
    }

    public function replay($id)
    {
        if (staff_cant('view', 'invoices')) {
            access_denied('bachs_admin');
        }

        $ok = $this->bachs_events_model->replay((int) $id);
        if ($ok) {
            set_alert('success', _l('bachs_event_replayed'));
        } else {
            set_alert('warning', _l('bachs_event_not_found'));
        }

        redirect(admin_url('bachs/bachs_admin'));
    }
}
