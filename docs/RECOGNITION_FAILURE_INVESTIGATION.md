# Recognition failure investigation — 2026-10-09

**October 10 delivery update (executed October 9 evening): the existing isolated one-person LIVE camera pilot is now enabled under explicit functional-demo authorization. Production remains gated; fresh physical attendance is NOT TESTED. The final section below supersedes older statements that Scan Arrivals requires two people before it can open.**

The existing attendance.php product and production safety gate are preserved. This iteration fixes a demonstrated scanner recovery failure and investigates recognition errors using the unchanged YuNet/SFace pipeline. No new recognition model, attendance service, schema, production policy or application was introduced.

## Recognition findings

The existing 144-image, 48-identity synthetic adult evaluation was reproduced. Its development-selected research points remain duplicate 0.5469521753092808 and attendance 0.5667687778180779; neither was installed. Development images were inspected and rerun; held-out images were not inspected or used to tune changes. Classification of frozen held-out decisions does not constitute another optimization pass.

| Held-out outcome | Before | After | Explanation |
|---|---:|---:|---|
| Known correct | 18/30 | 18/30 | Model, preprocessing, gallery and decisions unchanged |
| Known unmarked | 12/30 | 12/30 | Eight correct-top scores below threshold; four multiple-candidate ambiguities |
| Known wrong identity accepted | 0/30 | 0/30 | Ambiguous candidates remain unmarked |
| Unknown accepted | 1/22 | 1/22 | One unknown has exactly one candidate above the research point |
| Missed duplicate | 8/30 | 8/30 | All eight highest scores below the duplicate point |
| Distinct query falsely rejected as duplicate | 3/22 | 3/22 | Score overlap remains |

The correct identity ranks first for 29/30 known queries. Accepting the top candidate unconditionally would introduce incorrect identities and unknown acceptance; it is not a fix. Development has five correct-top rejections, four known ambiguities, one wrong known acceptance and four unknown acceptances. All four development unknown acceptances also occur using reference embeddings. Genuine/impostor overlap remains: development genuine range 0.45153–0.79774, impostor -0.06682–0.83133; held-out genuine 0.23513–0.81934, impostor -0.07062–0.76755.

The public benchmark uses one gallery image and three correlated generated views, not the product's five-observation enrollment or independently captured visits. Inspected development pose views sometimes change facial appearance substantially. This is evidence of pose/synthetic consistency and discrimination limitations, not proof of a specific mislabeled physical person or a broken model. Real child recognition cannot be inferred from these results.

All 14 development error-bearing images were visually inspected with detector landmarks, actual aligned crop and top candidate, alongside their numerical reference/quality checks. Examples 001/002 versus profile reference 000 have correct landmark placement but genuine similarities 0.51616/0.51297; unknown 060 versus gallery138 scores 0.78036 and depicts a very similar generated face under another UUID. Every inspected failure passes the unchanged basic quality filter. No reversed landmarks, incorrect color order or shifted crop was demonstrated. Private diagnostic sheets /tmp/ccc-recognition-all-development-failures-{1,2}-20261009.png preserve this inspection; held-out image inspection was deliberately excluded.

### Pipeline checks

| Stage | Actual evidence |
|---|---|
| Detection | All 144 original inputs detected one face; all 72 development rasters also detected one face in OpenCV |
| Landmarks | Five-point order and alignment inspected on development failures; official YuNet maximum coordinate difference 1.28705 pixels on a 640 raster |
| Alignment | Same raster and landmarks; official OpenCV alignCrop maximum mean absolute pixel difference 0.109322 on the 0–255 scale |
| Input | 1×3×112×112 float RGB, scale 1, mean 0; existing contract unchanged |
| Output | 128 dimensions; normalized unit vectors; original prepared model asset hashes unchanged |
| Browser/CPU inference | 70 usable development comparisons; minimum cosine 0.99994106 |
| Browser/official OpenCV feature | Minimum cosine 0.99994072; same prepared SFace weights |
| Reproducibility | Development browser vectors exactly match the previous run, component for component |
| Matching | 106 actual PHP decision probes pass; previous sequential floating-point summation regression remains protected |
| Quality | Six original inputs remain rejected; two development rejects have mean brightness 38.31 and 37.48 below the configured 40. Filter was not relaxed |

