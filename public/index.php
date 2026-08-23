<?php

/**
 * package OpenEMR
 * link http://www.open-emr.org
 * author Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;

require_once dirname(__FILE__, 5) . "/globals.php";
require_once dirname(__FILE__, 2) . '/vendor/autoload.php';

use Juggernaut\Quest\Module\BackgroundServices;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (empty($session->get('csrf_private_key', null))) {
    CsrfUtils::setupCsrfKey($session);
}
$csrfToken = CsrfUtils::collectCsrfToken($session);

$backgroundServices = new BackgroundServices();

if (isset($_POST['status'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["token"] ?? '', $session)) {
        CsrfUtils::csrfNotVerified();
    }
    $backgroundServices->status = ((int) ($_POST['status'] ?? 0) === 1) ? 1 : 0;
    $backgroundServices->changeStatus();
}

$serviceActive = $backgroundServices->isActive();
$msg = 'Click the button to toggle automatically downloading HL7 results';
$activeTab = $_POST['active_tab'] ?? $_GET['tab'] ?? 'home';
$allowedTabs = ['home', 'status', 'compendium', 'settings'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'home';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('Quest Lab Quantum Hub'); ?></title>
</head>
<body>

<div class="container mx-auto mt-5">
    <!-- Bootstrap Tabs Setup -->
    <ul class="nav nav-tabs" id="questTab" role="tablist">
        <li class="nav-item">
            <a class="nav-link<?php echo $activeTab === 'home' ? ' active' : ''; ?>" id="home-tab" data-toggle="tab" href="#home" role="tab" aria-controls="home" aria-selected="<?php echo $activeTab === 'home' ? 'true' : 'false'; ?>"><?php echo xlt('Home'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?php echo $activeTab === 'status' ? ' active' : ''; ?>" id="status-tab" data-toggle="tab" href="#status" role="tab" aria-controls="status" aria-selected="<?php echo $activeTab === 'status' ? 'true' : 'false'; ?>"><?php echo xlt('Services'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?php echo $activeTab === 'compendium' ? ' active' : ''; ?>" id="compendium-tab" data-toggle="tab" href="#compendium" role="tab" aria-controls="compendium" aria-selected="<?php echo $activeTab === 'compendium' ? 'true' : 'false'; ?>"><?php echo xlt('Compendium'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?php echo $activeTab === 'settings' ? ' active' : ''; ?>" id="settings-tab" data-toggle="tab" href="#settings" role="tab" aria-controls="settings" aria-selected="<?php echo $activeTab === 'settings' ? 'true' : 'false'; ?>"><?php echo xlt('Settings'); ?></a>
        </li>
    </ul>

    <div class="tab-content" id="questTabContent">
        <!-- Home Tab -->
        <div class="tab-pane fade<?php echo $activeTab === 'home' ? ' show active' : ''; ?>" id="home" role="tabpanel" aria-labelledby="home-tab">
            <div class="row">
                <div class="mx-auto mt-2" style="width: 80%">
                    <p><?php echo xlt('Thank you for enabling this module'); ?>.</p>
                    <p>
                        <?php echo xlt('If you have not contacted Quest, please take this time to contact them to begin the implementation process.'); ?><br>
                        <?php echo xlt("After clicking the button, select for physicians") ?><br>
                        <?php echo xlt("Vendor name: ")?><b><?php echo text("Juggernaut Systems Express") ?></b> <br>
                        <br>
                        <a class="btn btn-primary mt-1" href="https://www.getmyinterface.com/" target="_blank"><?php echo xlt('Request Implementation'); ?></a>
                    </p>
                    <p><?php echo xlt('Your OpenEMR server will need to be connected to a FQDN (Fully Qualified Domain Name) in order to use this module.'); ?></p>
                    <p><strong><?php echo xlt('You will also need a SSL certificate that is issued by a recognized authority. You cannot use a self-signed certificate.'); ?></strong></p>

                    <h3><?php echo xlt('Training Video'); ?></h3>
                    <p><?php echo xlt("Please watch this video on how to configure this module on your OpenEMR server"); ?></p>
                    <p><a class="btn btn-primary" href="https://youtu.be/4vYWFb4f_64" target="_blank"><?php echo xlt('Training Video'); ?></a></p>
                </div>
            </div>
        </div>

        <!-- Status Tab -->
        <div class="tab-pane fade<?php echo $activeTab === 'status' ? ' show active' : ''; ?>" id="status" role="tabpanel" aria-labelledby="status-tab">
            <div class="row">
                <div class="mx-auto mt-5" style="width: 80%">
                    <form method="post" action="index.php">
                        <input type="hidden" name="token" value="<?php echo attr($csrfToken); ?>">
                        <input type="hidden" name="active_tab" value="status">
                        <?php if ($serviceActive) { ?>
                            <input type="hidden" name="status" value="0">
                            <button type="submit" class="btn btn-success"><?php echo xlt('Enabled'); ?></button>
                            <span class="ml-3 text-success"><?php echo xlt('Automatic HL7 result download is ON.'); ?></span>
                        <?php } else { ?>
                            <input type="hidden" name="status" value="1">
                            <button type="submit" class="btn btn-primary"><?php echo xlt('Enable'); ?></button>
                            <span class="ml-3 text-muted"><?php echo xlt('Automatic HL7 result download is OFF. Click Enable to start the background service.'); ?></span>
                        <?php } ?>
                        <p class="mt-3 mb-0 text-muted"><?php echo xlt($msg); ?></p>
                    </form>
                </div>
            </div>
        </div>
        <!-- Compendium Request Tab -->
        <div class="tab-pane fade mt-5<?php echo $activeTab === 'compendium' ? ' show active' : ''; ?>" id="compendium" role="tabpanel" aria-labelledby="compendium-tab">
            <div class="row">
                <div class="mx-auto" style="width: 80%">
                    <h3><?php echo xlt('Compendium Request'); ?></h3>
                    <p><?php echo xlt('Click the button below to import Quest standard order codes.'); ?></p>
                    <p><?php echo xlt('This will populate the system with lab order codes to select from when creating a lab order.'); ?></p>
                    <p><a class="btn btn-primary" href="requestCompendium.php"><?php echo xlt('Request Compendium'); ?></a></p>
                </div>
            </div>
        </div>

        <!-- Settings Tab -->
        <div class="tab-pane fade mt-5<?php echo $activeTab === 'settings' ? ' show active' : ''; ?>" id="settings" role="tabpanel" aria-labelledby="settings-tab">
            <div class="row">
                <div class="mx-auto" style="width: 80%">
                    <h3><?php echo xlt('Module Settings'); ?></h3>
                    <p><?php echo xlt('Configure additional settings for the Quest Quantum Lab Hub module in Admin Config.'); ?></p>
                    <!-- Add any specific settings or controls here -->
                    <a class="btn-primary btn" href="<?php echo $GLOBALS['webroot'] ?>/interface/super/edit_globals.php"><?php echo xlt('Config Settings'); ?></a>
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
    </div>
</div>
<?php
// Do not load jQuery/Bootstrap from CDN here. Header::setupHeader() already
// includes OpenEMR's Bootstrap 4 assets; a second copy breaks nav-tabs
// (Compendium/Services/Settings panes never show).
?>
<script>
// Ensure tab switches work even if another script interfered with data-api binding.
(function () {
    if (typeof window.jQuery === 'undefined') {
        return;
    }
    var $ = window.jQuery;
    $(function () {
        $('#questTab a[data-toggle="tab"]').on('click', function (e) {
            e.preventDefault();
            $(this).tab('show');
        });
    });
})();
</script>
</body>
</html>
