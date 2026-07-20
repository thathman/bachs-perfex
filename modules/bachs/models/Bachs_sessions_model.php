<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_sessions_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get_open_for_invoice($invoice_id)
    {
        return $this->db->where('invoice_id', $invoice_id)
            ->where_in('status', ['created', 'open'])
            ->order_by('created_at', 'DESC')
            ->get(db_prefix() . 'bachs_checkout_sessions')
            ->row();
    }

    public function create($invoice_id, $bachs_checkout_id, $checkout_url, $status)
    {
        $this->db->insert(db_prefix() . 'bachs_checkout_sessions', [
            'invoice_id'         => $invoice_id,
            'bachs_checkout_id'  => $bachs_checkout_id,
            'checkout_url'       => $checkout_url,
            'status'             => strtolower($status),
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        return $this->db->insert_id();
    }

    public function update_status($bachs_checkout_id, $status)
    {
        $this->db->where('bachs_checkout_id', $bachs_checkout_id)->update(db_prefix() . 'bachs_checkout_sessions', [
            'status'     => strtolower($status),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
