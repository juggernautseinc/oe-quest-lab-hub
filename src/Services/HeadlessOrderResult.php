<?php

/**
 * HeadlessOrderResult
 *
 * Value object that carries the result of a headless procedure order submission.
 * Serializable to JSON for the API response.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Services;

class HeadlessOrderResult
{
    public function __construct(
        private int $orderId = 0,
        private string $status = 'unknown',
        private ?int $documentId = null,
        private ?string $requisitionPdfBase64 = null,
        private ?string $requisitionFilename = null,
        private array $errors = [],
    ) {
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getDocumentId(): ?int
    {
        return $this->documentId;
    }

    public function setDocumentId(?int $documentId): void
    {
        $this->documentId = $documentId;
    }

    public function getRequisitionPdfBase64(): ?string
    {
        return $this->requisitionPdfBase64;
    }

    public function setRequisitionPdfBase64(?string $pdf): void
    {
        $this->requisitionPdfBase64 = $pdf;
    }

    public function getRequisitionFilename(): ?string
    {
        return $this->requisitionFilename;
    }

    public function setRequisitionFilename(?string $filename): void
    {
        $this->requisitionFilename = $filename;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Serialize to an array suitable for JSON API response.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'order_id' => $this->orderId ?: null,
            'document_id' => $this->documentId,
            'requisition_pdf' => $this->requisitionPdfBase64,
            'requisition_filename' => $this->requisitionFilename,
            'errors' => $this->errors,
        ];
    }
}
