<?php
declare(strict_types=1);

// Execute the native event listener and headless order pipeline with stored inert
// SQL rows and the real ProcessLabOrder/PostOrder rejection parser. No OpenEMR
// bootstrap, shared database, credentials, vendor HTTP or requisition generation.
namespace OpenEMR\Common\Logging {
    class SystemLogger { public function __call($name, $args) {} }
}
namespace OpenEMR\Common\Crypto { class CryptoGen {} }
namespace OpenEMR\Core { class Kernel {} }
namespace OpenEMR\Common\Uuid {
    class UuidRegistry {
        public static function createMissingUuidsForTables(array $tables): void {
            if ($tables !== ['procedure_order']) throw new \RuntimeException('Unexpected UUID fixture');
        }
    }
}
namespace OpenEMR\Events\Services {
    // Core's event carries HL7 only, so the listener must use its real ORC-2 path.
    class QuestLabTransmitEvent {
        public function __construct(private string $order) {}
        public function getOrder(): string { return $this->order; }
    }
}
namespace Symfony\Component\EventDispatcher { interface EventDispatcherInterface {} }
namespace Juggernaut\Quest\Module {
    class QuestPostCommon {
        public function postRequestToQuest($location, $payload): string {
            if ($location !== '/hub-resource-server/oauth2/order/document') {
                throw new \RuntimeException('Unexpected vendor endpoint');
            }
            $GLOBALS['wire'][] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!$GLOBALS['replies']) throw new \RuntimeException('Unexpected transmission or retry');
            return array_shift($GLOBALS['replies']);
        }
    }
}
namespace {
    $root = getenv('QUEST_TEST_SOURCE') ?: dirname(__DIR__);
    foreach (['Exceptions/QuestHttpException', 'Exceptions/QuestOrderValidationException',
        'QuestGlobalConfig', 'PostOrder', 'ProcessLabOrder', 'Bootstrap',
        'Services/HeadlessOrderResult', 'Services/QuestDocumentService', 'Services/HeadlessOrderService'] as $file) {
        require $root . '/src/' . $file . '.php';
    }

    use Juggernaut\Quest\Module\Bootstrap;
    use Juggernaut\Quest\Module\Services\HeadlessOrderService;
    use OpenEMR\Events\Services\QuestLabTransmitEvent;
    use Symfony\Component\EventDispatcher\EventDispatcherInterface;

    class OfflineHeadlessOrderService extends HeadlessOrderService {
        protected function insertOrderCodes(int $formId, array $data): void {
            // Only core code-saving and HL7 generation are replaced. Validation,
            // order insert, transmission, exception handling and timestamp SQL run unchanged.
            $GLOBALS['code_saves'][] = [$formId, $data['procedure_codes']];
        }
        protected function generateHl7(int $formId): string { return hl7($formId); }
    }
    function hl7(int $id): string { return "MSH|^~\\&|synthetic\rPID|1\rORC|NW|$id\rOBR|1"; }
    $checks = 0;
    function check(bool $ok, string $message): void {
        global $checks;
        if (!$ok) throw new RuntimeException($message);
        $checks++;
    }
    function resetFixture(string $reply): void {
        $GLOBALS['orders'] = [
            42 => ['date_transmitted' => '2026-11-06 09:00:00'],
            43 => ['date_transmitted' => '2026-11-05 09:00:00'],
        ];
        $GLOBALS['wire'] = [];
        $GLOBALS['writes'] = [];
        $GLOBALS['replies'] = [$reply];
        $GLOBALS['code_saves'] = [];
    }
    function sqlInsert($sql, $params = []): int {
        if (!str_starts_with($sql, 'INSERT INTO procedure_order SET ') || substr_count($sql, '?') !== count($params)) {
            throw new RuntimeException('Unexpected fixture insert');
        }
        $GLOBALS['orders'][100] = ['date_transmitted' => null, 'patient_id' => $params[11], 'encounter_id' => $params[12]];
        return 100;
    }
    function sqlStatement($sql, $params = []): bool {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        if (!in_array($sql, [
            'UPDATE procedure_order SET date_transmitted = NULL WHERE procedure_order_id = ?',
            'UPDATE procedure_order SET date_transmitted = NOW() WHERE procedure_order_id = ?',
        ], true) || count($params) !== 1 || !isset($GLOBALS['orders'][$params[0]])) {
            throw new RuntimeException('Unexpected timestamp fixture write');
        }
        $GLOBALS['writes'][] = [$sql, $params];
        $GLOBALS['orders'][$params[0]]['date_transmitted'] = str_contains($sql, '= NULL') ? null : 'fixture-now';
        return true;
    }
    function assertOneTransmission(int $id): void {
        check(count($GLOBALS['wire']) === 1 && $GLOBALS['replies'] === [], 'Exactly one vendor attempt must occur');
        check(base64_decode($GLOBALS['wire'][0]['orderHl7'], true) === hl7($id), 'Transmitted HL7 must name the actual persisted order');
        check($GLOBALS['orders'][43]['date_transmitted'] === '2026-11-05 09:00:00', 'Unrelated order timestamp changed');
    }

