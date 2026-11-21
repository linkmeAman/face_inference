# All local face models and installation

All model weights and browser runtime files listed here are already present and
tracked in this project's Git repository. Keep these files in your commit: another
machine can then clone and run the standalone application without downloading
models or using a CDN. Virtual environments, keys and databases remain local.

## 1. What each model does

| Model / runtime | Exact local file | Used by |
| --- | --- | --- |
| YuNet 2023mar INT8 | `pf/vendor/browser-face/yunet.onnx` | Current standalone browser face detection |
| SFace 2021dec INT8BQ, prepared graph | `pf/vendor/browser-face/sface.onnx` | Current standalone browser face embeddings |
| ONNX Runtime Web 1.24.3 | `pf/vendor/browser-face/ort.wasm.min.mjs`, `ort-wasm-simd-threaded.mjs`, `ort-wasm-simd-threaded.wasm` | Current browser model execution, single-thread WASM |
| Original YuNet 2023mar INT8 | `services/face-inference/models/face_detection_yunet_2023mar_int8.onnx` | Optional Python inference service; source for browser preparation |
| Original SFace 2021dec INT8BQ | `services/face-inference/models/face_recognition_sface_2021dec_int8bq.onnx` | Optional Python inference service; source for browser preparation |
| Intel anti-spoof-mn3 | `services/face-inference/models/anti-spoof-mn3.onnx` | Optional Python PAD classifier |
| MediaPipe Face Landmarker task | `pf/vendor/face-landmarker/0.10.34/face_landmarker.task` | Preserved older guided capture/tracking workflow |
| MediaPipe Tasks Vision 0.10.34 | `pf/vendor/face-landmarker/0.10.34/vision_bundle.js` and the six JS/WASM files in its `wasm/` directory | Preserved older landmark worker |

The MediaPipe task is a bundle containing `face_detector.tflite`,
`face_landmarks_detector.tflite`, `face_blendshapes.tflite` and geometry metadata.
Those components are inside the `.task` file; they do not need separate installs.

The current standalone UI uses the two browser ONNX models and ORT Web. Installing
the optional PAD or MediaPipe files does not automatically enable liveness in that
UI. The Python-guided and browser workflows retain different preprocessing contracts;
their stored embeddings and calibration cannot be mixed.

## 2. Install and run the standalone application on another Linux machine

Use Python 3.12 to match the captured host environment. From the cloned repository:

```bash
python3.12 -m venv .venv
.venv/bin/python -m pip install -r requirements-standalone.txt
.venv/bin/python tools/model_assets.py verify
.venv/bin/python run.py check
.venv/bin/python run.py token
.venv/bin/python run.py
```

Open `http://127.0.0.1:6122` and enter the token. Use an SSH tunnel for a remote
server, or the HTTPS reverse-proxy setup in `STANDALONE_GUIDE.md`.

The standalone backend needs cryptography and Python's built-in SQLite/HTTP modules.
It does not need PHP, MySQL, Python OpenCV, Python ONNX Runtime or Node to run the
already prepared browser assets. Models run locally in the user's browser; normal
camera frames are not uploaded to an external model provider. No raw camera image
is stored by the standalone application.

For a browser-only asset check:

```bash
.venv/bin/python tools/model_assets.py verify --profile browser
```

## 3. Install the optional Python detector, recognizer and anti-spoofing service

Use a separate environment so the optional inference dependencies do not expand
the lightweight standalone environment:

```bash
python3.12 -m venv .venv-inference
.venv-inference/bin/python -m pip install -r snapshot/requirements.current-runtime.txt
.venv-inference/bin/python tools/model_assets.py verify --profile python
.venv-inference/bin/python run_inference.py --prepare
.venv-inference/bin/python run_inference.py --check
.venv-inference/bin/python run_inference.py
```

This private service uses `127.0.0.1:6121`, independent of the standalone UI/API on
6122. It loads the original YuNet/SFace/PAD models, generates independent settings
under `runtime/`, and relocates copies of the existing calibration files. Its private
routes require `X-Face-Inference-Token` from `runtime/settings.json`.

The captured Python 3.12 runtime includes:

| Package | Captured installed version |
| --- | --- |
| numpy | 2.5.3 |
| onnx | 1.22.0 |
| onnxruntime | 1.30.0 |
| opencv-contrib-python-headless | 5.0.0.93 |
| PyMySQL | 1.1.1; preserved optional experiment writer dependency, not a standalone database requirement |

The complete captured dependency list, including transitive packages, is
`snapshot/requirements.current-runtime.txt`. The original service's older
`requirements.lock` is retained as historical source; its Python 3.8-era pins are
different. Use the captured Python 3.12 file for reproducing this installed host.
If a different OS/architecture cannot install these exact pins, select compatible
dependencies deliberately and rerun model/functional verification before use.

Loaded historical calibration status is not validation of a new project's cameras,
users or presentation-attack defenses. This installation does not start a global
service, apply a database migration or enable approved browser matching.

