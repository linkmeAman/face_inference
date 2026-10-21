# Automatic face API contract

## Lifecycle and isolated pilot continuation — 2026-10-09

Schema 03 adds face_web_enrollment (encrypted candidate, PENDING/ACTIVE/REJECTED_DUPLICATE/RECAPTURE_REQUIRED, capture/model binding, active-template reference, decision/audit metadata) and face_web_evidence (encrypted complete observation batches bound to an authorized independently selected member/capture session). It does not activate or rewrite existing enrollments. Legacy aggregate-only calibration samples remain evidence with their limitations; missing observation batches must never be synthesized.

Enrollment now includes consent, capture_session UUID, observation batch, camera metadata and purpose ENROLL. Validation is POST validate_capture with the same capture contract and purpose VALIDATION, using an explicitly selected member as ground truth. It never predicts labels or marks attendance. Separate browser openings create separate capture UUIDs; date-based visit/split requirements remain. All actions require existing Attendance access and branch authorization, and use the existing request journal/tenant lock. Server recomputes aggregation, validates model/unit vectors and retains encrypted evidence. Responses include enrollment_state; only ACTIVE may display Face enrolled. Pending means Face captured; enrollment will finish automatically, duplicate means This face belongs to another member, ambiguous means Please capture this face again. Scanner unavailability is an operator message with administrative detail confined to health/report.

POST activate_pending / automatic activation before opening a ready scanner rechecks compatible, consented, unexpired candidates against active templates and every unresolved compatible candidate across the tenant. Active conflicts become REJECTED_DUPLICATE; cross-member pending conflicts become RECAPTURE_REQUIRED for the authorized branch, without choosing an arbitrary winner. Incompatible/expired/missing-key candidates need recapture. Activation is atomic under the existing lock, idempotent/restart-safe, and records policy hash/actor/time. No calibration readiness bypass; production policy additionally requires complete decision evaluation and independent workflow approval.

Pilot: attendance.php?face_pilot=1 uses the same component/API with pilot=1. The server resolves a protected, expiring, exact source-tenant/branch/batch/member allowlist, never a browser-supplied database/threshold. Before every pilot operation it verifies the separate database ownership marker, exact test-only population, no cross-schema views/triggers/foreign keys and policy/report/model hashes. AttendanceDomainService runs only on this isolated connection. Production routes keep production policy and templates. Manual/legacy write controls are disabled in the pilot page so camera results cannot feed a production attendance submission. Provisional thresholds must be derived from real labeled evidence and are explicitly not production approval.

Verification matrix: encrypted lifecycle persistence; version/aggregate/consent rejection; replay and crash recovery; two pending identical faces never activate arbitrarily; tenant-wide pending/active conflict; compatible unique activation; expired/key/version recapture; deletion/withdrawal; independent explicitly labeled validation without attendance; complete duplicate and attendance decision evaluation, unknown rejection, sessions/counts/confidence intervals; provisional-policy source/scope/report binding; pilot database tampering/cross-schema/extra-member rejection; actual model + PHP/MySQL/domain/browser workflows and unchanged production attendance/deductions/templates. Public fixtures are integration tests only; actual consented observations are required for biometric evaluation and live pilot acceptance.

## Current endpoint contract

Final validation regressions: a five-observation batch must restart when the single-face bounding box ceases to overlap its prior box or observations are interrupted. This is geometric continuity, not a claim of identity/liveness. Browser repeat suppression may skip only unchanged observation batches already confirmed by the server; similarity to a previous arrival is not identity authorization. Compatible unexpired PENDING and ACTIVE original enrollment references remain fixed while later captures supply separate evidence; explicit recapture/deletion can create a replacement. Status must request recapture for an unusable pending candidate even while matching is unavailable. Administrative activation sweeps must not reuse a journal key from an earlier completed sweep and strand newly captured candidates.

