<?php
declare(strict_types=1);

// Execute the bundled parser, never a deployed OpenEMR core or a real database.
namespace OpenEMR\Core {
    class OEGlobalsBag {
        public static function getInstance() { return new self(); }
        public function getSrcDir() { return $GLOBALS['hostFixtures']; }
        public function getString($name) { return $name; }
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
    $hostFixtures = sys_get_temp_dir() . '/quest-parser-' . bin2hex(random_bytes(8));
    mkdir($hostFixtures, 0700);
    file_put_contents($hostFixtures . '/forms.inc.php', '<?php');
    file_put_contents($hostFixtures . '/pnotes.inc.php', '<?php');
    require $root . '/deploy/openemr-8.1.1-dev/receive_hl7_results.inc.php';
    function xl($text) { return $text; }
    function ucname($text) { return $text; }
    function sqlQuery($sql, $args = []) {
        if (str_contains($sql, 'FROM categories')) { return ['id' => 1]; }
        if (str_contains($sql, 'FROM procedure_order_code')) { return ['procedure_order_seq' => 1]; }
        if (str_contains($sql, 'FROM procedure_order ')) {
            $row = $GLOBALS['fixtureOrder'];
            if (!$row) { return false; }
            if (str_contains($sql, 'control_id = ?')) { return $row; }
            if ((int)$args[0] !== 42) { return false; }
            if (str_contains($sql, 'AND lab_id = ?') && (int)$args[1] !== $row['lab_id']) { return false; }
            return $row;
        }
        if (str_contains($sql, 'FROM form_encounter WHERE')) { return ['encounter' => 70, 'provider_id' => 3]; }
        throw new \RuntimeException('Unexpected fixture query: ' . $sql);
    }
    function sqlStatement($sql, $args = []) {
        if (str_contains($sql, 'SELECT pid FROM patient_data')) { return [(object)['pid' => 7]]; }
        $GLOBALS['effects'][] = [$sql, $args];
        return true;
    }
    function sqlNumRows($rows) { return count($rows); }
    function sqlFetchArray(&$rows) { return (array)array_shift($rows); }
    function sqlInsert($sql, $args = []) { $GLOBALS['effects'][] = [$sql, $args]; return 99; }
    function check(bool $ok, string $message): void {
        if (!$ok) { throw new \RuntimeException($message); }
        ++$GLOBALS['checks'];
    }
    function segment(string $type, array $fields): string {
        $all = array_fill(0, 32, ''); $all[0] = $type;
        foreach ($fields as $index => $value) { $all[$index] = $value; }
        return implode('|', $all);
    }
    function message(string $id, int $encounter = 0): string {
        return implode("\r", [
            'MSH|^~\\&|SYNTHETIC|Lab|EHR|Practice|202610210800||ORU^R01|synthetic|P|2.3',
            segment('PID', [3 => '7', 5 => 'Patient^Synthetic', 7 => '20000101', 8 => 'F']),
            segment('PV1', [19 => (string)$encounter]),
            segment('OBR', [2 => $id, 3 => 'synthetic-control', 4 => 'TEST^Synthetic test',
                7 => '20261021', 22 => '20261021', 25 => 'F']),
        ]);
    }
    $checks = 0; $lab_npi = 'SYNTHETIC';
    set_error_handler(static function ($level, $message, $file, $line) {
        throw new \RuntimeException("$message at $file:$line");
    });
    try {
        foreach ([false, true] as $dryrun) {
            foreach (['42', 'STL-0042', 'orc-fallback'] as $id) {
                foreach ([10, 11] as $orderLab) {
                    $fixtureOrder = ['procedure_order_id' => 42, 'lab_id' => $orderLab,
                        'patient_id' => 7, 'encounter_id' => 70, 'control_id' => ''];
                    $effects = []; $match = []; $hl7 = message($id === 'orc-fallback' ? '' : $id);
                    if ($id === 'orc-fallback') {
                        $hl7 = str_replace('OBR|', segment('ORC', [2 => '42']) . "\rOBR|", $hl7);
                    }
                    $result = receive_hl7_results($hl7, $match, 10, 'B', $dryrun);
                    if ($orderLab !== 10) {
                        check(!empty($result['fatal']), 'foreign lab order must be rejected');
                        check($effects === [], 'foreign lab order must cause no writes');
                    } else {
                        check(empty($result['fatal']), 'same lab order remains supported');
                    }
                }
            }
        }
        $fixtureOrder = ['procedure_order_id' => 42, 'lab_id' => 10,
            'patient_id' => 7, 'encounter_id' => 70, 'control_id' => ''];
        $effects = []; $match = []; $hl7 = message('42', 71);
        $result = receive_hl7_results($hl7, $match, 10, 'B', false);
        check(!empty($result['fatal']) && $effects === [], 'mismatched encounter remains denied before writes');
        foreach ([false, true] as $child) {
            $effects = []; $match = []; $hl7 = message('42');
            $fields = [2 => $child ? '999' : '', 3 => 'synthetic-second',
                4 => 'SECOND^Synthetic follow-up', 7 => '20261021', 22 => '20261021', 25 => 'F'];
            if ($child) { $fields[29] = 'parent^synthetic-control'; $fields[26] = 'TEST^1'; }
            $hl7 .= "\r" . segment('OBR', $fields);
            $result = receive_hl7_results($hl7, $match, 10, 'B', false);
            check(empty($result['fatal']), $child ? 'same-lab parent-selected order remains supported' : 'same-lab cached order remains supported');
        }
        foreach ([false, true] as $dryrun) {
            $effects = []; $match = []; $hl7 = message('42');
            $result = receive_hl7_results($hl7, $match, 10, 'R', $dryrun);
            check(empty($result['fatal']), 'results-only matching existing same-lab order remains supported');
        }
        $fixtureOrder = false;
        $effects = []; $match = []; $hl7 = message('42');
        $result = receive_hl7_results($hl7, $match, 10, 'R', true);
        check(empty($result['fatal']) && $effects === [], 'results-only dryrun without order remains supported');
        $effects = []; $match = []; $hl7 = message('42');
        $result = receive_hl7_results($hl7, $match, 0, 'B', false);
        check(!empty($result['fatal']) && $effects === [], 'unselected provider fails before writes');
        echo "manual parser boundary: $checks checks passed\n";
    } finally {
        restore_error_handler();
        unlink($hostFixtures . '/forms.inc.php');
        unlink($hostFixtures . '/pnotes.inc.php');
        rmdir($hostFixtures);
    }
}
