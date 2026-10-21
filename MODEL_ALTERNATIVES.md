# Model alternatives and selection record

Reviewed 2026-10-22. The installed baseline remains YuNet 2023mar INT8 detection
plus SFace 2021dec INT8BQ recognition, with the existing browser preprocessing.
See [MODEL_SETUP.md](MODEL_SETUP.md) for the complete installed asset inventory.

## What the project records actually establish

* `services/face-inference/models/MODEL-MANIFEST.json` records the selected
  YuNet/SFace/Intel anti-spoof bundle and exact source hashes.
* `services/face-inference/models/MODEL-LICENSES.md` explicitly records that
  InsightFace model packs require separate commercial-use permission before
  substitution. This is a recorded alternative and licensing constraint, not a
  measured accuracy rejection.
* MediaPipe Face Landmarker is installed for the preserved guided workflow.
  It provides capture/tracking evidence rather than the active identity embedding.
* `docs/RECOGNITION_FAILURE_INVESTIGATION.md` records investigations using the same
  YuNet/SFace models. Medoid/aggregate reference experiments reused fitting captures
  and were not independent validation of a replacement.

The exported records do not contain a comparative benchmark of InsightFace,
FaceNet, dlib or MiniFASNet. The additional options below were reviewed for this
guide and remain unevaluated candidates. No alternative weights were installed,
and no comparative speed/accuracy winner is claimed.

## Recognition alternatives

| Candidate | Record/status | Why evaluate it | Integration and licensing considerations |
| --- | --- | --- | --- |
| SFace original/full-precision `face_recognition_sface_2021dec.onnx` | Additional same-family option; not installed or benchmarked here | Isolate the effect of using a different precision/export from the current INT8BQ graph | Pin its exact bytes, reproduce its input/output contract, prepare it for the chosen runtime and measure target-device memory/latency and complete identity decisions. Existing vector compatibility must be established, not inferred from the shared name. |
| SFace conventional INT8 `face_recognition_sface_2021dec_int8.onnx` | Additional same-family option; not installed or benchmarked here | Compare conventional quantization with the selected block-quantized model | Requires new model identity/checksums and calibration. OpenCV distinguishes the original, INT8 and block-quantized exports; a filename change alone is not a migration. |
| InsightFace recognition packs, such as `buffalo_l` | InsightFace is explicitly named in the original license notes; the particular pack is an example, not a recorded local benchmark | Evaluate a different recognition pipeline and its runtime against the target cohort | The project distinguishes MIT library code from pretrained-model terms, which restrict the provided models to non-commercial research without separate licensing. Obtain artifact-specific permission for the intended use. A new adapter, preprocessing and gallery are required. |
| FaceNet / Inception-ResNet-v1 | Additional research candidate; not installed or benchmarked here | Compare an independent embedding model implementation | The referenced implementation uses TensorFlow checkpoints, fixed image standardization and its alignment procedure. Porting/export and browser/runtime compatibility require work. Verify the exact checkpoint and its training-data/weight terms; a framework/code license alone is insufficient. |
| dlib `dlib_face_recognition_resnet_model_v1.dat` | Additional native/Python baseline; not installed or benchmarked here | Compare a separate recognition implementation on the same held-out trials | Requires dlib loading/alignment and an explicit embedding/scoring adapter. The model author describes the example weights as public domain, with training provenance documented per artifact. Do not extend that statement to every landmark model: the 68-point predictor has a specific commercial-use restriction in the same repository. |

OpenCV identifies its SFace exports as MobileFaceNet instances trained with SFace
loss and documents block quantization and the directory's Apache-2.0 license.
[Official SFace models and README](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface).

