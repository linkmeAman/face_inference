# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added the `src/face_inference/` package layout and `face-inference` CLI with `serve`, `init`, `check`, `token`, `purge`, `export`, and `models` commands.
- Added `face-inference models fetch|verify|path` with a checksum-pinned asset manifest for YuNet, SFace, and onnxruntime-web browser assets.
- Added per-OS default data directories with `FACE_INFERENCE_HOME` override and legacy `./.face-inference` fallback.
- Added environment and CLI overrides for `FACE_INFERENCE_HOST`, `FACE_INFERENCE_PORT`, `FACE_INFERENCE_ALLOWED_ORIGINS`, `FACE_INFERENCE_RATE_LIMIT`, `FACE_INFERENCE_TRUSTED_PROXIES`, and `--host`/`--port`.
- Added CORS, preflight handling, and embeddable `frame-ancestors` policy for allowed origins.
- Added scoped short-lived session tokens via `POST /api/v1/session-tokens`, with per-route authorization and atomic secret migration.
- Added a token-bucket rate limiter with trusted-proxy client IP resolution.
- Added a typed stdlib Python client for the REST API.
- Added the `<face-capture>` web component package with npm packaging, export, and browser end-to-end coverage.
- Added an OpenAPI 3.1 specification with route and runtime contract coverage tests.
- Added a Docker image and compose configuration (not yet verified on a live daemon), plus deployment and air-gapped setup documentation.
- Added CI, release, and models-release workflows, along with maintainer release guidance.
- Added an opt-in, default-off cache of decrypted templates (`gallery_cache_enabled`), keyed by each template's ciphertext so deleted, expired or re-enrolled records are never served stale; plaintext stays in memory for at most 5 seconds. At 1,000 templates a recognition measured about 142 ms without it and about 44 ms with it. See `docs/guide.md` and `PRIVACY.md`.
- Added transactional SQLite schema migrations to v2 with indexes and deferred transactions for pure collection and event reads.
- Added an optional fail-closed PAD provider contract with no liveness model.
- Added documentation for architecture, troubleshooting, deployment, privacy, security, compliance templates, export safety, deletion, API versioning, browser performance, and performance baselines.
- Added ControlFace10K subset dataset attribution in `THIRD_PARTY_NOTICES.md`.

### Changed

- Model assets can now be downloaded and checksum-verified on first run (`face-inference models fetch`), with offline install from a local directory. The model and WASM binaries are still bundled in the wheel until the `models-v1` release is published.
- Changed Python client behavior to align response models and lighting parameters with the server contract.

### Security

- Validated asset manifest paths and rejected unsafe manifest entries before fetch or copy.
- Stopped forwarding `Authorization` headers across redirect origins in the Python client and refused `https` to `http` redirects.
- Bound recognition session tokens to their subject and returned HTTP 400 for over-deep JSON payloads.
- Enabled SQLite `secure_delete` and WAL checkpointing after purge and delete operations to reduce retained biometric data exposure.
- Ensured the face-capture component never imports code from `model-base-url`; that attribute only selects model and WASM asset URLs.
- The browser worker now verifies the SHA-256 of the YuNet and SFace model bytes against pins shipped with the package before creating inference sessions (SEC-8); a mismatch is fatal.
- Known limitations: the ONNX Runtime WASM binary and its script glue are not yet hash-verified when served from a configured asset origin. No liveness/PAD is provided, and the system is not intended for unattended security gates.

### Removed

- Removed the legacy PHP code, old tests and snapshots from the working tree. Git history was not rewritten; the previous state is available at tag `legacy-ccc-final`.

### Documentation

- Documented the server boundary, distribution evaluation, PAD integration decision, and security audit findings and status.