Test matrix: abrupt single-face transition resets partial samples; interruption re-arms collection; similar-looking changed batch reaches backend; byte-identical confirmed batch avoids repeat submission; first pending/active ciphertext/reference unchanged across further captures and attendance; expired status is recapture; a new sweep sees new pending candidates; fresh browser context recognizes persisted enrollment without re-enrollment.

PHP endpoint: routes/browserFaceRoute.php?action=ACTION. Same-origin cookie authentication and existing Attendance level-1 access; branch/tenant/actor come from the session. POST requires X-Face-CSRF, HTTPS except the loopback CLI fixture, JSON limit 64 KiB, mutation rate limit 30 new requests/operator/minute. Templates/observations never appear in normal responses or logs. Response envelope: error, error_msg, message, data.

GET config returns safe contract/readiness/operator availability for the selected batch; health provides administrative readiness. GET options, roster and enrollment_status return authorized metadata and actual lifecycle states, never vectors. Pending activation requires both validated matching and server-authorized LIVE batch scope (or the existing isolated pilot approval). SHADOW never activates.

POST enroll requires person_id, template, observations (3–5 unit vectors), model_version, preprocessing_version, idempotency_key, capture_session UUID, consent:true, device, lighting and evaluation_context (batch_id, attendance_date, session_id, selected_time) from the current page. The server validates member access, dimensions/finite norms/versions, recomputed aggregation and server-derived eligible roster. On lifecycle schema it never accepts aggregate-only enrollment as a bypass. It returns enrollment_state PENDING/ACTIVE/REJECTED_DUPLICATE/RECAPTURE_REQUIRED. Only ACTIVE can show Face enrolled. A matching active candidate stays active; independent validation cannot overwrite the pending training gallery.

POST validate_capture uses the same complete capture contract and explicitly selected ground-truth person. It saves evidence without a new active enrollment or attendance. First two distinct dates are calibration; later dates validation. A repeat Check face on an already used calibration date requests a later visit. Separate capture UUIDs do not make same-day observations independent. No predicted label is accepted as ground truth.

POST activate_pending requires idempotency_key, selected batch_id, validated production policy and its exact authorized LIVE scope (or scoped pilot policy only on the verified pilot connection). Under tenant lock/transaction it verifies candidate membership, ordered consent audit, expiry, encryption key and contract, checks tenant-wide active and unresolved pending candidates, activates compatible unique candidates eligible for the approved batch, rejects active duplicates and flags pending conflicts/incompatible candidates for recapture. No blanket promotion. Decision hash/actor/time and active reference are audited. CLI activate-pending likewise requires --batch in addition to the existing database/branch/actor inputs.

POST open requires batch_id, attendance_session_id, attendance_date, selected_time, idempotency_key. It verifies actual scheduled context and freezes the authorized eligible roster, returning scan_id and scan_token. POST close uses these plus an idempotency_key.

POST attendance requires scan_id, scan_token, observations, versions, idempotency_key. Each of 3–5 observations must identify exactly one eligible ACTIVE person at ATTENDANCE_THRESHOLD, with unanimous winners. Unknown, ambiguous or conflicting observations remain unmarked. Confirmed identity calls existing AttendanceDomainService; returns contact_id, attendance_id, deducted_sessions and RECORDED/ALREADY_MARKED for immediate existing-row updates. Retrying a lost response retains identical payload/key and recovers a committed event rather than deducting twice. Enrollment performs one global comparison after aggregation, never per camera frame.

POST delete requires person_id and key; erases selected enrollment/candidate/evidence ciphertext and withdraws consent without deleting attendance history. calibration_delete erases selected calibration/evidence and pending candidates while preserving independently consented ACTIVE enrollment. Retention/export/purge commands are private CLI only.

## Pilot boundary

attendance.php?face_pilot=1 and route pilot=1 select only a protected exact-scope expiring server configuration; no client database/threshold is used. Source ccc_database / branch 2 / batch 33 / members 623–625 / contacts 791–793 / items 1188–1190 are the only allowed scope. Each request verifies a separate owned database, exact population, local membership views and absence of triggers/cross-schema dependencies. All attendance/domain writes use that connection. Pilot page disables every legacy/manual write route and notification registration; guarded pilot_batches/pilot_roster/pilot_attendance reads supply existing AngularJS row shapes.