    $temp = sys_get_temp_dir() . '/quest-order-rejection-' . bin2hex(random_bytes(8));
    mkdir($temp, 0700);
    $GLOBALS['OE_SITE_DIR'] = $temp;
    $GLOBALS['oe_quest_download_requisition'] = false;
    try {
        $listener = new Bootstrap(new class implements EventDispatcherInterface {});
        foreach (['HTTP Status Code: 401', 'HTTP Status Code: 500', 'HTTP Status Code: 0'] as $rejection) {
            resetFixture($rejection);
            $listener->sendOrderToQuestLab(new QuestLabTransmitEvent(hl7(42)));
            check($GLOBALS['orders'][42]['date_transmitted'] === null, "$rejection: native event left rejected order marked transmitted");
            check(count($GLOBALS['writes']) === 1 && $GLOBALS['writes'][0][1] === [42], 'Native rejection must clear only its exact order');
            check(!is_dir($temp . '/documents'), 'Native rejection continued into accepted-order output');
            assertOneTransmission(42);
        }
        resetFixture('{"accepted":true}');
        $listener->sendOrderToQuestLab(new QuestLabTransmitEvent(hl7(42)));
        check($GLOBALS['orders'][42]['date_transmitted'] === '2026-11-06 09:00:00' && $GLOBALS['writes'] === [],
            'Accepted native order must retain the timestamp set by core');
        assertOneTransmission(42);

        $service = new OfflineHeadlessOrderService();
        $data = ['pid' => 7, 'encounter_id' => 8, 'provider_id' => 9, 'lab_id' => 10,
            'billing_type' => 'T', 'order_diagnosis' => 'ICD10:R53.83', 'date_collected' => '2026-11-06 09:00:00',
            'procedure_codes' => [['procedure_type_id' => 147, 'diagnoses' => 'ICD10:R53.83']]];
        foreach (['HTTP Status Code: 401', 'HTTP Status Code: 500', 'HTTP Status Code: 0'] as $rejection) {
            resetFixture($rejection);
            $result = $service->createAndTransmitOrder($data);
            check($result->getOrderId() === 100 && $result->getStatus() === 'quest_error', "$rejection: headless rejection lost its saved order or error receipt");
            check($GLOBALS['orders'][100]['date_transmitted'] === null && $GLOBALS['writes'] === [],
                "$rejection: headless rejection wrote a transmitted timestamp");
            check($GLOBALS['orders'][100]['patient_id'] === 7 && $GLOBALS['orders'][100]['encounter_id'] === 8,
                'Headless order insert must retain its patient and encounter');
            check($GLOBALS['code_saves'] === [[100, $data['procedure_codes']]], 'Headless test must pass code-saving boundary before rejection');
            check(count($result->getErrors()) === 1 && str_starts_with($result->getErrors()[0], 'Quest transmission failed:'),
                'Headless rejection must report a transmission failure');
            check($result->getDocumentId() === null && $result->getRequisitionPdfBase64() === null,
                'Rejected headless order must not claim requisition output');
            assertOneTransmission(100);
        }
        resetFixture('{"accepted":true}');
        $result = $service->createAndTransmitOrder($data);
        check($result->getStatus() === 'transmitted' && $result->getErrors() === [], 'Accepted headless order must report transmitted');
        check($GLOBALS['orders'][100]['date_transmitted'] === 'fixture-now' && count($GLOBALS['writes']) === 1
            && $GLOBALS['writes'][0][1] === [100], 'Accepted headless order must mark exactly its own saved row');
        assertOneTransmission(100);
        echo "PASS $checks native/headless order rejection timestamp checks\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($temp);
    }
}
