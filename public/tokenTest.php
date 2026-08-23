<?php

/**
 *  package   OpenEMR
 *  link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c) 2024. Sherwin Gaddis <sherwingaddis@gmail.com>
 * All rights reserved
 *
 */

require_once dirname(__FILE__, 5) . "/globals.php";
require_once dirname(__FILE__, 2) . "/vendor/autoload.php";

use Juggernaut\Quest\Module\QuestToken;
use OpenEMR\Core\Header;

$token = new QuestToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <?php Header::setupHeader(); ?>
    <title></title>
</head>
<body>
<div class="container-fluid m-5">
    <h3><?php echo xlt("Configuration Validation") ?></h3>
    <?php

        $accessToken = json_decode($token->getFreshToken(), true);
        if (isset($accessToken['token_type']) && isset($accessToken['access_token'])) {
            echo "The configuration is completed successful and token retrieved successfully.";

        } else {
            echo "The configuration is incomplete. Check your username and password are in the system.";
        }

    ?>
</div>
</body>
</html>