Provisional reports require actual consented observations from that pilot database/branch, compatible model, scoped identities, measured genuine/impostor trials and data-derived finite threshold. They explicitly deny production approval. Production policy remains report-bound and requires complete independent decision evaluation, established coverage and independent workflow approval. No normal route exposes constructor-injected fixture thresholds.

## Compatibility and history

Migrations 01/02/03 supply journaling/model registry, calibration/migration audit, and lifecycle/full evidence respectively. They were applied only to ccc_database after private backups. Schema03 recovery promoted only prior journal-confirmed enrollment captures to PENDING, never standalone calibration/ACTIVE/attendance. Older aggregate-only schemas retain compatibility behavior but cannot meet current complete-decision release evidence requirements.

face.php preserves bookmarks by redirecting to attendance.php. The ordinary page requires no standalone calibration UI. The older authorized calibration_collect/progress/delete endpoints remain compatibility tooling, saving encrypted aggregates with consent/expiry; they cannot alone satisfy current full-decision release requirements. Migration status/replace endpoints require exact protected legacy allowlists and validated matching, preserve history transactionally, and are not ordinary UI controls. Older enrollment writers remain blocked. User-authorized exact erasure of templates 94–96 is complete; no other legacy deletion is authorized.

Actual tests, deployment commands, human evidence requirements and limits are documented in BROWSER_FACE_FINAL_DELIVERY.md, BROWSER_FACE_SETUP.md and IMPLEMENTATION_STATE.md.


Public evaluation contract (2026-10-09): evaluation files remain outside the web root and cannot become live policy. Inference imports the deployed worker and contract unchanged. Dataset image hashes and source identity labels define trials; repeated vectors used only to probe the backend three-observation API shape are one image trial, never independent camera observations. Development and held-out identity groups are disjoint. Backend static matching is called without a database or attendance writes. Label exclusion uses exact row fingerprints, preserves ciphertext, excludes evidence and pending activation transactionally, and supports guarded rollback; no active enrollment may be changed by this operation. Tests cover stale plans, exact scope, ciphertext preservation, activation exclusion and rollback.


Recognition failure / recovery contract (2026-10-09): preserve model, preprocessing and threshold values; diagnose development identities separately from final held-out measurements. A recovered attendance request whose camera and lease have already closed must open a fresh authorized scanner for the unchanged session. Resolving an old-session request must not resume or leave a stalled panel in the current session. Late retry errors must not update a newer camera generation. Tests must first reproduce these recovery failures, then verify actual PHP/MySQL event uniqueness and fresh scanner lease with real-model browser integration. No database schema change or production matching enablement.

Post-physical-capture contract: operator-confirmed captures under623/624/625 represent one person, retained as623; other labels use reversible exclusion and never supply independent impostor trials. Physical observation replay is an administrator-controlled isolated database integration test, never an installed matching report or a new independent camera capture. A terminated worker's queued error must not stop a restarted camera or overwrite its state; the current worker's error must still stop/release resources and permit Retry. Production matching and manual attendance remain unchanged.

## Expiring single-identity live pilot extension (2026-10-10 delivery)

The existing protected pilot report may explicitly authorize `SINGLE_IDENTITY_LIVE_DEMO` for one consented, already ACTIVE test reference. This is a functional demonstration, not calibration approval. The report must bind the isolated database, branch 2, batch 33, compatible model/preprocessing, expiry, identity and immutable encrypted reference hashes. Ordinary attendance permission remains required. The browser cannot supply thresholds, approve identities or select another database. No schema change.

