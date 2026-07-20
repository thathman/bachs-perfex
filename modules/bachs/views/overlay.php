<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo _l('bachs_overlay_loading'); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center; padding-top: 80px; color: #333; }
        a.btn { display: inline-block; padding: 10px 20px; background: #2196F3; color: #fff; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <p><?php echo html_escape(_l('bachs_overlay_loading')); ?></p>
    <noscript>
        <a href="<?php echo html_escape($checkout_url); ?>" class="btn">
            <?php echo html_escape(_l('bachs_overlay_continue_link')); ?>
        </a>
    </noscript>
    <script src="https://checkout.bachs.io/bachs.js"></script>
    <script>
    (function () {
        var checkoutUrl = <?php echo json_encode($checkout_url); ?>;
        var checkoutOrigin = <?php echo json_encode($checkout_origin); ?>;

        // bachs.js's own Checkout.open() rejects any checkoutUrl whose origin
        // doesn't match the baseUrl passed here -- confirmed 2026-07-20 by
        // reading the real bachs.js source (buildCheckoutUrl()'s originOf()
        // check). Without this, a sandbox checkout_url (sandbox-checkout.
        // bachs.io) never opens while baseUrl defaults to the live checkout
        // origin, and the resulting "checkout.error" event was previously
        // never listened for, so the failure was completely silent -- the
        // page just sat on this loading screen forever.
        Bachs.Initialize({
            baseUrl: checkoutOrigin,
            onEvent: function (event) {
                if (!event) {
                    return;
                }

                // The browser-side event is presentation feedback only -- per
                // the module's hard rule, actual payment confirmation happens
                // exclusively via the signature-verified server-side webhook,
                // never here.
                if (event.type === 'checkout.closed' || event.type === 'checkout.cancelled') {
                    window.location.reload();
                    return;
                }

                if (event.type === 'checkout.error') {
                    document.body.innerHTML = '<p><?php echo html_escape(addslashes(_l("bachs_overlay_loading"))); ?></p>'
                        + '<p>' + (event.message || 'Unknown error') + '</p>'
                        + '<a class="btn" href="' + checkoutUrl + '"><?php echo html_escape(addslashes(_l("bachs_overlay_continue_link"))); ?></a>';
                }
            }
        });

        Bachs.Checkout.open({ checkoutUrl: checkoutUrl });
    })();
    </script>
</body>
</html>
