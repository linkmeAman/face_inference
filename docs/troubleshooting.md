# Troubleshooting

Every row below was checked against the code in this repository. Exact strings and
error codes are quoted from the source; the section at the end shows the commands
that were actually run.

## Camera and browser

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Camera requires HTTPS`, or `face-error` with code `INSECURE_CONTEXT` and message `Camera requires HTTPS or localhost.` | The page is not a secure context, so `navigator.mediaDevices` is unavailable or `getUserMedia` is refused. Only `https://` or `localhost`/`127.0.0.1` counts. | Open the UI on `http://127.0.0.1:6122` or behind HTTPS. For a remote host use an SSH tunnel or a TLS reverse proxy. A non-loopback listener also prints a warning at startup. |
| `Allow camera access, then retry`, or `face-error` with code `CAMERA_DENIED` and message `Camera access denied. Please allow camera permissions and retry.` | The browser or OS denied the camera permission. | Allow camera access for the page's origin in the browser, then retry. |
| `face-error` code `CAMERA_NOT_FOUND`, message `No camera found on this device.` | `getUserMedia` raised `NotFoundError`. | Connect/enable a camera, or use a device that has one. |
| `face-error` code `CAMERA_NOT_READABLE`, message `Camera is in use by another application or could not be started.` | The device is busy or the track could not start. | Close other camera apps/tabs and retry. |
| `face-error` code `CAMERA_OVERCONSTRAINED`, message `No camera satisfies the requested constraints.` | Requested facing mode/device is unavailable. | Retry without a specific device; the capture code already retries once with plain 640x480 constraints. |
| `Camera stopped. Tap Retry to continue.` or `Camera paused. Reconnecting...` | The track ended/muted, the worker stopped replying, or frames stalled; automatic recovery was attempted twice. | Tap Retry. Backgrounding the tab pauses capture by design. |
| `Face capture timed out. Please try again.` | No usable batch completed before `capture_timeout_ms`. | Improve lighting/positioning and retry; keep one face inside the circle. |
| Camera preview is mirrored but the stored face is not | The front camera preview is mirrored only in CSS; the bitmap sent to the worker is unmirrored. | Expected behaviour, not a bug. |

Source: `src/face_inference/web/browser-face/capture.mjs:24-37,70-72,160-197,363`,
`packages/face-capture/src/config.js:89-127`,
`packages/face-capture/src/face-capture.js:276-311,423-431,568-578`,
`src/face_inference/cli.py:83-84`, `docs/deployment.md:12`.

## `<face-capture>` `face-error` events

The embeddable component dispatches `face-error` with a `detail` object of
`{code, message}`. These are the exact built-in values; request failures carry the
server's `code` and `error_msg` instead.

| Code | Message | Meaning |
| --- | --- | --- |
| `INVALID_CONFIG` | `api-url attribute or property is required` | Missing `api-url`. |
| `INVALID_CONFIG` | `collection attribute or property is required` | Missing `collection`. |
| `INVALID_CONFIG` | `mode must be "enroll" or "recognize"` | Bad `mode`. |
| `INVALID_CONFIG` | `person-id attribute or property is required when mode is "enroll"` | Enrollment without `person-id`. |
| `CONSENT_REQUIRED` | `Confirm consent before capturing a face` | Enrollment attempted without the consent checkbox. |
| `CAMERA_DENIED` / `CAMERA_NOT_FOUND` / `CAMERA_NOT_READABLE` / `CAMERA_OVERCONSTRAINED` / `INSECURE_CONTEXT` | see the camera table above | Camera/context failure, mapped from the browser error. |
| `CAMERA_ERROR` | the original message, else `An unexpected camera error occurred.` | Unmapped camera failure. |
| `SERVICE_UNAVAILABLE` | the client error message, else `Face service unavailable` | `GET /api/v1/config` failed before capture started. |
| `ENGINE_LOAD_ERROR` | `Failed to load face capture engine: <reason>` | The capture module or WASM runtime could not be imported. |
| `REQUEST_FAILED` | the client error message, else `Request failed` | Enrollment/recognition POST failed. |

Source: `packages/face-capture/src/config.js:46-127`,
`packages/face-capture/src/face-capture.js:383-460,486-490,547-560`.

