# Management boundary regression

The management pages `index.php`, `requestCompendium.php`,
`retrieveCompendium.php`, and `tokenTest.php` share an `admin/users` ACL guard
before management service construction. Retrieval also requires POST and a
string token verified against the native active OpenEMR session before download,
import, or acknowledgement. Token-producing pages request a writable session
before loading globals and use the existing native CSRF initialization pattern.
The AJAX request retains its original fields and JSON response contract.

`management-boundaries.php` runs the actual public entry points in an inert
OpenEMR-shaped fixture. It tests denied users, null/empty/invalid/array tokens,
uninitialized sessions, GET rejection, authorized status toggles, compendium
listing/import and optional acknowledgements, existing URI/filename rejection,
and configuration validation. It requires explicit `YOUR_*` placeholders for all
registration-response credential fields. Fixtures perform no real database,
vendor, or credential operations.

Run from the module root:

```sh
php tests/management-boundaries.php
php tests/output-boundaries.php
php tests/requisition-producer.php
```

The equivalent new Composer script is `composer test:management-boundaries`.
Direct scripts were used because Composer is unavailable in this environment.
PHP syntax checks for all four changed routes, the shared guard and the new test,
plus `git diff --check`, passed.

Fail-before proof uses an inert source snapshot from `git archive HEAD` with
`QUEST_TEST_SOURCE` pointing to that snapshot. The default regression fails on
the original registration response's non-placeholder credential fields. Setting
`QUEST_TEST_SKIP_DOCS=1` fails with `non-admin reached management service:
index.php`. Setting `QUEST_TEST_FOCUS=csrf` fails with `invalid CSRF reached
retrieval`. All checks pass against the repaired source.

Label/requisition regressions also pass. Clinical order paths, results background
entry points, REST routes and bundled/deployed core files are outside this patch.
Native session/CSRF signatures and the writable-session flag were checked by the
parent against the host source; fixture tests do not replace live integration
testing. No vendor or production database validation was attempted. The parent
owns the fresh candidate review and any deployment decision.

The one fresh read-only candidate review found no concrete bypass. Parent then
checked native CsrfUtils source: verification calls collectCsrfToken(), which
throws if the session has no key. The fixture now models that exact behavior;
it reproduced the candidate's uninitialized-session failure. Retrieval explicitly
rejects an absent key before calling native verification, preserving JSON 403
instead of an exception response. All 65 checks pass with this corrected model.
No second review cycle or broader session redesign was introduced.
