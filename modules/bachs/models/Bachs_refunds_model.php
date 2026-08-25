<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_refunds_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get_by_bachs_id($bachs_refund_id)
    {
        return $this->db->where('bachs_refund_id', $bachs_refund_id)
            ->get(db_prefix() . 'bachs_refunds')
            ->row();
    }

    public function get_for_charge($bachs_charge_id)
    {
        return $this->db->where('bachs_charge_id', $bachs_charge_id)
            ->order_by('created_at', 'DESC')
            ->get(db_prefix() . 'bachs_refunds')
            ->result();
    }

    public function get_all()
    {
        return $this->db->order_by('created_at', 'DESC')
            ->limit(200)
            ->get(db_prefix() . 'bachs_refunds')
            ->result();
    }

    // amount_minor/currency/status/bachs_charge_id are NOT NULL with no
    // column default -- a webhook missing one of these (malformed, or a
    // future event shape) would otherwise fail the INSERT outright instead
    // of recording the refund attempt at all.
    public function create($data)
    {
        $data = array_merge([
            'bachs_charge_id' => '',
            'amount_minor'    => 0,
            'currency'        => '',
            'status'          => 'pending',
        ], $data, [
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->db->insert(db_prefix() . 'bachs_refunds', $data);

        return $this->db->insert_id();
    }

    public function update_status($bachs_refund_id, $status)
    {
        $this->db->where('bachs_refund_id', $bachs_refund_id)->update(db_prefix() . 'bachs_refunds', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function upsert($bachs_refund_id, $data)
    {
        $existing = $this->get_by_bachs_id($bachs_refund_id);

        if ($existing) {
            $this->db->where('bachs_refund_id', $bachs_refund_id)->update(db_prefix() . 'bachs_refunds', array_merge($data, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            return (int) $existing->id;
        }

        return $this->create(array_merge(['bachs_refund_id' => $bachs_refund_id], $data));
    }
}
