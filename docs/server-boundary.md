# Server boundary

This document describes the current `face_inference` server trust boundary as implemented in the integration tree. It is a documentation-only feasibility/boundary note; it does not change production behavior.

## Architecture and data flow

- The browser captures camera frames, runs YuNet detection and SFace embedding locally with onnxruntime-web/WASM, and sends embedding observations/templates to the Python server; the server does not perform model inference on uploaded images (`docs/architecture.md:3-8`, `src/face_inference/web/app.mjs:50-62`, `src/face_inference/web/browser-face/worker.mjs:1-55`).
- The server is a stdlib `ThreadingHTTPServer` with explicit JSON API routes and an allowlisted static asset map (`src/face_inference/server.py:44-64`, `src/face_inference/server.py:257-267`). Static serving refuses symlinks and only serves selected top-level web files plus `/browser-face/*` and `/vendor/browser-face/*` assets (`src/face_inference/server.py:52-63`, `src/face_inference/server.py:257-267`).
- API mutations go through `FaceService`; SQLite persistence and Fernet encryption/decryption are owned by `SQLiteRepository` (`src/face_inference/service.py:25-32`, `src/face_inference/storage.py:10-63`).

## Trust boundary

| Boundary | Trusted role | Untrusted input | Current control |
| --- | --- | --- | --- |
| Browser client | Runs camera/UI/inference locally | User/device/browser environment | Camera access is browser-gated; UI keeps token in memory, not localStorage/cookies (`docs/guide.md:54-55`, `src/face_inference/web/client.mjs:3-12`). |
| HTTP API | Server validates requests | JSON bodies, headers, origins, tokens, embeddings | Bearer admin token or scoped session token, exact-origin checks for browser `Origin` requests, 128 KiB JSON limit, finite JSON parsing, duplicate-field rejection, bounded routes (`src/face_inference/server.py:20`, `src/face_inference/server.py:133-190`, `src/face_inference/server.py:192-256`). |
| Embedding payload | Server treats embeddings as data to validate | Client-computed observations/template | Service requires 3-5 finite 128-d unit vectors, checks model/preprocessing contract, recomputes aggregate, rejects mismatched supplied template (`src/face_inference/service.py:155-159`, `src/face_inference/service.py:183-190`, `src/face_inference/matching.py:12-60`, `docs/contract.md:27`). |
| Storage | Server process with configured key | Stored records and file permissions | SQLite DB created with `0600`, data dir `0700`, Fernet-encrypted templates/observations, key-check prevents wrong-key reopen, WAL/foreign keys/busy timeout (`src/face_inference/storage.py:10-54`, `src/face_inference/storage.py:56-63`, `src/face_inference/schema.sql:1-29`). |

## Loopback default and listener configuration

- Default config binds `127.0.0.1:6122` (`src/face_inference/config.py:50-56`, `docs/contract.md:69`). Host/port can be overridden per run by CLI/env/config precedence; overrides are not saved (`src/face_inference/config.py:83-89`, `docs/guide.md:65-69`).
- Listener host must be an IP literal or hostname form accepted by config validation (`src/face_inference/config.py:110-120`). Invalid listener/origin values fail startup with an error (`docs/guide.md:97-98`).
- A non-loopback listener is allowed only as an explicit configuration choice; guide/docker docs warn that remote camera access needs HTTPS/TLS in front because browsers require secure contexts outside localhost/loopback (`docs/guide.md:97-99`, `docs/docker.md:145-149`, `compose.yaml:15-25`).

## Authentication and authorization

- Every `/api/v1/*` request requires authorization except `/health` (`docs/contract.md:5-7`, `src/face_inference/server.py:194-204`). Admin bearer token is compared with constant-time HMAC compare (`src/face_inference/server.py:141-147`).
- Scoped session tokens are HMAC-signed, short-lived (1-3600s, default 300), and limited to `enroll` or `recognize` for a specific collection; optional subject binds enrollment/recognition scope (`src/face_inference/tokens.py:45-74`, `src/face_inference/tokens.py:77-116`, `docs/contract.md:10`, `docs/guide.md:123-131`).
- Session tokens cannot mint tokens or access other routes; server rejects non-enrollment/recognition session-token routes (`src/face_inference/server.py:149-159`, `docs/contract.md:85`).
- Collections are namespaces, not per-tenant authorization. One admin API token grants access to all collections; deployments needing tenant isolation must enforce it in an application adapter (`docs/contract.md:33-36`, `docs/guide.md:21-24`).