One development query sits exactly on the selected attendance point: browser score 0.5667687778180779 versus CPU reference 0.5667580471688535. It changes one reference decision by crossing that boundary. This small runtime difference is not the earlier Python/PHP summation bug and does not explain the much larger genuine/impostor overlap. No threshold adjustment was made to conceal it.

Each original query now has a private decision trace in the existing evaluator report: genuine rank/score, top/runner-up scores, accepted candidate count, winner, missed duplicate and false duplicate. It distinguishes below-threshold ranking, ambiguity, wrong acceptance and unknown acceptance without dumping images/vectors into ordinary logs.

### Aggregation experiment

Three actual five-observation captures under the retained identity 623 were compared, excluding other member labels for the same person. Stored template, browser-contract aggregation and actual PHP aggregation agree with cosine approximately 1. All five observations survived existing outlier rejection; normalized mean and existing aggregate agree. First-observation versus aggregate mean similarity to the other captures was respectively:

| Capture | Individual observation | Aggregate |
|---|---:|---:|
| 1 | 0.826723 | 0.825239 |
| 2 | 0.828923 | 0.845323 |
| 3 | 0.812731 | 0.813851 |

Aggregation has a small mixed effect, not a demonstrated engineering defect. Minimum similarity improved for captures 2/3 and fell slightly for capture 1. All captures are one person on one date; no real impostor separation or independent accuracy claim is possible. The temporary plaintext real-person export was removed.

Development-only synthetic three-view aggregation was also inspected: 22 complete identities, no unstable aggregate; maximum similarity to other labels fell from 0.831331 for first-view references to 0.782296 for aggregates. This is descriptive, includes all available views in each aggregate and lacks an independent genuine query. It does not justify extra frames, changing the five-frame product or claiming improved recognition accuracy. Original enrollment references remain unchanged.

## Actual product defect fixed

A committed attendance response lost during a network interruption could be recovered after camera closure, but the UI remained open without a camera: closure had removed its server scan lease, and Retry resumed only if that lease still existed. Related failures left a stalled old-session panel and allowed a late retry error to alter a newer generation.

Three tests reproduced these failures before the fix. The shared EmbeddedFaceFlow now resolves the exact original idempotent request, then opens a fresh authorized scan for the unchanged session when necessary. Old-session recovery closes its panel; stale retry errors/completions are generation-guarded. No recognition, attendance-domain or database code was duplicated.

The strengthened real-model PHP/MySQL browser workflow drops all three responses after an actual committed attendance write, closes the camera, restores the network, retries the same request and verifies a new scanner lease and live worker/stream. Attendance/event/deduction counts remain one. Continuous two-person scanning, row updates, browser-session persistence, unknown rejection and existing membership rules still pass in their isolated automated fixtures.

## Tests and scope

- Full browser workflow suite: **12 PASS, 3.1 minutes**; actual Angular attendance.php, WASM models, PHP/MySQL and AttendanceDomainService with simulated public-photo camera input.
- Reference helper compatibility rerun after extension: **1 PASS**; existing browser/reference cosine 0.9999513689.
- JavaScript: **26 cases / 3 files PASS**, including the three recovery failures now passing.
- Backend/lifecycle/exclusion: **137 assertions PASS**; pilot integration **15 PASS**; additive temporary-schema checks **3 PASS**.
- Evaluator: **15 Python tests PASS**; authentication **6 PASS**; report-bound policy **9 PASS**.
- Existing attendance: domain **6**, characterization **9**, regressions **3**, unlimited browser contract **PASS**.
- Development real-inference diagnostics: **72 images**, **70 usable**, official OpenCV parity assertions PASS. Actual report replay: **106 PHP probes PASS**, all comparable development/held-out measurements unchanged.
- Syntax and git diff checks PASS. Disposable browser databases were removed through existing ownership guards. Real source/pilot templates, attendance and memberships were not modified; the pilot test verifies source fingerprints remain unchanged.