## 4. Recover missing model/runtime files

First inspect what is missing:

```bash
python3 tools/model_assets.py verify
```

The tool checks **16 assets** across the browser, Python and older MediaPipe
workflows. It verifies their recorded SHA-256 values without network access.

If the files are tracked in Git, restore the local committed copy:

```bash
git restore -- services/face-inference/models pf/vendor/browser-face pf/vendor/face-landmarker
python3 tools/model_assets.py verify
```

`git restore` discards local changes in those directories; use it when you intend to
recover the committed assets. Alternatively, restore only the missing files from
the pinned official sources:

```bash
.venv-inference/bin/python tools/model_assets.py restore
.venv-inference/bin/python tools/model_assets.py verify
```

Available profiles: `--profile browser`, `--profile python`,
`--profile mediapipe`, or `--profile all` (default).

The restore command downloads only missing files. It refuses to overwrite a local
file whose checksum differs. It verifies original model SHA-256 values, npm package
archive integrity, and the exact extracted runtime asset hashes before publishing
any reconstructed file. Only rebuilding a missing prepared SFace graph requires the
optional `onnx` Python package; all other verification uses the standard library.

The MediaPipe model source contains `latest`. Its recorded SHA-256 pins this copy;
if upstream changes the bytes, recovery stops. Recover the matching committed or
archived copy instead of silently accepting a different model.

Original sources and licenses are recorded locally in:

* `services/face-inference/models/MODEL-MANIFEST.json`: original YuNet/SFace/PAD URLs,
  hashes and license references.
* `services/face-inference/models/MODEL-LICENSES.md`: preserved intended-use notes.
* `pf/vendor/browser-face/manifest.json`: exact prepared browser ONNX hashes.
* `package-lock.json`: ONNX Runtime Web 1.24.3 archive URL and SHA-512 integrity.
* `pf/vendor/face-landmarker/0.10.34/ASSET-MANIFEST.json`: exact Tasks Vision package
  version/archive integrity and every MediaPipe runtime/model hash.
* `pf/vendor/face-landmarker/0.10.34/LICENSE-APACHE-2.0` and
  `pf/vendor/browser-face/ORT-LICENSE`: preserved license notices/references.

## 5. Rebuild browser assets from original ONNX weights

This is optional when the prepared files already exist. Node/npm are only needed
for this rebuild and browser test tooling:

```bash
npm ci --no-audit --no-fund
.venv-inference/bin/python scripts/prepare_browser_face.py
python3 tools/model_assets.py verify --profile browser
```

The preparation script uses the original YuNet/SFace weights, keeps the SFace
`data` input and removes obsolete initializer input declarations. It does not
retrain or change model weights. The prepared SFace file therefore has a different
checksum from the original file. The verifier checks the recorded prepared hash;
do not replace the browser model with the original graph by renaming its file.

Keep the current layout: the worker resolves its model/runtime files from
`pf/vendor/browser-face/`. The model/preprocessing contract remains
`pf/js/browser-face/contract.json`: 128 dimensions, 640 detector input, 112 aligned
RGB face input, original quality thresholds and five spaced observations.

## 6. Include all assets in GitHub

In this repository, all three original ONNX files, both prepared browser ONNX
files, ORT Web runtime files, and the complete MediaPipe task/runtime are already
Git-tracked. To stage them explicitly along with the setup instructions:

```bash
git add README.md MODEL_SETUP.md tools/model_assets.py .gitignore
git add requirements-standalone.txt snapshot/requirements.current-runtime.txt package.json package-lock.json
git add services/face-inference/models pf/vendor/browser-face pf/vendor/face-landmarker
git status
git commit -m "Document and verify local face model setup"
git push
```

If you also include the refreshed export inventory, stage `SHA256SUMS`,
`snapshot/bundle-files.json` and `snapshot/model-assets-verification.json`.

Keep `.standalone/`, `runtime/`, `.env`, `.venv/`, `.venv-inference/` and
`node_modules/` out of Git. They contain local state, keys, databases or dependencies
that each installation should generate/install independently. Models are included
explicitly, with their manifests and notices; they are not secrets. Do not use a
broad `*.onnx`, `*.wasm` or `*.task` ignore rule in this exported project.

After cloning on another computer, run `python3 tools/model_assets.py verify` to
confirm that the full local model set arrived. Install the standalone dependencies
and generate a fresh private configuration for that project. Existing live CCC
enrollments and encryption keys were never included in this reusable repository.

## 7. Alternatives and model-selection history

See [MODEL_ALTERNATIVES.md](MODEL_ALTERNATIVES.md) for the recorded InsightFace
alternative, additional OpenCV/FaceNet/dlib/PAD candidates, upstream sources,
licensing distinctions and the adapter/enrollment/calibration work needed to
change models. Recorded decisions and newly reviewed options are labeled separately.