## Models and assets

| Symptom | Cause | Fix |
| --- | --- | --- |
| `serve` exits with `Run: face-inference models fetch` | Asset verification failed, so the server refuses to start. | Run `face-inference models fetch`, then start again. `models verify` shows which file failed. |
| `face-inference check` prints `"status": "assets-missing"` | Same as above, but `check`/`init` report it as JSON instead of exiting. | Run `face-inference models verify` to see missing/mismatched files, then `face-inference models fetch` or set `FACE_INFERENCE_MODELS_DIR` to a verified directory. |
| `Prepared face asset checksum mismatch` or `Missing browser runtime asset: ...` | A file in the models directory is missing, is a symlink, or does not match `manifest.json`. | Re-fetch, or point `FACE_INFERENCE_MODELS_DIR` at a verified copy. Fetch refuses to overwrite a file with a wrong hash, so remove the bad file first. |
| `face-inference models fetch` fails with `Existing asset has wrong checksum; refusing to overwrite: ...` | A previously downloaded file is corrupt. | Delete that file and run `models fetch` again. |
| `Failed to load face capture engine` (`ENGINE_LOAD_ERROR`) with assets present | The page cannot load the module/WASM files, e.g. a proxy serves `.wasm` with the wrong `Content-Type` or blocks `/vendor/browser-face/*`. | Serve the app and assets from the same origin; keep `application/wasm` for `.wasm` and `text/javascript` for `.mjs`. The built-in server already sets these. |

Source: `src/face_inference/cli.py:34-59`,
`src/face_inference/assets.py:68-111,142-231`,
`src/face_inference/config.py:172-190`,
`src/face_inference/server.py:52-63,257-267`,
`src/face_inference/web/browser-face/worker.mjs:4-16`.

## HTTP API: 401, 403, 429 and retries

All errors use the envelope `{error: 1, error_msg, message, code, data: {}}`; `code`
is the stable value. `GET /health` is the only public route.

| Status / code | Exact message | Cause | Fix |
| --- | --- | --- | --- |
| 401 `AUTH_REQUIRED` | `A valid API token is required` | Missing or wrong bearer token, and the header is not a `Bearer v1.` session token. | Use the token from `face-inference token` (run it in your own terminal). |
| 401 `TOKEN_INVALID` | `Session token is not permitted` | Session token failed signature/format verification. | Mint a new session token with `POST /api/v1/session-tokens`. |
| 401 `TOKEN_EXPIRED` | `Session token is not permitted` | Session token `ttl` elapsed. | Mint a new one; default TTL is 300 s, maximum 3600 s. |
| 403 `TOKEN_SCOPE` | `Session token is not permitted` | Session token used on the wrong route, wrong collection, wrong scope, or a different `person_id` during enrollment. | Use the token only for its collection and `enroll`/`recognize` route. |
| 403 `ORIGIN_REJECTED` | `Use the same-origin face service` (API) / `Use an allowed browser origin` (preflight) | Browser `Origin` is neither same-origin nor listed in `allowed_origins`. | Add the exact origin (scheme + host + port) to `allowed_origins` and restart, or use the same origin. |
| 403 `SCOPE_NOT_APPROVED` | `This collection is outside the approved matching scope` | `APPROVED` mode and the collection is not in `approved_collection_ids`. | Add the collection id to the approved scope, or use `EVALUATION`/`COLLECT`. |
| 429 `RATE_LIMIT` | `Too many requests; wait a minute` | The per-IP token bucket (capacity `rate_limit_per_minute`, refilled over 60 s) is exhausted. Response includes `Retry-After` seconds. | Wait `Retry-After` seconds, raise `rate_limit_per_minute`, or configure `trusted_proxies` behind a proxy. |
| 503 (empty body) | — | More than 8 concurrent connections; the server sheds load with `Retry-After: 1`. | Retry shortly; reduce concurrency. |
| 503 `STORAGE_BUSY` | `Storage is temporarily unavailable; retry with the same request key` | SQLite stayed busy past the 10 s busy timeout. | Retry the same request with the same `idempotency_key`. |
| 409 `CAPTURE_REUSED` | `Capture already submitted; start a new camera capture` | The same `capture_session` UUID was submitted twice. | Start a fresh capture; do not reuse the UUID. |
| 409 `IDEMPOTENCY_CONFLICT` | `This request key belongs to a different payload` | Same `idempotency_key`, different body. | Use a new key for a new payload; retry the original payload with the original key. |
| 400 `MODEL_MISMATCH` | `Capture model and preprocessing must match this service` | Client sent versions that differ from the served contract. | Reload the page/asset cache; do not mix client and server versions. |
| 409 `INCOMPATIBLE_GALLERY` | `Collection contains incompatible references; recapture is required` | A stored reference was captured with another model/preprocessing version. | Re-enroll affected people after a model change. |
| 415 `INVALID_CONTENT_TYPE` | `Use application/json` | POST body sent without `Content-Type: application/json`. | Send JSON with that header. |
| 413 `BODY_LIMIT` | `JSON body must be between 1 byte and 128 KiB` | Request body outside the 128 KiB bound. | Reduce the payload; observation batches are small. |

