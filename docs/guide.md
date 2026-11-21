# Python + SQLite integration guide

## Runtime and processing

```mermaid
flowchart LR
    Camera[Browser camera] --> Worker[Original YuNet/SFace WASM worker]
    Worker --> Capture[Original AutomaticCapture]
    Capture --> API[Python JSON API]
    API <--> DB[Encrypted SQLite storage]
    API --> Result[Identity result for your application]
```

The plain JavaScript UI imports `AutomaticCapture` from the original module graph.
It keeps the five observations, 350 ms spacing, guide/quality/continuity checks,
camera mirroring, worker recovery and actual model weights. Python ports the
original medoid/MAD aggregation, unit-vector validation, cosine score clamping,
duplicate checks and unanimous unique identity rule. PHP/MySQL/Angular are absent
from this runtime. Nothing writes CCC attendance or calls its login/session APIs.

`src/face_inference/service.py` contains the business-neutral operations;
`SQLiteRepository` owns encrypted storage and transactions. Applications can call
the JSON API, import the service in Python, or consume the client event. Collection
IDs define independent matching namespaces. The single API token is an operator
credential for **all** collections; collections alone are not multi-user tenant
authorization. Put your application's authorization in its own server adapter if
you later expose different collections to different users.

## Startup and private configuration

```bash
face-inference init       # Generate independent config/token/key and empty SQLite DB
face-inference check      # Validate configuration, models, encryption key and schema
face-inference token      # Print the token only in your own terminal
face-inference serve      # Serve the UI + API on 127.0.0.1:6122
face-inference serve --host 0.0.0.0 --port 7001
```

The data directory is resolved in this order:

1. `FACE_INFERENCE_HOME`, when set.
2. An existing `./.face-inference/` directory, with one notice on stderr that the
   legacy location is in use. Existing data is kept in place.
3. The per-user OS default:
   - macOS: `~/Library/Application Support/face-inference`
   - Linux: `$XDG_DATA_HOME/face-inference`, or `~/.local/share/face-inference`
   - Windows: `%APPDATA%\face-inference`, or `~\AppData\Roaming\face-inference`

New data directories use mode `0700` on POSIX. `init` and `check` include the
resolved directory in their JSON `home` field, even when models are missing.
An existing encryption key must remain stable across restarts and backups. Losing or
replacing it makes encrypted records unreadable; the persisted key check rejects
an incorrect key. The API never returns the key, token, stored face vectors or
database path. The UI keeps its supplied token in memory, clearing the input after
connection; it does not save it in localStorage/cookies.

Use `--config /private/location/config.json` or `FACE_STANDALONE_CONFIG` to choose
an independent deployment; its config parent is the resolved `home`. Model assets
use `<home>/models`, with verified packaged assets as a fallback;
`FACE_INFERENCE_MODELS_DIR` overrides the model location. Listener settings use
CLI flag > environment > config.json > default precedence. Overrides apply only to the current run and
are never saved to config.json; the token and encryption key stay unchanged.
`FACE_INFERENCE_ALLOWED_ORIGINS` replaces the saved list with comma-separated
origins, trims whitespace and ignores empty entries; an empty value clears the
list for that run. The JSON config supports:

| Setting | Environment | CLI flag | Default / meaning |
| --- | --- | --- | --- |
| `database` | — | — | Absolute `<home>/faces.sqlite3` path; another private path is supported |
| `host` | `FACE_INFERENCE_HOST` | `--host H` | `127.0.0.1`; IP literal or hostname |
| `port` | `FACE_INFERENCE_PORT` | `--port P` | `6122`; integer from 1 through 65535 |
| `mode` | — | — | `EVALUATION`; alternatives `COLLECT`, `APPROVED` |
| `duplicate_threshold`, `recognition_threshold` | — | — | `0.85`, `0.65`, original browser shadow values; provisional |
| `retention_days` | — | — | 30; expired face/evidence ciphertext is excluded and erased during mutations or CLI purge |
| `timezone` | — | — | `Asia/Kolkata`, matching the original capture-date convention; configurable IANA timezone |
| `capture_source` | — | — | `unvalidated-camera`; classify actual live evidence explicitly, never rename synthetic tests as live |
| `approved_collection_ids` | — | — | Empty; exact authorized scopes required for APPROVED captures/recognition |
| `policy_file` | — | — | Empty; private approved policy bound to a complete hash-verified evaluation report |
| `allowed_origins` | `FACE_INFERENCE_ALLOWED_ORIGINS` | — | Empty; exact HTTPS origins, or HTTP origins for localhost, 127.0.0.1 or [::1] |
| `rate_limit_per_minute` | `FACE_INFERENCE_RATE_LIMIT` | — | 120; integer from 1 through 100000 per client IP |
| `trusted_proxies` | `FACE_INFERENCE_TRUSTED_PROXIES` | — | Empty; list of trusted proxy IP addresses or CIDR networks, comma-separated in the environment |
| `max_people` | — | — | 1000 per collection |
| `api_token`, `encryption_key`, `session_secret` | — | — | Independent generated credentials; keep private |

