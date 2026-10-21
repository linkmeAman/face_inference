> Current correction (2026-10-09): operator confirmed only one real person across pilot labels. Stored labels are not independent people. The pilot wrong-label samples2/3 are reversibly excluded; member623 retained. Public synthetic evaluation is complete but does not approve matching. See [current public/one-person report](PUBLIC_FACE_DATASET_EVALUATION.md). Older label-count/impostor claims below are historical and cannot justify calibration.

# Browser face lifecycle and attendance delivery — 2026-10-09

Lifecycle, automatic evidence collection, complete-decision evaluation, isolated pilot and pending activation are implemented. Ordinary operators stay in attendance.php with their existing Attendance permission. **Production matching remains disabled and unverified:** actual evidence is 20 aggregate captures / six selected identity labels / zero independent validation / four complete batches. Source has five PENDING candidates and zero ACTIVE templates. No production thresholds were installed.

## Files and reuse

Backend: classes/services/{BrowserFaceService,BrowserFaceMath,BrowserFacePolicy,BrowserFacePilot}.php; controllers/browserFace.php; migration 20261009_03_browser_face_lifecycle.sql; scripts/{browser_face_admin,browser_face_apply_lifecycle,browser_face_pilot}.php.

UI: pf/attendance.php, pf/inc/top.php, pf/js/controllers/batchAttendanceController.js, browser-face/{api,embedded,attendance}.mjs. Evaluation: evaluation/{calibrate_thresholds,decision_evaluation,test_calibrate_thresholds}.py. Tests, environment example and operational documents were updated.

Reused camera/capture/worker, YuNet/SFace/ONNX Runtime Web, encrypted face_template, existing AngularJS rows, cookie/CSRF authentication, branch permissions and AttendanceDomainService. Model/preprocessing remain sface-int8bq-fb143eea / yunet640-similarity5-rgb255-v1. Existing membership/session rules, deductions, attendance/events and ordinary manual attendance remain authoritative. No new app, recognition model, production attendance domain or framework.

## Completed behavior

- Persistent PENDING / ACTIVE / REJECTED_DUPLICATE / RECAPTURE_REQUIRED. Captured encrypted templates can activate later. Only ACTIVE displays “Face enrolled.”
- Selected-member consented captures retain encrypted complete observation batches, capture UUID/date, hashed camera metadata, measured lighting and server-verified eligible context. No raw images or predicted labels. Conflicting enrollment labels are excluded from evidence export.
- Optional Check face in the same member row collects ground truth without enrollment/attendance. First two distinct dates are calibration; later dates validation. Same-day repeats cannot establish independence. Held-out captures cannot overwrite pending training templates.
- Ready options/scanner access automatically rechecks pending candidates under the tenant lock and one transaction. Compatible unique candidates activate; active duplicates are rejected; pending conflicts, expiry or incompatible candidates require recapture. Checks include active and unresolved pending identities across branches. Activation is idempotent, restart-safe and policy-audited.
- Evaluation uses actual aggregate templates and complete attendance batches with the runtime unanimous unique-winner/eligible-gallery rule. Reports include score distributions, missed/false duplicates, false-positive/false-negative identification, unknown rejection, sessions/conditions, sample counts and Wilson uncertainty. Unknown trials require unenrolled people against a nonempty eligible gallery. Existing minimum coverage and independent validation gates are preserved.
- A protected, expiring pilot uses a physically separate ownership-checked database and exactly branch 2 / batch 33 / members 623–625 / contacts 791–793 / items 1188–1190. Actual pilot evidence can derive provisional thresholds only for that connection. Cross-database views/FKs, triggers, foreign identities and legacy/manual writes are rejected. Production attendance, deductions and templates cannot be changed by pilot matching.
- Continuous embedded scanning updates existing rows immediately, keeps the camera running and cleans up on close. Fixed camera busy-state and retry-generation bugs. A changed-session retry cannot restart the old scanner against the new session.

## Actual tests

| Check | Result |
| --- | --- |
| JavaScript math/capture/transport/embedded flow | 3 files / 23 cases passed |
| PHP lifecycle/activation and existing backend rules | 125 PASS assertions |
| Authorization | 6 checks passed |
| Report-bound policy | 9 checks passed |
| Calibration/decision evaluator | 6 tests passed |
| Three additive migrations | Repeat-safe temporary MySQL checks passed |
| Isolated pilot and real attendance domain | 15 checks passed; source fingerprint unchanged |
| Full Chromium real-model suite | 12 passed in 3.0 minutes |
| Actual attendance.php shell | Isolated enrollment/duplicate/scanning, row updates, close, refresh and fresh browser session passed |
| Existing manual attendance/domain regressions | 9 characterization + 3 regressions + 6 domain scenarios + unlimited-browser contract passed |
| Syntax/whitespace | PHP/JS checks and git diff --check passed |

Browser tests use public-photo simulated camera input, actual YuNet/SFace inference and real PHP/MySQL/domain writes. Synthetic vectors and constructor-injected fixture thresholds stay in disposable regression databases. These are executable workflow evidence, not consented biometric evaluation. Physical desktop Chrome, Android Chrome and iPhone Safari remain NOT TESTED. Photos/replays may be accepted without validated presentation-attack protection.