Measured simulated five-observation completion: **4538.4 ms**, startup 90.6 ms. Sustained headless detection P50/P95 320.7/349.5 ms, recognition 91.6/107.2 ms. These are server Chromium measurements, not physical phone timings.

## Current usability and remaining work

**VERIFIED in automation:** integrated automatic enrollment capture, encrypted PENDING persistence, immutable reference, guarded pending activation, duplicate protection, continuous attendance/domain writes, row updates, uniqueness, membership deductions and improved recovery. **Usable now:** ordinary manual attendance and consented automatic pending capture inside attendance.php. “Face enrolled” still requires ACTIVE.

**FAILED suitability for release:** exploratory public recognition points retain the errors above. **BLOCKED:** physical-camera recognition/duplicate/unknown/device accuracy and production automatic attendance. No physical camera is exposed to this environment. Backend/database process restart remains **NOT TESTED**; fresh browser sessions and PHP service instances are tested.

Read-only final state: source ccc_database has five PENDING, zero ACTIVE, zero independent validation. Protected pilot ccc_database_face_pilot_10092026 has six retained records including two prior soft exclusions; usable analysis contains three captures/15 observations under 623, plus a later capture under 624 which is not independent identity evidence. An unapplied protected reversible review plan for that later sample 5 is /tmp/ccc-face-pilot-later-label-review-20261009.json. No records were changed. Pilot has zero ACTIVE, attendance or events and matching is disabled. Its exact branch2/batch33/member623–625 isolation remains verified; expiry is 2026-10-16 18:10:59 IST.

Next engineering action is to compare actual repeat-capture quality and genuine scores under separate real camera visits/lighting, using the same contract and original reference. If a reproducible camera-specific defect appears, inspect its raster/landmarks and fix that defect. The evidence above supplies no justification for a model replacement or global threshold reduction. Independent different-person, unknown and intended child/device comparisons are still needed to judge safe recognition, rather than merely to increase counts.

Operator actions: sign into Testing branch 2; open attendance.php?face_pilot=1; confirm Practice and batch33. Use Face Camera Test One/623 for the available person's consented capture, close/reopen and obtain separately labeled repeat observations through the existing row action. Never count that face under Two/Three as distinct identities. Recognition/duplicate/continuous physical trials require genuinely different consenting known and unknown people and an administrator-reviewed, data-derived pilot configuration confined to this database. Public points are not installable. Production requires the existing representative consented collection, independent known/unknown validation, reviewed error budgets and explicit rollout authorization. No recurring calibration task is added for ordinary operators.

## Reproduction

Use prepared assets, the existing Python face-inference virtual environment, cached Playwright browser and the private subset. No dataset download is required here.

```sh
python3 evaluation/public_dataset_evaluate.py /tmp/ccc-controlface-inference-20261009.json --output /tmp/new-face-report.json
LD_LIBRARY_PATH=/tmp/ccc-browser-libs/root/usr/lib/x86_64-linux-gnu PLAYWRIGHT_BROWSERS_PATH=/tmp/ccc-playwright node evaluation/public_dataset_inference.cjs /tmp/ccc-controlface-subset-20261009 /tmp/new-face-development.json --development-diagnostics
/var/lib/creativeculturalcentre/face-inference/venv/bin/python tests/browser-face/reference_encoder.py --development-diagnostics /tmp/new-face-development.json /tmp/new-face-reference.json
```

Private actual evidence: /tmp/ccc-recognition-investigation-report-20261009.json(.md), /tmp/ccc-recognition-failure-classification-20261009.json, /tmp/ccc-recognition-development-reference-checked-20261009.json, /tmp/ccc-recognition-aggregation-20261009.json, /tmp/ccc-recognition-development-aggregation-20261009.json, /tmp/ccc-recognition-product-browser-results-20261009.txt and /tmp/ccc-recognition-product-backend-results-20261009.txt. Diagnostic image directory is protected outside the web root. Recoverable source baseline is /tmp/ccc-recognition-failure-baseline-20261009; existing private database backups remain preserved. Work remains on master; pre-existing changes and the user-owned documentation swap are preserved.