`config` adds `pilot_mode` and `pilot_notice`. Pilot attendance responses add scalar `diagnostics`: observation/candidate counts, decision reason, processed-observation similarity range, request key and saved attendance context. Matching still stops at the first rejected observation. Optional `capture_diagnostics` contains only bounded numeric camera timings/dimensions/frame counts/quality metrics; the backend filters it into `diagnostics.camera_reported`. These client-reported values are untrusted diagnostics, not physical-camera or identity proof. The existing request journal retains those backend diagnostics. The embedded pilot panel also displays local camera/detection/quality/inference metrics; it never logs or displays images, landmarks, embeddings, ciphertext or scan tokens. Production responses omit pilot diagnostics.

New identities cannot activate in this mode. Genuine duplicate checking remains enabled against the pinned original reference; below-cutoff candidates remain PENDING. The normal multi-identity pilot and production release policy are unchanged. Expiry, report tampering, a changed reference or any additional ACTIVE identity fail closed.

Verification matrix: ready/blocked config; production connection rejected; report/model/expiry/reference tamper rejected; additional identity activation prevented; genuine duplicate rejected; below-cutoff observations unmarked; accepted observations use AttendanceDomainService; repeat requests produce one attendance/event/deduction; original reference survives; frontend diagnostics discard biometric fields; simulated real-model browser scanning with the actual protected pilot policy. Fresh physical-camera acceptance remains an operator test.

Android duplicate regression contract (2026-10-10): when matching is ready, every submitted enrollment aggregate must be duplicate-checked against all compatible other identities in the authorized database before activation, including when the selected member has a retained PENDING/ACTIVE reference. Attendance eligibility/matching filters must not narrow duplicate search. Existing references remain immutable. A rejected repeat attempt returns `state=REJECTED_DUPLICATE` and the actual persisted `enrollment_state`; an existing usable reference is preserved rather than overwritten or invalidated by a mistaken member selection. New rejected candidates persist REJECTED_DUPLICATE. Rejected observation evidence is LABEL_CONFLICT and cannot supply independent calibration labels. Pilot-only diagnostics bind capture/sample/request IDs to candidate count, best similarity, threshold, comparison source and decision. No schema or threshold change. Tests: low-score original pending then high-score duplicate repeat; identical fresh capture with no pending reference; reference preservation; exact retry; incompatible active population; global duplicate scope versus attendance matching scope; correct frontend state; actual encrypted Android-case replay using temporary tables only.


Real-batch rollout contract (October 10): authenticated `config` accepts the selected `batch_id` and returns server-derived `operating_mode` (`CAPTURE_ONLY`, `SHADOW`, `LIVE`), `scanning_available`, and safe instructions. All Attendance-authorized operators have SHADOW by default for a validated selected batch, and capture is available without a rollout approval step. A protected server rollout configuration binds exact database/branch/batch, approval and expiry for LIVE or capture-only overrides; client mode flags never authorize writes. LIVE additionally requires the existing validated production report. Enrollment capture remains consent-required and PENDING when acceptance is unavailable. `open` uses the same schedule/roster authorization; its immutable scan policy binds the operating mode. SHADOW `attendance` returns `SHADOW_EVALUATED`, processes compatible ACTIVE/PENDING references in the consenting eligible roster, stores bounded scalar candidate/rejection summaries in the existing request journal, and never calls AttendanceDomainService or activates templates. With no validated cutoff, scores are diagnostic rankings, not accepted identities. Trusted labeled checks use existing `validate_capture` and a separate visit; scanner predictions never become labels. Retention is 30 days, with a bounded shadow request count. Rollback immediately refuses new writes from any pre-existing LIVE scan when mode changes.

Verification matrix: capture-only consent/PENDING and immutable references; scoped shadow gallery includes compatible PENDING and excludes withdrawn/expired/conflicting labels/ineligible/other-branch members; shadow retries idempotent and domain counts/deductions unchanged; correct scheduled session; LIVE request cannot promote without approved scope and production report; mode rollback invalidates old writes; no-face/worker/recovery/duplicate regressions unchanged; manual controls remain visible outside isolated pilot.

