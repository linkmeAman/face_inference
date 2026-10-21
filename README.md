# Standalone face enrollment and recognition

The default application is now **Python + SQLite + plain JavaScript**, with no
PHP, MySQL, CCC login, member, batch or attendance dependency. It uses the original
browser YuNet/SFace models, preprocessing and camera methods from the exported
current system.

## Start on this server

```bash
cd /var/www/face_inference
python3 run.py check
python3 run.py token
python3 run.py
```

Open **http://127.0.0.1:6122**, enter the token printed by the second command,
create a collection, add people, confirm consent, then enroll or recognize.
For a remote server, access the loopback listener through your SSH tunnel or an
HTTPS reverse proxy configured as described in the guide. Ctrl-C stops the server.

This host already has the required Python cryptography package. On another machine:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install -r requirements-standalone.txt
.venv/bin/python run.py check
.venv/bin/python run.py
```

No Python model dependencies are required for this UI: the existing browser WASM
runtime runs YuNet/SFace. Models/runtime assets are already included, with no CDN.
The separately preserved Python ONNX/PAD inference service is still available
through `run_inference.py` and its original requirements when needed.

For every model, exact local paths, installation, offline use and missing-file recovery,
see [MODEL_SETUP.md](MODEL_SETUP.md). Verify all 16 model/runtime assets with:

```bash
python3 tools/model_assets.py verify
```

## What is configurable

* Create arbitrary collections and people through the UI or JSON API. IDs can be
  your employee/customer/student/application identifiers; metadata is optional.
* The private `.standalone/config.json` controls SQLite location, host/port,
  thresholds, retention, timezone, evidence source and approved collection scope.
* `.standalone/faces.sqlite3` stores encrypted face templates and observations.
  Keys and the API token are independent of the original CCC application.
* `FaceClient` and the `face-result` browser event let another application consume
  identity decisions. Recognition itself triggers no attendance or external action.

Default mode is **EVALUATION**. Enrollment is PENDING; recognition returns an
unverified SUGGESTED identity. The original shadow thresholds are provisional.
Approved recognition requires an independently validated policy/report and exact
approved collections. Face quality and recognition do not establish liveness.

## Documentation and code

| File | Purpose |
| --- | --- |
| [STANDALONE_GUIDE.md](STANDALONE_GUIDE.md) | Configuration, API, embedding into another app, evidence/export and verification |
| [MODEL_SETUP.md](MODEL_SETUP.md) | All models/runtime assets, local installation, checksum verification, recovery and Git instructions |
| [MODEL_ALTERNATIVES.md](MODEL_ALTERNATIVES.md) | Recorded model alternatives, additional candidates, tradeoffs, sources and migration requirements |
| [standalone/CONTRACT.md](standalone/CONTRACT.md) | API, schema, lifecycle and original test matrix |
| `standalone/service.py`, `math.py` | PHP-free enrollment, aggregation, duplicate and unanimous matching logic |
| `standalone/storage.py`, `schema.sql` | Authenticated encryption and SQLite persistence/transactions |
| `standalone/server.py`, `web/` | Python HTTP API and plain JavaScript camera UI/client |
| [DATASETS.md](DATASETS.md) | Original synthetic dataset, model provenance, results and limitations |
| [REUSE_GUIDE.md](REUSE_GUIDE.md) | Detailed original pipeline/method map and historical CCC integration context |

The original PHP code is retained as reference and provenance. The standalone
application never executes it or calls PHP endpoints. Original source models,
datasets, tweaks, tests and historical docs remain intact. The previous export
README is preserved in `snapshot/EXPORT_README.original.md`.

See `snapshot/STANDALONE_VERIFICATION.md` for the new actual-model browser and
Python/SQLite test results. No live CCC database or installed service was changed.
# face_inference
