# Quest Lab Hub — Standard Deploy Package (OpenEMR 8.1.1-dev)

**Package version:** module `oe-quest-lab-hub` **2.1.1**  
**Validated on:** New Century Labs (`openemr.newcl.us`), OpenEMR 8.1.1-dev  
**Goal:** Deploy without re-fixing the same issues every client.

This directory is the **source of truth** for the next implementation.

---

## What is in this folder

| Path | Purpose |
|------|---------|
| `oe-quest-lab-hub/` | **Full module** to deploy (v2.1.1) — includes all module fixes from NCL |
| `core-patches/openemr-8.1.1-dev/diffs/` | Core OpenEMR patches proven on 8.1.1-dev |
| `core-patches/openemr-8.1.1-dev/*.php` | Full fixed core file copies (fallback if patch fails) |
| `core-patches/openemr-8.1.1-dev/ORIG_*.php` | Pre-change baselines from NCL |
| `0001`…`0005` `*.patch` | **Legacy** patches — do **not** use as primary path on 8.1.1 (see below) |

### Core patches (use these)

Apply from OpenEMR webroot with `patch -p1` or `git apply`:

1. `core-patches/openemr-8.1.1-dev/diffs/01-procedure_order-common.patch`
2. `core-patches/openemr-8.1.1-dev/diffs/02-receive_hl7_results.patch`
3. `core-patches/openemr-8.1.1-dev/diffs/03-QuestLabTransmitEvent.patch`
4. `core-patches/openemr-8.1.1-dev/diffs/04-gen_hl7_order-manual-port.patch`

Or all at once:

- `core-patches/openemr-8.1.1-dev/diffs/ALL-core-openemr-8.1.1-dev-quest.patch`

**Dry-run first:**

```bash
cd /path/to/openemr
git apply --check core-patches/.../ALL-core-openemr-8.1.1-dev-quest.patch
# or
patch -p1 --dry-run < core-patches/.../ALL-core-openemr-8.1.1-dev-quest.patch
```

If `04-gen_hl7_order` fails on a newer tree, copy the full file:

- From: `core-patches/openemr-8.1.1-dev/gen_hl7_order.inc.php`
- To: `interface/procedure_tools/quest/gen_hl7_order.inc.php`

### Legacy patches (do not rely on alone)

| Patch | Notes |
|-------|--------|
| `0001-...v8.patch` | Bundles old module + core; **gen_hl7 hunk fails** on 8.1.1-dev; module half is outdated vs 2.1.1 |
| `0002`–`0005` | Compendium fixes — **already baked into** `oe-quest-lab-hub` 2.1.1 |

---

## Fixes included in module 2.1.1 (do not regress)

These were production issues on NCL. All must stay in the module tree.

1. **OE_SITE_DIR paths (not `$_SESSION['site_id']`)**  
   Empty session site → `sites//documents/temp` and compendium download 500.  
   Files: `QuestGetCommon.php`, `ImportCompendiumData.php`, `DirectoryCheckCreate.php`, `Bootstrap.php`, `SendAcknowledgement.php`

2. **CSRF API for OpenEMR 8.1**  
   `CsrfUtils::collectCsrfToken($session)` + `setupCsrfKey` if missing.  
   File: `public/index.php`  
   Symptom if missing: HTTP 500 on module home.

3. **No CDN jQuery/Bootstrap on module UI**  
   `Header::setupHeader()` already loads OE Bootstrap 4; second CDN stack breaks tabs.  
   File: `public/index.php`  
   Symptom: Compendium tab click does nothing (or 500 if CSRF also broken).

4. **Idempotent Globals “Quest Lab” section**  
   `createSection` only if missing (`getGlobalsMetadata`).  
   File: `src/Bootstrap.php`  
   Symptom: Admin → Config/Globals HTTP 500 “Section already exists”.

5. **ABN + REQ + AOE document request**  
   `ProcessRequisitionDocument` + `getOrderId()` on transmit event.  
   Requires core event + `common.php` dispatch with `$formid`.

6. **Compendium BU filenames / INSERT IGNORE**  
   From former 0002–0005 (already in tree).

7. **Services tab background toggle**  
   When inactive, UI must show **Enable** (posts `status=1`), not red **Disable**.  
   `BackgroundServices` uses `QueryUtils` UPDATE and sets `next_run = NOW()` on enable.  
   Files: `src/BackgroundServices.php`, `public/index.php`.

---

## Core OpenEMR changes (required for ABN + HL7)