Default scope needs no operator configuration: all existing Attendance-authorized branches/batches use SHADOW after roster validation. Administrators can later set `FACE_WEB_ROLLOUT_FILE` to a private file outside the web root (0600, readable by PHP). Example structure, **not installed or approved**:

```json
{"version":"browser-face-rollout-v1","scopes":[{"database":"ccc_database","branch_id":2,"batch_id":27,"mode":"LIVE","approved_by":"authorized reviewer","approved_at":"<approval timestamp>","expires_at":"<expiry timestamp>","matching_report_sha256":"<approved validated report SHA256>"}]}
```

A missing/expired/mismatched LIVE approval falls back to SHADOW; it cannot make an unvalidated configuration ready. CAPTURE_ONLY or SHADOW scope overrides support immediate rollback. Operators keep their ordinary Attendance permission. LIVE uses only ACTIVE references; source pending activation remains transactionally guarded against all compatible active and pending tenant identities. Existing excluded pilot aliases never cross from the isolated database into real batches.

Each successful enrollment capture records its operator-confirmed consent grant in the existing consent audit. Validation-only capture does not grant attendance consent. Latest withdrawal excludes ACTIVE and PENDING recognition references; a fresh explicit enrollment grant after withdrawal is ordered by timestamp and audit ID, avoiding a same-second re-enrollment race. Original usable encrypted references are preserved. Pre-existing pending captures with recorded consent and no consent-audit row remain eligible for shadow evidence; any recorded withdrawal overrides that fallback.

Private CLI `export-shadow --database NAME --branch ID --batch ID --output /private/new-file.jsonl` exports recent unlabelled summaries only, never vectors. `purge-shadow` with the same scope additionally requires `--apply --authorized-by NAME`; it removes scores after 30 days while preserving request outcome/idempotency and all attendance history. No production purge was executed. At most 10,000 unexpired/non-redacted shadow summaries are admitted per batch; later collection pauses clearly until authorized retention maintenance. These predicted scores do not satisfy independent labelled release validation.

## October 10 operator review contract

Recognition (`attendance`) is read-only for attendance in every mode, including the isolated pilot. LIVE returns REVIEW_CANDIDATE; unvalidated SHADOW may return an explicitly unverified, consistently ranked suggestion, never an accepted identity. Unknown, conflicting or ambiguous observations provide no suggestion. Drafts contain member/contact/name/context and recognition receipt keys only, no images or vectors.

`POST ?action=confirm`: scan_id, scan_token, idempotency_key, verified:true, candidates:[{person_id,receipt_key}]. Cookie/Attendance authorization, scan ownership, branch/batch/scheduled session, receipt association and current eligibility are rechecked. Only the operator-reviewed list is committed using existing AttendanceDomainService with TEACHER_CONFIRMED source. SHADOW remains unable to write via recognition, activate templates or approve matching. Confirmation returns CONFIRMED or PARTIAL_CONFIRMATION with per-member actual outcomes. A reserved request plus deterministic per-member domain keys recovers lost/partial commits. No schema changes.

Test matrix: recognition creates zero attendance/events/deductions; duplicate candidates deduplicated; unknown/conflicting faces excluded; forged/cross-actor/cross-session receipts rejected; unverified confirmation rejected; eligible confirmed members save once; partial failures and lost responses reconcile; references, consent, calibrated LIVE gate and manual attendance remain unchanged. UI finish stops camera without saving, review permits unchecked unvalidated suggestions, back resumes same lease, unsaved navigation warns, final confirmed rows alone update.

Drafts are held in the current browser session and refer to the existing server scan lease (two hours). Finishing releases media/worker resources; Back to Scanning resumes the same lease and draft. Refresh/navigation discards the in-memory draft after a native unsaved warning; no attendance is inferred from a draft. A cancelled session change preserves the original review context and requires returning to that session before confirmation. No background-camera support or draft durability across refresh is promised.

