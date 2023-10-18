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

class SendAcknowledgement
{
    private array $messageId;
    private array $sendingFacId;
    private array $receivingFacId;
    private mixed $requestId;

    public function __construct($messageId, $requestId)
    {
        $senderInfo = $this->getReceivingFacId();
        $this->sendingFacId = $senderInfo;
        $this->receivingFacId = $senderInfo;
        $this->messageId = $messageId;
        $this->requestId = $requestId;
    }

    public function sendAcknowledgement(): string
    {
        $resourceLocation = '/hub-resource-server/oauth2/result/acknowledgeResults';
        $payload = $this->buildAcknowledgementHeader();
        $response = new QuestPostCommon();

        return $response->postRequestToQuest(
            $resourceLocation,
            $payload
        );
    }
    private function buildAcknowledgementHeader(): string
    {
        return json_encode(
            [
                "resultServiceType" => "HL7",
                "requestId" => $this->requestId,
                "ackMessages" => [
                    [
                        "message" => $this->buildAcknowledgement(),
                        "controlId" => $this->messageId['hl7Message']['controlId']
                    ]
                ]
            ]
        );
    }
    private function buildAcknowledgement(): string
    {
        $date = date('YmdHis');
        $acknowledgement = "MSH|^~\&||" . $this->sendingFacId['send_fac_id'] . "|LAB|" .
            $this->receivingFacId['recv_fac_id'] . "|" . $date . "||ACK|" . rand(10000, 999999) . "|T|2.3\r" .
            "MSA|CA|" . $this->messageId['hl7Message']['controlId'];
        file_put_contents('/var/www/html/quest/ack_hl7.txt', $acknowledgement);
        return base64_encode($acknowledgement);
    }

    private function getReceivingFacId(): array
    {
        return sqlQuery("SELECT send_fac_id, recv_fac_id FROM `procedure_providers` where `name` = ?", ['Quest']);
    }
}
