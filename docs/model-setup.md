# All local face models and installation

All model weights and browser runtime files listed here are already present and
tracked in this project's Git repository. Keep these files in your commit: another
machine can then clone and run the standalone application without downloading
models or using a CDN. Virtual environments, keys and databases remain local.

## 1. What each model does

| Model / runtime | Exact local file | Used by |
| --- | --- | --- |
| YuNet 2023mar INT8 | `src/face_inference/web/vendor/browser-face/yunet.onnx` | Current standalone browser face detection |
| SFace 2021dec INT8BQ, prepared graph | `src/face_inference/web/vendor/browser-face/sface.onnx` | Current standalone browser face embeddings |
| ONNX Runtime Web 1.24.3 | `src/face_inference/web/vendor/browser-face/ort.wasm.min.mjs`, `ort-wasm-simd-threaded.mjs`, `ort-wasm-simd-threaded.wasm` | Current browser model execution, single-thread WASM |

Legacy MediaPipe task bundles and the older Python-only PAD service (`services/face-inference/`) were removed from `main` and are preserved under Git tag `legacy-ccc-final`.

The current standalone UI uses the two browser ONNX models and ORT Web. The Python backend verifies and compares embeddings; models run locally in the client browser.

## 2. Install and run the standalone application on another Linux machine

Use Python 3.10+ (Python 3.12 recommended). From the cloned repository:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install -e .
.venv/bin/face-inference models verify
.venv/bin/face-inference check
.venv/bin/face-inference token
.venv/bin/face-inference serve
```

Note that `face-inference check` is not a read-only command: it verifies configuration and model assets, initializing `config.json` (with generated API token, encryption key, and session secret) and the database schema if they do not yet exist. Missing assets return `{"status": "assets-missing", ...}` before database initialization.

Open `http://127.0.0.1:6122` and enter the token. Use an SSH tunnel for a remote
server, or the HTTPS reverse-proxy setup in [docs/guide.md](guide.md).

The standalone backend needs cryptography and Python's built-in SQLite/HTTP modules.
It does not need PHP, MySQL, Python OpenCV, Python ONNX Runtime or Node to run the
already prepared browser assets. Models run locally in the user's browser; normal
camera frames are not uploaded to an external model provider. No raw camera image
is stored by the standalone application.

For a browser-only asset check:

```bash
.venv/bin/face-inference models verify
```

## 3. Legacy Python inference service (historical)

The legacy Python-only detector and anti-spoofing service (`services/face-inference/`) was removed from `main` to simplify the package architecture. It is preserved under Git tag `legacy-ccc-final` for historical reference.

## 4. Recover missing model/runtime files

First inspect what is missing:

```bash
face-inference models verify
```

The tool checks the browser model and runtime assets. It verifies their recorded SHA-256 values without network access.

If the files are tracked in Git, restore the local committed copy:

```bash
git restore -- src/face_inference/web/vendor/browser-face
face-inference models verify
```

`git restore` discards local changes in those directories; use it when you intend to
recover the committed assets. Alternatively, fetch only the missing files from
the pinned official release:

```bash
face-inference models fetch
face-inference models verify
```

The fetch command downloads only missing files. It refuses to overwrite a local
file whose checksum differs. It verifies model SHA-256 values and the exact runtime asset hashes before publishing.
any reconstructed file. Only rebuilding a missing prepared SFace graph requires the
optional `onnx` Python package; all other verification uses the standard library.

Original sources and licenses are recorded locally in:

* `src/face_inference/web/vendor/browser-face/manifest.json`: exact prepared browser ONNX hashes and file sizes.
* `src/face_inference/web/browser-face/contract.json`: model input/output contract.
* `THIRD_PARTY_NOTICES.md`: upstream source, model, and runtime license notices.

## 5. Model asset packaging and distribution

In the current build configuration (`pyproject.toml`), model and WASM binaries (`sface.onnx`, `yunet.onnx`, `ort-wasm-simd-threaded.wasm`, totaling ~23 MB) located in `src/face_inference/web/vendor/browser-face/` are bundled directly into the package wheel.

Unbundling these binaries from future release wheels—so that wheels remain lightweight and binaries are downloaded on first run or via CLI (`face-inference models fetch`)—remains a future distribution goal. The distribution packaging scripts (`tools/package_assets.py`) already support preparing asset archives for release hosting:

```bash
python3 tools/package_assets.py
```

This verifies `manifest.json` SHA-256 hashes, copies assets to `dist-assets/`, and writes `SHA256SUMS`. When assets are unbundled or if local files are missing, `face-inference models fetch` downloads missing assets from the pinned release and verifies their checksums.

## 6. Local asset management

Browser model files and WASM runtime files are located under:
`src/face_inference/web/vendor/browser-face/`

Keep `.env`, `.venv/`, `node_modules/`, and `dist-assets/` out of Git. They contain local state, virtual environments, or release staging files.

After cloning on another computer, run `face-inference models verify` to confirm that the full local model set arrived, or `face-inference models fetch` to download missing assets.

## 7. Alternatives and model-selection history

See [model-alternatives.md](model-alternatives.md) for the recorded InsightFace
alternative, additional OpenCV/FaceNet/dlib/PAD candidates, upstream sources,
licensing distinctions and the adapter/enrollment/calibration work needed to
change models. Recorded decisions and newly reviewed options are labeled separately.

## Maintainer note: how the prepared browser models were built

`sface.onnx` and `yunet.onnx` in the model release are not raw upstream downloads. `tools/prepare_browser_face.py`
verifies the original OpenCV Zoo weights against the pinned SHA-256 values in
`src/face_inference/web/browser-face/contract.json`, strips SFace's stale weight inputs from the graph, and copies the pinned
`onnxruntime-web` 1.24.3 runtime files from `node_modules` (`npm install`, then `npm run face:assets`). It needs the `onnx`
Python package and the two original weight files in the working directory. Run it only to reproduce or audit the shipped
files; ordinary users never need it, they run `face-inference models fetch`.
