# Dataset and model inventory

## Included data

* `datasets/controlface10k-subset/`: the actual 144 PNG images / 48 synthetic
  identities used by the public evaluation, copied byte-for-byte with the original
  per-image SHA-256 manifest. Revision
  `a03589de1a9e028b2d16fa1eb0e019a6930e817c` of
  `HuMInGameLab/ControlFace10K`, with CC BY 4.0 attribution recorded in the manifest.
  Three generated views per identity; 24 development and 24 held-out identities.
  Downloader: `evaluation/download_controlface_subset.py`.
* `datasets/reports/`: original public inference vectors, evaluation reports,
  development/reference parity diagnostics and failure classification. These are
  synthetic research results, explicitly not installable live/pilot approval.
* `datasets/development-diagnostics/`: development-only original raster and alignment
  artifacts used in the reference investigation. Their filenames map to the subset.
* `services/face-inference/calibration/field-evaluation-dataset.json`: the existing
  20-row score-only genuine/impostor fixture. Preserve its actual size and role;
  it is not the underlying image dataset or independently verified field evidence.
* `services/face-inference/calibration/`: existing identity v2/v3 and PAD artifacts,
  with their recorded metrics, settings and source model bindings. This export
  preserves their contents; it does not attest to historical claims in those files.
* `tests/`: deterministic structural fixtures for quality, matching, duplicates,
  liveness policy, enrollment, lifecycle, recovery and integration. Structural unit
  vectors and mock classifiers are not biometric training/evaluation observations.

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
| `services/face-inference/models/face_detection_yunet_2023mar_int8.onnx` | Original YuNet | `321aa5a6afabf7ecc46a3d06bfab2b579dc96eb5c3be7edd365fa04502ad9294` |
| `services/face-inference/models/face_recognition_sface_2021dec_int8bq.onnx` | Original SFace | `fb143eea07838aa532d1c95df5f69899974ea0140e1fba05e94204be13ed74ee` |
| `services/face-inference/models/anti-spoof-mn3.onnx` | Python anti-spoofing | `c4c99af04603b62d7e44f6f4daeb33e0daeccc696008c0b1d62f6f5cebbb3262` |
| `pf/vendor/browser-face/sface.onnx` | Prepared SFace graph | `487a139eb85ccd5ff30aef7d0add7f12d2bb7abb90c5f08a7f481b452a660e00` |
| `pf/vendor/browser-face/yunet.onnx` | Prepared YuNet | Same YuNet hash as above |
| `pf/vendor/face-landmarker/0.10.34/face_landmarker.task` | Older MediaPipe tracker | Recorded in the export manifest |

Model source URLs/license references are in `MODEL-MANIFEST.json`,
`MODEL-LICENSES.md`, and the bundled MediaPipe license. The PAD model provenance
references CelebA-Spoof training; that corpus is not locally included. SFace/YuNet
upstream training data likewise is not part of this source extraction. No model
retraining or replacement was performed.

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
* Older test-only Lena/Obama/Biden photo/video fixtures are described with their
  source URLs and regeneration commands in `tests/browser-face/README.md`. They
  are not included as a relicensable face dataset. Public availability alone does
  not establish photo redistribution rights.
* Active policy/rollout metadata is captured as reference only under `snapshot/`.
  Application logs, scan tokens, experiment credentials and sessions are excluded.

Use `export_tools/evaluate_public.sh` to reproduce the synthetic experiment with
an external temporary data/output directory. Keep this folder private; it is not
an asset directory to publish with the frontend.
