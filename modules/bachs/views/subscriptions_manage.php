<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="_buttons">
                            <?php if (staff_can('create', 'subscriptions')) { ?>
                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/create'); ?>"
                               class="btn btn-primary pull-left display-block">
                                <?php echo _l('add_new', _l('bachs_subscription')); ?>
                            </a>
                            <?php } ?>
                            <div class="clearfix"></div>
                        </div>
                        <hr class="hr-panel-heading" />
                        <h4 class="no-margin"><?php echo _l('bachs_subscriptions'); ?></h4>
                        <p class="text-muted"><?php echo _l('bachs_subscriptions_description'); ?></p>
                        <?php if ($gateway->is_test_mode()) { ?>
                        <div class="alert alert-warning">
                            <?php echo _l('bachs_subscriptions_test_mode_notice'); ?>
                        </div>
                        <?php } ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th><?php echo _l('subscription_name'); ?></th>
                                        <th><?php echo _l('client'); ?></th>
                                        <th><?php echo _l('bachs_subscription_billing'); ?></th>
                                        <th><?php echo _l('bachs_status'); ?></th>
                                        <th><?php echo _l('bachs_subscription_next_billing'); ?></th>
                                        <th><?php echo _l('bachs_created'); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($subscriptions)) { ?>
                                    <tr>
                                        <td colspan="8"><?php echo _l('bachs_subscriptions_none_yet'); ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php foreach ($subscriptions as $subscription) { ?>
                                    <tr>
                                        <td><?php echo (int) $subscription->id; ?></td>
                                        <td>
                                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/edit/' . $subscription->id); ?>">
                                                <?php echo html_escape($subscription->name); ?>
                                            </a>
                                            <?php if ((int) $subscription->in_test_environment === 1) { ?>
                                            <span class="label label-default"><?php echo _l('bachs_sandbox'); ?></span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo admin_url('clients/client/' . $subscription->clientid); ?>">
                                                <?php echo html_escape($subscription->company); ?>
                                            </a>
                                        </td>
                                        <td><?php echo html_escape($gateway->amount_label($subscription)); ?></td>
                                        <td>
                                            <?php echo bachs_subscription_status_label($subscription); ?>
                                        </td>
                                        <td>
                                            <?php echo $subscription->next_billed_at ? _dt($subscription->next_billed_at) : '-'; ?>
                                        </td>
                                        <td><?php echo _dt($subscription->created); ?></td>
                                        <td class="text-right">
                                            <a href="<?php echo site_url('bachs/bachs_subscription/index/' . $subscription->hash); ?>"
                                               target="_blank" class="btn btn-default btn-xs"
                                               data-toggle="tooltip" title="<?php echo _l('bachs_subscription_client_view'); ?>">
                                                <i class="fa fa-external-link"></i>
                                            </a>
                                        </td>
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
</div>
<?php init_tail(); ?>
