# Dataset and model inventory

## Included data

* `datasets/controlface10k-subset/`: the actual 144 PNG images / 48 synthetic
  identities used by the public evaluation, copied byte-for-byte with the original
  per-image SHA-256 manifest. Revision
  `a03589de1a9e028b2d16fa1eb0e019a6930e817c` of
  `HuMInGameLab/ControlFace10K`, with CC BY 4.0 attribution recorded in the manifest.
  Three generated views per identity; 24 development and 24 held-out identities.
* `datasets/reports/`: original public inference vectors, evaluation reports,
  development/reference parity diagnostics and failure classification. These are
  synthetic research results, explicitly not installable live/pilot approval.
* `datasets/development-diagnostics/`: development-only original raster and alignment
  artifacts used in the reference investigation. Their filenames map to the subset.
* `tests/`: deterministic structural fixtures for quality, matching, duplicates,
  liveness policy, enrollment, lifecycle, recovery and integration. Structural unit
  vectors and mock classifiers are not biometric training/evaluation observations.
* Historical calibration artifacts (`field-evaluation-dataset.json`, identity v2/v3, and PAD models)
  are preserved under Git tag `legacy-ccc-final`.

Attribution: Kassi Nzalasse, Rishav Raj, Eli Laird, Corey Clark, “SIG: A Synthetic
Identity Generation Pipeline for Generating Evaluation Datasets for Face Recognition”
(2024). Source and attribution URLs are retained in the original dataset manifest
and `docs/PUBLIC_FACE_DATASET_EVALUATION.md`. Original subset images are unchanged;
diagnostic raster/alignment files are derived evaluation artifacts.

The recorded public held-out experiment identified 18/30 known queries correctly,
left 12/30 unmarked and accepted 1/22 unknown queries. Its explored thresholds
0.54695218 / 0.56676878 were not approved or installed. Preserve this limitation
when reusing the data. It has no representative child cohort, physical cameras
or independent real-world visits.

## Models included

| Artifact | Use | SHA-256 |
| --- | --- | --- |
| `src/face_inference/web/vendor/browser-face/yunet.onnx` | Prepared YuNet | `321aa5a6afabf7ecc46a3d06bfab2b579dc96eb5c3be7edd365fa04502ad9294` |
| `src/face_inference/web/vendor/browser-face/sface.onnx` | Prepared SFace graph | `487a139eb85ccd5ff30aef7d0add7f12d2bb7abb90c5f08a7f481b452a660e00` |

Legacy original full-size ONNX weights (`face_detection_yunet_2023mar_int8.onnx`,
`face_recognition_sface_2021dec_int8bq.onnx`), Intel anti-spoof-mn3, and MediaPipe
tracker bundles were removed from `main` and are preserved under Git tag `legacy-ccc-final`.

Model license notices and origins are documented in `THIRD_PARTY_NOTICES.md`
and `src/face_inference/web/vendor/browser-face/manifest.json`.

## Data outside this reusable extraction

* Live member enrollment vectors, portraits, consent rows, private evaluation
  exports and backups remain in their original protected stores. The source code,
  table migrations and encryption behavior are included; live records and their
  keys are not silently duplicated for another purpose.
* No raw live/PAD field corpus was found alongside the service calibration artifacts.
  Do not infer a complete raw dataset from a policy's score/count claims.
* HSFace/Vec2Face and LFW were assessed but not downloaded for the recorded public
  experiment; they are not bundled. The full 3.1 GB ControlFace archive was not
  downloaded; the actual selected subset is bundled in full.
* Older test-only photo/video fixtures are described with their
  source URLs and regeneration commands in `tests/README.md` (or preserved under `legacy-ccc-final`).
  They are not included as a relicensable face dataset. Public availability alone does
  not establish photo redistribution rights.
* Active policy/rollout metadata from historical deployments is preserved under Git tag `legacy-ccc-final`.
  Application logs, scan tokens, experiment credentials and sessions are excluded.
