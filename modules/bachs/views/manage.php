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
                                    <th><?php echo _l('bachs_refund_status'); ?></th>
                                    <th><?php echo _l('bachs_created'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)) { ?>
                                <tr><td colspan="8"><?php echo _l('bachs_none_yet'); ?></td></tr>
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
                                    <td><?php echo html_escape($transaction->refund_status ?? 'none'); ?></td>
                                    <td><?php echo _dt($transaction->created_at); ?></td>
                                    <td>
                                        <?php if (($transaction->refund_status ?? 'none') !== 'full' && staff_can('edit', 'invoices')) { ?>
                                        <button type="button" class="btn btn-default btn-icon" data-toggle="modal" data-target="#bachs-refund-modal" data-charge-id="<?php echo html_escape($transaction->bachs_charge_id); ?>">
                                            <i class="fa fa-reply"></i> <?php echo _l('bachs_refund'); ?>
                                        </button>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4><?php echo _l('bachs_refunds'); ?></h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?php echo _l('bachs_refund_id'); ?></th>
                                    <th><?php echo _l('bachs_charge_id'); ?></th>
                                    <th><?php echo _l('bachs_amount'); ?></th>
                                    <th><?php echo _l('bachs_status'); ?></th>
                                    <th><?php echo _l('bachs_reason'); ?></th>
                                    <th><?php echo _l('bachs_created'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($refunds)) { ?>
                                <tr><td colspan="6"><?php echo _l('bachs_none_yet'); ?></td></tr>
                                <?php } ?>
                                <?php foreach ($refunds as $refund) { ?>
                                <tr>
                                    <td><?php echo html_escape($refund->bachs_refund_id); ?></td>
                                    <td><?php echo html_escape($refund->bachs_charge_id); ?></td>
                                    <td><?php echo number_format($refund->amount_minor / 100, 2) . ' ' . html_escape($refund->currency); ?></td>
                                    <td><?php echo html_escape($refund->status); ?></td>
                                    <td><?php echo html_escape($refund->reason); ?></td>
                                    <td><?php echo _dt($refund->created_at); ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4><?php echo _l('bachs_disputes'); ?></h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?php echo _l('bachs_dispute_id'); ?></th>
                                    <th><?php echo _l('bachs_invoice'); ?></th>
                                    <th><?php echo _l('bachs_amount'); ?></th>
                                    <th><?php echo _l('bachs_status'); ?></th>
                                    <th><?php echo _l('bachs_reason'); ?></th>
                                    <th><?php echo _l('bachs_created'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($disputes)) { ?>
                                <tr><td colspan="6"><?php echo _l('bachs_none_yet'); ?></td></tr>
                                <?php } ?>
                                <?php foreach ($disputes as $dispute) { ?>
                                <tr>
                                    <td><?php echo html_escape($dispute->bachs_dispute_id); ?></td>
                                    <td>
                                        <?php if (!empty($dispute->invoice_id)) { ?>
                                        <a href="<?php echo admin_url('invoices/list_invoices/' . $dispute->invoice_id); ?>">#<?php echo (int) $dispute->invoice_id; ?></a>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo number_format($dispute->amount_minor / 100, 2) . ' ' . html_escape($dispute->currency); ?></td>
                                    <td><span class="label label-warning"><?php echo html_escape($dispute->status); ?></span></td>
                                    <td><?php echo html_escape($dispute->reason); ?></td>
                                    <td><?php echo _dt($dispute->created_at); ?></td>
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

<div class="modal fade" id="bachs-refund-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <?php echo form_open(admin_url('bachs/bachs_admin/refund'), ['class' => 'modal-content']); ?>
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><?php echo _l('bachs_refund'); ?></h4>
            </div>
            <div class="modal-body">
                <input type="hidden" name="charge_id" class="bachs-refund-charge-id" value="">
                <div class="form-group">
                    <label><?php echo _l('bachs_refund_amount_optional'); ?></label>
                    <input type="text" name="amount" class="form-control" placeholder="<?php echo _l('bachs_refund_full_if_blank'); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo _l('bachs_refund_reason'); ?></label>
                    <textarea name="reason" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _l('close'); ?></button>
                <button type="submit" class="btn btn-danger"><?php echo _l('bachs_refund_confirm'); ?></button>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<script>
$(function(){
    $('#bachs-refund-modal').on('show.bs.modal', function(e){
        var chargeId = $(e.relatedTarget).data('charge-id');
        $(this).find('.bachs-refund-charge-id').val(chargeId);
    });
});
</script>

<?php init_tail(); ?>
