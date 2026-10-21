<?php
// Exercise the real producer with synthetic transport/database collaborators only.
declare(strict_types=1);
namespace GuzzleHttp { class Client {} }
namespace OpenEMR\Common\Logging {
    class SystemLogger { public function debug(...$args) {} public function info(...$args) {} public function error(...$args) {} }
}
namespace OpenEMR\Common\Http {
    class oeHttpRequest {
        public function __construct($client) {}
        public function usingBaseUri($uri) { return $this; }
        public function usingHeaders($headers) { return $this; }
        public function asJson() { return $this; }
        public function send(...$args) { return $this; }
        public function getStatusCode() { return 200; }
        public function getBody() { return json_encode(['orderSupportDocuments'=>[[
            'documentType'=>'REQ', 'documentData'=>base64_encode(base64_encode($GLOBALS['test_pdf']))
        ]]]); }
    }
}
namespace Juggernaut\Quest\Module {
    class QuestToken {
        public function operationMode() { return 'https://vendor.invalid'; }
        public function getFreshToken() { return '{"access_token":"synthetic"}'; }
    }
    class Bootstrap {
        public static function requisitionFormPath() { return $GLOBALS['OE_SITE_DIR'] . '/documents/labs/'; }
    }
    function random_bytes(int $length): string { return $GLOBALS['test_random'] ?? \random_bytes($length); }
}
namespace {
    function sqlQuery($query, $params) { return ['order_abn'=>'not_required', 'billing_type'=>'P', 'question_count'=>0]; }
    $root = getenv('QUEST_TEST_SOURCE') ?: dirname(__DIR__);
    require $root . '/src/Exceptions/QuestFileSystemException.php';
    require $root . '/src/RequisitionFile.php';
    require $root . '/src/ProcessRequisitionDocument.php';
    $temp = sys_get_temp_dir() . '/quest-producer-' . bin2hex(random_bytes(8));
    mkdir($temp . '/documents/labs/quest/logs', 0700, true);
    $GLOBALS['OE_SITE_DIR'] = $temp;
    try {
        $GLOBALS['test_pdf'] = '%PDF-first';
        $first = (new \Juggernaut\Quest\Module\ProcessRequisitionDocument('HL7 synthetic', 1))->sendRequest();
        $GLOBALS['test_pdf'] = '%PDF-second';
        $second = (new \Juggernaut\Quest\Module\ProcessRequisitionDocument('HL7 synthetic', 2))->sendRequest();
        $dir = $temp . '/documents/labs/';
        if ($first === $second || file_get_contents($dir . $first) !== '%PDF-first' || file_get_contents($dir . $second) !== '%PDF-second') {
            throw new \RuntimeException('Producer mixed requisition contents');
        }
        $GLOBALS['test_random'] = str_repeat('c', 16);
        $third = (new \Juggernaut\Quest\Module\ProcessRequisitionDocument('HL7 synthetic', 3))->sendRequest();
        try {
            (new \Juggernaut\Quest\Module\ProcessRequisitionDocument('HL7 synthetic', 4))->sendRequest();
            throw new \LogicException('Producer collision accepted');
        } catch (\Juggernaut\Quest\Module\Exceptions\QuestFileSystemException $e) {
            if (file_get_contents($dir . $third) !== '%PDF-second') { throw new \RuntimeException('Original changed'); }
        }
        echo "PASS: real requisition producer isolation and handled collision failure\n";
    } finally {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($temp);
    }
}
