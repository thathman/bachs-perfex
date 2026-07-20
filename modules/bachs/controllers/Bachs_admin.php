<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_admin extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('bachs/bachs_transactions_model');
    }

    public function index()
    {
        if (staff_cant('view', 'invoices')) {
            access_denied('bachs_admin');
        }

        $data['title']        = _l('bachs_transactions');
        $data['transactions'] = $this->bachs_transactions_model->get_all();

        $this->load->view('manage', $data);
    }
}
