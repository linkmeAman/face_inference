# Standalone Python + SQLite face service contract

This is a new, PHP-free adapter for the exported current browser YuNet/SFace
pipeline. The original model/worker/capture files remain unchanged. Python ports
the existing aggregation, duplicate and unanimous unique-match rules. Business
actions such as attendance are callbacks for the consuming application.

## API

All `/api/v1/` endpoints require `Authorization: Bearer <private API token>`.
`GET /health` is public and contains no credentials. Static files are explicitly
allowlisted; the database, keys, datasets, original PHP and snapshots are private.
JSON responses contain `error`, `error_msg`, `message`, and `data`. Errors also
contain a stable `code`. Mutation keys bind route, collection and exact payload.

| Method and path | Input / response |
| --- | --- |
| GET `/api/v1/config` | Original safe browser contract, mode, policy readiness, provisional/approved distinction |
| GET/POST `/api/v1/collections` | List or create `{id,name,idempotency_key}`; arbitrary application-owned collection IDs |
| GET/POST `/api/v1/collections/{id}/people` | Metadata/status list or create `{id,name,metadata,idempotency_key}`; no CCC member tables |
| POST `/api/v1/collections/{id}/enrollments` | `{person_id,consent:true,capture_session,observations,template,model_version,preprocessing_version,idempotency_key}` |
| POST `/api/v1/collections/{id}/recognitions` | `{capture_session,observations,model_version,preprocessing_version,idempotency_key,eligible_ids?}`; `SUGGESTED` in evaluation, `MATCHED` only with an approved policy, otherwise `UNKNOWN`/`COLLECTION_ONLY` |
| POST `/api/v1/collections/{id}/observations` | Same labeled capture shape as enrollment, observation only; no gallery adaptation |
| DELETE `/api/v1/collections/{id}/people/{person}/face` | `{idempotency_key}`; erase encrypted face/evidence, preserve metadata and non-biometric audit |
| GET `/api/v1/collections/{id}/events` | Last 100 bounded event summaries; no vectors or raw images |

All captures require 3–5 finite 128-dimensional unit vectors bound to the original
model/preprocessing contract. Enrollment requires explicit consent and recomputes
the medoid/MAD aggregate, rejecting a mismatching supplied template. Capture UUIDs
cannot be reused for different requests. Bodies are limited to 128 KiB.

Collections are independent namespaces chosen by an authenticated application
operator; the API token grants access to all collections. They are not a replacement
for per-tenant authorization in a multi-user deployment. Eligible IDs are a subset
of that collection and never reveal other collections.

## Storage and policy

SQLite schema v1: `metadata`, `collections`, `people`, `captures`, `capture_claims`,
`requests` and `events`. Composite keys bind people/captures to collections. Face templates
and full labeled observations use authenticated Fernet encryption. A persisted
encrypted key check prevents silently reopening the database with a different key.
`BEGIN IMMEDIATE` protects duplicate check/save and request journal together;
unique request/capture constraints preserve retry and concurrency behavior.
SQLite foreign keys, WAL and busy timeout are enabled. No MySQL, PHP, CCC login,
attendance or external database is called.

COLLECT saves PENDING references and cannot recognize. EVALUATION is the default:
original browser shadow thresholds (duplicate 0.85, recognition 0.65) are explicitly
provisional; matches are `SUGGESTED`, `verified:false`, with no business side effect.
Thresholds are server-configurable, never request-controlled. APPROVED mode requires
the original complete held-out decision report, hash/contract/count/approval checks;
otherwise startup fails. APPROVED mode also requires explicit approved collection
IDs in server configuration. Pending activation checks every compatible reference in
the collection without choosing a winner among conflicting pending candidates.
ACTIVE requires approved policy. Reference updates require explicit deletion and
fresh consent/capture; later labeled observations never silently adapt a reference.
Evidence/reference expiry defaults to 30 days and is enforced in reads; the CLI
`purge` physically erases expired encrypted values. Every new capture purges expired
data as well. No raw camera images are stored.

Capture metadata includes device, lighting, model/preprocessing, consent, server
visit date and the collection's eligible identity snapshot. The default source is
`unvalidated-camera`; an operator must explicitly classify actual consented live
evidence rather than silently treating synthetic inputs as live. The `export` CLI
produces private JSONL compatible with the existing pure Python evaluator.

Config/data paths, host/port, thresholds, retention, timezone and collection contents are
configurable. Default listen address is `127.0.0.1:6122`. The client asks the operator
for the token and keeps it only in memory. Other apps can call the JSON API or import
the service/repository interface; the reference UI uses the original camera modules.

## Verification matrix before implementation

* Authentication, same-origin enforcement, rejected malformed/oversized/non-finite
  inputs, unknown static paths, traversal and no database/key exposure.
* Aggregation parity with original JS on real exported synthetic observations and
  deterministic outlier fixtures; scoring uses sequential accumulation.
* Valid enrollment, missing consent/version/norm/aggregate rejection, unknown person,
  duplicate across people, immutable references, collection isolation and expiry.
* Concurrent duplicate enrollments serialize, exact retries return the committed
  outcome, changed payload/key conflicts, reused capture conflict, restart persistence.
* Recognition unique/unanimous success; unknown, ambiguous, conflicting and ineligible
  batches rejected; no mutation of identity reference during recognition.
* Default evaluation never returns verified, COLLECT cannot recognize, invalid
  approval fails, approved fixture covers pending activation and conflict handling.
* Encrypted data at rest, wrong encryption key fails, deletion/expiry erase ciphertext.
* HTTP enrollment/recognition against SQLite and browser client cancellation/retry
  generation guards; actual-model browser smoke where Chromium is available.

Existing exported datasets/models/docs remain available; original PHP is historical
reference only and is never loaded, executed or required by the new runtime.
