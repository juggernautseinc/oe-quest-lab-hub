# Quest Quantum Hub Lab Module for OpenEMR

The Quest Quantum Hub module will allow for a bi-directional or HL7 results-only interface
with Quest Quantum Hub.
The module is a seamless integration with the existing interface.
The current interface will be used as usual. The module talks with Quest through a
series of API calls to their hub. The Quest Hub will auto-generate a requisition
form for each order. The requisition feature can be disabled in the config if not desired.
PDF results will be retrieved manually through the Quest user portal.

# Getting Started
The use of this module does require that Quest is contacted before enabling this module. Quest will
contact our office to schedule the turn-up of this module.

# Installation

Using composer:
Contact us for a access token.
Open interface/modules/custom_modules and run this command:

     git clone https://github.com/juggernautseinc/oe-quest-lab-hub.git

You should be prompted for a password. Use the access token provided by us.


# Headless Procedure Order API

The module exposes two REST API endpoints for headless (non-browser) procedure order submission and document retrieval. These endpoints are registered via the OpenEMR `RestApiCreateEvent` hook and inherit OAuth2 Bearer token authentication automatically.

## Authentication

All endpoints require an OAuth2 Bearer token with the `api:oemr` scope. Use the same token your headless application already uses for FHIR/standard API calls.

```
Authorization: Bearer <access_token>
Content-Type: application/json
```

## Endpoints

### POST /api/quest/order

Create a new procedure order, transmit it to Quest Diagnostics, and receive the requisition PDF back in a single request.

**Request body:**

```json
{
  "pid": 1,
  "encounter_id": 42,
  "provider_id": 3,
  "lab_id": 5,
  "date_ordered": "2026-06-09",
  "date_collected": "2026-06-09 10:30:00",
  "order_priority": "normal",
  "order_status": "pending",
  "billing_type": "T",
  "specimen_fasting": "yes",
  "order_psc": "0",
  "order_abn": "not_required",
  "clinical_hx": "Patient reports fatigue",
  "patient_instructions": "Fast 12 hours",
  "order_diagnosis": "ICD10:R53.83",
  "procedure_type_names": "laboratory_test",
  "procedure_codes": [
    {
      "procedure_type_id": 147,
      "diagnoses": "ICD10:R53.83",
      "procedure_order_title": "laboratory_test",
      "transport": ""
    }
  ]
}
```

**Required fields:** `pid`, `encounter_id`, `provider_id`, `lab_id`, `billing_type`, `order_diagnosis`, `procedure_codes`. Either `date_collected` or `order_psc` (PSC Hold) must be provided.

**Success response (200):**

```json
{
  "status": "transmitted",
  "order_id": 1234,
  "document_id": 567,
  "requisition_pdf": "<base64-encoded PDF>",
  "requisition_filename": "quest_REQ_2026-06-09-103000_order_1234.pdf",
  "errors": []
}
```

**Validation error (422):**

```json
{
  "status": "validation_error",
  "order_id": null,
  "document_id": null,
  "requisition_pdf": null,
  "errors": ["Ordering Provider is required", "At least one diagnosis is required"]
}
```

Other error statuses: `hl7_error`, `quest_error`, `error` (HTTP 500).

### GET /api/quest/order/:orderId/documents

Retrieve all documents (requisitions, ABNs, AOEs) linked to a procedure order.

**Response (200):**

```json
{
  "order_id": 1234,
  "documents": [
    {
      "document_id": 567,
      "type": "REQ",
      "filename": "quest_REQ_2026-06-09-103000_order_1234.pdf",
      "created_at": "2026-06-09 10:30:00",
      "pdf_base64": "<base64-encoded PDF>"
    }
  ]
}
```

Documents are also accessible via the standard OpenEMR document retrieval endpoint and the FHIR `DocumentReference` API.

## Architecture

The headless pipeline:

1. **Validate** — Required field checks (same rules as the UI form)
2. **Save** — Insert into `procedure_order` and `procedure_order_code` tables
3. **Generate HL7** — Quest-specific HL7 via `gen_hl7_order()`
4. **Transmit** — Send to Quest Hub API via `ProcessLabOrder`
5. **Fetch requisition** — Download PDF from Quest via `ProcessRequisitionDocument`
6. **Store document** — Save PDF in OpenEMR `documents` table (linked to patient and order)
7. **Return** — JSON response with order ID, document ID, and base64 PDF

Key classes:

- `src/RestControllers/QuestOrderRestController.php` — REST controller
- `src/Services/HeadlessOrderService.php` — Full order pipeline
- `src/Services/QuestDocumentService.php` — PDF storage in `documents` table
- `src/Services/HeadlessOrderResult.php` — Response DTO
- `src/Exceptions/QuestOrderValidationException.php` — Validation errors

Routes are registered in `src/Bootstrap.php` via `RestApiCreateEvent::EVENT_HANDLE`.

# Test Suite

The module includes a PHPUnit test suite covering validation logic, DTO serialization, exception behavior, and controller HTTP status mapping.

## Running Tests

From the project root:

```bash
vendor/bin/phpunit --configuration interface/modules/custom_modules/oe-quest-lab-hub/tests/phpunit.xml
```

The test suite uses a lightweight bootstrap that loads only the autoloaders (no database required). Tests that need a database connection are automatically skipped in this mode.

To run the full suite including database-dependent tests, use the main OpenEMR test bootstrap:

```bash
vendor/bin/phpunit --bootstrap tests/bootstrap.php interface/modules/custom_modules/oe-quest-lab-hub/tests/Unit
```

## Test Coverage

**39 tests, 118 assertions** across 4 test classes:

- **HeadlessOrderResultTest** (10 tests) — Constructor defaults, getter/setter pairs, `addError` accumulation, `toArray` serialization for success and error responses, zero-orderId null handling, JSON serializability
- **QuestOrderValidationExceptionTest** (6 tests) — Error storage/retrieval, message formatting, single/empty errors, exception chaining
- **HeadlessOrderServiceValidationTest** (13 tests) — Each required field tested individually, empty/non-array procedure codes, PSC bypasses collection date requirement, targeted error reporting, no PDF data on failure, API contract structure
- **QuestOrderRestControllerTest** (10 tests) — HTTP status mapping (200/422/500), response body key structure, service delegation, document retrieval 404/200

4 tests require a database connection and are cleanly skipped in the lightweight bootstrap.

# Contributing
If you want to contribute to this module, please get in touch with me at sherwingaddis@gmail.com.

