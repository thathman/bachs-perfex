<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Integration_runtime extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('integration_runtime/integration_events_model');
    }

    public function index()
    {
        if (staff_cant('view', 'integration_runtime')) {
            access_denied('integration_runtime');
        }

        $data['title'] = _l('integration_events');
        $data['failed'] = $this->integration_events_model->get_failed();
        $data['dead_letters'] = $this->integration_events_model->get_dead_letters();

        $this->load->view('manage', $data);
    }

    public function replay($id)
    {
        if (staff_cant('view', 'integration_runtime')) {
            access_denied('integration_runtime');
        }

        $ok = $this->integration_events_model->replay((int) $id);
        if ($ok) {
            set_alert('success', _l('integration_event_replayed'));
        } else {
            set_alert('warning', _l('integration_event_not_found'));
        }

        redirect(admin_url('integration_runtime'));
    }
}
