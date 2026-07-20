<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-6 col-md-offset-3 tw-text-center" style="padding-top: 60px;">
                <p><?php echo _l('bachs_overlay_loading'); ?></p>
                <noscript>
                    <a href="<?php echo html_escape($checkout_url); ?>" class="btn btn-primary">
                        <?php echo _l('bachs_overlay_continue_link'); ?>
                    </a>
                </noscript>
            </div>
        </div>
    </div>
</div>
<script src="https://checkout.bachs.io/bachs.js"></script>
<script>
(function () {
    var checkoutUrl = <?php echo json_encode($checkout_url); ?>;

    Bachs.Initialize({
        onEvent: function (event) {
            // The browser-side event is presentation feedback only -- per
            // the module's hard rule, actual payment confirmation happens
            // exclusively via the signature-verified server-side webhook,
            // never here.
            if (event && (event.type === 'checkout.closed' || event.type === 'checkout.cancelled')) {
                window.location.reload();
            }
        }
    });

    Bachs.Checkout.open({ checkoutUrl: checkoutUrl });
})();
</script>
<?php init_tail(); ?>
