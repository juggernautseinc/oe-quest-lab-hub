<?php

/**
 * QuestDocumentService
 *
 * Stores Quest requisition/ABN/AOE PDFs in the OpenEMR `documents` table
 * so they are permanently linked to the patient and order, visible in the
 * Order Documents UI, and accessible via FHIR DocumentReference.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Services;

use Document;
use OpenEMR\Common\Logging\SystemLogger;
use Juggernaut\Quest\Module\Bootstrap;
use Juggernaut\Quest\Module\Exceptions\QuestFileSystemException;

class QuestDocumentService
{
    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Store a Quest requisition PDF in the documents table.
     *
     * Mirrors the LabCorp saveEreq() pattern from common.php so that Quest
     * documents appear alongside LabCorp eREQs in the Order Documents UI.
     *
     * @param int $pid Patient ID
     * @param int $orderId Procedure order ID (procedure_order_id)
     * @param string $pdfBinary Raw PDF binary content
     * @param string $docType Document type label (e.g. 'REQ', 'ABN', 'AOE')
     * @return int|null The document ID on success, null on failure
     */
    public function storeRequisitionPdf(
        int $pid,
        int $orderId,
        string $pdfBinary,
        string $docType = 'REQ'
    ): ?int {
        if (empty($pdfBinary)) {
            $this->logger->error('QuestDocumentService: empty PDF binary, nothing to store');
            return null;
        }

        $categoryId = $this->getQuestCategoryId();
        $timestamp = date('Y-m-d-His');
        $filename = "quest_{$docType}_{$timestamp}_order_{$orderId}.pdf";

        $document = new Document();
        $error = $document->createDocument($pid, $categoryId, $filename, 'application/pdf', $pdfBinary);

        if (!empty($error)) {
            $this->logger->error('QuestDocumentService: failed to create document', [
                'error' => $error,
                'pid' => $pid,
                'order_id' => $orderId,
            ]);
            return null;
        }

        // Link the document to the procedure order via list_id and add documentationOf
        $documentationOf = $timestamp;
        if ($docType !== 'REQ') {
            $documentationOf = $docType;
        }

        sqlStatement(
            "UPDATE documents SET documentationOf = ?, list_id = ? WHERE id = ?",
            [$documentationOf, $orderId, $document->get_id()]
        );

        $this->logger->info('QuestDocumentService: PDF stored in documents table', [
            'document_id' => $document->get_id(),
            'pid' => $pid,
            'order_id' => $orderId,
            'doc_type' => $docType,
            'filename' => $filename,
        ]);

        return (int) $document->get_id();
    }

    /**
     * Store a Quest PDF from a file already saved on disk by ProcessRequisitionDocument.
     *
     * @param int $pid Patient ID
     * @param int $orderId Procedure order ID
     * @param string $filename Filename (not full path) in the labs directory
     * @return int|null Document ID on success
     * @throws QuestFileSystemException If the file cannot be read
     */
    public function storeFromDiskFile(int $pid, int $orderId, string $filename): ?int
    {
        $path = Bootstrap::requisitionFormPath() . $filename;

        if (!file_exists($path) || !is_readable($path)) {
            throw new QuestFileSystemException("Cannot read requisition file: {$path}");
        }

        $pdfBinary = file_get_contents($path);
        if ($pdfBinary === false) {
            throw new QuestFileSystemException("Failed to read requisition file: {$path}");
        }

        // Determine doc type from filename
        $docType = 'REQ';
        $upperFilename = strtoupper($filename);
        if (str_contains($upperFilename, 'ABN')) {
            $docType = 'ABN';
        } elseif (str_contains($upperFilename, 'AOE')) {
            $docType = 'AOE';
        }

        return $this->storeRequisitionPdf($pid, $orderId, $pdfBinary, $docType);
    }

    /**
     * Retrieve all documents linked to a procedure order.
     *
     * Queries the documents table using the same pattern as the Order Documents
     * UI in common.php (lines 1298–1313): foreign_id = pid, list_id = orderId.
     *
     * @param int $orderId Procedure order ID
     * @return array Array of document records with base64 PDF content
     */
    public function getOrderDocuments(int $orderId): array
    {
        // First get the pid for this order
        $order = sqlQuery(
            "SELECT patient_id FROM procedure_order WHERE procedure_order_id = ?",
            [$orderId]
        );

        if (empty($order)) {
            return [];
        }

        $pid = (int) $order['patient_id'];

        $result = sqlStatement(
            "SELECT id, url, name, documentationOf, date, mimetype
             FROM documents
             WHERE foreign_id = ? AND list_id = ? AND deleted = 0
             ORDER BY id",
            [$pid, $orderId]
        );

        $documents = [];
        while ($row = sqlFetchArray($result)) {
            $docType = $this->inferDocType($row['url'] ?? '', $row['name'] ?? '', $row['documentationOf'] ?? '');

            // Retrieve actual PDF content via Document class
            $pdfBase64 = null;
            $document = new Document($row['id']);
            $fileContent = $document->get_data();
            if (!empty($fileContent)) {
                $pdfBase64 = base64_encode($fileContent);
            }

            $documents[] = [
                'document_id' => (int) $row['id'],
                'type' => $docType,
                'filename' => $row['name'] ?? basename($row['url'] ?? ''),
                'created_at' => $row['date'] ?? '',
                'pdf_base64' => $pdfBase64,
            ];
        }

        return $documents;
    }

    /**
     * Get or create the Quest document category ID.
     *
     * @return int Category ID
     */
    private function getQuestCategoryId(): int
    {
        $category = sqlQuery("SELECT id FROM categories WHERE name LIKE ?", ['Quest']);
        if (!empty($category['id'])) {
            return (int) $category['id'];
        }

        // Fallback to Lab Report category
        $category = sqlQuery("SELECT id FROM categories WHERE name LIKE ?", ['Lab Report']);
        if (!empty($category['id'])) {
            return (int) $category['id'];
        }

        // Last resort fallback — use category 1 (typically "Categories")
        $this->logger->warning('QuestDocumentService: no Quest or Lab Report category found, using default');
        return 1;
    }

    /**
     * Infer document type from filename or documentationOf field.
     *
     * @param string $url
     * @param string $name
     * @param string $documentationOf
     * @return string
     */
    private function inferDocType(string $url, string $name, string $documentationOf): string
    {
        $combined = strtoupper($url . $name . $documentationOf);

        if (str_contains($combined, 'ABN')) {
            return 'ABN';
        }
        if (str_contains($combined, 'AOE')) {
            return 'AOE';
        }

        return 'REQ';
    }
}