## Post-pilot physical-camera evidence — 2026-10-09 (latest)

The operator now confirms personally performing physical-camera captures. This section supersedes the earlier six-record pilot snapshot and the blanket statement that no physical capture evidence exists. It does not supersede the production gate or convert backend replay into live camera attendance validation.

### Actual records inspected before writes

Database ccc_database_face_pilot_10092026, Testing branch2, batch33: **12 successful captures / 60 original observations**, all five observations per capture, **one real person** under three member labels. **Six new captures / 30 observations**, IDs7–12, arrived after the previous inspection. Twelve journals recorded enrollment PENDING responses; no failed camera attempt/rejected-frame history is persisted. Failed attempt count is therefore unavailable, not zero.

| New capture | Original member label | Database timestamp | Template cosine to original623 | Aggregation retained |
|---|---:|---|---:|---:|
| 7 | 623 | 2026-10-09 22:10:09 | 0.825502 | 4/5 |
| 8 | 624 | 2026-10-09 22:10:16 | 0.807993 | 3/5 |
| 9 | 625 | 2026-10-09 22:10:21 | 0.783652 | 4/5 |
| 10 | 625 | 2026-10-09 22:10:30 | 0.788927 | 4/5 |
| 11 | 623 | 2026-10-09 22:10:35 | 0.807322 | 5/5 |
| 12 | 624 | 2026-10-09 22:10:41 | 0.746783 | 5/5 |

All12 records have model sface-int8bq-fb143eea / preprocessing yunet640-similarity5-rgb255-v1, distinct capture-session identifiers, consent and encrypted templates/observations. Original623 enrollment refers to sample1 / capture afb412cc-2dca-4c2f-8eab-aad31daed697 and retained identical encrypted reference bytes across the later captures and activation.

Two hashed camera/browser fingerprints are present: the six earlier captures share one and the six newer share another. This does not establish two physical devices: the hash combines browser user agent, device identifier and facing mode; those raw values were deliberately not retained. All lighting labels are measured-normal. All visit labels are automatic-date:2026-10-09; these are same-day observations, not independent cross-day validation.

### Corrected labels and preservation

As explicitly requested, samples **5,8,9,10,12** under624/625 were reversibly excluded after a fresh pilot-only encrypted backup. Samples2/3 were already excluded. Calibration soft exclusion, evidence LABEL_CONFLICT and candidate RECAPTURE_REQUIRED/LABEL_EXCLUDED use the existing mechanism. No ciphertext, member/contact, attendance or historical record was deleted. Retained623 samples are **1,4,6,7,11**, five captures/25 observations. Export after correction contains only623. Excluded observations are compared solely as operator-confirmed same-person diagnostic/query data, never as impostors, independent identities or release evidence.

Protected new audit: /tmp/ccc-face-postpilot-label-exclusion-20261009.json and its append-only events; backup /tmp/ccc-face-postpilot-before-20261009.sql. Rollback is guarded by exact record fingerprints and must not reintroduce incorrect labels into evaluation. Source ccc_database was not modified; its independently collected capture count is26, still fivePENDING/zeroACTIVE/zero validation. Those source labels are not independently confirmed distinct people.

### Genuine consistency results

| Comparison | Pairs / observations | Minimum | Maximum | Mean |
|---|---:|---:|---:|---:|
| All12 same-person templates | 66 | 0.746783 | 0.971872 | 0.843255 |
| All60 original observation vectors | 1770 | 0.572393 | 0.987428 | 0.800452 |
| Observations across different captures | 1650 | 0.572393 | 0.958312 | 0.792237 |
| Retained623 templates only | 10 | 0.796309 | 0.921173 | 0.834580 |
| Six new templates to original reference | 6 | 0.746783 | 0.825502 | 0.793363 |
| New observations to original reference | 30 | 0.708842 | 0.828404 | 0.769574 |
| Earlier-fingerprint template pairs | 15 | 0.802045 | 0.971872 | 0.887186 |
| Newer-fingerprint template pairs | 15 | 0.819199 | 0.939078 | 0.887517 |
| Between-fingerprint template pairs | 36 | 0.746783 | 0.864949 | 0.806508 |

