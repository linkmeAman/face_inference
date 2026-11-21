# API Compatibility and Deprecation Policy

This document defines the prospective API compatibility, versioning, and deprecation policy for `face-inference`. It governs the HTTP REST contract (`/api/v1`), client libraries (`face_inference.client` and web `FaceClient`), and how changes are managed across releases.

Source citations:
- Route and contract definition: `docs/contract.md:8-27`, `docs/openapi.yaml:1-296`
- Route dispatcher, health endpoint, and response envelope: `src/face_inference/server.py:123-128,157-177,179-180,192-268`
- Package version declarations: `src/face_inference/__init__.py:1`, `packages/face-capture/package.json:3`
- Client implementations: `src/face_inference/client.py:18-117,205-265,329-331`, `src/face_inference/web/client.mjs:1-42`
- Compatibility test suite: `tests/test_openapi.py:1-296`

---

## 1. Versioning Hierarchy

The project distinguishes three separate versioning tracks to prevent coupling package distributions to the wire protocol:

1. **REST API Major Version (`/api/v1`)**:
   - Identifies the HTTP wire protocol, route schemas, request/response formats, error envelopes, and authentication mechanics.
   - Expressed directly in endpoint URL paths (`/api/v1/...`) and discovered via `GET /health` (`api_version: "1"`, `src/face_inference/server.py:197-199`).
   - Only changes when breaking wire-level compatibility.

2. **Python Package Release Version (`face-inference`)**:
   - Declared at `src/face_inference/__init__.py:1` (`__version__ = "0.1.0"`).
   - Governs the Python package distribution, CLI commands (`face-inference run`, `face-inference export`, etc.), local daemon implementation, and Python SDK (`face_inference.client.FaceClient`).
   - Follows standard package semantic versioning (Major.Minor.Patch).

3. **Frontend Web Component Release Version (`face-capture`)**:
   - Declared at `packages/face-capture/package.json:3` (`"version": "0.1.0"`).
   - Governs the embeddable `<face-capture>` custom element and npm ES module bundle.
   - Follows npm package semantic versioning (Major.Minor.Patch).

> [!NOTE]
> Bumping the Python package or npm package version (e.g., from `0.1.0` to `0.2.0` or `1.0.0`) does **not** change the REST API major version. The `/api/v1` route prefix remains unchanged as long as wire-level backward compatibility is maintained.

In addition, machine learning models (`model_version`) and preprocessing pipelines (`preprocessing_version`) are versioned strings tracked in configuration (`src/face_inference/web/browser-face/contract.json:2-3`, `src/face_inference/service.py:16-24`) and validated on enrollment/recognition requests (`docs/contract.md:28-31`). They represent biometric algorithm parity rather than HTTP protocol versioning.

---

## 2. API v1 Stability Guarantees

All endpoints rooted at `/api/v1/` maintain stable semantics for documented request parameters, response structures, and status codes.

### Response Envelopes
The server emits a consistent top-level JSON response envelope for all requests (`src/face_inference/server.py:123-128`, `docs/contract.md:13-14`):

- **Success Envelope** (`HTTP 200`):
  ```json
  {
    "error": 0,
    "error_msg": "",
    "message": "Human-readable confirmation",
    "code": "",
    "data": { ... }
  }
  ```
- **Error Envelope** (`HTTP 4xx / 5xx`):
  ```json
  {
    "error": 1,
    "error_msg": "Human-readable explanation",
    "message": "Human-readable explanation",
    "code": "MACHINE_READABLE_CODE",
    "data": {}
  }
  ```

### Documented Status Codes and Error Codes
The API preserves documented HTTP status codes and machine-readable `code` values for documented conditions:
- `400 Bad Request`: `INVALID_JSON`, `INVALID_BODY`, `MODEL_MISMATCH`, `AGGREGATE_MISMATCH`, `CONSENT_REQUIRED`, `INVALID_ID`, `INVALID_NAME`, `INVALID_METADATA`, `INVALID_OBSERVATIONS`, `INVALID_VECTOR`, `UNSTABLE_CAPTURE`, `UNEXPECTED_FIELD`.
- `401 Unauthorized`: `AUTH_REQUIRED`, `TOKEN_INVALID`, `TOKEN_EXPIRED`.
- `403 Forbidden`: `FORBIDDEN`, `TOKEN_SCOPE` (token lacking permission for requested action), `ORIGIN_REJECTED` (unauthorized CORS origin).
- `404 Not Found`: `NOT_FOUND` (unknown route), `COLLECTION_NOT_FOUND`, `PERSON_NOT_FOUND`.
- `409 Conflict`: `COLLECTION_EXISTS`, `IDEMPOTENCY_CONFLICT`, `CAPTURE_REUSED`, `INCOMPATIBLE_GALLERY`, `INDEPENDENT_VISIT_REQUIRED`.
- `413 Payload Too Large`: `BODY_LIMIT` (request body exceeds 128 KiB limit; `src/face_inference/server.py:179-180`).
- `415 Unsupported Media Type`: `INVALID_CONTENT_TYPE` (Content-Type is not application/json; `src/face_inference/server.py:173-174`).
- `429 Too Many Requests`: `RATE_LIMIT` (sliding window rate limit exceeded; `src/face_inference/server.py:128`).

Existing error codes and status mappings are not altered or reassigned within `/api/v1`.

---

## 3. Backward Compatibility Rules

