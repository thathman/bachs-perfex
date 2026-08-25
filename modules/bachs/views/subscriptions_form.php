<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-<?php echo isset($subscription) ? '7' : '12'; ?>">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                        <hr class="hr-panel-heading" />

                        <?php if ($gateway->is_test_mode()) { ?>
                        <div class="alert alert-warning">
                            <?php echo _l('bachs_subscriptions_test_mode_notice'); ?>
                        </div>
                        <?php } ?>

                        <?php if (!empty($environment_mismatch)) { ?>
                        <div class="alert alert-danger">
                            <?php echo _l('bachs_subscription_environment_mismatch'); ?>
                        </div>
                        <?php } ?>

                        <div class="alert alert-info">
                            <?php echo _l('bachs_subscription_usd_only_notice'); ?>
                        </div>

                        <?php echo form_open('', ['id' => 'bachsSubscriptionForm']); ?>

                        <?php $locked = isset($subscription) && !empty($subscription->bachs_subscription_id); ?>

                        <div class="tw-bg-neutral-50 tw-overflow-hidden tw-rounded-t-md tw-p-6 tw-border-b -tw-mt-6 -tw-mx-6 tw-border-solid tw-border-neutral-200 tw-mb-4">
                            <div class="row">
                                <div class="col-md-4">
                                    <?php echo render_input('amount', 'bachs_subscription_amount', isset($subscription) ? $subscription->amount : '', 'number', ['step' => '0.01', 'min' => '0.01', 'required' => true] + ($locked ? ['disabled' => true] : [])); ?>
                                </div>
                                <div class="col-md-2">
                                    <?php echo render_input('quantity', 'item_quantity_placeholder', isset($subscription) ? $subscription->quantity : 1, 'number', ['min' => '1'] + ($locked ? ['disabled' => true] : [])); ?>
                                </div>
                                <div class="col-md-2">
                                    <?php echo render_input('billing_frequency', 'bachs_subscription_every', isset($subscription) ? $subscription->billing_frequency : 1, 'number', ['min' => '1'] + ($locked ? ['disabled' => true] : [])); ?>
                                </div>
                                <div class="col-md-4">
                                    <?php
                                    $intervalOptions = [];
                                    foreach (Bachs_subscriptions_gateway::INTERVALS as $interval) {
                                        $intervalOptions[] = ['id' => $interval, 'name' => _l($interval)];
                                    }
                                    echo render_select(
                                        'billing_interval',
                                        $intervalOptions,
                                        ['id', 'name'],
                                        'bachs_subscription_interval',
                                        isset($subscription) ? $subscription->billing_interval : 'month',
                                        $locked ? ['disabled' => true] : []
                                    );
                                    ?>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3">
                                    <?php echo render_input('trial_frequency', 'bachs_subscription_trial_length', isset($subscription) ? $subscription->trial_frequency : '', 'number', ['min' => '0'] + ($locked ? ['disabled' => true] : [])); ?>
                                </div>
                                <div class="col-md-4">
                                    <?php
                                    $trialOptions = [['id' => '', 'name' => '-']];
                                    foreach (Bachs_subscriptions_gateway::INTERVALS as $interval) {
                                        $trialOptions[] = ['id' => $interval, 'name' => _l($interval)];
                                    }
                                    echo render_select(
                                        'trial_interval',
                                        $trialOptions,
                                        ['id', 'name'],
                                        'bachs_subscription_trial_interval',
                                        isset($subscription) ? $subscription->trial_interval : '',
                                        $locked ? ['disabled' => true] : []
                                    );
                                    ?>
                                </div>
                                <div class="col-md-5">
                                    <label class="control-label"><?php echo _l('currency'); ?></label>
                                    <input type="text" class="form-control" value="<?php echo Bachs_subscriptions_gateway::SUPPORTED_CURRENCY; ?>" readonly>
                                    <small class="text-muted"><?php echo _l('bachs_subscription_currency_locked'); ?></small>
                                </div>
                            </div>
                            <?php if ($locked) { ?>
                            <p class="text-muted tw-mt-4">
                                <i class="fa fa-lock"></i> <?php echo _l('bachs_subscription_pricing_locked'); ?>
                            </p>
                            <?php } ?>
                        </div>

                        <?php echo render_input('name', 'subscription_name', isset($subscription) ? $subscription->name : '', 'text', ['required' => true]); ?>
                        <?php echo render_textarea('description', 'subscriptions_description', isset($subscription) ? $subscription->description : ''); ?>

                        <div class="form-group">
                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" id="description_in_item" name="description_in_item"
                                    <?php echo (isset($subscription) && $subscription->description_in_item == '1') ? 'checked' : ''; ?>>
                                <label for="description_in_item"><?php echo _l('description_in_invoice_item'); ?></label>
                            </div>
                        </div>

                        <div class="form-group select-placeholder f_client_id">
                            <label for="clientid" class="control-label"><?php echo _l('client'); ?></label>
                            <select id="clientid" name="clientid" data-live-search="true" data-width="100%"
                                class="ajax-search" <?php echo $locked ? 'disabled' : ''; ?>
                                data-none-selected-text="<?php echo _l('dropdown_non_selected_tex'); ?>">
                                <?php
                                $selected = isset($subscription) ? $subscription->clientid : ($customer_id ?: '');
                                if ($selected != '') {
                                    $rel_data = get_relation_data('customer', $selected);
                                    $rel_val  = get_relation_values($rel_data, 'customer');
                                    echo '<option value="' . $rel_val['id'] . '" selected>' . e($rel_val['name']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group select-placeholder projects-wrapper<?php echo (!isset($subscription) || !customer_has_projects($subscription->clientid)) ? ' hide' : ''; ?>">
                            <label for="project_id"><?php echo _l('project'); ?></label>
                            <div id="project_ajax_search_wrapper">
                                <select name="project_id" id="project_id" class="projects ajax-search"
                                    data-live-search="true" data-width="100%"
                                    data-none-selected-text="<?php echo _l('dropdown_non_selected_tex'); ?>">
                                    <?php
                                    if (isset($subscription) && $subscription->project_id) {
                                        echo '<option value="' . (int) $subscription->project_id . '" selected>' . e(get_project_name_by_id($subscription->project_id)) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <?php
                        $dateParams = ['data-lazy' => 'false'];
                        if ($locked) {
                            $dateParams['disabled'] = true;
                        }
                        echo render_date_input('date', 'first_billing_date', isset($subscription) ? _d($subscription->date) : '', $dateParams);
                        ?>

                        <?php echo render_textarea('terms', 'terms_and_conditions', isset($subscription) ? $subscription->terms : '', ['placeholder' => _l('subscriptions_terms_info')]); ?>

                        <?php if (!isset($subscription) || staff_can('edit', 'subscriptions')) { ?>
                        <div class="text-right">
                            <button type="submit" class="btn btn-primary" data-loading-text="<?php echo _l('wait_text'); ?>"
                                data-form="#bachsSubscriptionForm"><?php echo _l('save'); ?></button>
                        </div>
                        <?php } ?>
                        <?php echo form_close(); ?>
                    </div>
                </div>
            </div>

            <?php if (isset($subscription)) { ?>
            <div class="col-md-5">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?php echo _l('bachs_subscription_status_panel'); ?></h4>
                        <hr class="hr-panel-heading" />

                        <p>
                            <strong><?php echo _l('bachs_status'); ?>:</strong>
                            <?php echo bachs_subscription_status_label($subscription); ?>
                            <?php if ((int) $subscription->cancel_at_period_end === 1 && $subscription->status !== 'canceled') { ?>
                            <span class="label label-warning"><?php echo _l('bachs_subscription_ending'); ?></span>
                            <?php } ?>
                        </p>

                        <table class="table table-condensed no-margin">
                            <tbody>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_billing'); ?></td>
                                    <td class="text-right"><?php echo html_escape($gateway->amount_label($subscription)); ?></td>
                                </tr>
                                <?php if ($gateway->trial_label($subscription)) { ?>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_trial'); ?></td>
                                    <td class="text-right"><?php echo html_escape($gateway->trial_label($subscription)); ?></td>
                                </tr>
                                <?php } ?>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_id_label'); ?></td>
                                    <td class="text-right"><?php echo $subscription->bachs_subscription_id ? html_escape($subscription->bachs_subscription_id) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_product_label'); ?></td>
                                    <td class="text-right"><?php echo $subscription->bachs_product_id ? html_escape($subscription->bachs_product_id) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_customer_label'); ?></td>
                                    <td class="text-right"><?php echo $subscription->bachs_customer_id ? html_escape($subscription->bachs_customer_id) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_current_period'); ?></td>
                                    <td class="text-right">
                                        <?php echo $subscription->current_period_start ? _d($subscription->current_period_start) : '-'; ?>
                                        &rarr;
                                        <?php echo $subscription->current_period_end ? _d($subscription->current_period_end) : '-'; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_next_billing'); ?></td>
                                    <td class="text-right"><?php echo $subscription->next_billed_at ? _dt($subscription->next_billed_at) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <td><?php echo _l('bachs_subscription_subscribed_on'); ?></td>
                                    <td class="text-right"><?php echo $subscription->date_subscribed ? _dt($subscription->date_subscribed) : '-'; ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <hr />

                        <div class="form-group">
                            <label class="control-label"><?php echo _l('bachs_subscription_client_link'); ?></label>
                            <input type="text" class="form-control" readonly
                                value="<?php echo site_url('bachs/bachs_subscription/index/' . $subscription->hash); ?>">
                        </div>

                        <div class="btn-group-vertical btn-block">
                            <?php if (staff_can('view', 'subscriptions')) { ?>
                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/send_to_email/' . $subscription->id); ?>"
                               class="btn btn-default btn-block">
                                <i class="fa fa-whatsapp"></i> <?php echo _l('bachs_subscription_send_to_client'); ?>
                            </a>
                            <?php } ?>

                            <?php if (!empty($subscription->bachs_subscription_id) && staff_can('edit', 'subscriptions')) { ?>
                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/sync/' . $subscription->id); ?>"
                               class="btn btn-default btn-block">
                                <i class="fa fa-refresh"></i> <?php echo _l('bachs_subscription_sync'); ?>
                            </a>

                            <?php if (!empty($subscription->bachs_customer_id)) { ?>
                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/portal/' . $subscription->id); ?>"
                               target="_blank" class="btn btn-default btn-block">
                                <i class="fa fa-credit-card"></i> <?php echo _l('bachs_subscription_portal'); ?>
                            </a>
                            <?php } ?>

                            <?php if ($subscription->status !== 'canceled') { ?>
                                <?php if ((int) $subscription->cancel_at_period_end === 1) { ?>
                                <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/resume/' . $subscription->id); ?>"
                                   class="btn btn-success btn-block">
                                    <i class="fa fa-play"></i> <?php echo _l('bachs_subscription_resume'); ?>
                                </a>
                                <?php } else { ?>
                                <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/cancel/' . $subscription->id . '?type=at_period_end'); ?>"
                                   class="btn btn-warning btn-block _delete"
                                   data-message="<?php echo _l('bachs_subscription_confirm_cancel_period_end'); ?>">
                                    <?php echo _l('bachs_subscription_cancel_at_period_end'); ?>
                                </a>
                                <?php } ?>
                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/cancel/' . $subscription->id . '?type=immediately'); ?>"
                               class="btn btn-danger btn-block _delete"
                               data-message="<?php echo _l('bachs_subscription_confirm_cancel_now'); ?>">
                                <?php echo _l('bachs_subscription_cancel_immediately'); ?>
                            </a>
                            <?php } ?>
                            <?php } ?>

                            <?php if (staff_can('delete', 'subscriptions')) { ?>
                            <a href="<?php echo admin_url('bachs/bachs_subscriptions_admin/delete/' . $subscription->id); ?>"
                               class="btn btn-danger btn-block _delete">
                                <i class="fa fa-remove"></i> <?php echo _l('delete'); ?>
                            </a>
                            <?php } ?>
                        </div>

                        <?php if (!empty($remote_error)) { ?>
                        <div class="alert alert-warning tw-mt-4">
                            <strong><?php echo _l('bachs_subscription_remote_unavailable'); ?></strong><br>
                            <?php echo html_escape($remote_error); ?>
                        </div>
                        <?php } ?>

                        <?php if (!empty($remote)) { ?>
                        <hr />
                        <h5><?php echo _l('bachs_subscription_remote_snapshot'); ?></h5>
                        <pre class="tw-text-xs" style="max-height:320px;overflow:auto;"><?php echo html_escape(json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<script>
    $(function() {
        // Identical wiring to the native subscription form -- the markup above
        // reuses the same .f_client_id / #project_ajax_search_wrapper /
        // .projects-wrapper hooks, so Perfex's own global handler already
        // repopulates the project list when the client changes.
        init_ajax_project_search_by_customer_id();

        appValidateForm('#bachsSubscriptionForm', {
            name: 'required',
            clientid: 'required',
            amount: {
                required: true,
                min: 0.01,
            },
            quantity: {
                required: true,
                min: 1,
            },
            billing_frequency: {
                required: true,
                min: 1,
            }
        });
    });
</script>
