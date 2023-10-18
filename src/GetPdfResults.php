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

class GetPdfResults
{
    private function buildResultsRequestMessage(): string
    {
        return json_encode(
            [
                "resultServiceType" => "printable",
            ]
        );
    }
    public function sendForResults(): bool|string
    {
        $resourceLocation = '/hub-resource-server/oauth2/result/getResults';
        $orderPayload = $this->buildResultsRequestMessage();
        $response = new QuestPostCommon();

        return $response->postRequestToQuest(
            $resourceLocation,
            $orderPayload
        );
    }
}
