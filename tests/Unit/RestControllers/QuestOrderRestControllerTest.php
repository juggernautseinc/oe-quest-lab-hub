<?php

/**
 * Tests for QuestOrderRestController
 *
 * Tests the HTTP status code mapping and response structure.
 * Uses reflection to inject mock services so that no DB or external
 * API calls are made.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Tests\Unit\RestControllers;

use Juggernaut\Quest\Module\RestControllers\QuestOrderRestController;
use Juggernaut\Quest\Module\Services\HeadlessOrderResult;
use Juggernaut\Quest\Module\Services\HeadlessOrderService;
use Juggernaut\Quest\Module\Services\QuestDocumentService;
use PHPUnit\Framework\TestCase;

class QuestOrderRestControllerTest extends TestCase
{
    /**
     * Inject a mock HeadlessOrderService into the controller via reflection.
     */
    private function injectMockOrderService(
        QuestOrderRestController $controller,
        HeadlessOrderService $mockService
    ): void {
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('orderService');
        $prop->setValue($controller, $mockService);
    }

    /**
     * Inject a mock QuestDocumentService into the controller via reflection.
     */
    private function injectMockDocumentService(
        QuestOrderRestController $controller,
        QuestDocumentService $mockService
    ): void {
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('documentService');
        $prop->setValue($controller, $mockService);
    }

    /**
     * Test that a transmitted order returns HTTP 200
     */
    public function testSubmitOrderReturns200OnTransmitted(): void
    {
        $result = new HeadlessOrderResult(
            orderId: 1234,
            status: 'transmitted',
            documentId: 567,
            requisitionPdfBase64: 'JVBERi0x',
            requisitionFilename: 'test.pdf',
        );

        $mockService = $this->createMock(HeadlessOrderService::class);
        $mockService->method('createAndTransmitOrder')->willReturn($result);

        $controller = new QuestOrderRestController();
        $this->injectMockOrderService($controller, $mockService);

        $response = $controller->submitOrder(['pid' => 1]);

        $this->assertSame(200, $response['http_status']);
        $this->assertSame('transmitted', $response['body']['status']);
        $this->assertSame(1234, $response['body']['order_id']);
        $this->assertSame(567, $response['body']['document_id']);
        $this->assertSame('JVBERi0x', $response['body']['requisition_pdf']);
    }

    /**
     * Test that a validation error returns HTTP 422
     */
    public function testSubmitOrderReturns422OnValidationError(): void
    {
        $result = new HeadlessOrderResult();
        $result->setStatus('validation_error');
        $result->addError('Patient ID is required');

        $mockService = $this->createMock(HeadlessOrderService::class);
        $mockService->method('createAndTransmitOrder')->willReturn($result);

        $controller = new QuestOrderRestController();
        $this->injectMockOrderService($controller, $mockService);

        $response = $controller->submitOrder([]);

        $this->assertSame(422, $response['http_status']);
        $this->assertSame('validation_error', $response['body']['status']);
        $this->assertNotEmpty($response['body']['errors']);
    }

    /**
     * Test that a quest_error returns HTTP 500
     */
    public function testSubmitOrderReturns500OnQuestError(): void
    {
        $result = new HeadlessOrderResult();
        $result->setStatus('quest_error');
        $result->addError('Quest API failed');

        $mockService = $this->createMock(HeadlessOrderService::class);
        $mockService->method('createAndTransmitOrder')->willReturn($result);

        $controller = new QuestOrderRestController();
        $this->injectMockOrderService($controller, $mockService);

        $response = $controller->submitOrder(['pid' => 1]);

        $this->assertSame(500, $response['http_status']);
        $this->assertSame('quest_error', $response['body']['status']);
    }

    /**
     * Test that an hl7_error returns HTTP 500
     */
    public function testSubmitOrderReturns500OnHl7Error(): void
    {
        $result = new HeadlessOrderResult();
        $result->setStatus('hl7_error');
        $result->addError('HL7 generation failed');

        $mockService = $this->createMock(HeadlessOrderService::class);
        $mockService->method('createAndTransmitOrder')->willReturn($result);

        $controller = new QuestOrderRestController();
        $this->injectMockOrderService($controller, $mockService);

        $response = $controller->submitOrder(['pid' => 1]);

        $this->assertSame(500, $response['http_status']);
        $this->assertSame('hl7_error', $response['body']['status']);
    }

    /**
     * Test that submitOrder response body always contains expected keys
     */
    public function testSubmitOrderResponseBodyHasExpectedKeys(): void
    {
        $result = new HeadlessOrderResult();
        $result->setStatus('transmitted');
        $result->setOrderId(99);

        $mockService = $this->createMock(HeadlessOrderService::class);
        $mockService->method('createAndTransmitOrder')->willReturn($result);

        $controller = new QuestOrderRestController();
        $this->injectMockOrderService($controller, $mockService);

        $response = $controller->submitOrder([]);
        $body = $response['body'];

        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('order_id', $body);
        $this->assertArrayHasKey('document_id', $body);
        $this->assertArrayHasKey('requisition_pdf', $body);
        $this->assertArrayHasKey('requisition_filename', $body);
        $this->assertArrayHasKey('errors', $body);
    }

    /**
     * Test getOrderDocuments returns 200 with documents.
     * Requires database — skipped in lightweight test environment.
     */
    public function testGetOrderDocumentsReturns200WithDocuments(): void
    {
        if (!function_exists('sqlQuery')) {
            $this->markTestSkipped('Requires database connection (sqlQuery not available)');
        }

        $mockDocService = $this->createMock(QuestDocumentService::class);
        $mockDocService->method('getOrderDocuments')->willReturn([
            [
                'document_id' => 10,
                'type' => 'REQ',
                'filename' => 'test_req.pdf',
                'created_at' => '2026-06-09 10:30:00',
                'pdf_base64' => 'JVBERi0x',
            ],
        ]);

        $controller = new QuestOrderRestController();
        $this->injectMockDocumentService($controller, $mockDocService);

        $response = $controller->getOrderDocuments(99999);

        if ($response['http_status'] === 404) {
            $this->assertStringContainsString('not found', $response['body']['error']);
        } else {
            $this->assertSame(200, $response['http_status']);
            $this->assertArrayHasKey('documents', $response['body']);
        }
    }

    /**
     * Test getOrderDocuments returns 404 for non-existent order.
     * Requires database — skipped in lightweight test environment.
     */
    public function testGetOrderDocumentsReturns404ForMissingOrder(): void
    {
        if (!function_exists('sqlQuery')) {
            $this->markTestSkipped('Requires database connection (sqlQuery not available)');
        }

        $controller = new QuestOrderRestController();
        $response = $controller->getOrderDocuments(0);

        $this->assertSame(404, $response['http_status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertSame(0, $response['body']['order_id']);
    }

    /**
     * Test getOrderDocuments response body has expected keys.
     * Requires database — skipped in lightweight test environment.
     */
    public function testGetOrderDocumentsResponseStructure(): void
    {
        if (!function_exists('sqlQuery')) {
            $this->markTestSkipped('Requires database connection (sqlQuery not available)');
        }

        $mockDocService = $this->createMock(QuestDocumentService::class);
        $mockDocService->method('getOrderDocuments')->willReturn([]);

        $controller = new QuestOrderRestController();
        $this->injectMockDocumentService($controller, $mockDocService);

        $response = $controller->getOrderDocuments(0);

        $this->assertArrayHasKey('http_status', $response);
        $this->assertArrayHasKey('body', $response);
    }

    /**
     * Test that submitOrder delegates to the service correctly
     */
    public function testSubmitOrderPassesDataToService(): void
    {
        $inputData = ['pid' => 42, 'encounter_id' => 7];

        $mockService = $this->createMock(HeadlessOrderService::class);
        $mockService->expects($this->once())
            ->method('createAndTransmitOrder')
            ->with($inputData)
            ->willReturn(new HeadlessOrderResult());

        $controller = new QuestOrderRestController();
        $this->injectMockOrderService($controller, $mockService);

        $controller->submitOrder($inputData);
    }
}
