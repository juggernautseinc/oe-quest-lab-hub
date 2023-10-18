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

class QuestPostCommon
{
    public function postRequestToQuest(
        $resourceLocation,
        $payload
    ): string
    {
        $token = new QuestToken();
        $postToken = json_decode($token->getFreshToken(), true);
        $mode = $token->operationMode();
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $mode . $resourceLocation,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $postToken['access_token'],
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);
        if ($status == 200) {
            curl_close($curl);
            return $response;
        } else {
            curl_close($curl);
            return $status;
        }
    }
}
