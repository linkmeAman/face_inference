# Standalone face enrollment and recognition

The default application is now **Python + SQLite + plain JavaScript**, with no
PHP, MySQL, CCC login, member, batch or attendance dependency. It uses the original
browser YuNet/SFace models, preprocessing and camera methods from the exported
current system.

## Start on this server

```bash
cd face_inference
python -m face_inference check
python -m face_inference token
python -m face_inference
```

Open **http://127.0.0.1:6122**, enter the token printed by the second command,
create a collection, add people, confirm consent, then enroll or recognize.
For a remote server, access the loopback listener through your SSH tunnel or an
HTTPS reverse proxy configured as described in the guide. Ctrl-C stops the server.

This host already has the required Python cryptography package. On another machine:

```bash
python3 -m venv .venv
.venv/bin/python -m pip install .
.venv/bin/python -m face_inference check
.venv/bin/python -m face_inference
```

No Python model dependencies are required for this UI: the existing browser WASM
runtime runs YuNet/SFace. Models/runtime assets are already included, with no CDN.
Legacy services, historical snapshots, and old tests are preserved under Git tag `legacy-ccc-final`.

For every model, exact local paths, installation, offline use and missing-file recovery,
see [docs/model-setup.md](docs/model-setup.md). Verify model/runtime assets with:

```bash
face-inference models verify
```

## What is configurable

* Create arbitrary collections and people through the UI or JSON API. IDs can be
  your employee/customer/student/application identifiers; metadata is optional.
* The private `<home>/config.json` controls SQLite location, host/port,
  thresholds, retention, timezone, evidence source and approved collection scope.
* `<home>/faces.sqlite3` stores encrypted face templates and observations.
  Keys and the API token are independent of the original CCC application.
* `FaceClient` and the `face-result` browser event let another application consume
  identity decisions. Recognition itself triggers no attendance or external action.

`<home>` uses `FACE_INFERENCE_HOME` when set, then an existing `./.face-inference/`
(with a legacy-location notice), otherwise the per-user OS data directory:
`~/Library/Application Support/face-inference` on macOS,
`$XDG_DATA_HOME/face-inference` or `~/.local/share/face-inference` on Linux, and
`%APPDATA%\face-inference` or `~\AppData\Roaming\face-inference` on Windows.
`face-inference init` and `check` print the resolved `home` in their JSON output.
See the [guide](docs/guide.md) for config and model directory overrides.

Default mode is **EVALUATION**. Enrollment is PENDING; recognition returns an
unverified SUGGESTED identity. The original shadow thresholds are provisional.
Approved recognition requires an independently validated policy/report and exact
approved collections. Face quality and recognition do not establish liveness.

## Documentation and code

| File | Purpose |
| --- | --- |
| [docs/guide.md](docs/guide.md) | Configuration, API, embedding into another app, evidence/export and verification |
| [docs/model-setup.md](docs/model-setup.md) | All models/runtime assets, local installation, checksum verification, and recovery |
| [docs/model-alternatives.md](docs/model-alternatives.md) | Recorded model alternatives, additional candidates, tradeoffs, sources and migration requirements |
| [docs/contract.md](docs/contract.md) | API, schema, lifecycle and verification matrix |
| `src/face_inference/service.py`, `matching.py` | PHP-free enrollment, aggregation, duplicate and unanimous matching logic |
| `src/face_inference/storage.py`, `schema.sql` | Authenticated encryption and SQLite persistence/transactions |
| `src/face_inference/server.py`, `web/` | Python HTTP API and plain JavaScript camera UI/client |
| [docs/datasets.md](docs/datasets.md) | Original synthetic dataset, model provenance, results and limitations |

Legacy PHP code, historical snapshots, and old tests were removed from `main`
and are preserved under Git tag `legacy-ccc-final`. The standalone application
has zero PHP/MySQL dependencies.
