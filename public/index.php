<?php

/**
 *
 *  package   OpenEMR
 *  link      http://www.open-emr.org
 *  author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c)
 *  All rights reserved
 *
 */

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

require_once dirname(__FILE__, 5) . "/globals.php";
require_once dirname(__FILE__, 2) . '/vendor/autoload.php';

use Juggernaut\Quest\Module\BackgroundServices;

$backgroundServices = new BackgroundServices();
$status = $backgroundServices->getStatus();

if (isset($_POST['status'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["token"])) {
        CsrfUtils::csrfNotVerified();
    }
    $backgroundServices->status = (int)$_POST['status'];
    $backgroundServices->changeStatus();
    $status = $backgroundServices->getStatus();
}

$msg = 'Click the button to toggle automatically downloading HL7 results';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <?php Header::setupHeader() ?>
    <title><?php echo xlt('Quest Lab Quantum Hub'); ?></title>
</head>
<body>
<div class="container m-5">
    <div class="row">
        <div class="mx-auto" style="width: 80%">
            <h1><?php echo xlt('Quest Quantum Lab Hub'); ?></h1>
        </div>
    </div>
    <div class="row">
        <div class="mx-auto" style="width: 80%">
            <p>&nbsp;</p>
            <p><?php echo xlt('Thank you for enabling this module'); ?>.</p>
            <p><?php echo xlt('If you have not contacted Quest, please take this time to contact them to
            begin the turn up process.'); ?></p>
            <p><?php echo xlt('Your OpenEMR server will need to be connected to a
            FQDN (Fully Qualified Domain Name) in order to use this module.'); ?></p>
            <p><strong><?php echo xlt('You will also need a SSL certificate that is issued by a recognized authority. You cannot
            use a self-signed certificate'); ?></strong></p>
            <p><?php echo xlt("Please watch this video on how to configure this module on your OpenEMR server"); ?></p>
            <p><a href="https://youtu.be/SerrFH9EF4Q" target="_blank"><?php echo xlt('Click here'); ?></a></p>
        </div>
    </div>
    <div class="row">
        <div class="mx-auto" style="width: 80%">
            <form method="post" action="index.php">
                <?php if ($status['active'] == '1') { ?>
                    <input type="hidden" name="status" value="0">
                    <input type="hidden" name="token" value="<?php echo CsrfUtils::collectCsrfToken(); ?>">
                    <button type="submit" class="btn btn-success"><?php echo xlt('Enabled'); ?></button>
                    <span class="ml-3"><?php echo xlt($msg); ?></span>
                <?php } else { ?>
                    <input type="hidden" name="status" value="1">
                    <input type="hidden" name="token" value="<?php echo CsrfUtils::collectCsrfToken(); ?>">
                    <button type="submit" class="btn btn-danger"><?php echo xlt('Disable'); ?></button>
                    <span class="ml-3"><?php echo xlt($msg); ?></span>
                <?php } ?>
            </form>
        </div>
    </div>
    <div class="row">
        <div class="mx-auto" style="width: 80%">
            <h4 class="mt-3"><?php echo xlt('About Operating Mode'); ?></h4>
            <p><?php echo xlt("By default, the system is in testing mode. All orders will be sent the certification hub."); ?></p>
            <p><?php echo xlt("Once certification is completed, go to Admin, Config, Quest Lab Hub and set system to production"); ?> </p>
        </div>
    </div>
    <div class="row">
        <div class="mx-auto" style="width: 80%">
            <h4 class="mt-3"><?php echo xlt('About label printing'); ?></h4>
            <p><?php echo xlt("In the config, go to PDF settings. The default setting is for Avery labels 5160"); ?>.</p>
            <p><?php echo xlt("After the lab order is created. On the forms encounter screen, there will be a Specimen Label button"); ?></p>
            <p><?php echo xlt("If you would like to use a dynamo printer. Select to edit the order and there will be a label button in there that will print a bar coded label. Either label is acceptable"); ?></p>
            <p><?php echo xlt("The system will print three labels at a time.") ?></p>
        </div>
    </div>
</div>
</body>
</html>


