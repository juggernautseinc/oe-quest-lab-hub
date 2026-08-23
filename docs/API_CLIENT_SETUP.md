# Quest Headless API — Client Setup Guide

This guide walks through registering an OAuth2 API client in OpenEMR and obtaining the credentials needed to consume the Quest headless procedure order endpoints.

## Prerequisites

1. **OpenEMR APIs must be enabled** — Go to **Administration → Config → Connectors** and check:
   - ☑ Enable OpenEMR Standard REST API
2. **SSL/TLS must be configured** — OAuth2 requires HTTPS
3. **Site Address must be set** — **Administration → Config → Connectors → Site Address (required for OAuth2)**
   - Example: `https://your-openemr.example.com`

## Step 1: Register the API Client

Send a POST request to the OpenEMR registration endpoint. No authentication is needed for registration itself.

### Option A: Client Secret Authentication (simpler)

```bash
curl -X POST -k -H 'Content-Type: application/json' \
  'https://YOUR_OPENEMR_HOST/oauth2/default/registration' \
  --data '{
    "application_type": "private",
    "redirect_uris": ["https://your-app.example.com/callback"],
    "client_name": "Quest Lab Order Client",
    "token_endpoint_auth_method": "client_secret_post",
    "contacts": ["admin@your-org.com"],
    "scope": "openid offline_access api:oemr api:fhir user/allergy.read"
  }'
```

### Option B: Asymmetric Key Authentication (recommended for backend services)

First, generate an RSA key pair:

```bash
# Generate private key (4096-bit RSA)
openssl genrsa -out quest_client_private.pem 4096

# Extract public key
openssl rsa -in quest_client_private.pem -pubout -out quest_client_public.pem

# Generate a JWKS-formatted public key (requires node/python or manual conversion)
# The key ID (kid) should be a unique identifier you choose
```

Then register with JWKS:

```bash
curl -X POST -k -H 'Content-Type: application/json' \
  'https://YOUR_OPENEMR_HOST/oauth2/default/registration' \
  --data '{
    "application_type": "private",
    "redirect_uris": ["https://your-app.example.com/callback"],
    "client_name": "Quest Lab Order Client",
    "token_endpoint_auth_method": "private_key_jwt",
    "contacts": ["admin@your-org.com"],
    "scope": "openid api:oemr api:fhir system/Patient.rs system/Encounter.rs",
    "jwks": {
      "keys": [
        {
          "kty": "RSA",
          "kid": "quest-client-key-1",
          "use": "sig",
          "alg": "RS384",
          "n": "YOUR_BASE64URL_ENCODED_MODULUS",
          "e": "AQAB"
        }
      ]
    }
  }'
```

### Registration Response

Save the response — you cannot retrieve the `client_secret` later:

```json
{
  "client_id": "LnjqojEEjFYe5j2Jp9m9UnmuxOnMg4VodEJj3yE8_OA",
  "client_secret": "j21ecvLmFi9HPc_Hv0t7Ptmf1pVcZQLtHjIdU7U9tkS9WAjFJwVM...",
  "registration_access_token": "uiDSXx2GNSvYy5n8eW50aGrJz0HjaGpUdrGf07Agv_Q",
  "registration_client_uri": "https://your-host/oauth2/default/client/...",
  "client_name": "Quest Lab Order Client",
  "scope": "openid offline_access api:oemr api:fhir user/allergy.read"
}
```

> **⚠️ Store `client_id` and `client_secret` securely.** The secret is shown only once.

## Step 2: Enable the Client in OpenEMR

An administrator must approve the newly registered client:

1. Log into OpenEMR as an admin
2. Go to **Administration → System → API Clients**
3. Find "Quest Lab Order Client" in the list
4. Click **Enable**

Without this step, token requests will be rejected.

## Step 3: Obtain an Access Token

### Using Client Secret (Option A)

```bash
curl -X POST -k \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  'https://YOUR_OPENEMR_HOST/oauth2/default/token' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'client_id=YOUR_CLIENT_ID' \
  --data-urlencode 'client_secret=YOUR_CLIENT_SECRET' \
  --data-urlencode 'user_role=users' \
  --data-urlencode 'username=YOUR_OPENEMR_USERNAME' \
  --data-urlencode 'password=YOUR_OPENEMR_PASSWORD' \
  --data-urlencode 'scope=openid offline_access api:oemr'
```

> **Note:** The password grant requires **Administration → Config → Connectors → Enable Password Grant** to be checked. For production, use the authorization code flow instead.

### Using Asymmetric Key / Client Credentials (Option B)

Build a signed JWT assertion:

```json
{
  "header": {
    "alg": "RS384",
    "kid": "quest-client-key-1",
    "typ": "JWT"
  },
  "payload": {
    "iss": "YOUR_CLIENT_ID",
    "sub": "YOUR_CLIENT_ID",
    "aud": "https://YOUR_OPENEMR_HOST/oauth2/default/token",
    "exp": 1700000000,
    "iat": 1699999700,
    "jti": "unique-random-id-12345"
  }
}
```

Sign it with your private key (RS384) and request the token:

```bash
curl -X POST -k \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  'https://YOUR_OPENEMR_HOST/oauth2/default/token' \
  --data-urlencode 'grant_type=client_credentials' \
  --data-urlencode 'client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer' \
  --data-urlencode 'client_assertion=YOUR_SIGNED_JWT_HERE' \
  --data-urlencode 'scope=openid api:oemr system/Patient.rs'
```

### Token Response

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def502003b2af66...",
  "scope": "openid offline_access api:oemr"
}
```

> **⚠️ Client credentials tokens are short-lived (60 seconds).** Request a new token for each batch of API calls. The password grant tokens last 3600 seconds and include a refresh token.

## Step 4: Call the Quest API Endpoints

### Submit a Procedure Order

```bash
curl -X POST -k \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  'https://YOUR_OPENEMR_HOST/apis/default/api/quest/order' \
  --data '{
    "pid": 1,
    "encounter_id": 42,
    "provider_id": 3,
    "lab_id": 5,
    "date_ordered": "2026-06-17",
    "date_collected": "2026-06-17 10:30:00",
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
  }'
```

### Retrieve Order Documents

```bash
curl -X GET -k \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  'https://YOUR_OPENEMR_HOST/apis/default/api/quest/order/1234/documents'
```

## Required Scopes

The Quest headless API endpoints require:

| Scope | Purpose |
|-------|---------|
| `openid` | Required for all OAuth2 flows |
| `api:oemr` | Required — grants access to `/api/` standard routes |
| `offline_access` | Optional — enables refresh tokens (password grant only) |

The route handlers enforce `admin/users` ACL. The authenticated user (or service account) must have admin-level access in OpenEMR.

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `401 Unauthorized` | Token expired or missing | Request a new token |
| `403 Forbidden` | Missing `api:oemr` scope | Re-register client with `api:oemr` scope |
| `403 Forbidden` | ACL denied | Ensure the user has `admin/users` permission |
| `400 Bad Request` | Client not enabled | Enable the client in Admin → System → API Clients |
| `422 Validation Error` | Missing required fields | Check the `errors` array in the response body |

## Quick Reference

| Item | Value |
|------|-------|
| Registration endpoint | `POST /oauth2/default/registration` |
| Token endpoint | `POST /oauth2/default/token` |
| Submit order | `POST /apis/default/api/quest/order` |
| Get order documents | `GET /apis/default/api/quest/order/:id/documents` |
| Required scope | `api:oemr` |
| Required ACL | `admin/users` |