The lowest observation pair is sample6 observation index3 versus sample11 index4. Scores shift between metadata groups and some within-capture vectors vary; this is actual instability worth tracking. It is not proof of a specific device, detector or alignment defect. All vectors are finite128-dimensional with norm0.9999999999999999–1.0. Actual browser aggregation reproduces every stored template with cosine≈1. The existing outlier filter discards five observations across four new batches while retaining at least3; no aggregation replacement or identity-threshold adjustment was justified.

The database has no raw images, landmark coordinates, blur/clipping values, individual exposure measurements, rejected-frame counts, camera dimensions or per-stage timing for these captures. Therefore exact optical/alignment causes and physical completion times cannot be recovered. Prior controlled OpenCV parity is relevant engineering evidence but is not a forensic check of these unretained physical frames. No detector/alignment failure was demonstrated; their absence cannot be inferred from missing telemetry.

### Complete backend integration using stored physical observations

A private CLI test used the existing verified pilot connection, operator attendance permission, BrowserFaceService and AttendanceDomainService. The original compatible623 reference was activated **only in the test database**; templateID1. No installed pilot or production matching report changed.

For this integration replay only, genuine lower-envelope settings were frozen from the three earlier retained captures1/4/6: duplicate0.802045105977794, attendance0.6513243536866755. These are positive-only structural replay settings with zero impostor/unknown evidence, not calibrated thresholds, recommendations or installable policy. They were frozen before testing later captures and existed only in the private CLI constructor. The existing installed pilot policy still requires distinct-person evidence and remains disabled.

- All11 repeat batches, including wrong-label batches explicitly mapped by the operator to the same real person, identify canonical623. Original gallery observations are excluded from repeat trials. Same-day replay does not establish independent recognition accuracy.
- A complete enrollment request using an actual stored repeat batch against member624 returns REJECTED_DUPLICATE through the existing lifecycle. Connection-local temporary evidence/journal tables prevent this replay from becoming another supposedly physical calibration capture. One active template remains.
- The frozen duplicate point **misses samples9,10,12**, or3/11 repeats /3/6 newer captures. This is a failed genuine-only duplicate operating point, not a backend cosine defect. It was not lowered after observing failures or installed in the live pilot.
- Actual AttendanceDomainService creates exactly attendanceID1 / eventID1, contact791 / member623, branch2 / batch33 / session1 / date2026-10-09. Other rows remain unmarked. Pilot membership is unlimited; usage stays0 and replay/repeats add no deductions.
- Closed-session committed-response recovery, exact-key retry, a fresh PHP connection/scanner, recognition from the unchanged stored reference, and rejection of wrong scheduled time passed. Active and original enrollment ciphertext/reference remain unchanged.
- Actual backend replay responses applied through the existing Angular bridge update only791, ignore another selected session and do not increase membership usage. This is automated contract evidence, not a physical-camera UI test.
- Source attendance/events/test membership usage/template/enrollment fingerprints are identical before/after. No source enrollment, attendance, deduction or production policy was changed.

Final consenting-person pilot: **oneACTIVE623**, twoRECAPTURE_REQUIRED excluded labels, one attendance, one event, twelve original physical captures with seven soft exclusions, and matching_ready=false. Existing manual production attendance remains available. The pilot may display the actual saved test enrollment and attendance on reopening; fresh-camera recognition is still disabled by the installed policy. Pending623 activation was replayed safely after the private test's temporary-table setup error, using the same journal key and creating no second template.

### Verified engineering fix and regression evidence