Final headless simulated measurements: five-observation capture 4542.5 ms, startup 93.2–94.2 ms, detection P50/P95 320.4/330.4 ms, recognition 92.3/111.5 ms, attendance requests 33.43/9.02/19.43 ms. Renderer RSS after five batches 283952 KiB; shared RSS is not unique total memory and JS heap excludes worker/native/WASM memory. Physical completion times remain unavailable; no physical-device or sustained-performance claim.

## Live, pilot and legacy status

Migration 03 applied only to ccc_database after fresh private backup /tmp/ccc-face-prelifecycle-20261009.sql (0600). Recovered one prior pending capture: member 616 / sample 16. It remains PENDING. Source ACTIVE count = 0. Existing attendance/events unchanged. Historical missing batches were not fabricated.

Private current evaluation: /tmp/ccc-face-physical-audit-evaluation-20261009.json and .md. Both threshold recommendations are null. Independent genuine pairs=0; different-label pairs=98; held-out/full-query results=0. Four complete batches are enrollment references, not independent query trials. Selected labels have not been independently confirmed distinct people. If the six labels are confirmed distinct, preliminary deficits are 14 identities/80 aggregate captures, plus complete independent known/unknown trials and reviewed conditions/risk budgets. The minimum counts alone do not justify release.

Pilot database ccc_database_face_pilot_10092026 has only the three designated test identities, no copied production biometrics/attendance, and matching disabled pending actual pilot evidence. Protected configuration /tmp/ccc-face-pilot-20261009.json expires after seven days. Daily dated sessions exist only inside the pilot for collection. Source observations involving member 616 cannot enable pilot matching.

Source test invoices have employee_id=0 and are omitted by the production invoice view's counsellor INNER JOIN, although the module roster lists them. The pilot explicitly seeds only these zero-value test memberships from that roster into local views reflecting local deductions; AttendanceDomainService is unchanged. This is not verification that source batch 33's test invoices are production-domain eligible. No source invoice correction or real deduction was made.

Legacy 94/95/96, all member 616 / contact 774 / branch 2, were previously erased/revoked with explicit user authorization. Incompatible ACTIVE count is zero; original reference 103, metadata and attendance are preserved. Exact allowlisted replacement/rollback remains tested. Erased ciphertext recovery requires explicit authorization and the private pre-retirement backup.

## Acceptance criteria

VERIFIED means the executable scope stated, never an unperformed live-person workflow.

| # | Criterion | Status | Evidence / limit |
| --- | --- | --- | --- |
| 1 | Enrollment inside attendance.php | VERIFIED | Actual authenticated page in isolated browser fixture; no navigation. |
| 2 | Scan Arrivals inside attendance.php | VERIFIED | Actual page and real PHP/MySQL pilot route. |
| 3 | Automatic suitable observations | VERIFIED | Actual model worker and virtual-camera quality/collection tests. |
| 4 | No head movements/manual photographs | VERIFIED | No pose/shutter/photo approval interaction. |
| 5 | Live calibrated template saves | BLOCKED | Encrypted ACTIVE fixture saves pass; production evidence incomplete. |
| 6 | Calibrated live duplicate rejection | BLOCKED | Real-model/concurrency fixture checks pass; real-person accuracy unvalidated. |
| 7 | Safe legacy migration | VERIFIED | Scope/transaction/retry/rollback tests and prior authorized exact retirement. |
| 8 | Existing attendance service | VERIFIED | Actual member/session/event writes in isolated tests. |
| 9 | Membership/deductions preserved | VERIFIED | One finite deduction/event, repeat prevention, source unchanged. |
| 10 | Continuous sequential recognition | VERIFIED | Two real-model public-photo identities on one uninterrupted scanner. |
| 11 | Rows update without reload | VERIFIED | Actual attendance.php row update and persisted refresh. |
| 12 | Cancellation/recovery | VERIFIED | Cleanup, stale callbacks, changed-session retry, lost committed response. |
| 13 | Automated tests | VERIFIED | Results above; final browser evidence in implementation state. |
| 14 | Actual live-person/device acceptance | BLOCKED | Consenting-person pilot and physical-device matching not performed. |

## Human-required next steps

1. Sign into Testing branch 2, open /pf/attendance.php?face_pilot=1 and confirm Practice banner/batch 33. With informed consent (appropriate guardian consent for children), use Enroll Face for two genuinely different test people against One/Two, capturing each twice initially. Keep Three unenrolled and use Check face for explicitly labeled unknown trials. Initial captures remain pending; no separate calibration screen or manual photos.
2. After those real samples exist, privately export/evaluate and install only their exploratory pilot report using BROWSER_FACE_SETUP.md. This is administrative setup, not an ordinary operator task. Exercise real enrollment, duplicate, distinct identity, known/unknown/repeat attendance, uninterrupted scanning and recovery on available physical browsers. Pilot approval cannot enable production.
3. Collect representative consented evidence through the same member-row actions across at least 20 identities/100 aggregate captures, including two calibration dates and two later validation dates per identity, intended cameras/lighting, similar-looking people and held-out unknown identities. Dates/splits are automatic. Review acceptable duplicate/attendance error budgets, independent evaluation and actual workflow/device results; minimum counts alone do not justify release.
4. Explicitly authorize production rollout of the protected validated report-bound policy. Unique pending candidates then activate automatically; ambiguous ones request recapture. Complete production acceptance on valid memberships/sessions before declaring production attendance verified. Ordinary operators need no calibration actions afterward.

