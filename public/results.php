<?php

/*
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *  author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c)
 *  All rights reserved
 *
 */

use Juggernaut\Quest\Module\GetHL7Results;
use Juggernaut\Quest\Module\ParseHl7Results;

require_once dirname(__DIR__, 4) . '/globals.php';

function getResults(): void
{
    $manualResults = false; //I have no idea what I was thinking here.
    $results = new GetHL7Results();
    $hl7Results = $results->sendForResults();
    $hl7ResultsArray = json_decode($hl7Results, true);

    if (!$hl7ResultsArray['results']) {
        $msg = xlt('no results found - Quest Lab Order Get');
        error_log($msg);
    } else {
        $parser = new ParseHl7Results($hl7ResultsArray);
        $manualResults = $parser->parseResults();
    }
    if ($manualResults) {
        $msg = xlt('Results have been process and saved to the inbound directory 1');
        error_log($msg);
    }
}

