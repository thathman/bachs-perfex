<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo html_escape(_l('bachs_return_title')); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; text-align: center; padding-top: 80px; color: #333; }
    </style>
</head>
<body>
    <p><?php echo html_escape(_l('bachs_return_message')); ?></p>
    <script>
    (function () {
        var invoiceUrl = <?php echo json_encode($invoice_url); ?>;

        // Cross-origin writes to window.top.location are allowed by
        // browsers specifically so a page can break itself out of an
        // iframe -- works whether this page is embedded (overlay) or
        // loaded at the top level directly.
        setTimeout(function () {
            window.top.location.href = invoiceUrl;
        }, 2500);
    })();
    </script>
</body>
</html>