All remaining acceptance blockers require consenting captures, validation/risk approval or authorized production rollout. Private commands, installation, retention/deletion and recovery are in BROWSER_FACE_SETUP.md. Do not install fabricated evidence to remove these blockers.


## Final physical-validation audit and focused fixes

Existing source code and passing tests were audited before changes. Recovery baseline: /tmp/ccc-face-final-validation-baseline-20261009; existing database backups and migration history preserved. No migration, key change, production template/attendance mutation or production threshold installation was performed in this continuation.

Verified defects fixed:

- A sudden single-face bounding-box discontinuity could complete the previous face's partial capture. Non-overlapping boxes, no/multiple faces and interruption now restart collection. This is geometric continuity, not identity/liveness assurance for an overlapping-position face swap.
- Browser cosine suppression could label a changed similar-looking face Already marked without central recognition. Only byte-identical confirmed observation batches are suppressed now; changed observations go through the backend's unanimous unique-winner rule.
- Repeated calibration-date captures replaced a usable original PENDING reference. The original is now fixed; later observations are separate evidence. Explicit recapture/deletion/expiry permits replacement. ACTIVE ciphertext/template ID remain unchanged by attendance.
- Expired pending captures still appeared pending while matching was blocked. Metadata status now requests recapture without enabling matching or mutating live candidates.
- The administrative sweep reused its old policy journal key and could replay an earlier result after new captures. Each sweep now gets a new key; identity-level activation remains transactional/idempotent.
- A healthy empty scanner stopped on the enrollment timeout. Attendance now cancels that collection deadline once the actual worker is ready; startup/enrollment timeouts remain intact.

New regressions reproduced the track, similarity-suppression, original-reference and expired-status failures before their fixes. All final relevant suites pass. YuNet/SFace prepared assets match their manifest; real WASM/Python reference cosine remains 0.9999513689468181. No model/preprocessing replacement or adaptation.

### Physical pilot A–J

No physical camera is exposed at /dev/video* and no tool provides the user's camera. Consenting participants are unavailable to the agent. Automated input was simulated. Therefore every actual physical identity test below is BLOCKED, with zero claimed physical passes or physical enrollment timings. Backend/DB process restart is NOT TESTED; production services were not restarted. Fresh browser session and fresh service-object persistence passed automated tests.

Use Testing branch 2 at /pf/attendance.php?face_pilot=1, confirm Practice attendance and batch 33/current session. This is ccc_database_face_pilot_10092026, not the shared source database. Protected pilot expires 2026-10-16 18:10:59 IST; no extension is automatic. Initially capture consenting A under Test One and B under Test Two twice each. Keep a third consenting person unenrolled and capture selected ground truth with Test Three's Check face where needed. PENDING is expected until actual scoped exploratory evidence is evaluated and installed. Report “pilot captures saved” so that actual data can be evaluated and only the isolated pilot configured; no production approval is implied. Then execute:

| Test | Physical status | Exact action / expected evidence |
| --- | --- | --- |
| A | BLOCKED | Refresh after scoped pilot setup. A/Test One becomes ACTIVE only after checks; verify encrypted template and Face enrolled row. |
| B | BLOCKED | Close the entire browser, reopen, sign in to Testing and select the same pilot/session; no re-enrollment. |
| C | BLOCKED | Scan A; correct row is present and one pilot attendance/event exists. |
| D | BLOCKED | Try A under Test Three; expect Duplicate detected, no ACTIVE third identity. Never use this deliberate conflicting label as calibration truth. |
| E | BLOCKED | Confirm genuinely different B/Test Two is ACTIVE; its reference differs from A. |
| F | BLOCKED | Scan B; only B's row changes in the correct session. |
| G | BLOCKED | Show the third consenting unenrolled person; Unable to recognize, no attendance increase. |
| H | BLOCKED | On another scheduled pilot session, scan A then B without closing the camera; two correct rows/events, same camera. |
| I | BLOCKED | Repeat A; Already marked, zero additional event/attendance/deduction. |
| J | BLOCKED | Close/reopen camera, deny/regrant permission, interrupt camera/network, retry, show two faces, change session during processing; resources release, no wrong-session row updates or duplicate deductions. |

Use the same selected identity for the same physical person; do not enroll the operator's face against multiple child labels except deliberate duplicate test D. Record actual browser/device/date/lighting, capture-to-result time, identity outcome, pilot attendance/event counts and membership usage. Any physical wrong match is FAILED and prevents production acceptance. For production evidence, obtain the independent labeled/unknown/condition coverage described above, review risk and explicitly authorize rollout. Ordinary operators keep the same automatic capture/scan flow; no separate everyday calibration screen or photograph approval is added.
