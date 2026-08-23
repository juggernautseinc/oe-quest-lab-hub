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
use OpenEMR\Common\Http\oeHttpRequest;
use OpenEMR\Common\Logging\SystemLogger;
use Juggernaut\Quest\Module\Exceptions\QuestHttpException;
use Juggernaut\Quest\Module\Exceptions\QuestFileSystemException;

class ProcessRequisitionDocument
{
    private mixed $orderHl7;
    private string $reqName;
    private string $path;
    private oeHttpRequest $httpClient;
    private SystemLogger $logger;
    private int $orderId;
    private ?array $orderDetails = null;

    public function __construct($orderHl7, int $orderId)
    {
        $this->orderHl7 = base64_encode($orderHl7);
        $this->orderId = $orderId;
        $this->httpClient = new oeHttpRequest(new Client());
        $this->logger = new SystemLogger();
        $this->loadOrderDetails();
    }

    /**
     * Load order details from database to determine document types needed
     *
     * @return void
     */
    private function loadOrderDetails(): void
    {
        $this->orderDetails = sqlQuery(
            "SELECT order_abn, billing_type FROM procedure_order WHERE procedure_order_id = ?",
            [$this->orderId]
        );
        
        if (empty($this->orderDetails)) {
            $this->logger->error('Order not found', ['order_id' => $this->orderId]);
            $this->orderDetails = ['order_abn' => 'not_required', 'billing_type' => 'P'];
        }
        
        $this->logger->debug('Order details loaded', [
            'order_id' => $this->orderId,
            'order_abn' => $this->orderDetails['order_abn'] ?? 'unknown',
            'billing_type' => $this->orderDetails['billing_type'] ?? 'unknown'
        ]);
    }

    /**
     * Determine which document types to request based on order details
     *
     * @return array Array of document type strings
     */
    private function determineDocumentTypes(): array
    {
        $documentTypes = [];
        
        $billingType = trim($this->orderDetails['billing_type'] ?? '');
        $orderAbn = trim(strtolower($this->orderDetails['order_abn'] ?? ''));
        
        // Request ABN if billing to third-party (Medicare) and ABN is not explicitly 'not_required'
        if ($billingType === 'T' && $orderAbn !== 'not_required') {
            $documentTypes[] = 'ABN';
            $this->logger->debug('ABN document requested', ['reason' => 'Third-party billing']);
        }
        
        // Always request requisition
        $documentTypes[] = 'REQ';
        
        // Check if AOE questions exist for this order
        if ($this->hasAOEQuestions()) {
            $documentTypes[] = 'AOE';
            $this->logger->debug('AOE document requested', ['reason' => 'AOE questions exist']);
        }
        
        $this->logger->info('Document types determined', ['types' => $documentTypes]);
        return $documentTypes;
    }

    /**
     * Check if order has unanswered AOE questions
     *
     * @return bool True if AOE questions exist and are unanswered
     */
    private function hasAOEQuestions(): bool
    {
        // Check if there are any unanswered AOE questions for this order
        $result = sqlQuery(
            "SELECT COUNT(*) as question_count 
             FROM procedure_questions pq
             INNER JOIN procedure_order_code poc ON pq.procedure_code = poc.procedure_code
             LEFT JOIN procedure_answers pa ON pa.procedure_order_id = poc.procedure_order_id 
                 AND pa.procedure_order_seq = poc.procedure_order_seq 
                 AND pa.question_code = pq.question_code
             WHERE poc.procedure_order_id = ? 
                 AND pq.activity = 1 
                 AND pa.answer IS NULL",
            [$this->orderId]
        );
        
        return ($result['question_count'] ?? 0) > 0;
    }

    private function buildRequest(): bool|string
    {
        $documentTypes = $this->determineDocumentTypes();
        
        $request = json_encode([
            "documentTypes" => $documentTypes,
            "orderHl7" => $this->orderHl7
        ]);
        
        $this->logger->debug("Requisition request payload completed", [
            'document_types' => $documentTypes
        ]);
        
        return $request;
    }