### Compatible (Additive) Changes in `/api/v1`
The following modifications are considered backward-compatible and may be introduced within `/api/v1` without a major version increment:

- **New routes**: Adding new endpoints under `/api/v1/` (e.g., a new subresource or management action).
- **New optional request fields**: Adding optional fields to request bodies, provided the endpoint functions identically when the field is omitted.
- **New optional query parameters or headers**: Adding optional request query parameters or HTTP headers.
- **New response fields**: Adding new keys inside the `data` object of success responses.
- **New error codes**: Adding distinct, granular `code` values for newly added features or failure scenarios, provided existing failure scenarios keep their existing codes.

### Incompatible (Breaking) Changes Requiring `/api/v2`
Any change that would break compliant `/api/v1` clients requires a new API major version (e.g., `/api/v2`):

- **Route removal or renaming**: Deleting an existing route or altering its path pattern.
- **Field removal or renaming**: Deleting or renaming existing fields in request bodies or response `data` payloads.
- **New required request parameters**: Making a previously optional field required, or introducing a mandatory field without a backward-compatible default.
- **Changed field semantics or types**: Altering the expected data type, structural format (e.g., vector dimension changes from 128-d), or interpretation of an existing parameter.
- **Envelope changes**: Modifying the top-level response envelope structure (`error`, `error_msg`, `message`, `code`, `data`).
- **Altered status codes**: Changing the HTTP status code or machine-readable `code` returned for an existing, documented condition.

---

## 4. Client Robustness Principles

To maintain resilience as the API evolves additively, client applications and SDKs must adhere to the following principles:

1. **Tolerant Reader (Ignore Unknown Fields)**:
   - Clients must ignore unrecognized fields in JSON response bodies.
   - In Python, `face_inference.client` declares model types using `TypedDict(..., total=False)` (`src/face_inference/client.py:18-117`), which discards or tolerates unmodeled response keys.
   - In JavaScript, `FaceClient.decode()` extracts `body.data` directly and tolerates additive envelope or data properties (`src/face_inference/web/client.mjs:13-17`).

2. **Strict Writer (Avoid Relying on Undocumented Features)**:
   - Clients must not rely on undocumented server behaviors, private attributes, incidental HTTP headers, or undocumented payload keys.
   - Payloads sent by clients must only include fields declared in `docs/contract.md` and `docs/openapi.yaml`.

3. **Machine-Readable Error Handling**:
   - Clients must branch on the machine-readable `code` field and HTTP status code rather than matching substrings in human-readable `message` or `error_msg` strings (`src/face_inference/client.py:127-139`). Human-readable messages may be clarified or refined across releases.

---

## 5. Deprecation Policy and Procedure

When an API route, parameter, or behavior is slated for retirement:

1. **Public Announcement in Release Notes**:
   - Deprecations will be documented prominently in `CHANGELOG.md` and in GitHub Release notes at the time the deprecation is initiated.
2. **Migration Guidance**:
   - Every deprecation announcement must be accompanied by explicit migration instructions, specifying recommended replacement endpoints, updated schemas, and code examples.
3. **Deprecation Overlap Period**:
   - Deprecated capabilities will remain functional alongside their designated replacements for an announced overlap period to allow consumers to transition safely before removal in a subsequent major release.
4. **No Fixed Calendar Support Guarantee**:
   - The project does **not** promise a fixed calendar support period (e.g., an arbitrary 12-month or 3-year support window). Deprecation timelines and overlap windows are announced with each specific deprecation in accordance with project release notes and maintainer planning.

---

## 6. Compatibility Verification and Health Discovery

### Runtime Health Discovery
Clients and proxies can programmatically discover service health and API version compatibility before making authenticated requests.

- **Endpoint**: `GET /health` (`src/face_inference/server.py:197-199`)
- **Authentication**: None (publicly accessible)
- **Response**:
  ```json
  {
    "status": "ok",
    "api_version": "1",
    "storage": "sqlite"
  }
  ```
- **Client Usage**:
  - The Python client provides `client.health()` which queries `/health` and returns `HealthResponse` (`src/face_inference/client.py:18-22,329-331`).
  - Monitoring tools and upstream gateways verify `status == "ok"` and `api_version == "1"` during startup and routine health probes.

### Automated CI and Developer Gates
API compatibility is continuously verified through automated test suites:

1. **OpenAPI Specification Conformance (`tests/test_openapi.py`)**:
   - Asserts that every route dispatched in `src/face_inference/server.py` is present in `docs/openapi.yaml` and vice-versa (`test_server_routes_extracted`, `test_openapi_routes_match_server`).
   - Asserts that every operation documents an `x-source` citation referencing real file and line numbers (`test_openapi_operations_have_source_citations`).
   - Verifies the HTTP Bearer authentication scheme is configured (`test_openapi_security_scheme`).
   - Validates that the top-level error envelope schema and standard status codes (`401`, `403`, `413`, `429`) match server responses (`test_openapi_error_envelope_and_status_codes`).
2. **Client and Server Regression Suites**:
   - Python client unit tests (`tests/test_client.py`) verify retry policies, idempotency key generation, and error mapping.
   - Server endpoint tests (`tests/test_server.py`) test authentication, input validation, and route responses.
   - JavaScript client tests (`tests/js/test_client.mjs`, `packages/face-capture/test/*.test.mjs`) verify browser client decoding and communication.
3. **Static Analysis**:
   - Code formatting and linting are enforced via Ruff (`.venv/bin/ruff check src tests`).
