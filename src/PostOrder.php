<?php

/*
 * package   OpenEMR
 * link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

namespace Juggernaut\Quest\Module;

use Juggernaut\Quest\Module\Exceptions\QuestHttpException;
use OpenEMR\Common\Logging\SystemLogger;

/**
 * PostOrder
 *
 * Handles posting lab orders to Quest Hub.
 *
 * @package Juggernaut\Quest\Module
 */
class PostOrder
{
    /**
     * System logger
     * @var SystemLogger
     */
    private SystemLogger $logger;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Build the JSON payload for the order
     *
     * @param string $encodedOrder Base64-encoded HL7 order
     * @return string JSON-encoded order payload
     */
    private function buildJsonMessage(string $encodedOrder): string
    {
        $payloadArray = [
            'orderHl7' => $encodedOrder,
            'documentTypes' => [
                'ABN', 'REQ', 'AOE'
            ]
        ];
        return json_encode($payloadArray);
    }

    /**
     * Send an order to Quest Hub
     *
     * @param string $encodedOrder Base64-encoded HL7 order
     * @return string Response from Quest Hub
     * @throws QuestHttpException If the request fails
     */
    final public function sendOrder(string $encodedOrder): string
    {
        $this->logger->debug('Order payload transmission started');
        $resourceLocation = '/hub-resource-server/oauth2/order/document';
        $orderPayload = $this->buildJsonMessage($encodedOrder);
        $response = new QuestPostCommon();
        return $response->postRequestToQuest(
            $resourceLocation,
            $orderPayload
        );
    }
}
