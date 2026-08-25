<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Overlay checkout's success_url lands here instead of directly on the
 * invoice page. Bachs's own hosted checkout page redirects to success_url
 * *inside the iframe* after its own completion countdown, regardless of
 * embed context -- pointing that straight at the real invoice page meant
 * the invoice reloaded embedded inside the modal, often before the webhook
 * had processed, looking exactly like the payment had never happened.
 * This page instead shows a brief confirmation, then breaks out of the
 * iframe (window.top.location, which browsers allow cross-origin
 * specifically for this) after a short delay -- giving the webhook a
 * realistic chance to land before the real invoice page reloads at the
 * top level. Hash-gated, no login required, matching the same guest-access
 * pattern already used by Invoice/Proposal/Estimate.
 */
class Bachs_return extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('invoices_model');
    }

    public function complete($invoiceId, $hash)
    {
        $invoice = $this->invoices_model->get((int) $invoiceId);

        if (!$invoice || !hash_equals((string) $invoice->hash, (string) $hash)) {
            show_404();
        }

        $this->load->view('bachs/return_complete', [
            'invoice_url' => site_url('invoice/' . $invoice->id . '/' . $invoice->hash),
        ]);
    }
}