Within the authenticated Attendance branch scope, duplicate responses include duplicate_visible:true and duplicate_person_name. Cross-branch/inactive/parked identities are not disclosed and use administrator-contact wording; all tenant-compatible templates still participate in duplicate rejection. Unvalidated SHADOW suggestions require a unique, consistent top rank across the existing observation batch, are labelled UNVERIFIED_SUGGESTION and start unchecked. This ranking supplies no unknown-person acceptance guarantee and is not a biometric threshold or production approval. It must be independently checked by the operator; validated unknown/conflicting/ambiguous decisions supply no candidate.

## Field release controls — October 10

Existing `FACE_WEB_ROLLOUT_FILE` now also supports `enabled:false` for global stop, `default_mode:DISABLED|CAPTURE_ONLY|SHADOW`, and exact tenant/branch/batch scopes (existing Attendance permission is the sole operator access check; historical `operator_ids` fields are ignored). A configured missing/invalid/insecure file fails closed. Global LIVE defaults are rejected; approved report-bound exact LIVE scopes retain the original calibration gate. DISABLED scopes stop the exact tenant/branch/batch until explicitly removed. SHADOW recognition cannot write attendance; its review suggestions start unchecked. Approved LIVE/pilot follows the superseding automatic flow below and uses AttendanceDomainService without routine review.

The owner explicitly selected all Attendance-authorized branches and existing invoice consent, rather than a new face permission/cohort or guardian-registration process. Installed private configuration defaults SHADOW with no additional operator allowlist. Existing explicit capture consent/audit, withdrawals, encryption, member and eligible roster authorization remain. No new consent gate or schema was introduced; organizational invoice authorization is owner-confirmed, not independently audited by the application.

Existing administrative CLI adds `field-status` (seven-day aggregate counts, no embeddings/images) and `disable-field --apply --authorized-by`. An exact `--batch` disables only the selected tenant/branch/batch; omitting it stops the field workflow globally. Existing successful confirmation receipts remain replayable; uncommitted confirmations cannot create records after disablement. CLI rollback never deletes attendance, membership, encryption or audit history.

## Approved automatic LIVE contract (October 10)

This supersedes the previous review requirement for LIVE only. `POST attendance`
uses three to five compatible observations and the owned scan lease. SHADOW
returns review suggestions and cannot write automatically. Approved LIVE searches
only compatible ACTIVE templates in the current eligible roster, applies the
approved unanimous unique-winner decision, and calls AttendanceDomainService with
FACE_MATCH. Responses RECORDED / ALREADY_MARKED contain the actual member,
contact, attendance ID and deduction count; UNKNOWN/REJECTED change no records.
No Confirm Attendance request is needed. The request journal plus deterministic
face domain attempt recovers a lost committed response, including closed leases.
LIVE leases bind the matching report and rollout scope; changing either invalidates
new decisions. Direct client mode flags cannot select LIVE.

`POST finalize` uses the existing owned scan ID/token and idempotency key. It is
accepted only in validated, exact-scope authorized LIVE with an additional scope
`finalization: {cutoff: "SCHEDULED_SESSION_END", approved_by, approved_at}`.
The server resolves the current scheduled end in Asia/Kolkata and existing branch
attendance_settings.deduct_session_absentee. Before cutoff, SHADOW, missing rules,
unauthorized actors/scopes and pilot calls reject without absence writes. Existing
Present/Absent/exempt records are preserved. Only unmatched currently eligible
members are submitted to the existing domain service, event ATTENDANCE_FINALIZED,
source MANUAL (absence policy, no face-identity acceptance), with cutoff/scope
metadata. FINALIZED or PARTIAL_FINALIZATION reports actual per-member results.
Partial finalization can safely retry the same reserved key; committed members
reconcile without additional events/deductions. Closing a camera never finalizes.

Executable regression matrix: temporary-table automatic.php verifies pending
activation/reference preservation, LIVE save, unknown/conflicting rejection,
report-bound stale scans, repeat/lost-response recovery, closed-camera non-trigger,
cutoff authorization, preserved Present/exempt, branch absence deductions and
repeated finalization. Existing real-model browser tests exercise these LIVE writes
and continuous scanning using disposable public-photo cameras; SHADOW tests retain
explicit independent review/manual confirmation. Production validation is separate.