Invalid listener/origin values exit with status 1 and a one-line error. A listener
other than `127.0.0.1`, `::1` or `localhost` prints this warning to stderr:
`listening on a non-loopback address: put TLS in front (camera needs HTTPS)`.

Restart to apply config changes. The approved report is revalidated on every new
operation, so revoking/corrupting it cannot silently leave approved matching active.
Exact idempotent retries can recover an earlier committed outcome after later
configuration or gallery changes.

## Cross-origin use

Add the embedding page's exact origin to the private JSON configuration, then restart:

```json
"allowed_origins": ["https://app.example.com", "http://localhost:3000"]
```

Test preflight without sending the API token:

```bash
curl -i -X OPTIONS http://127.0.0.1:6122/api/v1/config \
  -H 'Origin: https://app.example.com' \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Access-Control-Request-Headers: authorization, content-type'
```

For a remote host, keep the listener private and use an SSH tunnel, for example
`ssh -L 6122:127.0.0.1:6122 USER@SERVER`, then open localhost in your browser. An
HTTPS reverse proxy must forward this app's UI/API/assets together and have its
public origin listed in `allowed_origins`. No Nginx/systemd installation was
performed by this change. Only the explicitly allowlisted frontend/model assets
are served; datasets, source references, SQLite and keys have no HTTP route.

## Session tokens for public pages

Keep the admin token on your server. After authorizing the user, call
`POST /api/v1/session-tokens` with the admin bearer token and JSON such as
`{"collection_id":"my-team","scope":"enroll","person_id":"person-1","ttl":300}`.
The response data contains `token` and Unix timestamp `expires_at`. Send the
short-lived token to the page and use it as the bearer token for that collection's
`enrollments` route. Omit `person_id` to allow enrollment of any person in the
collection, or use scope `recognize` for its `recognitions` route. TTL defaults
to 300 seconds and must be an integer from 1 to 3600. Session tokens cannot
access other routes or issue new tokens; obtain a new one after expiry.

## JSON API

Every `/api/v1/` request requires `Authorization: Bearer TOKEN`. POST/DELETE bodies
use `Content-Type: application/json`; the maximum body is 128 KiB. Responses are
`{error,error_msg,message,code,data}`. A mutation must supply a unique
`idempotency_key`; use the **same key and payload** when recovering a lost response.
Reusing a key with different data returns 409. The reference client handles this.

| Method / endpoint | Payload |
| --- | --- |
| GET `/health` | No token required; bounded health response |
| GET `/api/v1/config` | Safe current model contract and mode |
| GET `/api/v1/collections` | List collections |
| POST `/api/v1/collections` | `id`, `name`, `idempotency_key` |
| GET `/api/v1/collections/C/people` | Names, application metadata and enrollment state |
| POST `/api/v1/collections/C/people` | `id`, `name`, optional `metadata`, `idempotency_key`; update changes metadata only |
| POST `/api/v1/collections/C/enrollments` | `person_id`, `consent:true`, `capture_session`, `observations`, `template`, versions, optional `device`/`lighting`, key |
| POST `/api/v1/collections/C/observations` | Same capture shape; labeled evidence only, no gallery update |
| POST `/api/v1/collections/C/recognitions` | `observations`, `capture_session`, versions, key, optional `eligible_ids` within this collection |
| DELETE `/api/v1/collections/C/people/P/face` | `idempotency_key`; erases that face and its capture evidence |
| GET `/api/v1/collections/C/events` | Last 100 event summaries, without biometric values |

IDs are application strings using letters/numbers plus `_`, `-`, `.`, `:`; they
need no numeric CCC counterpart. A collection's people can be added or updated
at runtime. Unknown person IDs are rejected. The browser calculates the observations
and aggregate automatically; another trusted capture client must preserve the same
`sface-int8bq-fb143eea` / `yunet640-similarity5-rgb255-v1` contract and 128 unit-vector
dimensions. Do not substitute vectors from the older Python-guided model version.

Use the default browser UI or the reusable `FaceClient`:

```javascript
import {FaceClient} from '/client.mjs';
const client = new FaceClient({token: operatorToken});
await client.send('/api/v1/collections', {id:'my-team',name:'My team'});
await client.send('/api/v1/collections/my-team/people', {
  id:'employee-001',name:'Person one',metadata:{external_id:'employee-001'}
});
// Supply the unchanged AutomaticCapture batch to /enrollments or /recognitions.
```

The reference page dispatches `face-result` with the returned decision:

```javascript
window.addEventListener('face-result', ({detail}) => {
  if (detail.state === 'MATCHED' && detail.verified) {
    onVerifiedIdentity(detail.person_id);
  }
});
```

An evaluation suggestion is `SUGGESTED` with `verified:false`; it must not be
treated as an approved identity action. API calls return decisions only. Your
application owns any subsequent action and its own permissions/idempotency.

## Python client

