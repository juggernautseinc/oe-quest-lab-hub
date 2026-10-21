<?php
declare(strict_types=1);

// Run the real public entry points against inert host, session and service doubles.
$root = getenv('QUEST_TEST_SOURCE') ?: dirname(__DIR__);
$temp = sys_get_temp_dir() . '/quest-management-' . bin2hex(random_bytes(8));
$module = $temp . '/interface/modules/custom_modules/quest';
mkdir($module . '/public', 0700, true);
mkdir($module . '/vendor', 0700);
$fixture = <<<'PHP'
<?php
namespace OpenEMR\Common\Acl {
    class AclMain {
        public static function aclCheckCore($section, $value) {
            if ([$section, $value] !== ['admin', 'users']) { throw new \RuntimeException('Wrong ACL'); }
            return $GLOBALS['case']['admin'];
        }
    }
}
namespace OpenEMR\Common\Session {
    class Session {
        public function get($key, $default = null) { return $GLOBALS['fixtureSession'][$key] ?? $default; }
    }
    class SessionWrapperFactory {
        public static function getInstance() { return new self(); }
        public function getActiveSession() { return new Session(); }
    }
}
namespace OpenEMR\Common\Csrf {
    class CsrfUtils {
        public static function setupCsrfKey($session) {
            if (empty($GLOBALS['sessionAllowWrite'])) { throw new \RuntimeException('Read-only session'); }
            $GLOBALS['fixtureSession']['csrf_private_key'] = 'fixture-key';
        }
        public static function collectCsrfToken($session) {
            if (!$session->get('csrf_private_key')) { throw new \RuntimeException('Missing key'); }
            return 'fixture-csrf';
        }
        public static function verifyCsrfToken(string $token, $session) {
            // Native verification calls collectCsrfToken and throws on an absent key.
            self::collectCsrfToken($session);
            return $session->get('csrf_private_key') === 'fixture-key' && $token === 'fixture-csrf';
        }
        public static function csrfNotVerified() { http_response_code(403); echo 'CSRF denied'; exit; }
    }
}
namespace OpenEMR\Core { class Header { public static function setupHeader(...$args) {} } }
namespace Juggernaut\Quest\Module {
    class BackgroundServices {
        public int $status = 0;
        public function __construct() { $GLOBALS['effects'][] = 'background'; }
        public function changeStatus() { $GLOBALS['effects'][] = 'status:' . $this->status; }
        public function isActive() { return true; }
    }
    class LoadCompendium {
        public function __construct() { $GLOBALS['effects'][] = 'list'; }
        public function getBuAbbreviation() { return 'TEST'; }
        public function requestCompendiumFileList() {
            return json_encode(['fullFileLinks'=>[['fileName'=>'TEST_CDC_FULL.zip',
                'retrieveURI'=>'/oauth2/compendium/retrieveCompendium/test',
                'ackURI'=>'/oauth2/compendium/ackCompendium/test']]]);
        }
    }
    class QuestGetCommon {
        public function __construct() { $GLOBALS['effects'][] = 'retrieve-constructor'; }
        public function retrieveCompendium($name, $retrieve, $ack) {
            $GLOBALS['effects'][] = [$name, $retrieve, $ack];
            return json_encode(['success'=>true, 'message'=>'Imported fixture']);
        }
    }
    class QuestToken {
        public function __construct() { $GLOBALS['effects'][] = 'token'; }
        public function getFreshToken() { return '{"token_type":"synthetic","access_token":"synthetic"}'; }
    }
}
namespace {
    $GLOBALS['case'] = json_decode(getenv('QUEST_MANAGEMENT_CASE'), true);
    $GLOBALS['effects'] = [];
    $GLOBALS['fixtureSession'] = empty($GLOBALS['case']['no_key']) ? ['csrf_private_key'=>'fixture-key'] : [];
    $_POST = $GLOBALS['case']['post'] ?? [];
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = $GLOBALS['case']['method'] ?? 'GET';
    $GLOBALS['webroot'] = '/openemr';
    function xlt($s) { return htmlspecialchars($s); }
    function text($s) { return htmlspecialchars($s); }
    function attr($s) { return htmlspecialchars($s, ENT_QUOTES); }
    register_shutdown_function(function () {
        fwrite(STDERR, json_encode(['status'=>http_response_code() ?: 200,'effects'=>$GLOBALS['effects']]));
    });
    $guard = getenv('QUEST_TEST_SOURCE') . '/src/ManagementAccess.php';
    if (is_file($guard)) { require $guard; }
}
PHP;
$checks = 0;
function managementCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($message); }
    ++$checks;
}
try {
    if (!getenv('QUEST_TEST_SKIP_DOCS') && getenv('QUEST_TEST_FOCUS') !== 'csrf') {
        $docs = file_get_contents($root . '/docs/API_CLIENT_SETUP.md');
        foreach (['client_id','client_secret','registration_access_token','registration_client_uri'] as $field) {
            managementCheck(preg_match('/"' . $field . '"\s*:\s*"YOUR_' . strtoupper($field) . '"/', $docs) === 1, 'registration response requires placeholder: ' . $field);
        }
    }
    file_put_contents($temp . '/interface/globals.php', $fixture);
    file_put_contents($module . '/vendor/autoload.php', '<?php');
    foreach (['index.php', 'requestCompendium.php', 'retrieveCompendium.php', 'tokenTest.php'] as $page) {
        copy($root . '/public/' . $page, $module . '/public/' . $page);
    }
    $run = static function (string $page, array $case) use ($module, $root): array {
        $env = array_merge(getenv(), ['QUEST_TEST_SOURCE'=>$root, 'QUEST_MANAGEMENT_CASE'=>json_encode($case)]);
        $process = proc_open([PHP_BINARY, $module . '/public/' . $page], [1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        managementCheck(proc_close($process) === 0, 'entry execution failed: ' . $page . ': ' . $err);
        $meta = json_decode($err, true);
        managementCheck(is_array($meta), 'unexpected diagnostic output: ' . $page);
        return [$out, $meta];
    };
    $valid = ['token'=>'fixture-csrf', 'fileName'=>'TEST_CDC_FULL.zip',
        'retrieveURI'=>'/oauth2/compendium/retrieveCompendium/test',
        'ackURI'=>'/oauth2/compendium/ackCompendium/test'];
    foreach (['index.php','requestCompendium.php','retrieveCompendium.php','tokenTest.php'] as $page) {
        if (getenv('QUEST_TEST_FOCUS') === 'csrf') { continue; }
        [$out,$meta] = $run($page, ['admin'=>false,'method'=>'POST','post'=>$valid + ['status'=>'1']]);
        managementCheck($meta['status'] === 403 && $meta['effects'] === [], 'non-admin reached management service: ' . $page);
        if ($page === 'retrieveCompendium.php') {
            managementCheck(json_decode($out,true)['success'] === false, 'ACL denial JSON contract');
        }
    }
    foreach ([null, '', 'wrong', ['fixture-csrf']] as $token) {
        $post = $valid; $post['token'] = $token;
        [$out,$meta] = $run('retrieveCompendium.php', ['admin'=>true,'method'=>'POST','post'=>$post]);
        managementCheck($meta['status'] === 403 && $meta['effects'] === [] && json_decode($out,true)['success'] === false, 'invalid CSRF reached retrieval');
    }
    [$out,$meta] = $run('retrieveCompendium.php', ['admin'=>true,'no_key'=>true,'method'=>'POST','post'=>$valid]);
    managementCheck($meta['status'] === 403 && $meta['effects'] === [], 'uninitialized session reached retrieval');
    [$out,$meta] = $run('retrieveCompendium.php', ['admin'=>true,'method'=>'GET','post'=>$valid]);
    managementCheck($meta['effects'] === [] && json_decode($out,true)['success'] === false, 'GET reached retrieval');
    [$out,$meta] = $run('retrieveCompendium.php', ['admin'=>true,'method'=>'POST','post'=>$valid]);
    managementCheck(json_decode($out,true)['success'] === true && $meta['effects'][1] === array_values(array_diff_key($valid,['token'=>true])), 'valid import fields changed');
    foreach (['fileName'=>'../TEST_CDC_FULL.zip', 'retrieveURI'=>'https://invalid.example/', 'ackURI'=>'https://invalid.example/'] as $field=>$value) {
        $post = $valid; $post[$field] = $value;
        [$out,$meta] = $run('retrieveCompendium.php', ['admin'=>true,'method'=>'POST','post'=>$post]);
        managementCheck($meta['effects'] === [] && json_decode($out,true)['success'] === false, 'existing input rejection changed: ' . $field);
    }
    $post = $valid; $post['ackURI'] = '';
    [$out,$meta] = $run('retrieveCompendium.php', ['admin'=>true,'method'=>'POST','post'=>$post]);
    managementCheck(json_decode($out,true)['success'] === true && $meta['effects'][1][2] === '', 'optional acknowledgement changed');
    [$out,$meta] = $run('requestCompendium.php', ['admin'=>true,'no_key'=>true]);
    managementCheck($meta['effects'] === ['list'] && str_contains($out,'token:       "fixture-csrf"') && str_contains($out,"dataType: 'json'"), 'request page token/session or AJAX contract');
    [$out,$meta] = $run('index.php', ['admin'=>true,'no_key'=>true]);
    managementCheck(str_contains($out,'name="token" value="fixture-csrf"'), 'index writable CSRF generation');
    [$out,$meta] = $run('index.php', ['admin'=>true,'method'=>'POST','post'=>['status'=>'1','token'=>'fixture-csrf']]);
    managementCheck(in_array('status:1',$meta['effects'],true), 'authorized status toggle failed');
    [$out,$meta] = $run('index.php', ['admin'=>true,'method'=>'POST','post'=>['status'=>'1','token'=>['fixture-csrf']]]);
    managementCheck($meta['status'] === 403 && !in_array('status:1',$meta['effects'],true), 'array CSRF changed status');
    [$out,$meta] = $run('tokenTest.php', ['admin'=>true]);
    managementCheck($meta['effects'] === ['token'] && str_contains($out,'token retrieved successfully'), 'authorized token validation failed');
    echo "PASS: $checks management boundary checks\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($temp);
}
