<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4><?php echo _l('bachs_transactions'); ?></h4>
                        <p class="text-muted"><?php echo _l('bachs_transactions_description'); ?></p>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?php echo _l('bachs_charge_id'); ?></th>
                                    <th><?php echo _l('bachs_invoice'); ?></th>
                                    <th><?php echo _l('bachs_amount'); ?></th>
                                    <th><?php echo _l('bachs_currency'); ?></th>
                                    <th><?php echo _l('bachs_status'); ?></th>
                                    <th><?php echo _l('bachs_created'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)) { ?>
                                <tr><td colspan="6"><?php echo _l('bachs_none_yet'); ?></td></tr>
                                <?php } ?>
                                <?php foreach ($transactions as $transaction) { ?>
                                <tr>
                                    <td><?php echo html_escape($transaction->bachs_charge_id); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('invoices/list_invoices/' . $transaction->invoice_id); ?>">
                                            #<?php echo (int) $transaction->invoice_id; ?>
                                        </a>
                                    </td>
                                    <td><?php echo number_format($transaction->amount_minor / 100, 2); ?></td>
                                    <td><?php echo html_escape($transaction->currency); ?></td>
                                    <td><?php echo html_escape($transaction->status); ?></td>
                                    <td><?php echo _dt($transaction->created_at); ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
