<?php

/**
 * Tests for HeadlessOrderResult DTO
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Tests\Unit\Services;

use Juggernaut\Quest\Module\Services\HeadlessOrderResult;
use PHPUnit\Framework\TestCase;

class HeadlessOrderResultTest extends TestCase
{
    /**
     * Test that a freshly constructed result has correct defaults
     */
    public function testDefaultValues(): void
    {
        $result = new HeadlessOrderResult();

        $this->assertSame(0, $result->getOrderId());
        $this->assertSame('unknown', $result->getStatus());
        $this->assertNull($result->getDocumentId());
        $this->assertNull($result->getRequisitionPdfBase64());
        $this->assertNull($result->getRequisitionFilename());
        $this->assertSame([], $result->getErrors());
    }

    /**
     * Test that constructor accepts and stores all parameters
     */
    public function testConstructorWithAllParameters(): void
    {
        $result = new HeadlessOrderResult(
            orderId: 42,
            status: 'transmitted',
            documentId: 99,
            requisitionPdfBase64: 'JVBERi0x',
            requisitionFilename: 'test.pdf',
            errors: [],
        );

        $this->assertSame(42, $result->getOrderId());
        $this->assertSame('transmitted', $result->getStatus());
        $this->assertSame(99, $result->getDocumentId());
        $this->assertSame('JVBERi0x', $result->getRequisitionPdfBase64());
        $this->assertSame('test.pdf', $result->getRequisitionFilename());
    }

    /**
     * Test all setter/getter pairs
     */
    public function testSettersAndGetters(): void
    {
        $result = new HeadlessOrderResult();

        $result->setOrderId(123);
        $this->assertSame(123, $result->getOrderId());

        $result->setStatus('validation_error');
        $this->assertSame('validation_error', $result->getStatus());

        $result->setDocumentId(456);
        $this->assertSame(456, $result->getDocumentId());

        $result->setRequisitionPdfBase64('base64data');
        $this->assertSame('base64data', $result->getRequisitionPdfBase64());

        $result->setRequisitionFilename('req.pdf');
        $this->assertSame('req.pdf', $result->getRequisitionFilename());
    }

    /**
     * Test that addError accumulates errors
     */
    public function testAddErrorAccumulates(): void
    {
        $result = new HeadlessOrderResult();

        $result->addError('First error');
        $result->addError('Second error');
        $result->addError('Third error');

        $errors = $result->getErrors();
        $this->assertCount(3, $errors);
        $this->assertSame('First error', $errors[0]);
        $this->assertSame('Second error', $errors[1]);
        $this->assertSame('Third error', $errors[2]);
    }

    /**
     * Test toArray serialization for a successful transmission
     */
    public function testToArrayTransmittedOrder(): void
    {
        $result = new HeadlessOrderResult();
        $result->setOrderId(1234);
        $result->setStatus('transmitted');
        $result->setDocumentId(567);
        $result->setRequisitionPdfBase64('JVBERi0xLjQK');
        $result->setRequisitionFilename('labRequisition.pdf');

        $array = $result->toArray();

        $this->assertSame('transmitted', $array['status']);
        $this->assertSame(1234, $array['order_id']);
        $this->assertSame(567, $array['document_id']);
        $this->assertSame('JVBERi0xLjQK', $array['requisition_pdf']);
        $this->assertSame('labRequisition.pdf', $array['requisition_filename']);
        $this->assertSame([], $array['errors']);
    }

    /**
     * Test toArray serialization for a validation error
     */
    public function testToArrayValidationError(): void
    {
        $result = new HeadlessOrderResult();
        $result->setStatus('validation_error');
        $result->addError('Patient ID is required');
        $result->addError('Lab ID is required');

        $array = $result->toArray();

        $this->assertSame('validation_error', $array['status']);
        $this->assertNull($array['order_id']);
        $this->assertNull($array['document_id']);
        $this->assertNull($array['requisition_pdf']);
        $this->assertNull($array['requisition_filename']);
        $this->assertCount(2, $array['errors']);
    }

    /**
     * Test that zero orderId serializes as null in toArray
     */
    public function testToArrayZeroOrderIdIsNull(): void
    {
        $result = new HeadlessOrderResult();
        $result->setOrderId(0);

        $array = $result->toArray();
        $this->assertNull($array['order_id']);
    }

    /**
     * Test that non-zero orderId serializes as the integer value
     */
    public function testToArrayNonZeroOrderIdIsPreserved(): void
    {
        $result = new HeadlessOrderResult();
        $result->setOrderId(1);

        $array = $result->toArray();
        $this->assertSame(1, $array['order_id']);
    }

    /**
     * Test that toArray output is JSON-serializable
     */
    public function testToArrayIsJsonSerializable(): void
    {
        $result = new HeadlessOrderResult(
            orderId: 100,
            status: 'transmitted',
            documentId: 200,
            requisitionPdfBase64: 'dGVzdA==',
            requisitionFilename: 'test.pdf',
        );

        $json = json_encode($result->toArray());
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertSame(100, $decoded['order_id']);
        $this->assertSame('transmitted', $decoded['status']);
    }
}
