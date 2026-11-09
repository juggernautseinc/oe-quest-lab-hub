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
    /**
     * Saves each result to the Quest results_path and acknowledges it to Quest only after the
     * file is fully written; an unsaved result is left unacknowledged so Quest offers it again.
     * Returns true only when every result was saved and acknowledged.
     */
    public function parseResults(): bool
    {
        $i = 0;  //random numbers could have been used here
        $complete = true;
        $requestId = $this->resultsArray['requestId'];
        $path = $this->getInboundLocation();
        $dir = is_array($path) ? rtrim((string) ($path['results_path'] ?? ''), '/') : '';
        foreach ($this->resultsArray['results'] as $resultset) {
            $i++;
            $hl7_message = base64_decode($resultset['hl7Message']['message'] ?? '', true);
            if ($hl7_message === false || $hl7_message === '') {
                error_log('Quest result ' . $i . ' could not be decoded; not acknowledged');
                $complete = false;
                continue;
            }
            if ($dir === '' || !is_dir($dir)) {
                error_log('Quest results_path is not configured or missing; result ' . $i . ' not acknowledged');
                $complete = false;
                continue;
            }
            $dropbox = $dir . '/quest_results_' . date('Y-m-d H:i:s') . '_' . $i . '.hl7';
            try {
                $written = file_put_contents($dropbox, $hl7_message, LOCK_EX);
                if ($written !== strlen($hl7_message)) {
                    error_log('Error writing lab results file ' . $dropbox . '; not acknowledged');
                    $complete = false;
                    continue;
                }
                $prepared_acknowledgement = new SendAcknowledgement($resultset, $requestId);
                $acknowledgement = $prepared_acknowledgement->sendAcknowledgement();
                error_log('Acknowledgement reply: ' . $acknowledgement);
                if (!is_string($acknowledgement) || str_starts_with($acknowledgement, 'HTTP Status Code: ')) {
                    // Saved locally; Quest may offer it again, which the importer de-duplicates by control ID.
                    $complete = false;
                }
            } catch (\Exception $e) {
                error_log('Error saving or acknowledging lab results file: ' . $e->getMessage());
                $complete = false;
            }
        }
        return $complete;
    }
    private function getInboundLocation(): bool|array|null
    {
        return sqlQuery('SELECT `results_path` FROM `procedure_providers` where `name` = ?', ['Quest']);
    }
}
