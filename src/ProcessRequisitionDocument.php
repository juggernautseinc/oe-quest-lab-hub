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

class ProcessRequisitionDocument
{
    private mixed $orderHl7;

    public function __construct($orderHl7)
    {
        $this->orderHl7 = base64_encode($orderHl7);
    }

    private function buildRequest(): bool|string
    {
        $request = json_encode( [
            "documentTypes" => [
                                    "REQ"
                               ],

            "orderHl7" => $this->orderHl7
        ]);
        error_log("Requisition request payload completed");
        return $request;
    }

    public function sendRequest(): string
    {
        $token = new QuestToken();
        $postToken = json_decode($token->getFreshToken(), true);
        $requestPayload = $this->buildRequest();

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => Bootstrap::HUB_RESOURCE_TESTING_URL . '/hub-resource-server/oauth2/order/document',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $requestPayload,
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $postToken['access_token'],
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);
        $info = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if (!curl_errno($curl)) {
            $code = $info['http_code'];
            error_log("Requisition document: returned successfully $code");
            curl_close($curl);
            $responsePdf = json_decode($response, true);
            $pdfDecoded = base64_decode($responsePdf['orderSupportDocuments'][0]['documentData']); //This is not a base64 encoded string
            $path = Bootstrap::requisitionFormPath();
            $reqName = 'labRequisition-' . time() . '.pdf';
            $directory = new DirectoryCheckCreate();
            file_put_contents($path . $reqName, base64_decode($pdfDecoded));
            return $reqName;
        } else {
            error_log('Requisition document: retrieval failed ' . $info['http_code']);
            curl_close($curl);
            return false;
        }
    }
}
