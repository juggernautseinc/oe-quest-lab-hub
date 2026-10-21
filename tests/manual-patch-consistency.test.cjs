const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const {mkdtempSync, mkdirSync, copyFileSync, readFileSync, rmSync} = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const test = require('node:test');
const base = path.resolve(__dirname, '../deploy/openemr-8.1.1-dev');
const entries = [
    ['common.php', 'interface/forms/procedure_order/common.php', '01-procedure_order-common.patch'],
    ['receive_hl7_results.inc.php', 'interface/orders/receive_hl7_results.inc.php', '02-receive_hl7_results.patch'],
    ['QuestLabTransmitEvent.php', 'src/Events/Services/QuestLabTransmitEvent.php', '03-QuestLabTransmitEvent.patch'],
    ['gen_hl7_order.inc.php', 'interface/procedure_tools/quest/gen_hl7_order.inc.php', '04-gen_hl7_order-manual-port.patch'],
];
for (const combined of [false, true]) {
    test(`manual ${combined ? 'combined' : 'individual'} patches reproduce packaged files`, t => {
        const dir = mkdtempSync(path.join(os.tmpdir(), 'quest-patch-'));
        t.after(() => rmSync(dir, {recursive: true, force: true}));
        for (const [name, destination] of entries) {
            mkdirSync(path.dirname(path.join(dir, destination)), {recursive: true});
            copyFileSync(path.join(base, 'ORIG_' + name), path.join(dir, destination));
        }
        const patches = combined ? ['ALL-core-openemr-8.1.1-dev-quest.patch'] : entries.map(row => row[2]);
        for (const patch of patches) {
            for (const extra of [['--check'], []]) {
                const result = spawnSync('git', ['apply', ...extra, path.join(base, 'diffs', patch)],
                    {cwd: dir, encoding: 'utf8', timeout: 3000});
                assert.equal(result.status, 0, result.stderr);
            }
        }
        for (const [name, destination] of entries) {
            assert.deepEqual(readFileSync(path.join(dir, destination)), readFileSync(path.join(base, name)), name);
        }
    });
}
