# face_inference

[![CI](https://github.com/linkmeAman/face_inference/actions/workflows/ci.yml/badge.svg)](https://github.com/linkmeAman/face_inference/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A browser-based face enrollment and recognition toolkit with local in-browser inference, encrypted storage, and two runtime dependencies (`cryptography` and `tzdata`); inference runs in the browser.

- **In-browser inference**: Face detection (YuNet) and recognition feature extraction (SFace) run locally via WebAssembly (`onnxruntime-web`)—no video or raw facial images leave the client browser.
- **Encrypted persistence**: 128-dimensional biometric embeddings are encrypted at rest with Fernet in SQLite, with automated retention expiry and consent gating.
- **Ready to embed**: Standalone web UI, embeddable `<face-capture>` custom element, typed standard-library Python client, and REST JSON API.

## Quick Start

> [!NOTE]
> *Once published to PyPI and npm, you will be able to install via `pip install face-inference` or `npm install face-capture`. Until published, install from a local checkout as shown below.*

### Path 1: Local Package Install
```bash
git clone https://github.com/linkmeAman/face_inference.git
cd face_inference
python3 -m venv .venv
source .venv/bin/activate
pip install .
```
*(Windows PowerShell: `.venv\Scripts\Activate.ps1`)*

### Path 2: Docker Compose *(not yet verified on a live daemon)*
```bash
docker compose up -d
```

### Path 3: From Source for Development
```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"
```
*(Windows PowerShell: `.venv\Scripts\Activate.ps1`)*

## First Run

```bash
# 1. Verify model weights (YuNet, SFace, ONNX Runtime WASM)
face-inference models verify

# 2. Generate configuration and print administrative bearer token
face-inference token

# 3. Start the service on 127.0.0.1:6122
face-inference serve
```
*(Windows PowerShell environment override: `$env:FACE_INFERENCE_HOME = "C:\path\to\data"`)*

Open **http://127.0.0.1:6122** in your browser, enter the API token from `face-inference token`, create a collection, add a person, confirm the consent checkbox, and enroll or recognize a face.

## Embed in Your Own Page

Mint short-lived session tokens from your backend using the admin token so private credentials remain secret:

```bash
curl -X POST http://127.0.0.1:6122/api/v1/session-tokens \
  -H "Authorization: Bearer ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"collection_id":"default","scope":"recognize","ttl":300}'
```

Embed the `<face-capture>` Web Component using the session token. Before publish, run `cd packages/face-capture && npm run build` and load `src="packages/face-capture/dist/face-capture.js"` (once published, use `node_modules/face-capture/dist/face-capture.js`):

```html
<script type="module" src="packages/face-capture/dist/face-capture.js"></script>

<face-capture
  api-url="http://127.0.0.1:6122"
  token="SESSION_TOKEN"
  collection="default"
  mode="recognize"
  autostart>
</face-capture>
```

For local experiments only you can paste the admin token.

## Use from Python

Interact with the service using the typed standard-library client:

```python
from face_inference.client import FaceClient

client = FaceClient("http://127.0.0.1:6122", token="YOUR_API_TOKEN")
health = client.health()
client.create_collection("my-team", "My Team")
client.add_person("my-team", "alice", "Alice Smith", metadata={"dept": "eng"})
people = client.list_people("my-team")
client.delete_face("my-team", "alice")
```

See [examples/python/quickstart.py](examples/python/quickstart.py) for a complete runnable walkthrough.

## REST API

The HTTP REST API specification is documented in [docs/openapi.yaml](docs/openapi.yaml) (OpenAPI 3.1). All `/api/v1/` endpoints require `Authorization: Bearer <TOKEN>` and `Content-Type: application/json`.

## Privacy & Operational Limits

> [!IMPORTANT]
> - **In-browser embeddings**: Camera frames are processed in client WASM memory; raw images are never sent to or stored on the server.
> - **Encryption at rest**: Face templates and observation vectors are encrypted in SQLite using Fernet (`0600` file permissions).
> - **Mandatory consent**: Affirmative consent confirmation is required before enrollment is permitted.
> - **No liveness / PAD**: The system does NOT detect presentation attacks (photos, screen replay, 3D masks) and reports `liveness_validated=False`. Do not use for unattended security gates.
> - **Evaluation mode default**: Operates in `EVALUATION` mode (`verified=false`, unverified suggestions). Production biometric matching requires formal policy validation. Recorded public synthetic tests identified 18/30 known queries, left 12/30 unmarked, and accepted 1/22 unknown queries with no real-world cohort (see [docs/datasets.md](docs/datasets.md)).
> See [PRIVACY.md](PRIVACY.md) and [SECURITY.md](SECURITY.md) for complete policies.

## Configuration

Configuration and data live in `<home>`. Back up `config.json` AND `faces.sqlite3` together: the encryption key is in config.json; losing it makes enrolled faces unrecoverable. Data directory `<home>` resolves in order: `FACE_INFERENCE_HOME`, existing `./.face-inference/`, or the per-user OS data directory (`~/Library/Application Support/face-inference` on macOS, `~/.local/share/face-inference` on Linux, `%APPDATA%\face-inference` on Windows).

| Setting | Environment | CLI flag | Default / meaning |
| --- | --- | --- | --- |
| `database` | — | — | Absolute `<home>/faces.sqlite3` path |
| `host` | `FACE_INFERENCE_HOST` | `--host H` | `127.0.0.1`; IP literal or hostname |
| `port` | `FACE_INFERENCE_PORT` | `--port P` | `6122`; integer from 1 through 65535 |
| `mode` | — | — | `EVALUATION`; alternatives `COLLECT`, `APPROVED` |
| `duplicate_threshold`, `recognition_threshold` | — | — | `0.85`, `0.65`, original browser shadow values; provisional |
| `retention_days` | — | — | 30; expired ciphertext erased during mutations or purge |
| `timezone` | — | — | `Asia/Kolkata`; configurable IANA timezone |
| `capture_source` | — | — | `unvalidated-camera`; classify actual evidence explicitly |
| `approved_collection_ids` | — | — | Empty; exact authorized scopes required for APPROVED mode |
| `policy_file` | — | — | Empty; private policy bound to verified evaluation report |
| `allowed_origins` | `FACE_INFERENCE_ALLOWED_ORIGINS` | — | Empty; exact HTTPS origins, or HTTP origins for `localhost`, `127.0.0.1`, `[::1]` (see docs/guide.md) |
| `rate_limit_per_minute` | `FACE_INFERENCE_RATE_LIMIT` | — | 120; integer from 1 through 100000 per client IP |
| `trusted_proxies` | `FACE_INFERENCE_TRUSTED_PROXIES` | — | Empty; list of trusted proxy IPs/CIDRs |
| `max_people` | — | — | 1000 per collection |
| `api_token`, `encryption_key`, `session_secret` | — | — | Generated credentials; keep private |

## Documentation

| Document | Purpose |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Two-tier architecture, sequence diagrams, data model, trust boundaries |
| [docs/contract.md](docs/contract.md) | Model contracts, preprocessing specs, and lifecycle states |
| [docs/datasets.md](docs/datasets.md) | Synthetic dataset inventory, model provenance, and evaluation caveats |
| [docs/deployment.md](docs/deployment.md) | Production deployment (TLS, reverse proxy, systemd, launchd) |
| [docs/docker.md](docs/docker.md) | Containerization with Docker & Docker Compose, offline deployment |
| [docs/guide.md](docs/guide.md) | Configuration reference, REST API, session tokens, and clients |
| [docs/model-alternatives.md](docs/model-alternatives.md) | Evaluation of alternative models and future upgrade paths |
| [docs/model-setup.md](docs/model-setup.md) | Model weight verification, checksums, and offline setup |
| [docs/openapi.yaml](docs/openapi.yaml) | OpenAPI 3.1 REST API specification |
| [docs/releasing.md](docs/releasing.md) | Maintainer release process, PyPI trusted publishing, and npm |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Diagnosing camera issues, CORS errors, and status codes |

## Project Layout

```
face_inference/
├── pyproject.toml              # Build & packaging configuration (Hatchling)
├── compose.yaml                # Docker Compose service definition
├── Dockerfile                  # Multi-stage container build
├── src/
│   └── face_inference/         # Core Python package & CLI entry point
│       └── web/                # In-browser UI, Web Worker, and WASM runtime
├── packages/
│   └── face-capture/           # Reusable <face-capture> Web Component
├── examples/                   # Python & Web integration examples
├── tools/                      # Maintenance, release packaging, and smoke scripts
├── docs/                       # Architecture, guides, and specifications
└── tests/                      # Pytest and Node.js test suites
```

## Contributing & Credits

- **Contributing**: See [CONTRIBUTING.md](CONTRIBUTING.md) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
- **License**: MIT License © 2026 Aman Singh ([LICENSE](LICENSE)).
- **Credits**: Created by Aman Singh. Third-party components: YuNet (MIT License, Shiqi Yu), SFace (Apache-2.0, OpenCV Zoo Contributors), onnxruntime-web (MIT License, Microsoft Corporation), cryptography (Apache-2.0 / BSD-3-Clause). Full notices in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