An independent recovery test demonstrated that an old terminated worker's queued error could stop a newly restarted camera, because onerror lacked the epoch guard already used by onmessage. A separate baseline probe also reproduced an old capture object clearing a replacement capture's shared preview. One-line guard in capture.mjs fixes both. The new regression first failed, then passed; it also verifies current-worker errors still release tracks/worker and report a retryable failure. This fix addresses recovery, not the unexplained genuine-score variation.

Latest tests: full12 real-model/backend browser scenarios PASS in3.0minutes,27 JavaScript cases/3files,137 backend/lifecycle/exclusion assertions,15 pilot checks,15 evaluator tests,6 authentication checks,9 policy checks,3 temporary migration checks,18 existing attendance scenarios and unlimited-browser contract PASS. Simulated capture4502.9ms; physical timing unavailable. The full existing browser suite uses real models/backend with simulated camera input; physical identity/unknown/continuous-scanning outcomes remain separately unverified. No new framework, model, schema, service or ordinary UI step was introduced. Disposable browser fixture databases were removed through ownership guards; the real pilot was retained. Pair counts above are correlated observations of one person and do not supply independent accuracy trials.

| Status | Evidence / limitation |
|---|---|
| VERIFIED WITH PHYSICAL CAMERA | Operator-confirmed six new captures, stored five observations each, compatible normalized vectors, automatic template generation and encrypted persistence; original reference preserved |
| VERIFIED WITH AUTOMATED TESTS | Stored-physical-observation backend replay: activation, duplicate submission,11 known batches, one domain attendance/event, fresh connection, recovery/idempotency/session guards; actual Angular response application; guarded camera-worker fix |
| FAILED | Frozen positive-only replay duplicate point misses3 newer same-person templates; no live installation |
| BLOCKED | Live pilot/production identity acceptance; zero independently labeled different-person/unknown observations and zero independent validation; one person's data cannot establish safety |
| NOT TESTED | Physical Scan Arrivals recognition after browser restart, physical continuous scanning/network recovery, unknown/different-person rejection, specific phone/browser validation, physical timing and backend/database process restart |

Current evidence has been fully inspected; no request for another large dataset is needed for this investigation. The next recognition engineering step is to correlate any future observed physical failure with scalar per-frame quality/alignment diagnostics using the existing pipeline, rather than attributing it to sample count or changing the original reference. Before enabling even exploratory live recognition, obtain correctly labeled different-person/unknown evidence sufficient to assess separation against these same-person scores. Production still requires the established representative independent validation and explicit rollout authorization. Same-face relabeling must not contribute identities or negative trials.

Private scalar/audit evidence: /tmp/ccc-face-postpilot-consistency-20261009.json, /tmp/ccc-face-postpilot-backend-replay-20261009.json, /tmp/ccc-face-postpilot-replay-intent-20261009.json, /tmp/ccc-face-postpilot-row-contract-20261009.json. Temporary decrypted observation exports are removed after inspection; encrypted database records and protected backups remain preserved.

## Existing live pilot enabled — October 10 delivery

Exact blocker identified and fixed: BrowserFacePilot::policy required two real identities/genuine/impostor trials; config returned false and EmbeddedFaceFlow.open refused to start the camera. An explicitly authorized SINGLE_IDENTITY_LIVE_DEMO protected report now makes the existing pilot ready without modifying the production validation policy. Backend verifies exact existing isolated schema/owner/views/population, branch2/batch33, expiry and one immutable ACTIVE reference623. Any additional active identity, changed template/enrollment association or ciphertext hash fails closed. Candidates cannot activate in this mode. Genuine per-observation matching remains unchanged. Actual serving PHP8.3-FPM reports pilot_ready=true/production_ready=false; original reference and source fingerprint verified unchanged.

Used the already inspected12 physical batches/60 observations of one person. The original's own observations score0.992–0.994 to its template: no demonstrated within-capture reference defect. Eleven repeats to the original template min0.7467827910/max0.9365837751/mean0.8362783152;55 repeat observations min0.6513243537/max0.9547966135/mean0.8079910764. Comparison of retained623 captures1/4/6/7/11: medoid reference repeat-template min0.7611102181/mean0.8551197696, aggregate reference min0.8763540059/mean0.9186493571. Corresponding observation minima0.6947488773/0.7616832437. These alternatives reuse the fitting captures, lack independent/unknown trials and **do not justify changing the original**. No replacement/adaptation or preprocessing reinvestigation occurred.

