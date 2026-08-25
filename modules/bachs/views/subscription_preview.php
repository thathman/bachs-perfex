<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-default tw-mt-8">
                <div class="panel-body">
                    <h2 class="no-margin"><?php echo html_escape($subscription->name); ?></h2>
                    <p class="text-muted"><?php echo html_escape($gateway->amount_label($subscription)); ?></p>
                    <?php if ($gateway->trial_label($subscription)) { ?>
                    <p><span class="label label-success"><?php echo html_escape($gateway->trial_label($subscription)); ?></span></p>
                    <?php } ?>
                    <hr />

                    <?php if (!empty($subscription->description)) { ?>
                    <div class="tw-mb-6"><?php echo $subscription->description; ?></div>
                    <?php } ?>

                    <table class="table table-bordered">
                        <thead>
                            <tr>
                                <th><?php echo _l('bachs_th_item'); ?></th>
                                <th class="text-center"><?php echo _l('bachs_th_quantity'); ?></th>
                                <th class="text-right"><?php echo _l('bachs_th_amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <?php echo html_escape($subscription->name); ?>
                                    <br>
                                    <small class="text-muted"><?php echo html_escape($gateway->interval_label($subscription)); ?></small>
                                </td>
                                <td class="text-center"><?php echo (int) $subscription->quantity; ?></td>
                                <td class="text-right">
                                    <?php echo app_format_money((float) $subscription->amount * (int) $subscription->quantity, strtoupper($subscription->currency)); ?>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="2" class="text-right"><?php echo _l('bachs_subscription_recurring_total'); ?></th>
                                <th class="text-right">
                                    <?php echo app_format_money((float) $subscription->amount * (int) $subscription->quantity, strtoupper($subscription->currency)); ?>
                                </th>
                            </tr>
                        </tfoot>
                    </table>

                    <p class="text-muted">
                        <small><?php echo _l('bachs_subscription_usd_only_client_notice'); ?></small>
                    </p>

                    <?php if (!empty($subscription->date)) { ?>
                    <p><strong><?php echo _l('first_billing_date'); ?>:</strong> <?php echo _d($subscription->date); ?></p>
                    <?php } ?>

                    <?php if (!empty($subscription->bachs_subscription_id)) { ?>
                    <div class="alert alert-info">
                        <strong><?php echo _l('bachs_status'); ?>:</strong>
                        <?php echo bachs_subscription_status_label($subscription); ?>
                        <?php if ($subscription->next_billed_at) { ?>
                        <br><?php echo _l('bachs_subscription_next_billing'); ?>: <?php echo _dt($subscription->next_billed_at); ?>
                        <?php } ?>
                        <?php if ((int) $subscription->cancel_at_period_end === 1 && $subscription->current_period_end) { ?>
                        <br><?php echo _l('bachs_subscription_ends_on'); ?>: <?php echo _d($subscription->current_period_end); ?>
                        <?php } ?>
                    </div>
                    <?php } ?>

                    <?php if (!empty($environment_mismatch)) { ?>
                    <div class="alert alert-warning">
                        <?php echo _l('bachs_subscription_unavailable_right_now'); ?>
                    </div>
                    <?php } ?>

                    <?php if (!empty($subscription->terms)) { ?>
                    <hr />
                    <h5><?php echo _l('terms_and_conditions'); ?></h5>
                    <div class="text-muted"><?php echo $subscription->terms; ?></div>
                    <?php } ?>

                    <?php if (!empty($can_subscribe)) { ?>
                    <hr />
                    <a href="<?php echo site_url('bachs/bachs_subscription/subscribe/' . $hash); ?>"
                       class="btn btn-primary btn-lg btn-block">
                        <?php echo _l('bachs_subscription_subscribe_button'); ?>
                    </a>
                    <p class="text-center text-muted tw-mt-2">
                        <small><?php echo _l('bachs_subscription_secure_checkout_notice'); ?></small>
                    </p>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>
