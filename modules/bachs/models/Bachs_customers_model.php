<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Maps a Perfex client to their Bachs customer id, one row per (client,
 * mode) pair since sandbox and live customers are entirely separate Bachs
 * records -- see Bachs_gateway's own mode-separation comments.
 */
class Bachs_customers_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get_for_client($client_id, $mode)
    {
        return $this->db->where('client_id', $client_id)
            ->where('mode', $mode)
            ->get(db_prefix() . 'bachs_customers')
            ->row();
    }

    public function get_by_bachs_id($bachs_customer_id, $mode)
    {
        return $this->db->where('bachs_customer_id', $bachs_customer_id)
            ->where('mode', $mode)
            ->get(db_prefix() . 'bachs_customers')
            ->row();
    }

    public function map($client_id, $bachs_customer_id, $mode)
    {
        $existing = $this->get_for_client($client_id, $mode);

        if ($existing) {
            if ($existing->bachs_customer_id !== $bachs_customer_id) {
                $this->db->where('id', $existing->id)->update(db_prefix() . 'bachs_customers', [
                    'bachs_customer_id' => $bachs_customer_id,
                ]);
            }
            return (int) $existing->id;
        }

        $this->db->insert(db_prefix() . 'bachs_customers', [
            'client_id'         => $client_id,
            'bachs_customer_id' => $bachs_customer_id,
            'mode'              => $mode,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return $this->db->insert_id();
    }
}