The old duplicate point0.802045106 misses3/11 genuine repeats due to the measured cross-capture score shift. The pilot-only duplicate candidate is the actual lower envelope0.746782791039185;0/11 misses on those same fitting templates is not independent improvement. Attendance retains the earlier frozen genuine-only0.6513243536866755, recognizing11/11 recorded batches. Neither cutoff has unknown/impostor safety evidence; both are explicitly unvalidated test-only settings. Wrong member labels remain quarantined and were used only as operator-confirmed same-person diagnostics, not independent identity evidence. Public/synthetic points were not installed. Production thresholds/gates unchanged.

Pilot-only safe metrics now distinguish no/multiple/small faces, dark/bright/clipped/blur frames, worker readiness/inference, backend below-cutoff/ambiguity/conflicting observations, saved context and record ID. Optional camera metrics are bounded untrusted client reports; no images/landmarks/embeddings/tokens displayed or logged. Existing request journal stores actual backend diagnostics. A layout probe demonstrated expanded diagnostics displaced the modal to−172px; bounded scroll restores top20/bottom700 in720px viewport.

Executable evidence:12 real-model simulated-camera browser scenarios PASS including actual attendance.php normal controller/protected demo configuration (no injected readiness or identity results in that segment);29 JS cases/3files,34 pilot,137 backend/lifecycle/exclusion,10 production-policy,6auth,3migration,15evaluation,18manual/domain/unlimited tests PASS. The new policy test rejects promoting a demo report into production. Existing106 PHP numeric probes remain equal and frozen synthetic metrics unchanged. Final actual-page/layout rerun documented in IMPLEMENTATION_STATE.md. No fresh physical attendance success was claimed and no new real-pilot attendance manufactured. Existing test record remains the earlier replay event.

The pilot is ready for a one-person physical demo: Attendance permission/Testingbranch2 → attendance.php?face_pilot=1 → batch33/date10-10-2026/session2 → Scan Arrivals. Existing623 enrollment persists; no recapture needed before scanning. Expect a genuine decision and test record/row update; repeat and reopen should return Already marked. Inspect stage/quality_reason/decision/request_key if it fails. Fresh physical attendance/recovery is NOT TESTED, agent physical access BLOCKED. Production remains BLOCKED by independent representative different/unknown-person/session validation and explicit rollout approval. Source records, original reference, encrypted backups and user-owned swap preserved. Temporary decrypted export removed after analysis. No new framework, schema, model, service or separate everyday application.

## Android physical attendance and inconsistent duplicate decision — October 10

The operator confirms every capture under623/624/625 is the same actual person. Twelve additional physical capture batches (IDs13–24,60 observations) were inspected read-only before changes. Five-observation templates reproduce the PHP aggregate with cosine≈1 and the same model/preprocessing contract; no new preprocessing defect was demonstrated. Device fingerprints are privacy-preserving hashes; Android provenance comes from operator confirmation, not reconstruction of the hashed user agent.

**Physical attendance VERIFIED:** 10:54:09IST, request98877d35-ad7d-42a6-a1f7-679b0c31315e, member623/contact791, branch2/batch33/date2026-10-10/session2, attendance2/event2, FACE_MATCH/PUNCH_IN. Five fresh observations scored0.796725–0.816558. Camera480×640; client-reported startup474.2ms, last detection193.7ms, inference78.9ms. These are individual reported stage timings, not enrollment-completion or broad phone-performance measurements. Scanner closed10:54:11 and reopened10:54:14; fresh request4bc3ed94-7304-47df-81e4-6857cfafd0e7 at10:54:18 returnedALREADY_MARKED with the same record and zero deduction. Exactly one October10/session2 attendance/event exists; the other record is the October9 replay. All local test memberships remain sessions_used0. Immediate UI update is operator-reported, with correct response contact/session association; independent physical DOM recording is unavailable.

