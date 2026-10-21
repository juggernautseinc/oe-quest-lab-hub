<?php
declare(strict_types=1);

namespace Juggernaut\Quest\Module {
    // Deterministic collision injection; production uses PHP's global random_bytes.
    function random_bytes(int $length): string {
        return $GLOBALS['quest_test_random'] ?? \random_bytes($length);
    }
}

namespace {
    $root = getenv('QUEST_TEST_SOURCE') ?: dirname(__DIR__);
    require $root . '/src/LabelCount.php';
    require $root . '/src/Exceptions/QuestFileSystemException.php';
    require $root . '/src/RequisitionFile.php';
    use Juggernaut\Quest\Module\LabelCount;
    use Juggernaut\Quest\Module\RequisitionFile;
    $checks = 0;
    function check(bool $ok, string $message): void {
        global $checks;
        if (!$ok) { throw new RuntimeException($message); }
        ++$checks;
    }
    foreach (['1', '3', '30', '300'] as $value) {
        check(LabelCount::parse($value) === (int) $value, 'valid count');
    }
    foreach ([null, [], ['3'], 3, '0', '-1', '301', '99999999999999999', '1e2', '3.0', ' 3', '3 ', '+3', "3\n", '03', ''] as $value) {
        try { LabelCount::parse($value); throw new RuntimeException('invalid count accepted'); }
        catch (InvalidArgumentException $e) { ++$checks; }
    }

    $temp = sys_get_temp_dir() . '/quest-boundaries-' . bin2hex(random_bytes(8));
    mkdir($temp, 0700);
    try {
        $files = [];
        foreach (['REQ', 'ABN', 'AOE', 'ABN-REQ', '../../REQ'] as $type) {
            for ($i = 0; $i < 10; ++$i) {
                $bytes = '%PDF-synthetic-' . $type . '-' . $i;
                $name = RequisitionFile::save($temp, $type, $bytes);
                check(basename($name) === $name && str_ends_with($name, '.pdf'), 'basename contract');
                check(!isset($files[$name]), 'unique per generation');
                $files[$name] = $bytes;
                if (str_contains($type, 'ABN')) { check(str_contains($name, 'ABN'), 'ABN marker'); }
                if ($type === 'AOE') { check(str_contains($name, 'AOE'), 'AOE marker'); }
            }
        }
        foreach ($files as $name => $bytes) {
            check(file_get_contents($temp . '/' . $name) === $bytes, 'separate contents retained');
        }
        $GLOBALS['quest_test_random'] = str_repeat('x', 16);
        $name = RequisitionFile::save($temp, 'REQ', 'first');
        try { RequisitionFile::save($temp, 'REQ', 'second'); throw new LogicException('collision overwritten'); }
        catch (RuntimeException $e) { ++$checks; }
        check(file_get_contents($temp . '/' . $name) === 'first', 'collision preserves original');
        unset($GLOBALS['quest_test_random']);
        try { RequisitionFile::save($temp . '/absent', 'REQ', 'bytes'); throw new LogicException('invalid directory accepted'); }
        catch (RuntimeException $e) { ++$checks; }

        // Execute unchanged public scripts in an inert OpenEMR-shaped fixture.
        $module = $temp . '/interface/modules/custom_modules/quest/public';
        mkdir($module, 0700, true);
        copy($root . '/public/globals.php', $module . '/globals.php');
        copy($root . '/public/lab_labels.php', $module . '/lab_labels.php');
        $bootstrap = <<<'PHP'
<?php
require getenv('QUEST_TEST_SOURCE') . '/src/LabelCount.php';
$GLOBALS['webroot'] = '/openemr';
$GLOBALS['chart_label_type'] = '1';
$_SESSION = ['pidList' => [], 'pid' => 1, 'encounter' => 2];
$_GET = json_decode(getenv('QUEST_TEST_QUERY'), true);
function xlt($value) { return htmlspecialchars($value); }
function attr($value) { return htmlspecialchars($value, ENT_QUOTES); }
function sqlQuery($query, $args) {
    return ['fname'=>'Synthetic', 'lname'=>'Patient', 'DOB'=>'2000-01-01', 'pid'=>1,
        'send_fac_id'=>'TEST', 'date_collected'=>'2026-10-20'];
}
class PDF_Label {
    public function __construct($type) { echo 'PDF_START'; }
    public function AddPage() {}
    public function Add_Label($text) { echo 'LABEL'; }
    public function Output() { echo 'PDF_END'; }
}
PHP;
        file_put_contents($temp . '/interface/globals.php', $bootstrap);
        $run = static function (string $page, array $query) use ($module, $root): string {
            $env = array_merge(getenv(), ['QUEST_TEST_SOURCE'=>$root, 'QUEST_TEST_QUERY'=>json_encode($query)]);
            $process = proc_open([PHP_BINARY, $module . '/' . $page], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
            check(proc_close($process) === 0 && $err === '', 'script execution: ' . $err);
            return $out;
        };
        $out = $run('globals.php', []);
        check(str_contains($out, '/interface/super/edit_globals.php'), 'native settings link retained');
        check(!str_contains($out, 'Encrypted value'), 'no secret display');
        // No Bootstrap/secret getter exists in the fixture: accidental retrieval fails execution.
        $out = $run('lab_labels.php', ['count'=>'3']);
        check(substr_count($out, 'LABEL') === 3 && str_contains($out, 'PDF_END'), 'normal three-label print');
        foreach ([[], ['count'=>'301'], ['count'=>['3']], ['count'=>'1e9']] as $query) {
            $out = $run('lab_labels.php', $query);
            check(!str_contains($out, 'PDF_START') && str_contains($out, 'Label count must'), 'invalid request stopped before PDF');
        }
        $source = file_get_contents($root . '/src/ProcessRequisitionDocument.php');
        check(str_contains($source, 'RequisitionFile::save($this->path, $documentType, $pdfBinary)'), 'shared producer uses tested writer');
        echo "PASS: $checks Quest output-boundary checks\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($temp);
    }
}
