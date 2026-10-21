# Public dataset evaluation — 2026-10-09

The actual browser YuNet/SFace pipeline ran on a small explicitly licensed synthetic subset. Model execution and PHP decision agreement are VERIFIED. These results do not validate live biometric accuracy. The exploratory public operating points FAILED suitability for rollout and were not installed in either the pilot or production.

Latest continuation: [recognition failure investigation](RECOGNITION_FAILURE_INVESTIGATION.md) reproduces these results, classifies every query, checks 72 development rasters against official OpenCV and fixes a demonstrated embedded scanner recovery defect. Matching/model settings remain unchanged. Full 12 browser scenarios now passed again, with simulated camera input. Read its current pilot metadata before relying on the earlier two-capture snapshot below.

## Dataset assessment

| Dataset | Provenance and labels | Repeated identity images | License assessment | Size and selection |
|---|---|---|---|---|
| [ControlFace10K](https://huggingface.co/datasets/HuMInGameLab/ControlFace10K) | Author-published SIG-generated identities; UUID folders; race/gender/age/pose metadata | 3 generated pose images per identity; 3,336 identities / 10,008 images | Explicit dataset CC BY 4.0; selected for attributed offline research evaluation. This permits commercial use under its terms, but is not a guarantee of all upstream/privacy/publicity rights. No training or real enrollment use. | Archive 3,137,641,968 bytes. Downloaded 144 images / 48 identities by ZIP byte ranges; 45,039,155 image/header bytes plus about 2.3 MB archive metadata. Full archive was not downloaded. |
| [HSFace / Vec2Face](https://huggingface.co/datasets/BooBooWu/Vec2Face/tree/main/HSFaces) | Synthetic sampled feature identities from Vec2Face; author training uses WebFace4M; identity-indexed images in LMDB/masks | Author reports HSFace10K: 10,000 identities / 500,000 images, about 50 images per identity | Dataset card lists MIT; [code license](https://github.com/HaiyuWu/Vec2Face/blob/main/LICENSE) also MIT. Code license alone is not proof of unrestricted rights to data or upstream trained models. No verified child-age coverage. | Smallest listed HSFace archive: hsface10k.lmdb 1.72 GB; not downloaded. [Authors report an April 2025 correction of earlier identity leakage](https://github.com/HaiyuWu/Vec2Face); pin and verify any future dataset revision. |
| [LFW](https://www.tensorflow.org/datasets/catalog/lfw) | Internet photographs labeled by person; uneven samples, many single-image identities; public-figure bias | Multiple photos exist for some labels; others unsuitable for genuine-pair tests | Commercial rights to individual photographs were not established from the reachable primary sources. A hosting page/code license does not grant rights to the photographs. Deferred. | TFDS lists 13,233 images, 172.20 MiB download. Not downloaded. |

Attribution: Kassi Nzalasse, Rishav Raj, Eli Laird, Corey Clark, “SIG: A Synthetic Identity Generation Pipeline for Generating Evaluation Datasets for Face Recognition” (2024), [paper](https://arxiv.org/html/2409.08345v2). Images unchanged; only subset selection and recognition evaluation performed. Dataset license: [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). The paper describes ages 25, 50, 65 and Absolute Reality/Stable Diffusion generation. There is no child cohort, physical camera or independent real-world visit in this dataset.

## Reproducible methodology

1. Pin ControlFace10K revision `a03589de1a9e028b2d16fa1eb0e019a6930e817c`. Read the remote ZIP central directory; choose the first two UUID identities in each of its 24 race/gender/age strata. Keep all three original images. Verify ZIP CRC/length and SHA-256. The reproducible convenience subset is not a random population sample.
2. Assign the first identity in each stratum to development, the second to held-out evaluation before inference: 24 disjoint identities in each. Hash-order labels to choose 16 enrolled gallery identities and 8 unknown identities per partition. Unknown identities never enter that partition’s gallery. Gallery reference is the first quality-approved original view. Public identities are never imported into member, enrollment or attendance tables.
3. Run the unchanged `pf/js/browser-face/worker.mjs` through ONNX Runtime Web WASM in headless Chromium, using the deployed contract and prepared model checksums. No alternate detector, crop/alignment, recognizer, embedding encoder or quality settings. The localhost-only harness reuses one worker; every result comes from its real inference.
4. Compare original image views. No generated augmentation, relabeling, adjacent-camera frames or repeated vectors count as additional trials. Same-identity view pairs are correlated synthetic views, not independent live validation visits.
5. Explore threshold tradeoffs on development identities only, minimizing the equal-weight measured error rates. Freeze the resulting research points and measure held-out queries once. This is descriptive exploration without approved release risk budgets.
6. One-to-many identity decisions use the existing unique-above-threshold and unanimous-observation rule. The existing PHP `BrowserFaceService::matchObservations` and `cosine` verify the actual scores/decisions without connecting to a database. To meet its three-observation input shape, one image vector is repeated three times **only as a structural API probe**; it remains one image trial. This does not validate independent five-observation enrollment or multi-observation camera attendance accuracy.
7. Record quality failures separately. Identification error rates condition on usable embeddings. Pair counts are correlated; image-level Wilson intervals are descriptive and optimistic, not a release guarantee. Full distributions, threshold curves, duplicate errors and uncertainty remain in the private JSON report.

Commands, with prepared model assets and existing Playwright Chromium available:

```bash
python3 evaluation/download_controlface_subset.py --output /private/controlface-subset
node evaluation/public_dataset_inference.cjs /private/controlface-subset /private/controlface-inference.json
python3 evaluation/public_dataset_evaluate.py /private/controlface-inference.json --output /private/controlface-report.json
python3 -m unittest discover -s evaluation -p 'test_*.py' -v
php tests/browser-face/exclusion.php
```

This server additionally required the existing cached Playwright browser and compatibility libraries:

```bash
LD_LIBRARY_PATH=/tmp/ccc-browser-libs/root/usr/lib/x86_64-linux-gnu PLAYWRIGHT_BROWSERS_PATH=/tmp/ccc-playwright node evaluation/public_dataset_inference.cjs /tmp/ccc-controlface-subset-20261009 /tmp/ccc-controlface-inference-20261009.json
```

Inference outputs and downloaded images are protected outside the web root. Nothing in this harness installs matching policy. Source `public-synthetic-research`, `production_approved:false`, and `pilot_installable:false` distinguish it from authorized consented live-camera release evidence.

## Actual results

144 images detected one face each. 138 produced normalized 128-dimensional embeddings; six were rejected by the existing quality filter. Exact-image repeat cosine: 1.000000000000. Model `sface-int8bq-fb143eea`, preprocessing `yunet640-similarity5-rgb255-v1`. The prepared YuNet/SFace SHA-256 values matched the deployed manifest. All 106 PHP identification and duplicate decision probes agreed after the summation correction.

| Partition | Genuine similarities: n / mean / range | Impostor similarities: n / mean / range |
|---|---|---|
| development | 68 / 0.63252 / 0.45153–0.79774 | 2347 / 0.23911 / -0.06682–0.83133 |
| heldout | 64 / 0.58952 / 0.23513–0.81934 | 2214 / 0.22351 / -0.07062–0.76755 |

Exploratory public operating points: duplicate **0.54695218**, attendance **0.56676878**. Neither is a recommended live/pilot threshold.

| Held-out measurement | Actual result |
|---|---|
| Eligible gallery | 16 identities |
| Known correctly identified | 18/30 = 60.0% |
| Known left unmarked | 12/30 = 40.0% |
| Known identified as a different label | 0/30 |
| Unknown incorrectly accepted | 1/22 = 4.55% |
| Unknown rejected | 21/22 |
| Queries with multiple accepted candidates | 5/52 — safely unmarked |
| Top candidate correct before acceptance gating | 29/30; top candidate alone never marks attendance |
| Missed duplicate query at duplicate point | 8/30 |
| Distinct/unknown falsely rejected as duplicate | 3/22 |
| Duplicate returned a different label as highest match | 1/30 |

Held-out pair verification at the attendance research point: false acceptance 37/2214 (1.67%), false rejection 18/64 (28.12%). These pair rates differ from 1:N identification rates. Unknown acceptance’s descriptive 95% Wilson interval is 0.81%–21.80%; known unmarked 24.59%–57.68%; zero known wrong matches still has an upper bound of 11.35%. Similarity overlap and five ambiguous held-out queries remain; no arbitrary identity is selected when multiple candidates pass.

A verified evaluator defect was corrected: Python 3.12 `sum()` improves floating summation, whereas PHP/JavaScript accumulate sequentially. At an exact candidate score this produced one contradictory duplicate outcome. The shared evaluator now accumulates left-to-right and calibration pair comparisons reuse it. A numerical-boundary regression and actual public-image PHP comparison pass. Production matching/model/preprocessing were not changed.

## Corrected one-person pilot

User confirmed all pilot labels represent one real person and chose the first label. Keep **member 623 / Face Camera Test One**, pilot samples **1 and 4**. Samples **2 (624)** and **3 (625)** were reversibly excluded on the isolated database `ccc_database_face_pilot_10092026`: calibration soft exclusion, evidence `LABEL_CONFLICT`, candidates `RECAPTURE_REQUIRED/LABEL_EXCLUDED`. Ciphertexts, original references, member/contact records and attendance are preserved. Those samples cannot export as calibration/validation evidence, activate, or create cross-label pending duplicate conflicts. No active enrollments existed. Protected before-state/fingerprint audit and append-only event log are `/tmp/ccc-face-pilot-label-exclusion-20261009.json` and `.events.jsonl`.

Two retained captures contain 10 normalized observations using the current contract. Aggregate repeat cosine **0.9211729884**; within-capture observation-to-template similarities **0.99231–0.99423** and **0.98154–0.99186**. This confirms persisted same-person repeat evidence reported by the operator, not independent attendance accuracy. Both are from the same date; validation observations remain zero. One retained candidate remains PENDING, zero ACTIVE, zero pilot attendance/events. No live threshold was installed.

The source database still has 20 captures under six labels; these labels must no longer be interpreted as six distinct people. Source samples **15–20** under members **616–620** have a protected, unapplied reversible review plan `/tmp/ccc-face-source-label-exclusion-20261009.json`. This turn did not modify source biometric records. Confirm the exact source labels belong to the same test person and authorize that source-scope exclusion before applying it. Unrelated members, source attendance, memberships, legacy retirement and historical references remain unchanged. Older six-identity/98-impostor-pair reports cannot provide legitimate different-person evidence from these relabeled captures.

Pilot rollback, only if audited records have not changed or activated:

```bash
php scripts/browser_face_admin.php rollback-label-exclusion --database ccc_database_face_pilot_10092026 --branch 2 --audit /tmp/ccc-face-pilot-label-exclusion-20261009.json --authorized-by REVIEWER --apply
```

Rollback is not needed for continued testing: reintroducing wrong labels would invalidate evidence. A later genuinely different person may be captured under the cleared member; stale rollback then fails safely. No automatic cleanup/erasure was performed. Existing consent/expiry/purge rules still apply.

## Verification and required human actions

| Item | Status and evidence |
|---|---|
| Actual browser detector/recognizer/preprocessing | VERIFIED: 144 real model image runs, 138 usable unit vectors, asset hashes, same-raster OpenCV reference cosine 0.99995137 in existing browser regression |
| PHP matching parity | VERIFIED: 106 identification and duplicate probes |
| Reversible pilot exclusion | VERIFIED: 12 new assertions; 125 existing lifecycle/backend assertions also pass; ciphertext preserved, scope/stale-plan/idempotency/rollback/activation tested |
| Existing regression suites | VERIFIED: 23 JavaScript cases in three test files, 14 Python tests, authorization 6, policy 9, pilot 15, migration 3, manual attendance 18 plus unlimited browser regression |
| Browser camera/recovery regression rerun | VERIFIED in automation only: four actual-model/simulated-camera browser scenarios passed this turn. Earlier full 12-scenario run is historical evidence, not a newly rerun full suite. |
| Explored public points suitable for live use | FAILED: held-out missed/unknown/duplicate errors above; no validated risk acceptance |
| Actual live restart/recognition/duplicate/recovery | BLOCKED: agent cannot access operator hardware; pilot remains uncalibrated. Stored user camera captures support capture/persistence only. |
| Child accuracy, independent dates, cross-device/lighting | BLOCKED: absent representative consented cohort and held-out observations |
| Production rollout | BLOCKED: public/synthetic results cannot satisfy the existing production policy gate |

1. Use the existing Practice pilot inside `attendance.php?face_pilot=1`, Testing branch 2 / batch 33. For real camera capture always select **Test One / 623** for the available person. Reopen the browser, recapture on separate real dates/lighting/devices and use the existing selected-person **Check face** capture for later independent evidence. Do not enroll the same person as multiple identities to increase counts. Record actual timing and permission/network recovery; no camera timing was supplied for the stored batches.
2. More than one person is still needed to validate different-person rejection. Public synthetic negatives do not replace live unknown-person tests. With one real person, actual different-person/unknown accuracy and approved pilot attendance remain blocked. This implementation preserves the existing pilot requirement for actual scope-matched positive and negative evidence; the public points were not used to bypass it.
3. For production, obtain at least **20 genuinely distinct consenting identities**, at least **100 representative captures**, two separate calibration dates and two later independent validation dates per identity, known and explicitly labeled unenrolled queries against nonempty realistic session rosters, similar-looking identities, child/guardian consent where appropriate, and phone/lighting coverage. With only one confirmed identity, at least **19 additional real identities** are missing. Counts are preliminary targets, not accuracy proof. Many same-day frames do not satisfy visit independence.
4. Review actual duplicate/1:N/unknown error tradeoffs and uncertainty, complete physical end-to-end acceptance, then explicitly approve validated configuration and production rollout. No extra everyday-operator calibration screen or face page was added.

Private artifacts: `/tmp/ccc-controlface-subset-20261009/manifest.json`, `/tmp/ccc-controlface-inference-20261009.json`, `/tmp/ccc-controlface-evaluation-20261009.json` and `.md`, `/tmp/ccc-face-one-person-repeat-report-20261009.json`. The decrypted pilot export used for repeat measurement was removed; audit snapshots contain encrypted records only. Dataset images and vectors are not application logs or database enrollments.
