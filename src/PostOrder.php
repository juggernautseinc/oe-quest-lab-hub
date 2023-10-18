<?php

/*
 * package   OpenEMR
 * link      http://www.open-emr.org
 * author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * Copyright (c)
 * All rights reserved
 */

namespace Juggernaut\Quest\Module;

class PostOrder
{
    //$orderType can be overwritten because there are different order that can be sent
    // TODO read string to get order type and set it further up the stream
    // this is actually set in the requisition form and not in the order form.
    private function buildJsonMessage($encodedOrder, $orderType = 'AOE'): bool|string
    {
        $payloadArray = [
            'orderHl7' => $encodedOrder,
            'documentTypes' => [
                $orderType,
            ]
        ];
        return json_encode($payloadArray);
    }
    final public function sendOrder($encodedOrder): bool|string
    {
        $resourceLocation = '/hub-resource-server/oauth2/order/document';
        $orderPayload = $this->buildJsonMessage($encodedOrder);
        $response = new QuestPostCommon();
        return $response->postRequestToQuest(
            $resourceLocation,
            $orderPayload
        );
    }
}