Applications can interact with the HTTP API using the typed standard-library client:

```python
from face_inference.client import FaceClient, FaceApiError, FaceConnectionError

client = FaceClient("http://127.0.0.1:6122", token=operator_token, timeout=10.0)

# Check service health
health = client.health()

# Manage collections and people
client.create_collection("my-team", "My team")
client.add_person("my-team", "employee-001", "Person One", metadata={"dept": "engineering"})

# Enroll a person
result = client.enroll(
    collection_id="my-team",
    person_id="employee-001",
    observations=observations,
    template=template,
    capture_session=session_uuid,
    model_version="sface-int8bq-fb143eea",
    preprocessing_version="yunet640-similarity5-rgb255-v1",
)

# Recognize
match = client.recognize(
    collection_id="my-team",
    observations=observations,
    capture_session=session_uuid,
    model_version="sface-int8bq-fb143eea",
    preprocessing_version="yunet640-similarity5-rgb255-v1",
)

# Erase face data for subject
client.delete_face("my-team", "employee-001")
```

The client requires zero third-party dependencies (Python 3.10+ stdlib only).
- **Idempotency and retry**: Mutating calls (`POST`, `DELETE`) automatically attach a unique UUID `idempotency_key` and retry up to 3 times on transient network failures or 5xx/429 status codes with exponential backoff. Confirmed client errors (4xx) are raised immediately without retry.
- **Unresolved request recovery**: If retries are exhausted on a mutation, the request state remains pending in `client.pending` and can be recovered with `client.retry()`.
- **Token redaction**: The API credential is never exposed in `repr()`, `str()`, or exception messages.
- **Error types**: Server-side error responses raise `FaceApiError(status, code, message)`; network failures or timeouts raise `FaceConnectionError`.

## Examples

Runnable example scripts and walkthroughs are available in the [`examples/`](../examples/README.md) directory:
- [Python client quickstart](../examples/python/quickstart.py): health check, collection creation, adding people, listing people, and deletion using `FaceClient`.
- [Web component embed](../examples/web/index.html): vanilla HTML/JS embed of `<face-capture>`, including [React](../examples/web/react.md) and [Vue](../examples/web/vue.md) snippets.

## Enrollment lifecycle and persistence

The SQLite transaction covers duplicate comparison, face save, capture claim,
audit event and request journal. Concurrent duplicate captures cannot each create
a valid reference. Later captures are still checked against other identities but
preserve the original usable reference. A conflicting new attempt cannot erase
an existing good reference. Explicit deletion plus new capture is the replacement
workflow. Observation-only checks never enroll an unknown person or adapt a stored
gallery template.

EVALUATION stores PENDING references and uses them only for unverified suggestions.
COLLECT cannot recognize. APPROVED uses ACTIVE references and requires the original
complete-decision report validation (20 identities / 100 captures are preliminary
coverage, independent validation/workflow approval, actual live source, known and
unknown held-out decisions, matching contract, exact recommendations and report
hash). Exact collection scope is also required. No synthetic test report or
provisional threshold is installed by this delivery.

Pending activation checks every compatible pending/active reference in the same
collection. Pending conflicts require recapture without choosing an arbitrary
winner. Unknown, multiple qualifying identities and conflicting observation winners
return UNKNOWN. No face image is saved; templates and full labeled observation
batches use authenticated encryption. Capture metadata retains model versions,
consent, real server visit date, measured lighting, hashed camera identity and the
server-resolved collection population at capture time.

## Calibration evidence without PHP

```bash
face-inference export --collection my-team --output /tmp/my-team-face-evidence.jsonl
face-inference purge
```

The export is exclusive mode 0600, contains decrypted vectors and must remain
private. It includes original enrollment-reference flags and excludes duplicate
label conflicts. The evaluator is pure Python. Its source/coverage/
independent-visit/workflow checks remain; it does not approve insufficient data.
`capture_source` defaults to unvalidated, and omitted device/lighting labels are
recorded as unreported rather than fabricated. Set the actual source and collect
the required real data deliberately. The browser supplies measured brightness and
hashed camera metadata. First two distinct actual server dates are calibration;
later dates are validation. Multiple same-day captures never create independent
visits. Check face on a previously used calibration date requests a later visit.

## Verification

```bash
pytest tests
node --test tests/js/*.test.mjs
face-inference check
```

The included browser smoke test needs Playwright/Chromium and a synthetic Y4M
camera fixture; `tests/js/browser-smoke.cjs` runs the actual UI, unchanged
worker/models, Python API and a disposable encrypted SQLite DB. It checks enrollment,
duplicate rejection, recognition suggestions, response-loss recovery and persistence
across a new Python process/browser document. No successful identity response is
mocked.

The standalone adapter is new code. Legacy PHP code and old services have been
removed from `main` and are preserved under Git tag `legacy-ccc-final`.
Passing synthetic-camera tests demonstrates integration and recovery; physical-device
accuracy, representative cohort evaluation and liveness remain separate evidence.
