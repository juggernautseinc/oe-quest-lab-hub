<?php
declare(strict_types=1);

// Bundled Quest classes against stubbed transport, filesystem and database; no vendor call.
namespace OpenEMR\Common\Logging {
    class SystemLogger {
        public function __call($name, $args) {}
    }
}
namespace Juggernaut\Quest\Module {
    class QuestPostCommon {
        public function postRequestToQuest($location, $payload) {
            $GLOBALS['posted'][] = $location;
            return array_shift($GLOBALS['replies']);
        }
    }
}
namespace {
    $root = getenv('QUEST_TEST_SOURCE') ?: dirname(__DIR__);
    require $root . '/src/Exceptions/QuestHttpException.php';
    require $root . '/src/PostOrder.php';
    require $root . '/src/SendAcknowledgement.php';
    require $root . '/src/ParseHl7Results.php';
    require $root . '/src/Bootstrap.php';
    use Juggernaut\Quest\Module\Exceptions\QuestHttpException;
    use Juggernaut\Quest\Module\ParseHl7Results;
    use Juggernaut\Quest\Module\PostOrder;

    $checks = 0;
    function check(bool $ok, string $m): void { global $checks; if (!$ok) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } $checks++; }
    function sqlQuery($sql, $args = []) { return ['results_path' => $GLOBALS['resultsPath']]; }
    ini_set('error_log', '/dev/null');

    // Orders: only an HTTP 200 body is a transmission.
    $GLOBALS['replies'] = ['{"ok":true}'];
    check((new PostOrder())->sendOrder('x') === '{"ok":true}', 'accepted order returns the Quest body');
    foreach (['HTTP Status Code: 401' => 401, 'HTTP Status Code: 500' => 500, 'HTTP Status Code: 0' => null] as $reply => $status) {
        $GLOBALS['replies'] = [$reply];
        try { (new PostOrder())->sendOrder('x'); check(false, "$reply must not read as transmitted"); }
        catch (QuestHttpException $e) { check($e->getStatusCode() === $status, "$reply raises QuestHttpException with its status"); }
    }

    // Results: acknowledged only after the file is saved.
    $results = ['requestId' => 'r1', 'results' => [
        ['hl7Message' => ['message' => base64_encode("MSH|^~\\&|A\rPID|1")], 'senderInfo' => 'f', 'messageId' => 'm1'],
    ]];
    $GLOBALS['resultsPath'] = sys_get_temp_dir() . '/quest-results-' . bin2hex(random_bytes(6));
    mkdir($GLOBALS['resultsPath'], 0700);
    $GLOBALS['posted'] = []; $GLOBALS['replies'] = ['{"ack":true}'];
    check((new ParseHl7Results($results))->parseResults() === true, 'saved and acknowledged result reports success');
    check(count(glob($GLOBALS['resultsPath'] . '/*.hl7')) === 1 && count($GLOBALS['posted']) === 1, 'one file saved, then one acknowledgement');

    $GLOBALS['posted'] = []; $GLOBALS['replies'] = ['HTTP Status Code: 503'];
    check((new ParseHl7Results($results))->parseResults() === false, 'a refused acknowledgement is not reported as complete');

    $unwritable = $GLOBALS['resultsPath'];
    $GLOBALS['resultsPath'] = $unwritable . '/missing';
    $GLOBALS['posted'] = []; $GLOBALS['replies'] = ['{"ack":true}'];
    check((new ParseHl7Results($results))->parseResults() === false && $GLOBALS['posted'] === [], 'missing results folder: not saved, not acknowledged');

    $bad = $results; $bad['results'][0]['hl7Message']['message'] = '%%%';
    $GLOBALS['resultsPath'] = $unwritable; $GLOBALS['posted'] = [];
    check((new ParseHl7Results($bad))->parseResults() === false && $GLOBALS['posted'] === [], 'undecodable result is not acknowledged');
    array_map('unlink', glob($unwritable . '/*')); rmdir($unwritable);

    // Native transmit: the order ID comes from ORC-2 because OpenEMR's event has no getOrderId().
    $class = null;
    foreach (get_declared_classes() as $c) { if (str_ends_with($c, '\\Bootstrap') && method_exists($c, 'placerOrderId')) { $class = $c; } }
    check($class !== null, 'Bootstrap exposes placerOrderId');
    check($class::placerOrderId("MSH|^~\\&|X\rPID|1\rORC|NW|0042|||||||20261028\rOBR|1") === 42, 'ORC-2 placer number is the order ID');
    check($class::placerOrderId("MSH|^~\\&|X\nPID|1") === null, 'no ORC segment: no order ID');

    echo "PASS $checks Quest transmit and result receipt checks\n";
}
