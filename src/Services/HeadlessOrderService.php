<?php

/**
 * HeadlessOrderService
 *
 * Encapsulates the full headless procedure order pipeline:
 * validate → save order → generate HL7 → transmit to Quest → fetch requisition → store document.
 *
 * Reuses existing save functions and Quest transmission classes to avoid duplication.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\Services;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Orders\Hl7OrderGenerationException;
use OpenEMR\Common\Uuid\UuidRegistry;
use Juggernaut\Quest\Module\Bootstrap;
use Juggernaut\Quest\Module\Exceptions\QuestOrderValidationException;
use Juggernaut\Quest\Module\Exceptions\QuestHttpException;
use Juggernaut\Quest\Module\ProcessLabOrder;
use Juggernaut\Quest\Module\ProcessRequisitionDocument;

class HeadlessOrderService
{
    private SystemLogger $logger;
    private QuestDocumentService $documentService;

    public function __construct()
    {
        $this->logger = new SystemLogger();
        $this->documentService = new QuestDocumentService();
    }

    /**
     * Create and transmit a procedure order to Quest headlessly.
     *
     * @param array $data Validated input data from the API request
     * @return HeadlessOrderResult
     * @throws QuestOrderValidationException If required fields are missing
     */
    public function createAndTransmitOrder(array $data): HeadlessOrderResult
    {
        $result = new HeadlessOrderResult();

        try {
            // 1. Validate
            $this->validateOrderData($data);

            // 2. Insert procedure_order
            $formId = $this->insertProcedureOrder($data);
            $result->setOrderId($formId);

            // 3. Insert order codes via save functions
            $this->insertOrderCodes($formId, $data);

            // 4. Generate HL7
            $hl7 = $this->generateHl7($formId);

            // 5. Transmit to Quest
            new ProcessLabOrder($hl7);
            $this->logger->info('HeadlessOrderService: order transmitted to Quest', [
                'order_id' => $formId,
            ]);

            // 6. Fetch requisition if enabled
            $documentId = null;
            $pdfBase64 = null;
            $reqFilename = null;

            if ($GLOBALS['oe_quest_download_requisition'] ?? false) {
                $pdf = new ProcessRequisitionDocument($hl7, $formId);
                $reqFilename = $pdf->sendRequest();

                if (!empty($reqFilename)) {
                    // 6a. Store in documents table
                    $documentId = $this->documentService->storeFromDiskFile(
                        (int) $data['pid'],
                        $formId,
                        $reqFilename
                    );

                    // 6b. Read for API response
                    $path = Bootstrap::requisitionFormPath() . $reqFilename;
                    if (file_exists($path)) {
                        $pdfBinary = file_get_contents($path);
                        if ($pdfBinary !== false) {
                            $pdfBase64 = base64_encode($pdfBinary);
                        }
                    }
                }
            }

            // 7. Mark transmitted
            sqlStatement(
                "UPDATE procedure_order SET date_transmitted = NOW() WHERE procedure_order_id = ?",
                [$formId]
            );

            // 8. Build result
            $result->setStatus('transmitted');
            $result->setDocumentId($documentId);
            $result->setRequisitionPdfBase64($pdfBase64);
            $result->setRequisitionFilename($reqFilename);
        } catch (QuestOrderValidationException $e) {
            $result->setStatus('validation_error');
            foreach ($e->getValidationErrors() as $err) {
                $result->addError($err);
            }
        } catch (Hl7OrderGenerationException $e) {
            $result->setStatus('hl7_error');
            $result->addError($e->getMessage());
            $this->logger->error('HeadlessOrderService: HL7 generation failed', [
                'error' => $e->getMessage(),
            ]);
        } catch (QuestHttpException $e) {
            $result->setStatus('quest_error');
            $result->addError('Quest transmission failed: ' . $e->getMessage());
            $this->logger->error('HeadlessOrderService: Quest API error', [
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
        } catch (\Exception $e) {
            $result->setStatus('error');
            $result->addError('Unexpected error: ' . $e->getMessage());
            $this->logger->error('HeadlessOrderService: unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $result;
    }

    /**
     * Validate required fields for order submission.
     *
     * @param array $data
     * @throws QuestOrderValidationException
     */
    private function validateOrderData(array $data): void
    {
        $errors = [];

        if (empty($data['pid'])) {
            $errors[] = 'Patient ID (pid) is required';
        }
        if (empty($data['encounter_id'])) {
            $errors[] = 'Encounter ID is required';
        }
        if (empty($data['provider_id']) || ((int) $data['provider_id'] < 1)) {
            $errors[] = 'Ordering Provider is required';
        }
        if (empty($data['lab_id'])) {
            $errors[] = 'Lab ID is required';
        }
        if (empty($data['billing_type'])) {
            $errors[] = 'Billing Type is required';
        }
        if (empty($data['order_diagnosis'])) {
            $errors[] = 'At least one diagnosis is required';
        }
        if (empty($data['procedure_codes']) || !is_array($data['procedure_codes'])) {
            $errors[] = 'At least one procedure code is required';
        }
        if (empty($data['date_collected']) && empty($data['order_psc'])) {
            $errors[] = 'Specimen collection date is required unless this is a PSC Hold Order';
        }

        if (!empty($errors)) {
            throw new QuestOrderValidationException($errors);
        }
    }

    /**
     * Insert a new procedure_order row.
     *
     * Uses the same column set as common.php lines 210–268.
     *
     * @param array $data
     * @return int The new procedure_order_id
     */
    private function insertProcedureOrder(array $data): int
    {
        $sets =
            "date_ordered = ?, " .
            "provider_id = ?, " .
            "lab_id = ?, " .
            "date_collected = ?, " .
            "order_priority = ?, " .
            "order_status = ?, " .
            "billing_type = ?, " .
            "order_psc = ?, " .
            "specimen_fasting = ?, " .
            "clinical_hx = ?, " .
            "patient_instructions = ?, " .
            "patient_id = ?, " .
            "encounter_id = ?, " .
            "history_order = ?, " .
            "order_abn = ?, " .
            "order_diagnosis = ?, " .
            "account = ?, " .
            "account_facility = ?, " .
            "collector_id = ?, " .
            "procedure_order_type = ?, " .
            "order_intent = ?, " .
            "scheduled_date = ?, " .
            "scheduled_start = ?, " .
            "scheduled_end = ?, " .
            "performer_type = ?, " .
            "location_id = ?";

        $params = [
            $data['date_ordered'] ?? date('Y-m-d'),
            (int) $data['provider_id'],
            (int) $data['lab_id'],
            !empty($data['date_collected']) ? $data['date_collected'] : null,
            $data['order_priority'] ?? 'normal',
            $data['order_status'] ?? 'pending',
            $data['billing_type'],
            $data['order_psc'] ?? '0',
            $data['specimen_fasting'] ?? '',
            trim($data['clinical_hx'] ?? ''),
            trim($data['patient_instructions'] ?? ''),
            (int) $data['pid'],
            (int) $data['encounter_id'],
            trim($data['history_order'] ?? ''),
            trim($data['order_abn'] ?? 'not_required'),
            trim($data['order_diagnosis']),
            trim($data['account'] ?? ''),
            (int) ($data['account_facility'] ?? 0),
            (int) ($data['collector_id'] ?? 0),
            trim($data['procedure_type_names'] ?? 'laboratory_test'),
            trim($data['order_intent'] ?? 'order'),
            !empty($data['scheduled_date']) ? $data['scheduled_date'] : null,
            !empty($data['scheduled_start']) ? $data['scheduled_start'] : null,
            !empty($data['scheduled_end']) ? $data['scheduled_end'] : null,
            trim($data['performer_type'] ?? ''),
            (int) ($data['location_id'] ?? 0),
        ];

        $formId = sqlInsert("INSERT INTO procedure_order SET $sets", $params);
        UuidRegistry::createMissingUuidsForTables(['procedure_order']);

        $this->logger->info('HeadlessOrderService: procedure order created', [
            'order_id' => $formId,
            'pid' => $data['pid'],
        ]);

        return $formId;
    }

    /**
     * Insert order codes using the existing save functions.
     *
     * Builds a normalized $postData array that matches the $_POST key structure
     * expected by saveProcedureOrderCodes().
     *
     * @param int $formId
     * @param array $data
     */
    private function insertOrderCodes(int $formId, array $data): void
    {
        // Load the save functions
        require_once dirname(__DIR__, 5) . '/interface/forms/procedure_order/procedure_order_save_functions.php';

        // Build a $_POST-compatible array from the JSON input
        $postData = [
            'form_proc_type' => [],
            'form_proc_type_diag' => [],
            'form_proc_order_title' => [],
            'form_transport' => [],
            'form_proc_code' => [],
            'form_procedure_type' => [],
            'form_proc_type_desc' => [],
            'form_proc_order_seq' => [],
            'form_proc_reason_code' => [],
            'form_proc_reason_description' => [],
            'form_proc_reason_date_low' => [],
            'form_proc_reason_date_high' => [],
            'form_proc_reason_status' => [],
            'form_proc_specimen_id' => [],
            'form_proc_specimen_identifier' => [],
            'form_proc_accession_identifier' => [],
            'form_proc_specimen_type_code' => [],
            'form_proc_specimen_type' => [],
            'form_proc_collection_method_code' => [],
            'form_proc_collection_method' => [],
            'form_proc_specimen_location_code' => [],
            'form_proc_specimen_location' => [],
            'form_proc_specimen_date_low' => [],
            'form_proc_specimen_date_high' => [],
            'form_proc_specimen_collected' => [],
            'form_proc_specimen_volume_value' => [],
            'form_proc_specimen_volume_unit' => [],
            'form_proc_specimen_condition_code' => [],
            'form_proc_specimen_condition' => [],
            'form_proc_specimen_comments' => [],
            'form_lab_id' => $data['lab_id'],
            'procedure_type_names' => $data['procedure_type_names'] ?? 'laboratory_test',
        ];

        foreach ($data['procedure_codes'] as $i => $code) {
            $postData['form_proc_type'][$i] = (int) ($code['procedure_type_id'] ?? 0);
            $postData['form_proc_type_diag'][$i] = $code['diagnoses'] ?? $data['order_diagnosis'] ?? '';
            $postData['form_proc_order_title'][$i] = $code['procedure_order_title'] ?? 'laboratory_test';
            $postData['form_transport'][$i] = $code['transport'] ?? '';
            $postData['form_proc_code'][$i] = $code['procedure_code'] ?? '';
            $postData['form_procedure_type'][$i] = $code['procedure_type'] ?? ($data['procedure_type_names'] ?? 'laboratory_test');
            $postData['form_proc_type_desc'][$i] = $code['procedure_name'] ?? '';
            $postData['form_proc_order_seq'][$i] = '';
            $postData['form_proc_reason_code'][$i] = $code['reason_code'] ?? '';
            $postData['form_proc_reason_description'][$i] = $code['reason_description'] ?? '';
            $postData['form_proc_reason_date_low'][$i] = $code['reason_date_low'] ?? '';
            $postData['form_proc_reason_date_high'][$i] = $code['reason_date_high'] ?? '';
            $postData['form_proc_reason_status'][$i] = $code['reason_status'] ?? '';
            // Empty specimen arrays — headless orders typically don't include specimen details
            $postData['form_proc_specimen_id'][$i] = [];
            $postData['form_proc_specimen_identifier'][$i] = [];
            $postData['form_proc_accession_identifier'][$i] = [];
            $postData['form_proc_specimen_type_code'][$i] = [];
            $postData['form_proc_specimen_type'][$i] = [];
            $postData['form_proc_collection_method_code'][$i] = [];
            $postData['form_proc_collection_method'][$i] = [];
            $postData['form_proc_specimen_location_code'][$i] = [];
            $postData['form_proc_specimen_location'][$i] = [];
            $postData['form_proc_specimen_date_low'][$i] = [];
            $postData['form_proc_specimen_date_high'][$i] = [];
            $postData['form_proc_specimen_collected'][$i] = [];
            $postData['form_proc_specimen_volume_value'][$i] = [];
            $postData['form_proc_specimen_volume_unit'][$i] = [];
            $postData['form_proc_specimen_condition_code'][$i] = [];
            $postData['form_proc_specimen_condition'][$i] = [];
            $postData['form_proc_specimen_comments'][$i] = [];
        }

        // Clear existing answers then save codes (same as common.php lines 303-304)
        sqlStatement("DELETE FROM procedure_answers WHERE procedure_order_id = ?", [$formId]);
        saveProcedureOrderCodes($formId, $postData);
    }

    /**
     * Generate HL7 for the given order using the Quest-specific generator.
     *
     * @param int $formId
     * @return string HL7 message content
     * @throws Hl7OrderGenerationException
     */
    private function generateHl7(int $formId): string
    {
        // The Quest HL7 generator needs $webserver_root which is set by globals.php
        // (available via the REST dispatcher).
        $interfaceDir = realpath($GLOBALS['webserver_root'] . '/interface');
        $procToolsDir = $interfaceDir . DIRECTORY_SEPARATOR . 'procedure_tools';
        require_once("{$procToolsDir}/quest/gen_hl7_order.inc.php");

        $hl7Result = gen_hl7_order($formId);
        return $hl7Result->hl7;
    }
}