| File | Change |
|------|--------|
| `interface/forms/procedure_order/common.php` | Skip ABN block for Quest; pass `$formid` into `QuestLabTransmitEvent` |
| `interface/orders/receive_hl7_results.inc.php` | Parse `FACILITY-ORDERID` placer ids |
| `src/Events/Services/QuestLabTransmitEvent.php` | Optional `$orderId` / `getOrderId()` |
| `interface/procedure_tools/quest/gen_hl7_order.inc.php` | `hl7AbnStatus`, ORC-per-OBR, PSC, NPI, NTE; AOE OBX-3 `^^^seq^question_text`; keep shared `hl7Phone` |

Do **not** redefine global `hl7Phone` in the quest gen file (lives in `library/global_functions.inc.php` on 8.1).

### Quest AOE OBX-3 format (required)
Wrong (internal question_code only):
```text
OBX|1|ST|7500000395||URINE
```
Correct (analytic id from `procedure_questions.seq` + question text):
```text
OBX|1|ST|^^^75400658^SOURCE||URINE
```
`seq` is loaded from AOE file field 4 during compendium import; `question_code` is the internal key used to join answers.

---

## Standard deploy steps (every client)

### A. Backup

```bash
# module + 4 core files + note OpenEMR version
```

### B. Module

1. Remove any leftover dirs like `oe-quest-lab-hub.pre_*` from `custom_modules` (they register as second active modules and **duplicate menu items**).
2. Deploy `oe-quest-lab-hub/` → `interface/modules/custom_modules/oe-quest-lab-hub/`.
3. Ownership: match other custom modules / web user (**on NCL Docker: uid 1000 `apache`, not `www-data`**).
4. Register / enable in Modules UI if needed.
5. Confirm `version.php` shows **2.1.1**.

### C. Core

1. Dry-run `ALL-core-openemr-8.1.1-dev-quest.patch` from OpenEMR root.
2. Apply; if gen_hl7 fails, install full `gen_hl7_order.inc.php` from this package.
3. PHP lint the four core files.

### D. Config (UI / DB)

1. **Admin → Config → Quest Lab** (must open without 500):
   - Client ID / secret  
   - Enable menu  
   - **Download requisition** ON for ABN/REQ PDFs  
   - Production flag when certified  
2. **Procedure provider “Quest”** (Local Filesystem):
   - Prefer paths **inside the container / already mounted volume**, e.g.:
     - Orders: `{OE_SITE_DIR}/documents/quest_lab/outbound`
     - Results: `{OE_SITE_DIR}/documents/quest_lab/inbound`
   - On NCL Docker absolute paths that work without compose restart:
     - `/var/www/localhost/htdocs/openemr/sites/default/documents/quest_lab/outbound`
     - `/var/www/localhost/htdocs/openemr/sites/default/documents/quest_lab/inbound`
   - `documents/` is Apache deny-all (not public web).
   - **Do not** use `/var/www/quest/...` unless that path is bind-mounted into the container (compose change = planned maintenance).
3. Web user is **not always `www-data`**. On OpenEMR flex Docker it is often **`apache` (1000:101)**.

### E. Verification checklist

- [ ] Module menu appears **once**
- [ ] Module home loads (no 500); tabs switch (Home / Services / Compendium / Settings)
- [ ] Admin → Globals opens; **Quest Lab** section present
- [ ] Compendium request + Import Data succeeds (zip under `documents/temp`, no `sites//`)
- [ ] Transmit Quest order, billing T, ABN required/signed → ABN PDF returned
- [ ] Order text file appears in configured **Orders Path**
- [ ] HL7 ORC.20 reflects ABN status; results match `FACILITY-####` if used

---

## Docker / filesystem notes (from NCL)

- Recreating OpenEMR compose mid-session can take the site down — plan volume mounts.
- Prefer **sites volume** paths for FS protocol unless a dedicated non-web mount (e.g. `/opt/quest_lab` → `/quest`) is added in a maintenance window.
- Host owner for container-written dirs: match container PHP user (**1000:101** on NCL), not `www-data` if that user does not exist.

---

## Explicit non-actions

- Do not deploy full legacy `0001` as the only step on 8.1.1.
- Do not leave `*.pre_v210_replace` (or similar) under `custom_modules`.
- Do not load extra Bootstrap/jQuery CDN on module pages.
- Do not build site paths from bare `$_SESSION['site_id']` on OE 8.1+.

---

## Change log (2.1.1 vs prior 2.1.0 package)

- OE_SITE_DIR path fixes (compendium + labs + ack)
- index.php CSRF 8.1 + tab fix (no CDN)
- Bootstrap Globals section idempotent
- Core patch set captured from working NCL 8.1.1-dev tree
- This deploy standard document