No reference portrait is created or reconstructed from embeddings. Historical
legacy portrait storage is separate and not evidence of authorized browser
reference-image preview. Preview remains unavailable until specific retention and
access authorization can be established; status/activation do not depend on it.
Supplemental references remain disabled: attendance never changes the original.
Any future promotion must reuse quarantined, independently corroborated labeled
captures, preserve versioned original references, cap supplemental references at
an explicitly approved limit, recheck conflicts/evaluation and support revocation.
Model predictions alone cannot supply corroboration. No adaptation collection or
promotion is enabled by this release.

October11 non-writing Testing mode: private rollout `testing_evaluation` selects
TEST only for `ccc_database`/branch2; report SHA/version/expiry are checked server-side.
Production ready/threshold fields are untouched. `config` adds `release_version` and
`test_evaluation_ready`. Enrollment returns actual `enrollment_id`, original
`calibration_id`, newly committed `capture_calibration_id`, observation_count,
persistence_succeeded, duplicate_check_status, unchanged enrollment_state and test_ready.
Statuses/options include test_ready from compatible, unexpired, nonwithdrawn database
references. New successful ENROLL captures may supply TEST-only pending references;
original lifecycle references remain unchanged, and validation/label-conflict captures
are never selected for that role. Duplicate comparison includes both originals and
current test references. `attendance` in TEST returns TEST_RECOGNIZED or TEST_UNKNOWN,
attendance_saved:false and diagnostics; it never calls the attendance writer. Direct
`confirm` rejects a TEST lease even with verified:true; mode/report changes invalidate
a lease. Global/batch disable and the10,000 per-batch diagnostic limit still apply.

`camera_event` accepts only camera_recovery on authorized TEST/pilot member/scan context,
uses standard CSRF/Attendance permissions and idempotency, and stores no camera data.
Client sends at most8 notices per camera opening. Sparse event logs use the protected
FACE_WEB_EVENT_LOG setting, allowlisted metadata and2MiB/two-backup rotation. Committed
persistence/replays are distinguished from failure and from attendance_saved:false.
HTTP X-Face-Request-ID uses the valid idempotency key or a generated opaque correlation
ID for malformed/unauthenticated requests; raw payloads/exceptions/tokens are never logged.

On server/database failures, logs mark UNKNOWN_RECONCILE_IDEMPOTENCY rather than
claiming no save; explicit input rejection remains persistence_succeeded:false.
The original key/receipt must be reconciled. A real post-domain-commit receipt failure
and exact-key recovery are covered by testing.php, with one event/deduction.

### Testing-only Punch (testing-20261012-1)

Existing cookie/HTTPS/CSRF/Attendance/branch access applies; no new role. TEST recognition
returns test_receipt_key/test_scan_id and stays non-writing. POST action=test_punch:
`{batch_id,attendance_date,attendance_session_id,selected_time,candidates:[{person_id,receipt_key}],idempotency_key}`.
Empty/duplicate/foreign/stale/conflicted candidates fail server-side. The complete
receipt set is validated before isolated domain writes. Response TEST_PUNCHED or
PARTIAL_TEST_PUNCH has results containing person_id,contact_id,state,test_attendance_id,
deducted_sessions; attendance_saved=false, test_only=true. Test IDs must never be
assigned to operational attendance_id. Unknown network outcomes retry identical keys;
returned partial outcomes allow retrying only unsaved members. Source journal/domain
uniqueness recover already committed test results. SHADOW/LIVE cannot call this action.
GET action=test_attendance uses the same batch/date/session/time fields and returns
persisted PRESENT_PUNCHED_TEST rows under current eligibility/context checks.
`update_face:true` on authorized TEST enrollment records update intent; original
production references/state remain unchanged, and failed/duplicate updates are excluded
from current TEST-reference selection. No raw portrait retention is introduced.
