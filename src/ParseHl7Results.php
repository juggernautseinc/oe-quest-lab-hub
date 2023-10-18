<?php

/*
 *   package   OpenEMR
 *   link      http://www.open-emr.org
 *  author    Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c)
 *  All rights reserved
 *
 */

namespace Juggernaut\Quest\Module;

class ParseHl7Results
{
    private mixed $resultsArray;
    public function __construct($resultsArray)
    {
        $this->resultsArray = $resultsArray;
    }
    public function parseResults(): bool
    {
        $i = 0;  //random numbers could have been used here
        $requestId = $this->resultsArray['requestId'];
        foreach ($this->resultsArray['results'] as $resultset) {
            $hl7_message = base64_decode($resultset['hl7Message']['message']);
            $path = $this->getInboundLocation();
            $filename = 'quest_results_' . date('Y-m-d H:i:s') . '_' . $i . '.hl7';
            $dropbox = $path['results_path'].'/'.$filename;
            try {
                file_put_contents($dropbox, $hl7_message);
                $prepared_acknowledgement = new SendAcknowledgement($resultset, $requestId);
                $acknowledgement = $prepared_acknowledgement->sendAcknowledgement();
                error_log('Acknowledgement reply: ' . $acknowledgement);
            } catch (\Exception $e) {
                error_log('Error writing lab results file: ' . $e->getMessage());
            }
            $i++;
        }
        return true;
    }
    private function getInboundLocation(): bool|array|null
    {
        return sqlQuery('SELECT `results_path` FROM `procedure_providers` where `name` = ?', ['Quest']);
    }
}