The reference client already retries transient failures up to three times with the
same payload and idempotency key, and only retries confirmed-safe errors; 4xx
validation/auth failures are raised immediately. If the page shows
`Retry save to recover the same request`, use the Retry save button rather than
starting a new capture.

Source: `src/face_inference/server.py:20,66-72,111-131,133-190,228-256,270-281`,
`src/face_inference/service.py:87-108,128-131,155-166`,
`src/face_inference/web/client.mjs:18-41`, `docs/guide.md:135-136,226`.

## CORS, proxies and containers

| Symptom | Cause | Fix |
| --- | --- | --- |
| Browser preflight fails; `OPTIONS` returns 403 `ORIGIN_REJECTED` | The preflight `Origin` is not exactly in `allowed_origins`; wildcards, paths and plain HTTP for non-local hosts are rejected. | Add the exact origin, e.g. `"allowed_origins": ["https://app.example.com"]`, and restart. Test with `curl -i -X OPTIONS ... -H 'Origin: https://app.example.com'`. |
| Same-origin page works, embedded page gets `ORIGIN_REJECTED` | The embedding page's origin was not listed; same-origin is computed from `Origin` vs `Host`. | List the embedding origin; the server also allows local origins on the listener port. |
| Every user behind a reverse proxy shares one rate-limit bucket, or `X-Forwarded-For` is ignored | The proxy's IP is the peer; forwarded headers are trusted only when the peer is in `trusted_proxies`. | Set `trusted_proxies` to the proxy IP/CIDR (config or `FACE_INFERENCE_TRUSTED_PROXIES`); then the first untrusted address in `X-Forwarded-For` is used. |
| Rate limiting bypassed because clients can spoof `X-Forwarded-For` | `trusted_proxies` is too broad or includes client networks. | Trust only the actual proxy addresses. With an empty list the header is ignored entirely. |
| Docker/container: the container starts but the browser cannot reach the API on the published port, or the camera never starts | Inside the container the server binds `127.0.0.1` by default, so a published port does not reach it; and a non-local hostname without HTTPS is not a secure context. | Bind the listener to `0.0.0.0` **inside** the container (config/`--host`/`FACE_INFERENCE_HOST`), publish it, and put HTTPS in front; list the public origin in `allowed_origins`. Startup prints a non-loopback warning. |
| Behind a proxy the browser reports `Failed to load face capture engine` | The proxy does not forward the UI, `/browser-face/*` and `/vendor/browser-face/*` together, or rewrites MIME types. | Forward the app and both asset prefixes unchanged; keep `.wasm` as `application/wasm`. |

Source: `src/face_inference/server.py:105-121,158-167,201-206`,
`src/face_inference/config.py:91-113,136-153`,
`src/face_inference/ratelimit.py:104-162`,
`src/face_inference/cli.py:83-86`, `docs/guide.md:86-107`,
`docs/deployment.md:5,12`.

