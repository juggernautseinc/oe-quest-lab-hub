<?php

/**
 * Tests for HeadlessOrderService validation logic
 *
 * These tests exercise the validation path of createAndTransmitOrder().
 * Validation failures are caught internally and returned as a HeadlessOrderResult
 * with status 'validation_error' — no database interaction occurs, so these
 * tests run without needing a live database connection.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Tests\Unit\Services;

use Juggernaut\Quest\Module\Services\HeadlessOrderService;
use PHPUnit\Framework\TestCase;

class HeadlessOrderServiceValidationTest extends TestCase
{
    private HeadlessOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HeadlessOrderService();
    }

    /**
     * Helper to build a valid data array that passes all validation.
     * Individual tests override specific fields to trigger targeted failures.
     */
    private function validOrderData(): array
    {
        return [
            'pid' => 1,
            'encounter_id' => 42,
            'provider_id' => 3,
            'lab_id' => 5,
            'billing_type' => 'T',
            'order_diagnosis' => 'ICD10:R53.83',
            'date_collected' => '2026-06-09 10:30:00',
            'procedure_codes' => [
                [
                    'procedure_type_id' => 147,
                    'diagnoses' => 'ICD10:R53.83',
                ],
            ],
        ];
    }

    /**
     * Test that completely empty data produces validation errors for all required fields
     */
    public function testEmptyDataReturnsAllValidationErrors(): void
    {
        $result = $this->service->createAndTransmitOrder([]);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertSame(0, $result->getOrderId());
        $this->assertNull($result->getDocumentId());

        $errors = $result->getErrors();
        $this->assertNotEmpty($errors);

        // All required fields should be flagged
        $errorText = implode(' | ', $errors);
        $this->assertStringContainsString('Patient ID', $errorText);
        $this->assertStringContainsString('Encounter ID', $errorText);
        $this->assertStringContainsString('Ordering Provider', $errorText);
        $this->assertStringContainsString('Lab ID', $errorText);
        $this->assertStringContainsString('Billing Type', $errorText);
        $this->assertStringContainsString('diagnosis', $errorText);
        $this->assertStringContainsString('procedure code', $errorText);
        $this->assertStringContainsString('collection date', $errorText);
    }

    /**
     * Test that missing pid is caught
     */
    public function testMissingPidIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['pid']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('Patient ID', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing encounter_id is caught
     */
    public function testMissingEncounterIdIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['encounter_id']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('Encounter ID', implode(' ', $result->getErrors()));
    }

    /**
     * Test that provider_id = 0 is caught
     */
    public function testZeroProviderIdIsValidationError(): void
    {
        $data = $this->validOrderData();
        $data['provider_id'] = 0;

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('Ordering Provider', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing lab_id is caught
     */
    public function testMissingLabIdIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['lab_id']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('Lab ID', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing billing_type is caught
     */
    public function testMissingBillingTypeIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['billing_type']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('Billing Type', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing order_diagnosis is caught
     */
    public function testMissingDiagnosisIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['order_diagnosis']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('diagnosis', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing procedure_codes is caught
     */
    public function testMissingProcedureCodesIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['procedure_codes']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('procedure code', implode(' ', $result->getErrors()));
    }

    /**
     * Test that empty procedure_codes array is caught
     */
    public function testEmptyProcedureCodesArrayIsValidationError(): void
    {
        $data = $this->validOrderData();
        $data['procedure_codes'] = [];

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('procedure code', implode(' ', $result->getErrors()));
    }

    /**
     * Test that non-array procedure_codes is caught
     */
    public function testNonArrayProcedureCodesIsValidationError(): void
    {
        $data = $this->validOrderData();
        $data['procedure_codes'] = 'not-an-array';

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('procedure code', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing date_collected without order_psc is caught
     */
    public function testMissingCollectionDateWithoutPscIsValidationError(): void
    {
        $data = $this->validOrderData();
        unset($data['date_collected']);
        // order_psc is not set either

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $this->assertStringContainsString('collection date', implode(' ', $result->getErrors()));
    }

    /**
     * Test that missing date_collected WITH order_psc passes the collection date check.
     *
     * We verify this by testing that removing ONLY date_collected (while adding
     * order_psc) does NOT produce a collection date validation error.
     * The order will fail beyond validation (at the DB layer) in this lightweight
     * test environment, so we check the error text rather than the status.
     */
    public function testMissingCollectionDateWithPscPassesValidation(): void
    {
        if (!function_exists('sqlInsert')) {
            $this->markTestSkipped('Requires database connection (sqlInsert not available)');
        }

        $data = $this->validOrderData();
        unset($data['date_collected']);
        $data['order_psc'] = '1';

        $result = $this->service->createAndTransmitOrder($data);

        $errorText = implode(' ', $result->getErrors());
        // The collection date check should NOT fire when order_psc is set
        $this->assertStringNotContainsString('collection date', $errorText);
        // It should NOT be a validation_error (it passed validation, then
        // hit the DB insert step)
        $this->assertNotSame('validation_error', $result->getStatus());
    }

    /**
     * Test that only the specifically missing fields are reported
     */
    public function testOnlyMissingFieldsReported(): void
    {
        $data = $this->validOrderData();
        unset($data['pid']);
        unset($data['lab_id']);

        $result = $this->service->createAndTransmitOrder($data);

        $this->assertSame('validation_error', $result->getStatus());
        $errors = $result->getErrors();

        // Should have exactly 2 errors for the 2 missing fields
        $this->assertCount(2, $errors);
        $errorText = implode(' | ', $errors);
        $this->assertStringContainsString('Patient ID', $errorText);
        $this->assertStringContainsString('Lab ID', $errorText);
    }

    /**
     * Test that the result contains no PDF data on validation failure
     */
    public function testValidationFailureHasNoRequisitionData(): void
    {
        $result = $this->service->createAndTransmitOrder([]);

        $this->assertNull($result->getRequisitionPdfBase64());
        $this->assertNull($result->getRequisitionFilename());
        $this->assertNull($result->getDocumentId());
    }

    /**
     * Test that toArray on a validation error matches the expected API contract
     */
    public function testValidationErrorResponseMatchesApiContract(): void
    {
        $result = $this->service->createAndTransmitOrder([]);
        $array = $result->toArray();

        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('order_id', $array);
        $this->assertArrayHasKey('document_id', $array);
        $this->assertArrayHasKey('requisition_pdf', $array);
        $this->assertArrayHasKey('requisition_filename', $array);
        $this->assertArrayHasKey('errors', $array);

        $this->assertSame('validation_error', $array['status']);
        $this->assertNull($array['order_id']);
        $this->assertNull($array['document_id']);
        $this->assertNull($array['requisition_pdf']);
        $this->assertIsArray($array['errors']);
        $this->assertNotEmpty($array['errors']);
    }
}
