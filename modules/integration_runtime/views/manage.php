<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4><?php echo _l('integration_events'); ?> &mdash; <?php echo _l('dead_letters') ?? 'Dead letters'; ?></h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Provider</th>
                                    <th>Event type</th>
                                    <th>Attempts</th>
                                    <th>Last error</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dead_letters)) { ?>
                                <tr><td colspan="7"><?php echo _l('nothing_to_see_here_yet'); ?></td></tr>
                                <?php } ?>
                                <?php foreach ($dead_letters as $event) { ?>
                                <tr>
                                    <td><?php echo $event->id; ?></td>
                                    <td><?php echo html_escape($event->provider); ?></td>
                                    <td><?php echo html_escape($event->event_type); ?></td>
                                    <td><?php echo $event->attempt_count; ?></td>
                                    <td><?php echo html_escape($event->error_message); ?></td>
                                    <td><?php echo _dt($event->created_at); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('integration_runtime/replay/' . $event->id); ?>" class="btn btn-default btn-icon" onclick="return confirm('<?php echo _l('confirm_replay_event'); ?>');">
                                            <i class="fa-solid fa-rotate-right"></i> <?php echo _l('replay'); ?>
                                        </a>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <hr>

                        <h4><?php echo _l('failed'); ?> (<?php echo _l('awaiting_automatic_retry') ?? 'awaiting automatic retry'; ?>)</h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Provider</th>
                                    <th>Event type</th>
                                    <th>Attempts</th>
                                    <th>Next retry</th>
                                    <th>Last error</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($failed)) { ?>
                                <tr><td colspan="7"><?php echo _l('nothing_to_see_here_yet'); ?></td></tr>
                                <?php } ?>
                                <?php foreach ($failed as $event) { ?>
                                <tr>
                                    <td><?php echo $event->id; ?></td>
                                    <td><?php echo html_escape($event->provider); ?></td>
                                    <td><?php echo html_escape($event->event_type); ?></td>
                                    <td><?php echo $event->attempt_count; ?></td>
                                    <td><?php echo _dt($event->next_retry_at); ?></td>
                                    <td><?php echo html_escape($event->error_message); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('integration_runtime/replay/' . $event->id); ?>" class="btn btn-default btn-icon" onclick="return confirm('<?php echo _l('confirm_replay_event'); ?>');">
                                            <i class="fa-solid fa-rotate-right"></i> <?php echo _l('replay'); ?>
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

<?php init_tail(); ?>
