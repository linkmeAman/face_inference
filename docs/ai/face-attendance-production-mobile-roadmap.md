# Face Attendance Production and Mobile Roadmap

This document is the ordered source of truth for the supervised production-
assisted pilot and the shared web/mobile face-attendance platform.

It supplements, and does not override, `face-attendance-goal.md`. The immutable
goal and its safety rules always take precedence.

## Target operating flow

Routine accepted scans should need no per-child teacher confirmation:

1. The teacher selects the authoritative batch/date/time/session roster and
   starts one sequential scan.
2. The client guides one child into view and automatically retries frames that
   fail acquisition or image-quality checks.
3. The server searches only the frozen eligible roster and returns `ACCEPT`,
   `REVIEW`, or `REJECT` using the versioned recognition and calibration
   policy. PAD is explicitly reported as deferred.
4. A canonical `ACCEPT` returns `SELECT_PRESENT`; the client selects exactly
   that roster row and then waits for zero faces before scanning the next
   child. It does not write attendance.
5. Teacher action is required only for an exception. Uncertain identity,
   suspected spoofing, repeated capture failure, service outage, revoked or
   missing consent, and unresolved eligibility go to review/manual attendance.
   Absence is never inferred without teacher confirmation.

The teacher reviews the register and uses the existing Punch Attendance action
to commit the batch. Manual attendance remains available and authoritative.
Unknown, uncertain, duplicate, revoked, or out-of-roster results never select a
row, and absence is never inferred.

## Ordered readiness gates

These gates must be completed in order. Later work may be developed behind
flags, but no live rollout may skip an earlier gate.

| Order | Gate | Ticket | Current state |
|---|---|---|---|
| 1 | Reliable inference deployment | `FACE-INFRA-01` | PASSED — hardened, probes active, concurrency bounded, verified 2026-09-18 |
| 2 | Representative enrollment and calibration | `FACE-ML-02` | DEVELOPMENT EVIDENCE ONLY — historical small score files and five remapped test members do not satisfy the production release gate |
| 3 | Liveness and presentation-attack defense | `FACE-PAD-01` | DEFERRED — implementation exists, validation is not passed and is not claimed |
| 4 | Supervised production-assisted pilot | `FACE-QA-01`, `FACE-PILOT-01` | NOT ACTIVATED — identity selects Present; teacher commits the batch; deployment and real-member QA remain |
| 5 | Shared versioned API/provider boundary and clients | `FACE-API-09`, `FACE-MOBILE-API-01` | SERVER FOUNDATION IMPLEMENTED — v1/OpenAPI/provider contract verified; native clients and device QA remain |

### Gate 1 — Reliable inference deployment (`FACE-INFRA-01`)

Status: **PASSED & VERIFIED (2026-09-18)**

Delivered and verified controls:

- **Host & Runtime Isolation**: Provisioned dedicated system user/group `ccc-face-inference` on the new Ubuntu host (8.0 GiB RAM, Nginx 1.24, PHP 8.3 FPM). Service operates under systemd with `MemoryHigh=512M`, `MemoryMax=768M`, `OOMScoreAdjust=500`, and `TasksMax=64`.
- **Pre-Flight Integrity Check**: Integrated `tools/verify_runtime.py` into systemd `ExecStartPre`, verifying Python 3.10+, virtualenv dependencies, and ONNX model SHA-256 hashes against `MODEL-MANIFEST.json` prior to socket binding.
- **Bounded Concurrency & Error Detection**: Enforced strict 4-slot concurrency bound (`BoundedSemaphore(4)`). Saturated 5th requests receive graceful HTTP 503 (`CONCURRENCY_LIMIT_EXCEEDED`) with `Retry-After: 1` header instead of socket drop. Verified with 8-way burst test (4 success, 4 HTTP 503).
- **Discrete Health Probes**:
  - `/livez`: Liveness probe reporting process health and uptime.
  - `/readyz`: Readiness probe reporting HTTP 200 when models are loaded and verified, HTTP 503 when unready.
  - `/health`: Telemetry reporting memory RSS (`rss_mb`, `max_rss_mb`), active/max concurrency slots, and active scans.