| Attempt | Fresh template to original623 | Old backend decision | Supported cause |
|---|---:|---|---|
|624 sample17,10:54:02|0.804029|PENDING|Retained old sample13 was checked instead|
|624 sample18,10:54:27|0.854160|PENDING|Same retained reference|
|625 sample19,10:54:34|0.827157|REJECTED_DUPLICATE|Fresh candidate was checked|
|624 samples20/21/23|0.805862/0.817896/0.779826|PENDING|Same retained reference|
|625 samples22/24|0.817525/0.824048|REJECTED_DUPLICATE|Fresh candidates were checked|

The unchanged pilot duplicate threshold is0.746782791039185. Member623 was present in the compatible active population; versions matched. The bug was **wrong comparison input**, not an omitted623 or an arbitrarily restrictive cutoff for these morning captures. `keepTraining` correctly preserved624's immutable original PENDING reference, sample13 from00:27:27, but `activatePending` kept evaluating that reference (score0.583846) instead of deciding the newly submitted capture. It remainedPENDING/PILOT_NOT_APPROVED.625 had no retained usable pending reference, so each rejected candidate was replaced on recapture and its new aggregate was checked.624 never becameACTIVE.

Small fix: check the submitted aggregate against every other compatible active identity before pending activation, keeping the original reference unchanged. A rejected attempt may have stateREJECTED_DUPLICATE while the selected member's actual enrollment_state remainsPENDING orACTIVE; the Angular bridge now preserves that truth. New rejected candidates persistREJECTED_DUPLICATE. Rejected observations areLABEL_CONFLICT. Attendance's allowlist applies only to attendance search; it cannot narrow duplicate search. Pilot-only scalar diagnostics identify the actual sample/capture/request, comparison source, candidate count and score. No templates/vectors/images are exposed.

Actual-case reproduction used eight stored morning batches in connection-local temporary copies of the original encrypted pilot tables: before fix,624's five attempts returnedPENDING and625's three returnedREJECTED_DUPLICATE; after fix, all8 returnedREJECTED_DUPLICATE against623. Original pending/active references, sole ACTIVE population and all persistent rows were verified unchanged. This is recorded-observation regression, **not another physical camera trial**. Deterministic regressions also cover idempotency, conflicting evidence and an attendance-restricted gallery still performing full duplicate search.

**Separate genuine condition limitation:** midnight captures13/14/16 scored0.583846/0.559393/0.523915 to623, below the unchanged duplicate cutoff; the correctly labeled observation-only623 sample15 scored0.535574. Their measured lighting was dark. Five corresponding live attendance requests returnedUNKNOWN with all top observations below0.6513243536866755; no attendance was written. This is actual same-person rejection, not different-person evidence. The records lack images/landmarks needed to establish its exact optical/alignment cause. Morning normal-light vectors improved markedly; no threshold/reference/model change is justified from one person's correlated captures. Sample20 contains a0.369133 observation; the existing aggregate's outlier filtering produced0.805862, and aggregation parity passed.

As authorized, new wrong-label samples13,14,16–24 were reversibly soft-excluded using the existing exact-snapshot mechanism. Eleven new exclusions plus seven earlier exclusions; retained623 samples1/4/6/7/11/15 represent6 captures/30 observations of one person and zero independent release-validation batches. Ciphertext/history/journals are preserved;624/625 candidates areRECAPTURE_REQUIRED/LABEL_EXCLUDED and cannot activate. Protected audit/backup/result files are recorded in IMPLEMENTATION_STATE.md. Original623, attendance/events, memberships and production fingerprint remain unchanged. Decrypted temporary export removed after regression.

The physical duplicate fix still needs one fresh Android capture. Physical uninterrupted scanning/rearming, physical page-refresh recognition and physical network interruption remain NOT TESTED; the corresponding automated workflows are exercised separately. Production matching remains blocked by representative independently labeled different/unknown-person validation, realistic roster/error-budget evidence and explicit rollout authorization. No production safeguards or pilot cutoffs changed.
