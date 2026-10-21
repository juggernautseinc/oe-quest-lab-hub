<?php
declare(strict_types=1);

// Real bundled sender/poller with inert transport and database collaborators.
namespace phpseclib3\Net {
    class SFTP {
        public function __construct($host, $port = 22) { $GLOBALS['events'][] = ['connect', $host, $port]; }
        public function getServerPublicHostKey() {
            $GLOBALS['events'][] = ['key'];
            if ($GLOBALS['serverKey'] instanceof \Throwable) { throw $GLOBALS['serverKey']; }
            return $GLOBALS['serverKey'];
        }
        public function login($user, $password) { $GLOBALS['events'][] = ['login']; return $GLOBALS['loginOk']; }
        public function put($file, $data) { $GLOBALS['events'][] = ['put']; return true; }
        public function nlist($path) { $GLOBALS['events'][] = ['nlist']; return []; }
    }
}
namespace OpenEMR\Core {
    class OEGlobalsBag {
        public static function getInstance() { return new self(); }
        public function getSrcDir() { return $GLOBALS['fixture']; }
        public function get($key) { return $GLOBALS['fixture']; }
    }
}
namespace OpenEMR\Common\Session {
    class SessionWrapperFactory {
        public static function getInstance() { return new self(); }
        public function getActiveSession() { return $this; }
        public function get($key) { return 'synthetic'; }
    }
}
namespace OpenEMR\Common\Logging {
    class EventAuditLogger {
        public static function getInstance() { return new self(); }
        public function newEvent(...$args) {}
    }
}
namespace {
    $root = getenv('QUEST_TEST_SOURCE') ?: dirname(__DIR__);
    $fixture = sys_get_temp_dir() . '/quest-sftp-' . bin2hex(random_bytes(8));
    mkdir($fixture . '/custom', 0700, true);
    foreach (['forms.inc.php', 'pnotes.inc.php', 'custom/code_types.inc.php'] as $file) {
        file_put_contents($fixture . '/' . $file, '<?php');
    }
    $webserver_root = $fixture;
    require $root . '/deploy/openemr-8.1.1-dev/gen_hl7_order.inc.php';
    require $root . '/deploy/openemr-8.1.1-dev/receive_hl7_results.inc.php';
    function xl($value) { return $value; }
    function sqlQuery($query, $args = []) { return $GLOBALS['provider']; }
    function sqlStatement($query) { return [$GLOBALS['provider']]; }
    function sqlFetchArray(&$rows) { return array_shift($rows); }
    function check(bool $ok, string $message): void {
        if (!$ok) { throw new \RuntimeException($message . ': ' . json_encode($GLOBALS['events'])); }
        ++$GLOBALS['checks'];
    }
    $pin = 'ssh-ed25519 ' . base64_encode('synthetic-pinned-public-key');
    $priorPin = getenv('QUEST_SFTP_HOST_KEY_42');
    $checks = 0;
    $provider = ['ppid' => 42, 'protocol' => 'SFTP', 'remote_host' => 'synthetic.invalid',
        'login' => 'synthetic-user', 'password' => 'synthetic-password', 'orders_path' => '/orders',
        'results_path' => '/results', 'send_fac_id' => 'SYNTHETIC', 'recv_fac_id' => 'SYNTHETIC',
        'recv_app_id' => 'SYNTHETIC', 'name' => 'Synthetic', 'npi' => 'SYNTHETIC', 'DorP' => 'P'];
    try {
        foreach (['send', 'receive'] as $operation) {
            $provider['remote_host'] = $operation === 'receive' ? 'synthetic.invalid:2222' : 'synthetic.invalid';
            foreach (['missing', 'whitespace', 'mismatch', 'false-key', 'empty-key', 'exception', 'trusted', 'login-fails', 'rsa-sha2-256', 'rsa-sha2-512', 'rsa-mismatch'] as $case) {
                $events = []; $loginOk = $case !== 'login-fails';
                $enrolledPin = str_starts_with($case, 'rsa-') ? 'ssh-rsa ' . base64_encode('synthetic-rsa-key') : $pin;
                $serverKey = match ($case) {
                    'mismatch' => 'ssh-ed25519 ' . base64_encode('different-synthetic-key'),
                    'false-key' => false, 'empty-key' => '',
                    'exception' => new \RuntimeException('Synthetic handshake failure'), default => $pin,
                    'rsa-sha2-256', 'rsa-sha2-512' => $case . ' ' . base64_encode('synthetic-rsa-key'),
                    'rsa-mismatch' => 'rsa-sha2-512 ' . base64_encode('different-rsa-key'),
                };
                if ($case === 'missing') { putenv('QUEST_SFTP_HOST_KEY_42'); }
                else { putenv('QUEST_SFTP_HOST_KEY_42=' . ($case === 'whitespace' ? '  ' : $enrolledPin)); }
                if ($operation === 'send') {
                    // Caller alias must not choose a different enrollment from the selected DB row.
                    $result = quest_send_hl7_order('0042', "MSH|^~\\&|a|b|c|d|e||ORM^O01|synthetic\r");
                } else {
                    $info = []; $result = poll_hl7_results($info, 42);
                }
                $names = array_column($events, 0);
                if (in_array($case, ['trusted', 'rsa-sha2-256', 'rsa-sha2-512'], true)) {
                    check($result === '', "$operation trusted transport remains usable");
                    check(array_search('key', $names, true) < array_search('login', $names, true), 'key verified before login');
                    check(in_array($operation === 'send' ? 'put' : 'nlist', $names, true), 'existing operation reached');
                    check($events[0] === ['connect', 'synthetic.invalid', $operation === 'send' ? 22 : 2222], 'existing endpoint/port behavior retained');
                } elseif ($case === 'login-fails') {
                    check($result !== '' && in_array('login', $names, true), 'verified login failure retains error');
                    check(!in_array('put', $names, true) && !in_array('nlist', $names, true), 'no transfer after login failure');
                } else {
                    check($result !== '', "$operation rejects $case");
                    check(!in_array('login', $names, true) && !in_array('put', $names, true) && !in_array('nlist', $names, true), "$operation no credentials or transfer for $case");
                }
            }
        }
        putenv('QUEST_SFTP_HOST_KEY_42');
        $provider['protocol'] = 'FS';
        mkdir($fixture . '/outbound', 0700);
        mkdir($fixture . '/inbound', 0700);
        $provider['orders_path'] = $fixture . '/outbound';
        $provider['results_path'] = $fixture . '/inbound';
        $events = [];
        $message = "MSH|^~\\&|a|b|c|d|e||ORM^O01|synthetic\r";
        check(quest_send_hl7_order(42, $message) === '', 'filesystem send remains usable without a host pin');
        check(file_get_contents($fixture . '/outbound/synthetic.txt') === $message, 'filesystem payload preserved');
        $info = [];
        check(poll_hl7_results($info, 42) === '' && $events === [], 'filesystem polling stays outside SFTP');
        echo "manual SFTP boundary: $checks checks passed\n";
    } finally {
        putenv($priorPin === false ? 'QUEST_SFTP_HOST_KEY_42' : 'QUEST_SFTP_HOST_KEY_42=' . $priorPin);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixture, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($fixture);
    }
}
