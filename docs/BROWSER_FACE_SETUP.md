# Automatic browser face enrollment and attendance

Everyday enrollment and Scan Arrivals are embedded directly in `/pf/attendance.php`, with the existing CCC sign-in and Attendance permission. Attendance's Scan Arrivals and Face enrollment actions open this flow. There is no shutter, pose sequence, blink requirement or capture approval. PHP/MySQL and the existing attendance domain remain authoritative. No additional service, database engine, native app or frontend framework is required.

## Installation

Requirements: existing CCC PHP/MySQL installation and face/attendance migrations, PHP mysqli/OpenSSL, Node 20+, existing inference Python environment with ONNX. Existing face templates from a different preprocessing version cannot be mixed: new enrollment explicitly blocks rather than silently omit them from duplicate search. Review an authorized migration or deletion/re-enrollment before switching populations; this task does not erase existing data.

From the repository root:

```sh
npm ci --no-audit --no-fund
/var/lib/creativeculturalcentre/face-inference/venv/bin/python scripts/prepare_browser_face.py
```

The preparation script verifies the existing YuNet and SFace model checksums, removes stale SFace weight-input declarations (same as the existing Python encoder), and publishes model files plus pinned ONNX Runtime Web 1.24.3 to `pf/vendor/browser-face/`. No model weights change. Artifacts are ignored by Git; retain them in the deployment bundle or prepare them on the target host. To obtain missing original assets, use the source URLs and checksums in `services/face-inference/models/MODEL-MANIFEST.json`. Recognition remains SFace, with the recorded Apache-2.0 directory license; detector YuNet has MIT licensing. Upstream sources: [SFace](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface), [YuNet](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet), [ORT](https://github.com/microsoft/onnxruntime/blob/v1.24.3/LICENSE). Existing licensing/intended-use records remain applicable; no new licensing claim is made about training data.

Review `database/migrations/20261009_01_browser_face.sql`, back up the selected tenant, then apply it to that tenant only:

```sh
mysql --defaults-extra-file=/secure/path/tenant.cnf TENANT_DATABASE < database/migrations/20261009_01_browser_face.sql
mysql --defaults-extra-file=/secure/path/tenant.cnf TENANT_DATABASE < database/migrations/20261009_02_browser_face_collection.sql
mysql --defaults-extra-file=/secure/path/tenant.cnf TENANT_DATABASE < database/migrations/20261009_03_browser_face_lifecycle.sql
```

The migrations add request/rate-limit journaling, the model registry entry, encrypted calibration and migration-audit storage, persistent enrollment candidates and complete observation evidence. They do not activate candidates. While that browser registry is active/pending calibration, legacy enrollment/reference-creation/admin-activation writers return `AUTOMATIC_WEB_ENROLLMENT_REQUIRED` and direct the operator to this one web flow. Legacy status/deletion endpoints remain available. This prevents legacy writes from bypassing the new central duplicate boundary. Apply the rollout with no enrollment saves in flight. It does not alter or delete historical attendance or enrollments. Do not apply it to every tenant without selecting the intended deployment scope. Initial implementation did not apply this migration. On 2026-10-09, the user authorized live camera testing in Testing branch (2); the migration was applied to its tenant `ccc_database` after backup. Other tenants were not migrated. See `docs/IMPLEMENTATION_STATE.md` for the created testing batch/member IDs and operational changes.

Copy the keys in `services/face-inference/browser-face.env.example` into the existing secure server configuration. Reuse the current encryption key/key ID; do not rotate it merely to enable this screen. Keys and private calibration datasets must be outside publicly served directories or denied by Nginx, with filesystem permissions restricted to the application account. No keys appear in config/health responses.

For a localhost verification server at the documented port:

```sh
php -S 127.0.0.1:8137 -t .
```

Open `http://127.0.0.1:8137/signin.html`, sign in through CCC, then open `/pf/attendance.php`. The existing app's local authentication/database configuration must already be usable. The local HTTPS exception applies only to the CLI server on loopback port 8137; deployments require HTTPS.

## HTTPS deployment

Use the existing TLS-enabled Nginx/PHP-FPM server. Include `.mjs` as `application/javascript` and `.wasm` as `application/wasm`. Serve model/ORT files at their existing same-origin `/pf/vendor/browser-face/` paths. Single-thread WASM deliberately works without COOP/COEP or SharedArrayBuffer. Do not add an external CDN or browser-exposed template endpoint. [ORT deployment guidance](https://onnxruntime.ai/docs/tutorials/web/deploy.html) describes the same-origin runtime asset requirements.

The page sends a CSP allowing same-origin workers and WASM execution, forbids framing outside the origin, and disables response caching. The API requires same-origin cookie authentication, a page-issued CSRF token and Attendance permission. Never proxy untrusted `uid`/`bid` headers to this endpoint. The application resolves tenant/actor/branch from the authenticated server session. Existing app login/session policy still needs to provide Secure/HttpOnly/SameSite cookies in production; use PHP `session.cookie_secure=1`, `session.cookie_httponly=1`, and `session.cookie_samesite=Lax` in the relevant pool/server configuration after checking other CCC integrations.

Recommended Nginx access restrictions, merged into existing rules (do not blindly replace Nginx config):

```nginx
location ^~ /tests/ { deny all; }
location ^~ /evaluation/ { deny all; }
location ^~ /scripts/ { deny all; }
location ^~ /services/ { deny all; }
location ~ /\. { deny all; }
```

After an explicitly authorized deployment, validate Nginx configuration and reload through the existing deployment procedure. Verify authenticated `GET /routes/browserFaceRoute.php?action=health`: assets must be ready and acceptance remains false until live calibration is approved. Missing schema/encryption returns a bounded readiness error. Never enable acceptance by substituting fixture thresholds or renaming synthetic evidence.

## Operator workflow

During initial matching setup, Enroll Face on attendance.php also collects the selected consenting member's calibration observation automatically. The notice above the rows explains the consent confirmed by clicking. No separate calibration page, manual labels, shutter or guided poses are required. Camera/device metadata is hashed; lighting labels are measured face brightness buckets, not verified room lighting. The server uses the actual capture date as a visit: the first two distinct dates are calibration and later dates validation. Repeated captures on the same day never manufacture independent validation visits. Samples are encrypted and expire in 30 days. The row says Face sample saved; matching setup pending, never Face ready before an active enrollment exists.

This automatic collection removes the operator's calibration data-entry step; it does not eliminate the need for representative people, independent dates/devices/conditions, evaluation and a validated configuration. Unknown scanner observations cannot supply trusted identity labels and are not used as automatic enrollment evidence. Attendance remains unmarked until identity acceptance is ready. After approved matching setup, Enroll Face follows ordinary central duplicate checking and Scan Arrivals uses AttendanceDomainService. Pending observations alone are not active enrollments.

1. On attendance.php, select the existing batch/date/time and click Enroll Face on the member row. The selected member is passed automatically. Capture collects five usable spaced observations; the backend checks the aggregate once and returns saved, duplicate, already enrolled, or a clear blocker. The row updates after a confirmed database save.
2. Click Scan Arrivals on the same attendance page. All observations must agree on exactly one eligible identity before the existing attendance transaction saves presence. The row updates and the camera continues. Unknown/ambiguous/conflicting faces stay unmarked. Membership deductions and one-record-per-person/session remain protected by existing database constraints.
3. Cancel closes media tracks and processing. A request already submitted may have committed; Retry checks the same request/key rather than claim cancellation undid the save. The API reserves attendance request identity before the attendance domain transaction, and recovers its committed event if a response/journal update is lost.
4. Delete face enrollment erases template ciphertext and withdraws the new workflow's authorization without deleting attendance history. Enrollment can then restart. Old reference portraits from legacy enrollment are governed by the existing legacy deletion process; this new workflow creates none.

Current sessions last at most two hours. Reopen the camera/session afterward. After 30 new mutation requests per operator/minute, slow down and retry later. Completed idempotent retries do not consume new submissions. Keep the request journal according to the organization's attendance audit policy; do not purge unresolved `PENDING` rows while requests may be retried.

## Automatic evidence, lifecycle and approval

Everything ordinary stays in attendance.php. Enroll Face on the selected consenting member automatically collects observations and saves a persistent encrypted candidate plus the encrypted full batch. With no validated matching policy it reports PENDING (“Face captured. Enrollment will finish automatically when the system is ready.”). Only ACTIVE reports “Face enrolled.” Duplicate and ambiguous states are distinct and request correction/recapture. Explicit consent is shown in the page; obtain guardian consent where applicable. No raw images, photograph approval or predicted calibration labels.

Optional Check face on a manually selected member collects ground truth without creating an enrollment/attendance. Keep designated unknown people unenrolled and use this action to measure unknown rejection against the eligible enrolled gallery. Current batch/date/session are verified server-side. First two actual distinct dates are calibration, later dates validation; same-day repeats cannot become independent visits. Held-out captures cannot replace the pending training template. Existing aggregate-only samples remain limited evidence, never fabricated full batches.

Evidence expires after 30 days and is excluded from decisions/export. Explicit deletion clears candidate/calibration/evidence ciphertext, preserving attendance. Run private expiry purge daily to physically erase expired ciphertext; expiry alone does not erase an idle database. No global cron was installed. Exports must be outside the webroot, exclusive mode 0600, and erased after evaluation. Backups need equally controlled retention.

```sh
php scripts/browser_face_admin.php export-calibration --database ccc_database --branch 2 --output /private/face-observations.jsonl
python3 evaluation/calibrate_thresholds.py /private/face-observations.jsonl --output /private/face-evaluation.json
php scripts/browser_face_admin.php purge-expired --database ccc_database --branch 2 --apply
```

Do not paste vectors into chat/logs or commit exports. Review the report, then use reviewed numerical risk budgets via --duplicate-max-far and --attendance-max-far. These flags have no invented defaults. Duplicate FAR controls false duplicate rejection of a genuinely different person; also inspect missed duplicates. Attendance FAR controls unknown acceptance and wrong known-person identification. Reject-everyone decisions cannot justify release. Reports evaluate actual aggregate enrollment templates and complete attendance batches against server-verified eligible galleries, not just embedding pairs, and include independent results/counts/uncertainty.

The established preliminary coverage remains 20 identities/100 aggregate observations, two calibration dates and two different later validation dates per identity, representative cameras/lighting, withheld unknown identities and independent workflow review. These counts are not proof of adequate accuracy. Collect through the same row actions; there is no ordinary separate calibration task. The older face-calibration.php collector is optional advanced compatibility tooling and saves aggregates only, which cannot alone satisfy complete-decision release requirements.

Only after genuinely acceptable independent evaluation/workflow results and explicit rollout approval, install a private policy containing current contract versions, exact measured DUPLICATE_THRESHOLD and ATTENDANCE_THRESHOLD, actual identities/samples, source: consented-live-cameras, independent_validation:true, independent_workflow_validation:true, report_path, report_sha256, approved:true, approved_by and approved_at. FACE_WEB_CALIBRATION_FILE points to it. The loader checks hash, source, versions, counts, no report blockers, complete held-out decisions and exact recommendations. It does not accept public/synthetic/aggregate-only reports.

Ready options/enrollment-status/scanner access triggers activation automatically. All active and unresolved pending identities are checked tenant-wide under the existing lock/transaction; activation only changes authorized branch candidates. Version/key/consent/expiry failures and pending identity conflicts require recapture; active duplicates are rejected. An administrative sweep is also available after validated deployment:

```sh
php scripts/browser_face_admin.php activate-pending --database ccc_database --branch 2 --actor EXISTING_ATTENDANCE_OPERATOR_ID
```

This command is safe to retry and refuses an unvalidated policy. No general production bypass exists. Disable the protected policy to halt future matching if rollout fails; this does not undo committed attendance. Do not destructively restore a database or resurrect legacy templates as routine rollback.

## Controlled consenting-person pilot

The Testing branch shares ccc_database; it is not an isolated matching environment. A physically separate, ownership-checked database has been provisioned: ccc_database_face_pilot_10092026. Its exact allowlist is source ccc_database / branch 2 / batch 33 / members 623,624,625 / contacts 791,792,793 / items 1188,1189,1190. No source biometrics or attendance are copied. Local test-only membership views reflect local deductions. Guarded UI and endpoints reject legacy/manual writes; every request verifies the connection, marker, population, local views and absence of triggers/cross-schema FKs. Daily dated sessions are provisioned only in the pilot for the seven-day collection window.

The protected pilot configuration is /tmp/ccc-face-pilot-20261009.json, root:www-data mode 0640, referenced by FACE_WEB_PILOT_FILE. It expires after seven days. No expiry extension is automatic. Source test invoices have employee_id=0 and are omitted by the production invoice view; only this pilot seeds their explicit zero-value test membership from the existing module roster. Production-domain eligibility of those source fixtures is not claimed, and no source invoice or deduction was changed.

1. Sign into Testing branch 2 and open /pf/attendance.php?face_pilot=1. Confirm Practice banner and batch 33. Use Enroll Face for two genuinely different consenting test people under One/Two; repeat each capture twice initially. Keep Three unenrolled and use Check face for labeled unknown observations. PENDING is expected before provisional pilot evidence is installed.
2. Export and evaluate actual pilot-only observations privately:

```sh
php scripts/browser_face_pilot.php verify /tmp/ccc-face-pilot-20261009.json
php scripts/browser_face_admin.php export-calibration --database ccc_database_face_pilot_10092026 --branch 2 --output /private/pilot-observations.jsonl
python3 evaluation/calibrate_thresholds.py /private/pilot-observations.jsonl --output /private/pilot-evaluation.json
php scripts/browser_face_pilot.php update-report /tmp/ccc-face-pilot-20261009.json /private/pilot-evaluation.json
```

The provisional report is derived from actual compatible observations and expressly production_approved:false. update-report refuses wrong source/database/branch/identity/model/report binding or insufficient exploratory evidence. It does not change production thresholds. Review exploratory error/uncertainty; same-session fit is not independent accuracy. Existing 16 source samples include member 616 and cannot enable this pilot.

3. Reopen/refresh the pilot attendance page. Valid unique pending test candidates activate through the same lifecycle. Exercise real duplicate/distinct enrollment, known/unknown/repeat attendance, one event/deduction, sequential continuous recognition and recovery. Confirm rows in the separate database and record physical browser/device results. Duplicate tests may create RECAPTURE_REQUIRED instead of selecting an arbitrary identity when two pending templates conflict.
4. Gather later independent labeled captures via Check face and proceed separately toward production evidence/review. Three pilot people cannot meet the production release gate. A reviewed pilot is not production activation.

Provisioning commands for an explicitly authorized future isolated pilot: create/verify/update-report/session-days using scripts/browser_face_pilot.php and a reviewed private exact-scope configuration. create refuses to overwrite an existing database. Do not point the pilot at ccc_database. Only consenting captures, independent acceptance/risk approval and authorized production rollout remain human-required.

## Executable verification

```sh
npm run test:face
php tests/browser-face/authorization.php
php tests/browser-face/policy.php
php tests/browser-face/migration.php
php tests/browser-face/lifecycle.php
php tests/browser-face/pilot.php
python3 -m unittest discover -s evaluation -p 'test_*.py' -v
```

The backend test uses only connection-scoped temporary tables in `ccc_database_test`; it never modifies persistent tenant tables. Required infrastructure errors fail visibly. It uses the existing connector; a local test installation may need to adapt connection credentials through its standard configuration.

Browser tests require Chromium and public fixtures; see `tests/browser-face/README.md` for exact setup, isolated integration DB creation and cleanup. The test router runs only in a CLI server on loopback with a dedicated fixture key/database; it injects test policy into the real service constructor. The production routes have no test mode, and are not supplied a fabricated calibration artifact. Tests never replace the model or database responses with mocked identity success. Actual scoped timings and memory observations are recorded in `docs/IMPLEMENTATION_STATE.md`.

## Physical-device checklist and limits

On desktop Chrome, Android Chrome and iPhone Safari, record the actual device/browser/camera and repeat: permission grant/denial, centered/offset face, portrait/landscape rotation, mirrored overlay, no face, two faces, small/blurred/dark/overexposed face, automatic enrollment, genuine duplicate, distinct face, known/unknown/ambiguous attendance, repeated person, independent sessions, lost connection/response, cancel while saving, restart, background/foreground, navigation, deletion and fresh enrollment. Inspect saved row counts, membership deductions, camera-track cleanup and authorization boundaries.

Measure cold/warm startup, detection, recognition, completion, backend latency and sustained memory. `performance.memory` alone excludes some worker/camera/native memory; do not claim total application memory from it. Desktop resize/emulated mobile is a layout check, not physical mobile verification. Automated public-photo tests demonstrate functionality and also illustrate spoofing risk. Without validated presentation-attack defenses this is unsuitable as a sole security control or a high-assurance biometric authenticator.


## Current rollout and legacy history

Migrations 01/02/03 were applied only to ccc_database after fresh private backups and authorized test rollout. Latest lifecycle backup: /tmp/ccc-face-prelifecycle-20261009.sql (0600). One prior journal-confirmed member 616 enrollment capture was recovered as PENDING, not ACTIVE; standalone calibration samples were not promoted. Attendance/events were unchanged. Other tenants were not migrated.

Legacy templates 94/95/96 all belonged to member 616, contact 774, branch 2. The user's explicit authorization allowed targeted erasure/revocation; incompatible ACTIVE count is now zero. Original reference 103, historical metadata and attendance remain. The ordinary page has no legacy replacement control. The exact allowlisted retained-history migration/rollback mechanism remains tested for future authorized replacements, but erased vectors require explicitly authorized recovery from the private pre-retirement backup. Do not revive old enrollment writers or assume embeddings can be converted between preprocessing contracts.

See BROWSER_FACE_FINAL_DELIVERY.md and IMPLEMENTATION_STATE.md for actual tests, all acceptance statuses and current human-required steps. Production attendance is not operational/verified until consented independent evidence and rollout criteria are satisfied.


## Final physical test status

No physical camera/participants are available to the agent; A–J real-person tests are BLOCKED. Current source evidence is 20 aggregate captures/six selected labels/four full batches/zero validation/five PENDING/zero ACTIVE. The isolated pilot has zero captures/attendance and matching disabled. Preliminary count deficits are at least14 identities/80 aggregates if existing labels are confirmed distinct; full independent known/unknown trials and review are also required. Detailed operator A–J steps and exact limits are in BROWSER_FACE_FINAL_DELIVERY.md. Current pilot expires 2026-10-16 18:10:59 IST.

Final focused fixes preserve original usable enrollment references, reset partial capture on geometric track loss, send changed similar-looking observations to central recognition, show unusable pending captures as recapture, allow new administrative activation sweeps to process later candidates, and keep a healthy empty attendance scanner running. Runtime model/preprocessing and production calibration requirements are unchanged. No production data/configuration mutation occurred during this final validation continuation.

## October 10 field release

The deployed `browser-face-field-20261010-1` release uses the existing Attendance permission in every authorized branch/batch. The owner explicitly approved this broad permission scope and confirmed authorization is obtained through the existing invoice process. No new guardian registration screen, face-specific permission, or operator allowlist was installed. The organization must ensure that its invoice authorization/notice covers children's encrypted face enrollment, attendance suggestions and retained test observations; this statement records the owner's instruction, not independent legal verification.

The protected `/var/lib/creativeculturalcentre/browser-face-field-rollout.json` (root:www-data,0640) is selected by the existing `.env` setting. It defaults SHADOW, leaves exact LIVE scopes empty, and does not approve matching or activate pending templates. Only independently reviewed, explicitly selected suggestions reach `confirm` and AttendanceDomainService as TEACHER_CONFIRMED. All proposals start unchecked. See the short [operator guide](BROWSER_FACE_OPERATOR_GUIDE.md).

Emergency commands (authorized server administrator only):

```sh
php scripts/browser_face_admin.php field-status --database ccc_database --branch 2 --batch 27
# Disable only an affected batch; replace IDs with the incident's actual scope.
php scripts/browser_face_admin.php disable-field --database ccc_database --branch 2 --batch 27 --apply --authorized-by 'Incident owner'
# Disable field capture/shadow/new confirmations globally. Manual attendance stays available.
php scripts/browser_face_admin.php disable-field --database ccc_database --branch 2 --apply --authorized-by 'Incident owner'
```

Since release `testing-20261013-1` the rollout file lives in `/var/lib/creativeculturalcentre/browser-face-rollout/rollout.json` (directory root:www-data 2770, file 0660, selected by `FACE_WEB_ROLLOUT_FILE` in the existing `.env`), so the administrator page below can change it. The CLI commands above keep working unchanged against that path.

The file is read on each backend request: no service restart is needed. Close existing camera panels after disabling; the server blocks new work and preserves successful confirmation recovery. Re-enable only after incident review by atomically restoring the approved configuration, never by turning on LIVE or deleting history. If code rollback is necessary, use the private source snapshot/manifest from this release, not `git checkout` over the accumulated uncommitted implementation. The source baseline predates these field-control fixes, so code rollback must preserve the server stop gate until compatibility is reviewed.

Private recovery baseline: `/tmp/ccc-field-release-20261010/` contains the original HEAD/worktree record, source-baseline archive, environment backup (0600), fresh transactional compressed tenant dump and checksum. Critical data tables restored successfully into connection-local temporary tables. Verified copies are preserved in `/var/backups/creativeculturalcentre/face-field-release/20261010/` (private directory), with separate protected environment/key recovery in `/var/backups/ccc-face/browser-field-20261010.environment` (0600). This is a local recovery rehearsal, not a verified full-server or off-site disaster recovery. Arrange off-site recovery through the organization's existing backup process; `/tmp` alone is not used as the durable backup destination. No schema migration was needed for this release.

Use existing privacy-safe request journal aggregates and Nginx error/status logs to investigate backend failures. `field-status` contains no face vectors/images; shadow summaries are bounded and explicitly unlabelled. Camera failures/recovery are in the existing bounded in-memory health diagnostics; there is no new per-frame log or analytics platform. Suggestions removed on the client and physical camera failures are not centrally aggregated. Report device/browser, time, session, visible error, and whether preview or recognition stopped; do not send biometric vectors or screenshots of children into ordinary support logs.

Organizational review: confirm invoice notice/parental authorization, withdrawal process, retention/deletion (expiry excludes samples but does not physically erase all ciphertext), access and key recovery. India's [official DPDP Rules notification](https://www.meity.gov.in/static/uploads/2025/11/53450e6e5dc0bfa85ebd78686cadad39.pdf) includes phased commencement and a verifiable-parent-consent framework; obtain organization-specific advice about current applicability/exemptions rather than claiming this implementation is certified or legally approved. Physical staff acceptance and independent biometric accuracy remain separate release evidence.

## Automatic LIVE release preparation (supersedes LIVE review)

`automatic-20261010-1` restores automatic accepted LIVE arrivals. SHADOW remains
an evaluation/manual-confirmation workflow. Current deployment stays SHADOW;
no production thresholds or ACTIVE references are installed by this change.

First approved batch activation uses the existing process:
1. Collect independently labeled, consented current-model enrollment and later
   validation captures through Enroll Face / Check face. Predictions are not labels.
2. Run the existing private export and calibrate_thresholds.py decision evaluator.
   Satisfy the unchanged live evidence requirements and review actual duplicate,
   roster identification/unknown errors and uncertainty; approve the report.
3. Install the protected report-bound FACE_WEB_CALIBRATION_FILE configuration.
   Authorize exactly one tenant/branch/batch LIVE scope, with matching_report_sha256,
   reviewer/time/expiry (and optional existing operator scope). Do not set a global
   LIVE default. Existing DISABLED controls provide immediate rollback.
4. Approve the existing branch absence-deduction setting and scheduled session end
   explicitly through `finalization: {"cutoff":"SCHEDULED_SESSION_END",
   "approved_by":"...","approved_at":"..."}` in that exact scope.
5. Run existing `activate-pending --database ... --branch ... --batch ... --actor ...`;
   it rechecks consent/compatibility and tenant-wide active/pending conflicts within
   a transaction, without replacing original references or requiring valid pending
   captures to be repeated. Conflicts/expired evidence stay inactive.
6. Using the existing cron mechanism, schedule (only after LIVE/cutoff approval):
   `php scripts/browser_face_admin.php finalize-due --database TENANT --branch BRANCH`.
   This scans at most100 oldest unfinalized browser LIVE leases per run, including
   closed cameras, rechecks current operator access/report/exact rollout, and uses
   server scheduled end/branch rules. Run periodically; finalized leases are excluded.
   Pending committed outcomes reconcile; partial finalizations retry safely.
   Camera callbacks also check cutoff every30seconds while visible/open. Camera
   close/permission failure alone never invokes finalization. The scheduler is
   **prepared, not installed or production-executed** in the unvalidated release.
7. Verify one consenting real batch physically: persistent ACTIVE reference,
   automatic Present/update, unknown/ambiguous rejection, sequential arrivals,
   repeat/restart/retry, cutoff preservation and deductions. Then approve expansion.

No new portrait retention or preview is enabled. Browser references contain only
embeddings; one historical legacy portrait exists but recorded authorization does
not establish the additional reference-image preview purpose. The face-status
column remains available without preview. Specific image-purpose authorization is
required before securely reusing/retaining a portrait; it is not an activation gate.
Supplemental-template adaptation is disabled. Future optional promotion must use
independently corroborated identity evidence, a quarantined candidate, explicit
consent/retention, original-reference preservation, versioned references (maximum
2 supplemental references per identity initially), conflict checks, offline evaluation
and revocation/rollback. Attendance predictions never modify the original template.

## Operator access clarification — October 11

Attendance permission in the authenticated tenant/branch is the sole face operator
permission. Existing member and invoice/batch roster checks supply member/session
context. Historical `operator_ids` rollout fields are ignored. No face role, PIN,
extra login or daily approval is required. Approved LIVE has no routine review;
report-bound batch rollout, model compatibility, ACTIVE state, consent withdrawal
and attendance eligibility remain enforced. Explicit authorization for destructive
legacy replacement/rollback is preserved; it is not ordinary operator access.

Read-only onboarding audit: all five invoice_settings rows point to
https://tickleright.com/terms-and-conditions/ and have tnc_accept=0 (the configured
automatic terms-message dispatch is disabled, not proof that paper consent is
absent). The117 invoice_terms rows contain four distinct clauses about internal
class recording, promotional parental consent, recording restrictions and fees.
These clauses do not specify facial identification, encrypted attendance templates,
test-observation retention or optional reference portrait viewing. The public terms
and app privacy policy likewise do not establish that specific authorization; the
app policy's camera-upload and child privacy sections require organizational review
for this use. The owner's invoice-consent instruction remains recorded, but operator
capture attestation is not an independently verified parental agreement. No invoice
terms, consent rows or release policy were altered by this audit.

Resolve any uncovered purpose within the existing parent onboarding agreement,
once: explain face enrollment/attendance and encrypted template retention, testing
observation retention/deletion, withdrawal/manual attendance fallback and authorized
access. Optional encrypted reference-photo retention and viewing must be separately
clear in that existing agreement if enabled. This is proposed notice content for
organizational approval, not silently published parent authorization. No extra
operator screen or face permission is introduced. Browser preview remains disabled
and no new photos are retained. The existing historical portrait endpoint reuses
Member/Attendance access, now binds tenant/operator/branch to authenticated context
and sends no-store responses; request actor/client/branch values cannot grant access.

## Testing branch immediate recognition — October 11

Normal `attendance.php`, authenticated tenant `ccc_database`, branch2: a protected
`testing_evaluation` entry in the existing rollout file selects non-writing `TEST`
for existing authorized batches. No operator role, popup, login or approval is added.
The owner confirms physical parental documents cover enrollment/attendance; reuse
existing onboarding administration. Preview remains optional/disabled and no new
portraits are retained.

Captures commit encrypted PENDING enrollment/evidence before reporting **Face saved.
Ready for testing.** Database-backed statuses persist across refresh/login. New
successful authorized ENROLL captures can supply the latest TEST-only pending
reference; original enrollment ciphertext/calibration/session stay immutable. Failed
or duplicate captures and validation-only captures cannot replace that test reference.
Duplicate checks compare the submitted aggregate to both original and current
compatible Testing references for other members. Approved production activation still
uses its original protected lifecycle; TEST is never ACTIVE or production approval.

Scan Arrivals compares3–5 consistent observations to the current eligible Testing
roster and returns **Recognized: name**, unknown, or ambiguity. There is no TEST review
queue or confirmation. The backend rejects TEST confirmation and never calls
AttendanceDomainService for TEST matching. Testing shares operational tables; use the
existing separate Practice pilot for test attendance writes, currently restricted to
its authorized623 identity. Manual attendance remains available in normal batches.

The initial TEST cutoffs reuse the existing hash-bound genuine-only provisional
pilot report (attendance0.6513243536866755, duplicate0.746782791039185), **not validated
multi-person/child accuracy or production settings**. No values were fitted or lowered
in this rollout. Config/report expiry and existing global/batch disable controls stop
TEST. The configuration expires October16,2026 at18:10:59 IST; production LIVE still
requires its unchanged independent validation and exact approved batch rollout.

Sparse server events use `FACE_WEB_EVENT_LOG`, a protected JSON-lines destination
outside the webroot, with2MiB rotation and two backups. Only allowlisted identifiers,
state/reason, counters, durations and persistence/replay results are recorded. Saved
results are logged after transaction commit; failures and exact-key retries are
separate events. The existing MySQL request journal remains authoritative. Up to8
camera recovery notices per opened camera are sent; no per-frame logs, images,
embeddings, keys, documents or tokens. Private event logs are server-administration
material, never a teacher camera overlay or public URL.

Android test: hard refresh normal attendance.php; choose Testing and the intended
batch/date/time; select the correct consenting member; Enroll Face; wait for automatic
save; refresh and confirm Saved for testing; enroll genuinely different members;
Scan Arrivals; verify the displayed name, leave the circle and show the next person.
No attendance should change in TEST. Show an unknown face; a wrong/ambiguous name
must be reported rather than treated as accuracy validation. Close/reopen, switch
cameras and briefly background/restore. For actual test Present/Already marked use
the existing isolated Practice page, not the shared Testing operational session.

Existing616–620 references strongly overlap (0.8485–0.9751); labels do not prove distinct
people. Do not guess or silently overwrite them. Fresh duplicate/ambiguity responses
are expected when the same face was attached to multiple members. Incorrect labels
need the existing operator-confirmed reversible exclusion mechanism; records and
historical attendance are preserved until the owner identifies the exact labels.

## October 11 Testing reset and identity-consistency release

`testing-20261011-2` handles multi-member duplicate conflicts without naming an
arbitrary child, distinguishes saved references from rejected attempts, and clears
camera/ring resources on the result screen. Retry starts a new member-bound capture.
Server-derived conflicting galleries cannot yield a TEST identity. Production gates
and all thresholds are unchanged.

The owner authorized a clean reset of prior face data for Testing616–620. Exactly35
samples are reversibly excluded from calibration, duplicate matching and recognition;
encrypted originals/audit remain. Normal rows show Not captured. A fresh authorized
capture becomes Saved for testing when unique. No old identity was silently relabeled.
Private audited recovery is in `/var/backups/ccc-face/testing-reset-20261011/`; the
existing rollback-label-exclusion command refuses to overwrite later new captures.
Practice623 and operational attendance/memberships remain untouched.

Android:hard refresh attendance.php;select Testing and correct batch/date/time;
enroll one consenting person under their intended member;verify Saved for testing
after refresh;show the same person under another member and verify duplicate plus
clean Retry/Close;enroll genuinely different people under their correct members;
Scan Arrivals,leave circle between people,and verify each displayed name plus unknown
rejection. TEST writes no operational attendance. Physical accuracy after this reset
is not claimed by automated public-photo camera tests. Use existing isolated Practice
for test Present/Already marked. No new roles, consent camera popup or ordinary review.

## Testing Punch workflow — October12 release

`testing-20261012-1`: normal Testing attendance.php uses Enroll Face when no usable
reference exists and Update Face when the database says TEST-ready. Update selects
one newer successful TEST reference; originals remain encrypted and unchanged.
Scan Arrivals stages Present—Pending Punch without saving attendance. Punch Attendance
stops the scanner and saves only authorized accepted receipt-bound members through
AttendanceDomainService into the existing isolated Practice database's
face_testing_attendance/face_testing_attendance_event. These two additive tables are
installed there; never apply this migration to an operational tenant. Normal Practice
623 and real attendance/membership tables stay untouched. No new operator permission,
identity-review list or activation approval is needed for TEST. Pending captures are
not production ACTIVE. Other branches/LIVE safeguards are unchanged.

Hard refresh, select Testing/batch/date/time; enroll genuinely different consenting
participants under correct members; verify Update Face after refresh. Scan sequentially,
leave the circle between arrivals; verify correct Present—Pending Punch rows. Punch,
then refresh and confirm Present(Test). Zero staged members disables Punch; unknown/
ambiguous faces change no row. Reset unsaved scan discards drafts only. Changing session
warns before losing drafts. Unsaved drafts are not persisted on deliberate reload;
punched test records are. Retry an interrupted Punch with the original frozen request.

The deployed backend verification created one isolated TEST record for616/contact774,
batch27/October11/session0 using stored-observation replay. It is not a fresh physical
camera result or operational attendance. Today's session remains a separate test.
Physical Android/iPhone acceptance and representative biometric accuracy remain to
be established. The protected provisional TEST configuration expires October16,2026
18:10:59IST and cannot enable production automatically.

### Testing release testing-20261012-2: paused enrollment

Hard refresh attendance.php. During Enroll/Update Face, accepted observations stay
on the circular ring when positioning, visibility or image quality is interrupted.
The original face must pass three fresh suitable identity checks before collection
resumes. A different or uncertain face cannot advance progress. Return the original
person, improve positioning/lighting, or use Retry to discard this partial attempt.
Close, member change and explicit camera switching also start fresh; internal camera
recovery retains the attempt but requires re-verification. No unfinished observations
are persisted. Old saved references survive failed/interrupted updates.

Android acceptance uses two genuinely different consenting people: collect3/5 for A,
remove A,confirm60% stays,show B,confirm no advance/save,return A,confirm re-verification
and completion. Then verify Update Face after refresh and Scan Arrivals→Pending Punch
→Punch Attendance→persisted Present(Test). Repeat once after background/restore.
This new physical behavior is not yet agent-verified. TEST continuity remains provisional;
production matching/activation are unchanged. Git/evidence and exact checks are in the
latest IMPLEMENTATION_STATE.md entry.


## Face Attendance Settings (administrator page)

`pf/face-attendance-settings.php` (menu: Settings → Face Attendance) is the one place an administrator changes where face features are available, without editing code or deploying. Access is the same check as `face-pilot-config.php` (administrator, position 1); ordinary Attendance operators get 403 and cannot post changes. It adds no role, PIN or service: `BrowserFaceRolloutAdmin` only writes the existing `browser-face-rollout-v1` file, and `BrowserFacePolicy::rollout()` remains the single reader/enforcer for every face request.

Availability and automatic attendance are separate. Enrollment and non-writing scanning (SHADOW) need no matching approval; they are on by default for every branch (`default_mode: SHADOW`) and the page overrides that per branch or batch:

| Page setting | Stored mode | Effect (enforced server-side) |
|---|---|---|
| Disabled | `DISABLED` | every face request is refused (`FIELD_RELEASE_DISABLED`); manual attendance unaffected |
| Shadow, enrollment on, scanning off | `CAPTURE_ONLY` | Enroll/Update Face works; Scan Arrivals is refused |
| Shadow, enrollment and scanning on | `SHADOW` | recognition suggestions only; no automatic attendance |
| Live | `LIVE` | automatic Present through AttendanceDomainService |

* Scopes are exact tenant/branch/batch entries; a batch id of `0` is a branch-wide scope. A branch-wide `DISABLED` is a kill switch that overrides batch scopes and never expires; `CAPTURE_ONLY`/`SHADOW` set the branch default; **LIVE can never be branch-wide**. "Stop all" sets `enabled:false`. Saved faces, attendance and the audit history are never deleted by any of these.
* **LIVE is only saved when the policy confirms it is effective**: approved matching readiness and report hash (`FACE_WEB_CALIBRATION_FILE`, unchanged), at least one ACTIVE enrollment in the batch, and not a branch still in Testing. The writer checks the policy before writing, re-reads the file and checks again after, and restores the previous file if the result is not exactly what was requested. A LIVE scope records the approving administrator, the report hash and expires after 90 days (then the batch falls back to SHADOW). Selecting LIVE cannot bypass any of this; while matching is not approved the option is disabled and the page says "Automatic Attendance Not Ready".
* Saves are atomic (temp file + rename under a lock), rejected as "changed by someone else" if the file changed since the page loaded, never partially applied (an invalid target rejects the whole request), and shown as "Unable to Save" unless the file on disk was verified. Every saved version is also copied to `rollout-<time>-<request>.json` in the `history/` subdirectory (never pruned automatically).
* Events written with `BrowserFaceAudit` (no biometric fields): `face_feature_enabled`, `face_feature_disabled`, `face_mode_changed`, `face_rollout_rolled_back` (LIVE turned back, and Stop all) and `face_rollout_rejected`, each with timestamp, release, administrator id, tenant, branch/batch scope, previous and new mode, outcome, `config_sha256` and a correlation id.
* The enrollment figures are read from persisted rows only: eligible members (the same roster rule Scan Arrivals uses), saved faces (pending + active), pending, active, duplicate conflicts (`REJECTED_DUPLICATE`) and members not yet enrolled. A captured face stays Pending until it is approved for matching.
* The attendance page hides Enroll/Update Face for a disabled class and Scan Arrivals when scanning is off; the server refuses the actions regardless.

Tests: `tests/browser-face/rollout-admin.php` (policy branch scopes and the writer, 60 assertions) and `tests/browser-face/zzzzz-face-settings.spec.cjs` (real server, rollout file and face endpoints).
