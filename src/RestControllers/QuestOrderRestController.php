<?php

/**
 * QuestOrderRestController
 *
 * REST controller for headless Quest procedure order submission
 * and order document retrieval. Called from API routes registered
 * via RestApiCreateEvent in Bootstrap.php.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2025 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Juggernaut\Quest\Module\RestControllers;

use Juggernaut\Quest\Module\Services\HeadlessOrderService;
use Juggernaut\Quest\Module\Services\QuestDocumentService;
use OpenEMR\Common\Logging\SystemLogger;

class QuestOrderRestController
{
    private HeadlessOrderService $orderService;
    private QuestDocumentService $documentService;
    private SystemLogger $logger;

    public function __construct()
    {
        $this->orderService = new HeadlessOrderService();
        $this->documentService = new QuestDocumentService();
        $this->logger = new SystemLogger();
    }

    /**
     * Submit a new procedure order and transmit to Quest.
     *
     * @param array $data JSON-decoded request body
     * @return array JSON-serializable response
     */
    public function submitOrder(array $data): array
    {
        $this->logger->info('QuestOrderRestController: order submission received', [
            'pid' => $data['pid'] ?? null,
            'encounter_id' => $data['encounter_id'] ?? null,
        ]);

        $result = $this->orderService->createAndTransmitOrder($data);
        $response = $result->toArray();

        $httpStatus = match ($result->getStatus()) {
            'transmitted' => 200,
            'validation_error' => 422,
            default => 500,
        };

        return [
            'http_status' => $httpStatus,
            'body' => $response,
        ];
    }

    /**
     * Retrieve all documents linked to a Quest order.
     *
     * @param int $orderId Procedure order ID
     * @return array JSON-serializable response
     */
    public function getOrderDocuments(int $orderId): array
    {
        $this->logger->info('QuestOrderRestController: document retrieval requested', [
            'order_id' => $orderId,
        ]);

        // Verify the order exists
        $order = sqlQuery(
            "SELECT procedure_order_id FROM procedure_order WHERE procedure_order_id = ?",
            [$orderId]
        );

        if (empty($order)) {
            return [
                'http_status' => 404,
                'body' => [
                    'error' => 'Order not found',
                    'order_id' => $orderId,
                ],
            ];
        }

        $documents = $this->documentService->getOrderDocuments($orderId);

        return [
            'http_status' => 200,
            'body' => [
                'order_id' => $orderId,
                'documents' => $documents,
            ],
        ];
    }
}
