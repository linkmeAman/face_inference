# Distribution evaluation

This document evaluates current distribution paths and OS-specific installer feasibility for `face_inference`. It is a feasibility evaluation only: it does not ship installers, select vendors, or claim installer artifacts exist.

## Current distribution paths (present)

| Path | Current state | Evidence | Operational notes |
| --- | --- | --- | --- |
| Python package / CLI (`pip`/`uvx` target) | Present in packaging metadata and CLI entrypoint | `pyproject.toml` declares `name="face-inference"`, hatchling build, `requires-python>=3.10`, console script `face-inference = face_inference.cli:main` (`pyproject.toml:1-42`, `pyproject.toml:44-46`). | Runtime deps are minimal (`cryptography>=42`) (`pyproject.toml:11`). Model/WASM assets are verified/fetched by asset tooling; packaged vendor assets may be used when valid (`src/face_inference/assets.py:118-147`, `src/face_inference/assets.py:149-171`). |
| npm web component (`face-capture`) | Present as package in `packages/face-capture` | `packages/face-capture/package.json` defines ESM exports, types, build/test scripts, and MIT license (`packages/face-capture/package.json:1-46`). | This is a browser embedding path; it does not replace the Python server. It depends on server/API compatibility and secure browser context for remote camera use (`docs/guide.md:111-121`, `docs/contract.md:3-7`). |
| Docker image / Compose | Present | `Dockerfile` builds wheel in `python:3.12-slim`, runs as non-root UID 10001, sets `FACE_INFERENCE_HOME=/data`, `FACE_INFERENCE_HOST=0.0.0.0`, exposes 6122, healthchecks `/health`, entrypoint fetches/verifies models then serves (`Dockerfile:1-76`). `compose.yaml` binds `127.0.0.1:6122:6122` by default and includes commented Caddy TLS proxy example (`compose.yaml:1-37`). | Compose default is privacy-oriented (loopback bind). Remote camera access still requires HTTPS/TLS proxy (`docs/docker.md:145-176`, `compose.yaml:15-25`). |

## Current deployment/config facts relevant to packaging

- Data home defaults to OS user data dir unless `FACE_INFERENCE_HOME` or legacy `./.face-inference` is used (`src/face_inference/config.py:22-36`). Config is generated with `0600`; data dir `0700` on POSIX (`src/face_inference/config.py:44-59`).
- Default listen is `127.0.0.1:6122`; config validates host/port/origins/rate limits/trusted proxies (`src/face_inference/config.py:50-56`, `src/face_inference/config.py:83-176`).
- Model/runtime assets are verified by manifest size/SHA-256 and reject symlinks; fetch supports HTTPS download or local `--from-dir` copy, with checksum checks and refusal to overwrite wrong-hash existing files (`src/face_inference/assets.py:28-43`, `src/face_inference/assets.py:94-116`, `src/face_inference/assets.py:149-230`).
- Docker offline paths exist via mounted `/data/models` or build-time seed / `FACE_INFERENCE_MODELS_FROM_DIR` (`docs/docker.md:87-143`, `Dockerfile:36-63`).

## OS-specific installer feasibility

The roadmap decision is: prioritize pip/npm/Docker; OS installers are a feasibility evaluation only (locked program decision: prioritize pip/npm/Docker; OS installers are feasibility evaluation only).

| Approach | Feasibility | Maintenance/signing/update implications | Constraints / risks |
| --- | --- | --- | --- |
| macOS `.pkg`/app bundle wrapping Python runtime + CLI/service | Feasible as a packaging exercise, but not currently shipped | Requires Apple Developer signing, notarization, installer signing, and a repeatable update channel. Each Python/runtime/dependency/model-manifest update needs rebuild, signing, notarization, and QA on supported macOS versions. | Must preserve private data dir/key semantics; uninstall/update must not delete `config.json`/DB silently. Camera/browser secure-context requirements still apply for remote access. Unverified: which macOS versions/architectures would be officially supported. |
| Windows MSI/MSIX/winget-style installer | Feasible in principle, but not currently shipped | Requires Authenticode code signing, installer/update pipeline, and servicing for Python runtime, VC/runtime dependencies as applicable, and firewall/service behavior. MSIX/store packaging has sandbox/path implications; MSI/EXE has different elevation/update tradeoffs. | Must handle `%APPDATA%` data location, service/user permissions, localhost firewall prompts, and key-loss backup warnings. Secure-context/TLS proxy still required for remote browser camera use. Unverified: enterprise deployment constraints and supported Windows versions. |
| Linux distro packages (`deb`/`rpm`) / systemd unit | Feasible for system integration, but not currently shipped | Requires per-distro packaging, dependency policy compliance, signing/repo maintenance, and update cadence across distro releases. Systemd hardening and user/data migration need explicit ownership. | Distro Python version drift and dependency availability can break reproducibility. Must not auto-expose network listener; default should remain loopback. Unverified: target distros, init systems, SELinux/AppArmor profiles. |
| Self-contained desktop wrapper (Tauri/Electron + local server) | Technically feasible, highest complexity | Adds another runtime/update surface (webview/app framework + Python sidecar), code signing on all OSes, auto-update security, and larger attack surface. | Risks duplicating existing browser UI and complicating model asset/update trust. Not justified by current scope; would need benchmark/product evidence. Unverified: UX/performance/resource cost. |

## Comparison with present paths

- **pip/uvx/CLI**: lowest packaging overhead, aligns with existing Python package and CLI (`pyproject.toml:1-46`, `docs/guide.md:3-17`). Best for developers/operators who can manage Python environments. Weakness: Python/runtime management is user-owned.
- **npm `face-capture`**: solves browser embedding, not server installation (`packages/face-capture/package.json:1-46`). Useful alongside server, not a replacement.
- **Docker/Compose**: strongest environment isolation and easiest repeatable deployment; already documents healthcheck, volume persistence, offline models, and TLS proxy pattern (`Dockerfile:1-76`, `docs/docker.md:1-205`, `compose.yaml:1-37`). Weakness: Docker dependency and volume/key backup responsibility.
- **OS installers**: potentially better end-user ergonomics, but add signing, notarization, updater, platform QA, and support burden. Current repo has no installer manifests/scripts/signing pipeline; therefore they remain feasibility-only.

## Unverified platform claims / gaps

- No verified support matrix for OS installer targets (specific macOS/Windows/Linux versions, ARM/x86 combinations) beyond Python package metadata `Operating System :: OS Independent` and `requires-python>=3.10` (`pyproject.toml:15-33`). Classifier is not installer support evidence.
- No verified code-signing/notarization/update infrastructure in this repo for any OS installer format (no installer manifests, no signing keys/workflows, no updater config). This is an absence observed from repository contents, not proof that external infrastructure cannot exist.
- No verified auto-update security design for desktop/package installers. Any future installer would need signed updates and rollback/key-rotation considerations; these are unimplemented here.
- Docker image runs as UID 10001 and includes healthcheck (`Dockerfile:20-76`), but this evaluation does not claim runtime vulnerability scanning, image signing, SBOM publication, or registry provenance are configured.
- Browser camera secure-context behavior is documented as a browser requirement for remote hosts (`docs/docker.md:145-149`, `docs/guide.md:111-121`); this evaluation does not claim behavior across every browser/version/vendor.

## Recommendation boundary

Current evidence supports continuing to prioritize pip/npm/Docker. OS-specific installers should remain a separate, explicitly-scoped project only after defining target platforms, signing/update ownership, data migration/uninstall semantics, and security review. No installer is shipped or promised by this document.
