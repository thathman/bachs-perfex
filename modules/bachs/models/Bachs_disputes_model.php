<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Bachs_disputes_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get_by_bachs_id($bachs_dispute_id)
    {
        return $this->db->where('bachs_dispute_id', $bachs_dispute_id)
            ->get(db_prefix() . 'bachs_disputes')
            ->row();
    }

    public function get_all()
    {
        return $this->db->order_by('created_at', 'DESC')
            ->limit(200)
            ->get(db_prefix() . 'bachs_disputes')
            ->result();
    }

    public function upsert($bachs_dispute_id, $data)
    {
        $existing = $this->get_by_bachs_id($bachs_dispute_id);

        if ($existing) {
            $this->db->where('bachs_dispute_id', $bachs_dispute_id)->update(db_prefix() . 'bachs_disputes', array_merge($data, [
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            return (int) $existing->id;
        }

        $this->db->insert(db_prefix() . 'bachs_disputes', array_merge($data, [
            'bachs_dispute_id' => $bachs_dispute_id,
            'created_at'       => date('Y-m-d H:i:s'),
        ]));

        return $this->db->insert_id();
    }
}
