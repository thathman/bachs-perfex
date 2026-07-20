<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Reconciliation-tracking foundation for Bachs: records every real charge
 * this install has ever seen, independent of tblinvoicepaymentrecords (which
 * is Perfex's own dedupe table for the actual money movement). Full
 * reconciliation scope (fees/settlements, refunds/chargebacks, a daily
 * comparison job against Bachs's own records) is not built yet -- this
 * table is the prerequisite data source for that, not the report itself.
 */
class Bachs_transactions_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function exists($bachs_charge_id)
    {
        return (bool) $this->db->where('bachs_charge_id', $bachs_charge_id)
            ->get(db_prefix() . 'bachs_transactions')
            ->row();
    }

    public function record($bachs_charge_id, $invoice_id, $amount_minor, $currency, $status)
    {
        $this->db->insert(db_prefix() . 'bachs_transactions', [
            'bachs_charge_id' => $bachs_charge_id,
            'invoice_id'      => $invoice_id,
            'amount_minor'    => $amount_minor,
            'currency'        => $currency,
            'status'          => $status,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->db->insert_id();
    }

    public function get_all()
    {
        return $this->db->order_by('created_at', 'DESC')
            ->limit(200)
            ->get(db_prefix() . 'bachs_transactions')
            ->result();
    }
}