## TLS proxy responsibility

- The Python server itself does not terminate TLS. It serves HTTP and relies on a deployment boundary for transport security when exposed beyond loopback.
- For remote/browser camera use, docs require a secure context (HTTPS) or localhost/127.0.0.1; reverse proxies must forward UI/API/assets together and the proxy origin must be explicitly allowlisted (`docs/guide.md:111-121`, `docs/docker.md:145-176`, `compose.yaml:15-25`).
- `allowed_origins` is an exact-origin allowlist for browser CORS/origin checks; HTTPS origins are accepted generally, HTTP origins only for localhost/127.0.0.1/[::1] (`src/face_inference/config.py:139-154`, `src/face_inference/server.py:112-123`, `src/face_inference/server.py:161-172`).
- Behind trusted proxies, client IP for rate limiting is resolved from `X-Forwarded-For` only when the peer is in `trusted_proxies`; invalid forwarded chains fall back to peer (`src/face_inference/ratelimit.py:93-152`, `src/face_inference/server.py:135-140`, `src/face_inference/config.py:96-109`).

## Client-controlled embeddings and server validation

- The client controls capture quality selection and sends embeddings; it does not upload raw camera frames. The server has no image upload route and accepts JSON only (`docs/architecture.md:3-8`, `src/face_inference/server.py:174-190`, `src/face_inference/web/app.mjs:50-62`).
- Server-side validation is mandatory: payload shape, model/preprocessing version compatibility, 3-5 observations, finite 128-d unit vectors, aggregate recomputation, and template/aggregate consistency checks (`src/face_inference/service.py:155-159`, `src/face_inference/service.py:183-190`, `src/face_inference/matching.py:12-60`, `docs/contract.md:27`).
- Recognition/enrollment decisions use server-configured thresholds and policy/mode; requests cannot set thresholds (`src/face_inference/config.py:163-168`, `src/face_inference/service.py:106-108`, `docs/contract.md:49-58`).
- The system is not liveness/PAD. It reports `liveness_validated:false` and quality/continuity checks do not prove a live person (`src/face_inference/service.py:34-38`, `docs/architecture.md:223-229`, `README.md:63-65`).

## Operational limitations

- **No TLS termination in app:** operators must provide TLS/reverse proxy for non-loopback or remote browser use (`docs/guide.md:97-99`, `docs/docker.md:145-176`).
- **No multi-tenant auth:** admin token is global; collections are namespaces (`docs/contract.md:33-36`).
- **No liveness/PAD:** quality checks are not anti-spoofing (`src/face_inference/service.py:34-38`, `README.md:63-65`).
- **Client-computed embeddings:** server validates shape/consistency but cannot prove embeddings came from a real camera frame; it relies on client behavior plus policy/evidence controls (`docs/contract.md:27`, `src/face_inference/service.py:183-190`).
- **Local secret material:** `config.json` contains API token, Fernet key, and session secret; losing/replacing encryption key makes encrypted records unreadable (`src/face_inference/config.py:50-59`, `docs/docker.md:192-205`).
- **Rate limit scope:** per-IP token bucket, default 120/min, in-memory state; behind proxies correctness depends on trusted proxy config (`src/face_inference/config.py:56`, `src/face_inference/config.py:90-109`, `src/face_inference/ratelimit.py:16-91`).
- **No raw-image audit:** raw camera frames are not stored or uploaded; stored biometric data is encrypted embeddings/evidence (`docs/contract.md:38-47`, `docs/contract.md:61-62`).

## Source citations checked

Citations in this document reference current integration-tree files: `docs/architecture.md`, `docs/contract.md`, `docs/guide.md`, `docs/docker.md`, `README.md`, `compose.yaml`, `src/face_inference/config.py`, `src/face_inference/server.py`, `src/face_inference/service.py`, `src/face_inference/storage.py`, `src/face_inference/matching.py`, `src/face_inference/ratelimit.py`, `src/face_inference/tokens.py`, `src/face_inference/web/app.mjs`, `src/face_inference/web/client.mjs`, `src/face_inference/web/browser-face/worker.mjs`, and `src/face_inference/schema.sql`.
