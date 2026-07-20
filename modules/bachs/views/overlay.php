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
</body>
</html>
