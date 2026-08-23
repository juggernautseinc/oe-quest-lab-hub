<?php

/*
 * package   OpenEMR
 * link           https://open-emr.org
 * author      Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c) 2024.  Sherwin Gaddis <sherwingaddis@gmail.com>
 */

require_once dirname(__DIR__, 4) . '/globals.php';

use OpenEMR\Core\Header;
use Juggernaut\Quest\Module\LoadCompendium;
use Juggernaut\Quest\Module\Exceptions\QuestConfigException;

try {
    $requestCompendium = new LoadCompendium();
    $buAbbreviation    = $requestCompendium->getBuAbbreviation();
    $compendiumJson    = $requestCompendium->requestCompendiumFileList();
} catch (QuestConfigException $e) {
    $configError    = $e->getMessage();
    $buAbbreviation = '';
    $compendiumJson = '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo xlt('Compendium') ?></title>
    <?php Header::setupHeader(['common']); ?>
    <!-- Hide the quest-error div -->
    <style>
        #quest-error {
            display: none;
        }

        .loader {
            border: 16px solid #f3f3f3;
            border-radius: 50%;
            border-top: 16px solid #3498db;
            width: 120px;
            height: 120px;
            -webkit-animation: spin 2s linear infinite; /* Safari */
            animation: spin 2s linear infinite;
        }

        /* Safari */
        @-webkit-keyframes spin {
            0% { -webkit-transform: rotate(0deg); }
            100% { -webkit-transform: rotate(360deg); }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="row mt-5">
        <div class="col-md-12">
            <h1><?php echo xlt('Compendium') ?></h1>
            <p><?php echo xlt('This is a list of all the lab orders that can be requested.') ?></p>

            <?php
            $compendiumFileName = '';
            $resourceLocation   = '';
            $ackLocation        = '';

            if (!empty($configError)) {
                echo "<div class='alert alert-danger'><strong>" . xlt('Configuration Error') . ':</strong> ' . text($configError) . "</div>";
                return;
            }

            $list = json_decode($compendiumJson, true);

            if (!is_array($list)) {
                echo "<div class='alert alert-danger'>" . xlt('No response from Quest. Please try again.') . "</div>";
                return;
            }

            if (isset($list['exception'])) {
                echo "<div class='alert alert-danger'><strong>" . xlt('Quest Error') . ':</strong> ' . text($list['exception']) . "</div>";
                return;
            }

            // Quest returns: { transactionId, fullFileLinks: [{fileName, retrieveURI, ackURI}], ... }
            $fullFileLinks = $list['fullFileLinks'] ?? [];
            if (empty($fullFileLinks) || !is_array($fullFileLinks)) {
                echo "<div class='alert alert-warning'>" . xlt('Quest returned no file links. Please try again later.') . "</div>";
                return;
            }

            // Build pattern dynamically from the provider BU abbreviation (recv_fac_id)
            $pattern = '/' . preg_quote($buAbbreviation, '/') . '_CDC_FULL/';
            foreach ($fullFileLinks as $fileLink) {
                if (empty($fileLink) || !is_array($fileLink)) {
                    continue;
                }
                if (isset($fileLink['fileName']) && preg_match($pattern, $fileLink['fileName'])) {
                    $compendiumFileName = $fileLink['fileName'];
                    $resourceLocation   = $fileLink['retrieveURI'] ?? '';
                    $ackLocation        = $fileLink['ackURI']      ?? '';
                    break;
                }
            }

            if (empty($compendiumFileName) || empty($resourceLocation)) {
                echo "<div class='alert alert-danger'>" . xlt('No compendium file found. Please try again or contact support.') . "</div>";
            } else {
                echo "<p>" . xlt('File Name') . ": " . text($compendiumFileName) . "</p>";
            }
            ?>
        </div>
        <div class="col-md-12" id="stepone">
            <?php if (!empty($compendiumFileName) && !empty($resourceLocation)): ?>
            <button class='btn btn-primary' id="getCompendiumFile"><?php echo xlt("Import Data"); ?></button>
            <?php endif; ?>
            <a href="index.php" class='btn btn-secondary ml-3'><?php echo xlt("Back"); ?></a>
        </div>
        <div class="loader"></div>
        <div class="col-md-12 mt-4" id="quest-success" style="display:none;"></div>
        <div class="col-md-12 mt-4" id="quest-error" style="display:none;">
            <p><?php echo xlt('If you are having trouble downloading the file, please contact support@ehrcommunityhelpdesk.com') ?></p>
        </div>
    </div>
</div> <!-- /container -->
</body>
<script>
    $(document).ready(function() {
        $('.loader').hide();

        $('#getCompendiumFile').click(function() {
            $('.loader').show();
            const data = {
                fileName:    '<?php echo attr($compendiumFileName); ?>',
                retrieveURI: '<?php echo attr($resourceLocation); ?>',
                ackURI:      '<?php echo attr($ackLocation); ?>'
            };

            $.ajax({
                url: 'retrieveCompendium.php',
                type: 'POST',
                data: data,
                dataType: 'json',
                success: function(response) {
                    $('.loader').hide();
                    if (response.success) {
                        $('#quest-success').show().html(
                            '<span class="text-success"><strong>' + response.message + '</strong></span>'
                        );
                        $('#getCompendiumFile').prop('disabled', true);
                    } else {
                        $('#quest-error').show().append('<p>' + response.message + '</p>');
                    }
                    console.log('Response:', response);
                },
                error: function(xhr, status, error) {
                    $('#quest-error').show().append('<p>' + error + '</p>');
                    $('.loader').hide();
                    console.error('Error:', error);
                }
            });
        });
    });
</script>
</html>