InsightFace's current upstream license section separately describes the code and
provided model/data terms, including a licensing contact for open-source recognition
packs. [Official InsightFace license section](https://github.com/deepinsight/insightface#license).
Its Python package documentation describes ONNX Runtime integration and first-use
model downloads; installing the package does not grant different weight rights.
[Official Python package guide](https://github.com/deepinsight/insightface/tree/master/python-package).

The FaceNet repository documents its checkpoints, architectures, preprocessing
and training datasets. [Official FaceNet implementation](https://github.com/davidsandberg/facenet).
The dlib model repository documents individual artifact provenance and the landmark
exception. [Official dlib model inventory](https://github.com/davisking/dlib-models).

## Detection, landmarks and anti-spoofing alternatives

These roles produce different outputs. Replacing a detector or landmark model does
not replace an identity recognizer; installing a PAD model does not connect it to
the standalone browser's identity decision automatically.

| Candidate | Role and status | Evaluation / adapter work |
| --- | --- | --- |
| YuNet 2023mar original/full precision or block-quantized export | Additional detector variants; current selection remains the 2023mar INT8 file | Check tensor names, strides, output decoding, five-point landmark order, alignment parity and downstream recognition accuracy. Compare model size, memory and latency on the actual browser/CPU. |
| YuNet 2026may dynamic-input export | Additional upstream detector export, reviewed now; not installed here | OpenCV documents changed input-shape behavior. The current browser contract is fixed at 640 and uses a specific decoder; validate runtime/shape compatibility rather than adopting a newer filename automatically. |
| MediaPipe Face Landmarker | Already installed for the older guided workflow; optional tracking/landmark path | Its documented outputs are landmarks, expression blendshapes and transformation matrices. These are not the current SFace identity embeddings. Any revised tracking/depth/pose gate needs separate camera and attack evaluation. |
| MiniFASNet V1/V2 from Silent-Face-Anti-Spoofing | Additional PAD candidate, reviewed now; not installed or benchmarked here | Needs its own weight provenance/license review, patch scale/crop, tensor normalization, output interpretation and attack-fixture calibration. Its score thresholds cannot be copied from Intel anti-spoof-mn3. |

OpenCV lists the YuNet exports, MIT directory license and 2026may dynamic-shape
behavior. [Official YuNet models and README](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet).
Google describes the Face Landmarker outputs and supported camera/image workflows.
[Official Face Landmarker guide](https://developers.google.com/edge/mediapipe/solutions/vision/face_landmarker).
MiniVision documents MiniFASNet models, patch preprocessing and dependence on camera
and capture conditions. [Official Silent-Face-Anti-Spoofing guide](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing/blob/master/README_EN.md).

## How to evaluate an alternative locally

1. Create an isolated experiment checkout/environment and an empty collection or
   gallery. Keep the selected, working baseline available for comparison.
2. Record the exact source/revision, weight filename, SHA-256, code and weight
   licenses, input color/order/size/normalization, landmark order, output dimension
   and runtime dependencies. Review the actual artifact, not only the algorithm name.
3. Implement and test an explicit adapter. The current browser decoder and SFace
   worker are model-specific; Python service paths are also tied to their detector,
   encoder and PAD adapters. Installing a package or editing an environment variable
   does not supply support for a new architecture.
4. Version the model/preprocessing contract and register the new artifact checksums.
   `standalone/math.py` currently validates 128-dimensional vectors. A model with a
   different dimension requires coordinated schema/API/validation/client changes.
   Equal dimensions also do not imply that two models share an embedding space.
5. Re-enroll for a different embedding space. Keep old and new galleries isolated;
   only migrate an existing gallery if explicit compatibility evidence supports it.
   Recompute independent duplicate and recognition operating points for the full
   detector/alignment/recognizer pipeline.
6. Compare actual held-out known, unknown, similar-looking and ineligible identities,
   complete multi-observation decisions, failed captures and uncertainty. Use
   representative independent visits, cameras and lighting. Test persistence,
   duplicates, idempotent retries and interruption recovery as well as raw scores.
7. Measure cold start, download/cache size, memory and P50/P95 latency on target
   devices. Published benchmark figures from different cohorts are not local results.
   Evaluate PAD separately using real attack fixtures and the exact preprocessing.
8. Promote only a validated model/contract/gallery/policy combination. Preserve the
   versioned baseline and enrollment data needed for an intentional rollback.

The existing `tools/model_assets.py verify/restore` commands cover the installed
16-asset inventory only. They deliberately do not fetch unselected candidates or
rewrite manifests to accept alternative bytes. Add a separately reviewed manifest
and adapter when implementing an experiment; this guide changes no active model.

## Dataset alternatives are a separate choice

`docs/PUBLIC_FACE_DATASET_EVALUATION.md` records ControlFace10K as the selected
synthetic subset and HSFace/Vec2Face and LFW as assessed/deferred datasets. These
are evaluation data sources, not alternative inference models. The synthetic
experiment's limitations and error counts remain in that report and `DATASETS.md`;
they cannot establish production accuracy for any candidate listed here.
