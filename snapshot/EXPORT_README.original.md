# Current face system reuse bundle

Snapshot of the CCC face system taken on 2026-10-21 from commit
`1fa65fad8800c1d9a4feae09989fd7d1094f37ca`, assembled for `/var/www/face_inference/`.
Source files retain their original relative paths. Model weights, prepared browser
models, WASM files and calibration JSON ignored by Git are included.

Start with [REUSE_GUIDE.md](REUSE_GUIDE.md) for the frontend/backend methods,
exact processing rules and the boundary to adapt for your separate application.
[DATASETS.md](DATASETS.md) describes every bundled dataset and the data that was
unavailable or deliberately not exported.

## Contents

| Location | Contents |
| --- | --- |
| `services/face-inference/` | Complete Python app, tools, tests, three ONNX models, calibration and original systemd units |
| `pf/js/browser-face/` | Current automatic enrollment/recognition modules and exact shared contract |
| `pf/vendor/browser-face/` | Prepared YuNet/SFace models and ONNX Runtime Web 1.24.3 JS/WASM |
| `pf/js/faceEnrollment*`, `pf/vendor/face-landmarker/` | Older guided tracker, worker, MediaPipe 0.10.34 model/runtime |
| `pf/attendance.php`, `pf/inc/browser-face-panel.php`, `pf/css/` | Current UI integration, panel and face styles |
| `classes/`, `controllers/`, `routes/` | Matching, enrollment, encrypted storage, attendance, policy, provider and API implementations |
| `database/migrations/`, `scripts/` | Schema and migration/admin/asset tools, preserved for review |
| `evaluation/`, `tests/` | Evaluators, Python/JS/PHP tests and original integration harnesses |
| `datasets/` | 144 original synthetic evaluation images, manifests, results and development diagnostics |
| `docs/` | Original methodology, setup, API contracts, decisions, fixes and operating history |
| `snapshot/` | Per-file provenance/checksums, redacted settings, runtime versions and observed state |
| `export_tools/`, `run_inference.py` | Bundle verification and isolated portable Python launcher |

## Run the Python service separately

From this directory, create a new virtual environment using Python 3.12 and
install the actual captured runtime versions:

```bash
python3.12 -m venv .venv
.venv/bin/python -m pip install -r snapshot/requirements.current-runtime.txt
.venv/bin/python export_tools/verify_bundle.py
.venv/bin/python run_inference.py --prepare
.venv/bin/python run_inference.py --check
.venv/bin/python run_inference.py
```

The new service binds to `127.0.0.1:6121`, generates a separate private token,
disables experiment database logging, and uses relocated copies of the captured
calibration. `GET http://127.0.0.1:6121/health` checks it. Other private routes
require `X-Face-Inference-Token` from `runtime/settings.json`. Keep that file private.
The launcher performs no systemd installation or database migration. Stop it with
Ctrl-C. `--port NUMBER` selects another independent port.

The original `services/face-inference/requirements.lock` is also preserved, but
its Python 3.8-era pins differ from the installed Python 3.12 runtime. Use the
captured runtime file above to reproduce this host; see `snapshot/python-version.txt`.
The optional `export_tools/face-inference-separate.service.example` is a separate
unit template. Review its user and permissions before installing it.

## Reuse the current frontend

The ordinary UI now uses browser inference and PHP matching. It does **not** send
its browser embeddings into the older Python guided-enrollment protocol.
Use `EmbeddedFaceFlow` or `AutomaticCapture` from `pf/js/browser-face/`, preserve
the worker/assets relative layout, and implement the API/storage adapter described
in [REUSE_GUIDE.md](REUSE_GUIDE.md). Existing PHP code supplies that adapter for CCC.

This is an extraction of the face subsystem, not a second installed CCC admin
application. CCC login/tenant resolution, member/session tables, navigation and
other business features need your host application. The database connector uses `FACE_EXPORT_DB_HOST`,
`FACE_EXPORT_DB_USER` and `FACE_EXPORT_DB_PASSWORD` instead of inline credentials.
Other credential-bearing configuration/test references were sanitized too. All six
modified files and their original/exported hashes are listed in the manifest.
No copied PHP page is automatically published or connected to a database.

## Current state and reproduction limits

The running Python service reported ready models and approved loaded identity/PAD
artifacts at export time. The current browser configuration has no
`FACE_WEB_CALIBRATION_FILE`; its rollout defaults to SHADOW. The saved Testing
exception expired on 2026-10-16. Preserve this distinction: Python artifact status
does not enable approved browser LIVE matching. Captured historical calibration
claims have not been independently revalidated by this export.

No live database records, member biometrics, encryption keys, production shared
tokens or passwords were copied. The complete source/model/evaluation methodology
is included; reusing existing live identities would additionally require a
separately scoped data migration and the original protected encryption key.
Public synthetic data and mock tests do not establish live accuracy or liveness.

Do not serve this whole directory as a website. Publish only the required frontend
assets through your authenticated application's existing web boundary; keep
datasets, snapshots, service code and generated runtime settings private.

See `snapshot/VERIFICATION.md` for checks actually performed during this export.