    public function sendRequest(): string
    {
        try {
            $token = new QuestToken();
            $baseUrl = $token->operationMode();
            $this->logger->debug("Requisition document request initiated", ['mode' => $baseUrl]);

            $tokenResponse = json_decode($token->getFreshToken(), true);
            if (!isset($tokenResponse['access_token'])) {
                throw new QuestHttpException(
                    'Failed to extract access token from OAuth2 response',
                    0,
                    400,
                    json_encode($tokenResponse)
                );
            }

            $accessToken = $tokenResponse['access_token'];
            $requestPayload = $this->buildRequest();

            // Make HTTP request using oeHttpRequest
            $response = $this->httpClient
                ->usingBaseUri($baseUrl)
                ->usingHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->asJson()
                ->send('POST', '/hub-resource-server/oauth2/order/document', [
                    'body' => $requestPayload,
                ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->error(
                    'Requisition document request failed',
                    ['status_code' => $statusCode]
                );
                throw new QuestHttpException(
                    'Requisition document request failed',
                    0,
                    $statusCode,
                    $response->getBody()
                );
            }

            $this->logger->debug('Requisition document returned successfully', ['status_code' => $statusCode]);

            // Ensure directories exist
            $this->ensureDirectories();

            // Parse response
            $responsePdf = json_decode($response->getBody(), true);
            if (!is_array($responsePdf)) {
                throw new QuestHttpException(
                    'Invalid JSON response from Quest',
                    0,
                    400,
                    $response->getBody()
                );
            }

            // Save raw response for debugging
            $rawJsonPath = $GLOBALS['OE_SITE_DIR'] . '/documents/labs/requisitionRaw.json';
            file_put_contents($rawJsonPath, json_encode($responsePdf, JSON_PRETTY_PRINT));

            // Process all returned documents
            $documents = $responsePdf['orderSupportDocuments'] ?? [];
            if (empty($documents)) {
                throw new QuestHttpException(
                    'No documents returned in response',
                    0,
                    400,
                    json_encode($responsePdf)
                );
            }

            // Process the first document (usually the only one, or the combined document)
            $document = $documents[0];
            $documentType = $document['documentType'] ?? 'REQ';
            $documentData = $document['documentData'] ?? '';
            $responseMessage = $document['responseMessage'] ?? '';
            $requestStatus = $document['requestStatus'] ?? '';
            
            $this->logger->info('Document response details', [
                'document_type' => $documentType,
                'response_message' => $responseMessage,
                'request_status' => $requestStatus,
                'status' => $document['status'] ?? false
            ]);

            if (empty($documentData)) {
                throw new QuestHttpException(
                    'No PDF document data in response',
                    0,
                    400,
                    json_encode($document)
                );
            }

            // Decode PDF - Quest returns double base64 encoded data
            // First decode: converts from JSON base64 to intermediate string
            $pdfDecoded = base64_decode($documentData, true);
            if ($pdfDecoded === false) {
                throw new QuestHttpException(
                    'Failed to decode base64 PDF data',
                    0,
                    400
                );
            }

            // Second decode: converts from intermediate base64 to actual PDF binary
            $pdfBinary = base64_decode($pdfDecoded, true);
            if ($pdfBinary === false) {
                throw new QuestHttpException(
                    'Failed to decode second-level base64 PDF data',
                    0,
                    400
                );
            }

            // Determine filename based on document type
            $this->path = Bootstrap::requisitionFormPath();
            $timestamp = time();
            $this->reqName = $this->generateFilename($documentType, $timestamp);
            $pdfPath = $this->path . $this->reqName;

            if (file_put_contents($pdfPath, $pdfBinary) === false) {
                throw new QuestFileSystemException(
                    'Failed to write PDF file to: ' . $pdfPath
                );
            }

            $this->logger->info('Document PDF saved successfully', [
                'file' => $this->reqName,
                'document_type' => $documentType,
                'response_message' => $responseMessage
            ]);
            
            return $this->reqName;

        } catch (QuestHttpException $e) {
            $this->logger->error('Quest HTTP error during requisition fetch', [
                'error' => $e->getMessage(),
                'status_code' => $e->getCode()
            ]);
            throw $e;
        } catch (QuestFileSystemException $e) {
            $this->logger->error('Filesystem error during requisition save', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during requisition fetch', [
                'error' => $e->getMessage()
            ]);
            throw new QuestHttpException(
                'Unexpected error fetching requisition: ' . $e->getMessage(),
                0,
                null,
                null,
                $e
            );
        }
    }

    /**
     * Ensure all required directories exist
     *
     * @return void
     * @throws QuestFileSystemException
     */
    private function ensureDirectories(): void
    {
        $baseDir = $GLOBALS['OE_SITE_DIR'] . '/documents/labs/';
        $subdirs = ['quest', 'quest/logs'];

        foreach ($subdirs as $subdir) {
            $fullPath = $baseDir . $subdir;
            if (!is_dir($fullPath)) {
                if (!mkdir($fullPath, 0755, true)) {
                    throw new QuestFileSystemException(
                        'Failed to create directory: ' . $fullPath
                    );
                }
                $this->logger->debug('Created directory', ['path' => $fullPath]);
            }
        }
    }

    /**
     * Generate appropriate filename based on document type
     *
     * @param string $documentType The type of document returned (ABN, REQ, ABN-REQ, etc.)
     * @param int $timestamp Unix timestamp for unique file naming
     * @return string Generated filename
     */
    private function generateFilename(string $documentType, int $timestamp): string
    {
        $docType = strtoupper(trim($documentType));
        
        // Map document types to descriptive filenames
        if (str_contains($docType, 'ABN-REQ') || str_contains($docType, 'ABN') && str_contains($docType, 'REQ')) {
            // Combined ABN and Requisition
            return "labABN-Requisition-{$timestamp}.pdf";
        } elseif (str_contains($docType, 'ABN')) {
            // ABN only
            return "labABN-{$timestamp}.pdf";
        } elseif (str_contains($docType, 'AOE')) {
            // AOE document (may include requisition)
            return "labAOE-Requisition-{$timestamp}.pdf";
        } else {
            // Default to requisition
            return "labRequisition-{$timestamp}.pdf";
        }
    }
}