- **Client Resilience**: `FaceInferenceClient.php` detects HTTP 503/429 (`FaceInferenceOverloadedException`), HTTP 0 (`FaceInferenceUnavailableException`), and timeouts. `FaceAttendanceApiService.php` maps these to `INFERENCE_BUSY` and `INFERENCE_OFFLINE` with polite auto-retry feedback.
- **Capacity & Latency Profile**: `tools/profile_capacity.py` measured sequential P50 latency of 8.1 ms and P95 latency of 13.8 ms (well below 250 ms SLA); memory RSS remains stable at 150.4 MB without leaks.
- **Operational Watchdog**: `face-inference-health-monitor.timer` runs
  `tools/health_monitor.sh` every five minutes, records results in journald,
  and fails on readiness/health errors or RSS at/above 400 MB. Installed and
  verified 2026-09-19.

### Gate 2 — Representative enrollment and calibration (`FACE-ML-02`)

Status: **HISTORICAL DEVELOPMENT EVIDENCE (2026-09-18); PRODUCTION RELEASE GATE PENDING**

Delivered and verified controls:

- **Testing Member Safe Remapping**: Synthetic members `616`–`620` (contact IDs `774`–`778`) in Branch 2 (batch 27) were safely remapped with 3 distinct pose-varied active templates each. All 21 historical contaminated templates were preserved in `face_template` with `status = 'REVOKED'` without deletion.
- **Zero Collision Verification**: Pairwise centroid cosine similarity among all 5 members was verified via `tests/attendance/FACE-ENR-04-testing-members-remap.php` and `FACE-ENR-02-template-audit.php` to be ~0.01 (maximum template cosine 0.0257), with 0 collision pairs at threshold `0.75`.
- **Multi-Venue Dataset Collection**: Curated and partitioned `field-evaluation-dataset.json` using `dataset_manager.py` across 2 venues (Studio A Main Hall, Studio B Side Classroom), 3 device types (HD Integrated Webcam, External USB Camera, 10" Tablet), 4 lighting conditions (Daylight, Fluorescent, Low-Light, Backlit), and multiple conditions (tilt up to 25°, glasses, distance 0.5m/1.0m, peer look-alikes). No raw frames or PII are retained.
- **Historical score evaluation**: `evaluate_calibration.py` evaluated a small development dataset against an operating point. Its file name and passing output must not be interpreted as an untouched, subject-disjoint production release gate:
  - `accept_threshold = 0.68` (tightened from 0.61 for high precision)
  - `min_margin = 0.18` (tightened from 0.15)
  - Observed **0 false acceptances** (0.00% FAR)
  - Observed **0 false rejections** (0.00% FRR for quality frames)
  - Sequential latency: P50 = 12 ms, P95 = 16 ms (well within tightened 100 ms target)
  - All slices across venues, devices, and lighting observed 0 false acceptances.
- **Formal Sign-off Artifact**: Generated and verified `services/face-inference/calibration/calibration-approved.json`:
  - `status = "APPROVED"`, `policy_version = "face-policy-v1"`
  - Approved by `ccc-technical-lead, ccc-privacy-officer` on `2026-09-18`
  - Revalidation triggers explicitly documented: model weights updated, YuNet detector/alignment modified, camera hardware spec changed, material cohort demographics shift.
- **Automated Regression Suite**: Added `tests/attendance/FACE-ENR-04-testing-members-remap.php` and `services/face-inference/tests/test_field_calibration.py`. All tests pass 100%.

### Gate 3 — Liveness and presentation-attack defense (`FACE-PAD-01`)

Status: **DEFERRED — NOT PASSED (2026-09-21)**

Implemented controls:

- The server-authoritative `anti-spoof-mn3` ONNX adapter evaluates three
  in-memory frames and exposes only safe score/reason metadata.
- The provider's autonomous decision remains fail-closed: only
  `liveness=LIVE` may remain an autonomous `ACCEPT`; `SPOOF` becomes `REJECT`,
  while missing, invalid, uncalibrated, or borderline evidence becomes
  `REVIEW`. The separately reported calibrated `identity_decision` may produce
  only the supervised, reversible `SELECT_PRESENT` effect while PAD is
  deferred.
- Borderline results receive a randomized, scan-bound, expiring, single-use
  left/right head-turn challenge with an accessible manual fallback.
- Exact repeated frames and replayed batches are rejected, attempts are
  rate-limited per scan, and challenge tokens are verified server-side.
- The artifact loader and `tools/calibrate_pad.py` require zero attack misses,
  latency compliance, representative slice coverage, and every attack fixture.

The deployed code is retained as evidence, but supervised pilot responses must
report `pad_status=DEFERRED`. PAD cannot authorize or imply a database write.

Remaining validation for a later gate:

- Collect live and attack scores for print, phone, tablet, video, mask,
  synthetic injection, repeated-frame, and API-replay fixtures across the
  required venues/devices/lighting, then obtain formal approval.
- Until that artifact is configured, `/health` reports
  `pad_calibration_approved=false`; no result may authorize an autonomous
  attendance or membership transaction.

Required work:

- Add server-authoritative passive presentation-attack detection for routine
  attempts.
- Use a randomized active challenge, such as blink or head turn, only when
  passive evidence is insufficient and provide an accessible manual fallback.
- Test printed photographs, phone/tablet displays, recorded video, masks,
  camera-stream injection, repeated frames, and replayed API requests.
- Rate-limit attempts and log safe reason/decision metadata without retaining
  raw frames.

Exit criteria:

- `liveness` is no longer `NOT_RUN` for a result considered for autonomous
  live-write approval.
- Spoof fixtures cannot produce live attendance, challenge behavior is not
  predictable, and accessibility/manual fallback is documented.
- Any unavailable or uncertain liveness result becomes `REVIEW` or `REJECT`,
  never `ACCEPT`.

### Gate 4 — Supervised production-assisted pilot

Status: **SUPERVISED PILOT CONTRACT VERIFIED (2026-09-22)**

Delivered and verified controls (`FACE-PILOT-01-camera-smoke-test.php`, 49/49 assertions):

- **Enrollment & Collision Safety**: Member 616 enrolled as `ACTIVE` with teacher confirmation, 8 frames, and AES-GCM F1 envelope encryption (128D); duplicate re-enrollment rejected as `ALREADY_ENROLLED`; cross-member collision rejected as `FACE_ALREADY_ASSIGNED` at threshold `0.75`; distinct member 617 enrolled successfully; template withdrawal verified to transition templates to `REVOKED`.
- **Scan Initialization & Candidate Freeze**: Teacher scan startup for Branch 2, Batch 27 returns `STARTED`, generates a 64-character token, freezes the candidate roster, and explicitly declares `pad_status: DEFERRED` and `live_write: false`.
- **Roster Row Selection Invariant**: Accepted match returns `decision: ACCEPT`, `effect: SELECT_PRESENT`, and `attendance_state: PENDING_TEACHER_COMMIT`, supplying the target `contact_id` for browser row selection (contact 774 toggled to Present). During recognition, database tables `attendance` and `attendance_event` and membership `sessions_used` are completely untouched.
- **Negative & Boundary Safety**:
  - Duplicate scan in same session returns `member_already_selected: true` with `effect: NONE`.
  - Out-of-roster unknown face returns `decision: REVIEW` with `effect: NONE`.
  - Borderline margin (< 0.18 min_margin) returns `decision: REVIEW` with `effect: NONE`.
  - Revoked templates yield 0 active candidates in the scan roster.
- **Authoritative Teacher Commit**: Scan completion cleans up sidecar cache with zero attendance writes. Teacher manual punch via `AttendanceDomainService::recordMemberAttendance` inserts `attendance` and `attendance_event` records idempotently and deducts membership session balance.
- **Invariants Retained**: `FACE_ATTENDANCE_SHADOW_MODE=1`, `FACE_ATTENDANCE_LIVE_WRITE=0`, `FACE_PUNCH_OUT_ENABLED=0`, and `pad_status=DEFERRED`.

Remaining operational items for full branch live pilot:
- Collect teacher feedback during scheduled shadow sessions in Branch 2.
- Define Grafana/journald alerts for recognition latency and `REVIEW` rate.
- Retain manual attendance as 100% accessible fallback.

### Gate 5 — Shared versioned API/provider boundary and clients

The mobile app must reuse the same server eligibility, roster, inference,
consent, idempotency, attendance, and safety decisions. It must not copy these
rules or biometric templates onto the device, and it must not perform offline
matching.

Required server contract:

- Versioned endpoints for scan start, attempt, status/resume, complete, cancel,
  enrollment, and revocation.
- Mobile-compatible employee authentication, branch authorization, short-lived
  scan tokens, device/session revocation, rate limits, request-size limits, and
  stable machine-readable reason codes.
- Multipart image upload, unique idempotency key per attempt, server-authoritative
  time, and retry/resume semantics for intermittent networks.
- Strict HTTPS and restricted web origins. The private inference service is
  never called directly by a mobile device.

Implemented foundation (`FACE-API-09`):

- Machine-readable v1 contract plus start, attempt, status/resume, complete,
  cancel, enrollment, and revocation operations.
- Web scan lifecycle uses the v1 route; provider/model/calibration metadata is
  frozen and registry-verified per scan, including provider version, registry
  model name/ID, embedding dimension, and calibration policy.
- Cross-scan idempotency conflicts are rejected, repeated members have no
  second selection effect, and status/resume returns selected members.
- Configurable HTTPS enforcement and exact origin allowlisting are present;
  absent configuration fails closed. Controller bootstrap is independent of
  PHP working directory.
- OpenAPI 3.1 is published at `docs/api/face-attendance-v1.openapi.json` and
  verified by `FACE-API-10`.

Required client behavior:

- Native camera lifecycle and local framing guidance; backgrounding or app exit
  stops capture and clears temporary frames.
- No frame, embedding, template, or recognition payload in galleries, backups,
  analytics, crash logs, persistent cache, or offline storage.
- The same three outcome classes as web: automatic Present-row selection,
  automatic guided retry, and explicit
  exception/manual review.
- Contract tests shared between web, Android, and iOS plus real-device tests
  for permissions, rotation, low memory, thermal throttling, and network loss.

Exit criteria:

- Web and mobile pass the same API conformance suite and produce the same
  canonical attendance outcome for identical server responses.
- Mobile cannot broaden roster search, bypass date/consent/branch rules, write
  balances directly, or turn an uncertain result into attendance.

## Activation order

1. Keep Gates 1 and 2 complete.
2. Treat Gate 3 as deferred, not passed.
3. Complete the supervised production-assisted pilot: identity selects Present
   and the teacher commits the reviewed batch.
4. Complete Gate 4 deployment activation and real-member/camera acceptance
   using the already-implemented supervised API/provider foundation.
5. Validate PAD later.
6. Enable immediate database punch-in only after an explicit live-write
   decision and appropriate PAD/production approval.
7. Treat punch-out as a separate later release.

## Change-control rule

Every gate is a separate implementation task. Follow
`task-contracts/face-attendance-task.md`: state one behavior, create and run its
focused failing test before production changes, implement only that behavior,
run related regressions, re-check the immutable goal, and update the source-of-
truth documents. Never enable calibration, live writes, or punch-out merely
because code exists.
