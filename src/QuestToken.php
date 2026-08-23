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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Juggernaut\Quest\Module\Exceptions\QuestHttpException;

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

    private function requestNewToken(): string
    {
        $endPoint      = $this->operationMode();
        $tokenEndpoint = $endPoint . '/hub-authorization-server/oauth2/token';

        try {
            $client   = new Client();
            $response = $client->post($tokenEndpoint, [
                'http_errors'  => false,
                'form_params'  => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body       = $response->getBody()->getContents();

            if ($statusCode === 200) {
                return $body;
            }

            error_log('Quest token request failed: HTTP ' . $statusCode . ' — ' . $body);
            return json_encode([
                'error'  => 'token_request_failed',
                'status' => $statusCode,
                'reason' => 'HTTP ' . $statusCode,
            ]);
        } catch (GuzzleException $e) {
            error_log('Quest token Guzzle error: ' . $e->getMessage());
            return json_encode([
                'error'  => 'token_request_failed',
                'status' => 0,
                'reason' => $e->getMessage(),
            ]);
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
