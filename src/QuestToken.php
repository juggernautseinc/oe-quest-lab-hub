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

class QuestToken
{
    /**
     * @var mixed|null
     */
    private $clientId;
    /**
     * @var bool|string
     */
    private $clientSecret;

    public function __construct()
    {
        $b = new Bootstrap($GLOBALS['kernel']->getEventDispatcher());
        $credentials = $b->getGlobalConfig();
        $this->clientId = $credentials->getTextOption();
        $this->clientSecret = $credentials->getEncryptedOption();
    }

    final public function getFreshToken()
    {
        return $this->requestNewToken();
    }

    private function requestNewToken()
    {
        $curl = curl_init();
        $endPoint = $this->operationMode();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $endPoint . '/hub-authorization-server/oauth2/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array('grant_type' => 'client_credentials',
                                        'client_id' => $this->clientId,
                                        'client_secret' => $this->clientSecret),
        ));

        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);
        if ($status == 200) {
            return $response;
        } else {
            return $status;
        }
    }

    public function operationMode(): string
    {
        if ($GLOBALS['oe_quest_production']) {
            return Bootstrap::HUB_RESOURCE_PRODUCTION_URL;
        } else {
            return Bootstrap::HUB_RESOURCE_TESTING_URL;
        }
    }
}