## Data, keys and retention

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Wrong database encryption key; restore the original key` at startup | `config.json` has a different `encryption_key` than the one bound to `faces.sqlite3`. | Restore the original `config.json`/key from backup. Replacing the key makes existing encrypted templates and captures unreadable. |
| `Existing database has no configuration; restore its original encryption key` | A database exists but the matching `config.json` is gone. | Restore the original config; do not generate a new one over existing data. |
| `Existing face database has no encryption-key binding` | A non-empty legacy database lacks the key check. | Restore the configuration that created it; do not point a fresh config at it. |
| People show `RECAPTURE_REQUIRED` and recognition ignores them | The reference expired (`retention_days`, default 30) or a model/preprocessing change made it incompatible. | Re-enroll the person. Expired encrypted values are purged on the next mutation or `face-inference purge`. |
| A face was deleted but metadata remains | `DELETE .../people/{id}/face` erases the encrypted template and capture evidence but preserves the person record and audit events. | Expected: re-enroll or delete the person through your own application data. |

Losing the encryption key is unrecoverable for the encrypted columns: the key
check rejects a wrong key and decryption fails closed. Back up `config.json` and
`faces.sqlite3` together.

Source: `src/face_inference/storage.py:19-34,56-63`,
`src/face_inference/config.py:44-56`, `src/face_inference/service.py:57-64,121-126`,
`docs/guide.md:51-53`, `docs/contract.md:38-47`.

## Commands used to verify this page

Run in this worktree with `PYTHONPATH=src` and a scratch home. Outputs are copied
from the real runs; long header lists are trimmed to the relevant lines. The token
itself is deliberately not printed.

```console
$ FACE_INFERENCE_HOME=.scratch/i3-home python -m face_inference check
{"status": "ready", "home": ".../.scratch/i3-home", "config": ".../.scratch/i3-home/config.json", "database": ".../.scratch/i3-home/faces.sqlite3", "mode": "EVALUATION", "model_version": "sface-int8bq-fb143eea"}

$ FACE_INFERENCE_HOME=.scratch/i3-home python -m face_inference models path
.../src/face_inference/web/vendor/browser-face

$ FACE_INFERENCE_HOME=.scratch/i3-home python -m face_inference models verify
{
  "status": "PASS",
  "checked_assets": 6,
  "failures": []
}

$ FACE_INFERENCE_HOME=.scratch/i3-home python -m face_inference models fetch
(no output, exit 0: all packaged assets already verified)
```

```console
$ curl -s -w '\nHTTP %{http_code}\n' http://127.0.0.1:6213/api/v1/config
{"error": 1, "error_msg": "A valid API token is required", "message": "A valid API token is required", "code": "AUTH_REQUIRED", "data": {}}
HTTP 401

$ curl -s -w '\nHTTP %{http_code}\n' http://127.0.0.1:6213/api/v1/config \
    -H "Authorization: Bearer $TOKEN" -H 'Origin: https://evil.example'
{"error": 1, "error_msg": "Use the same-origin face service", "message": "Use the same-origin face service", "code": "ORIGIN_REJECTED", "data": {}}
HTTP 403

$ curl -s -i -X OPTIONS http://127.0.0.1:6213/api/v1/config \
    -H 'Origin: https://app.example.com' -H 'Access-Control-Request-Method: GET'
HTTP/1.0 204 No Content
Access-Control-Allow-Origin: https://app.example.com
Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type
Access-Control-Max-Age: 600

$ curl -s -w '\nHTTP %{http_code}\n' -X OPTIONS http://127.0.0.1:6213/api/v1/config \
    -H 'Origin: https://evil.example' -H 'Access-Control-Request-Method: GET'
{"error": 1, "error_msg": "Use an allowed browser origin", "message": "Use an allowed browser origin", "code": "ORIGIN_REJECTED", "data": {}}
HTTP 403

$ curl -s -i http://127.0.0.1:6213/api/v1/config -H "Authorization: Bearer $TOKEN"
HTTP/1.0 429 Too Many Requests
Retry-After: 11
{"error": 1, "error_msg": "Too many requests; wait a minute", "message": "Too many requests; wait a minute", "code": "RATE_LIMIT", "data": {}}
```

The 429 was produced with `FACE_INFERENCE_RATE_LIMIT=5`. Mermaid syntax was not
validated: `npx --offline @mermaid-js/mermaid-cli` has no cached package on this
machine.
