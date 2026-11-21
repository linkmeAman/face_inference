# Supply Chain Security & Audit Policy

## Overview
This document specifies the automated dependency auditing, provenance verification, and supply-chain security controls implemented across the `face_inference` repository.

## 1. Scope & Dependency Surfaces
The supply-chain auditing architecture covers two distinct dependency surfaces:

### Python Surface
- **Production dependencies**: Declared in `pyproject.toml` under `[project.dependencies]` (currently `cryptography>=42`).
- **Development dependencies**: Declared in `pyproject.toml` under `[project.optional-dependencies.dev]` (`hatchling`, `pytest`, `pytest-cov`, `pytest-xdist`, `pyyaml`, `ruff`).
- **Binary and model assets**: Pretrained neural models (`sface.onnx`, `yunet.onnx`) and ONNX Runtime WASM binaries are not distributed as raw Python wheels; they are fetched with SHA-256 integrity verification (`tools/package_assets.py` and `face_inference.assets`).

### npm Surface
- **Root tooling dependencies**: Declared in root `package.json` with lockfile `package-lock.json` (`onnxruntime-web` runtime, `@playwright/test` dev dependency).
- **Web component package**: Declared in `packages/face-capture/package.json`. The embeddable component relies on browser standard APIs and local bundled assets, with zero third-party production runtime package dependencies.

## 2. Pinned Tool Versions & Verification
Automated audits run in GitHub Actions (`.github/workflows/supply-chain.yml`) with least-privilege permissions (`contents: read`) and no access to deployment secrets or publishing credentials.

- **Python Auditor**: `pip-audit` pinned to version `2.10.1`, querying the PyPI vulnerability database and Google Open Source Vulnerabilities (OSV) service.
- **Node/npm Auditor**: Native `npm audit --audit-level=high` against the npm registry security advisory database.
- **GitHub Actions**: All GitHub Action dependencies are pinned to verified upstream major releases:
  - `actions/checkout@v7` (verified v7.0.1)
  - `astral-sh/setup-uv@v10` (verified v10.4.0)
  - `actions/setup-node@v7` (verified v7.1.0)
  - `actions/setup-python@v7` (verified v7.1.0)
  - `actions/upload-artifact@v7` (verified v7.0.2)
  - `actions/download-artifact@v8` (verified v8.0.2)
  - `pypa/gh-action-pypi-publish@release/v1` (verified v1.14.3)

## 3. Invocation

### Automated CI
The supply-chain workflow executes on:
- Every push to the `main` branch.
- Every pull request targeting `main`.
- A weekly scheduled cron (`0 6 * * 1` - Mondays at 06:00 UTC).
- Manual triggering via GitHub Actions `workflow_dispatch`.

### Local Invocation
To run the supply-chain audit locally:

```bash
# Audit Python production dependencies
uv run --with pip-audit==2.10.1 pip-audit --skip-editable

# Audit Python development dependencies
uv run --extra dev --with pip-audit==2.10.1 pip-audit --skip-editable

# Audit root npm dependencies
npm audit --audit-level=high

# Audit face-capture package (if package-lock.json is present)
if [ -f packages/face-capture/package-lock.json ]; then
  npm --prefix packages/face-capture audit --audit-level=high
fi
```

## 4. Actionable Failure Remediation
When a vulnerability is detected:
1. **Advisory Identification**: The audit output identifies the package name, installed version, CVE/GHSA advisory identifier, and severity rating.
2. **Impact Assessment**: Determine whether the affected code path is reachable in production runtime environments or isolated to developer tooling.
3. **Resolution Steps**:
   - For Python: Update version constraints in `pyproject.toml`, run `uv lock` (or `uv pip compile`), and verify tests with `pytest`.
   - For npm: Run `npm update <package>` or `npm audit fix`, verify lockfile updates in `package-lock.json`, and run `npm test`.

## 5. Limitations
- **Zero-Day Vulnerabilities**: Scanners detect known vulnerabilities cataloged in public vulnerability databases (OSV, PyPI, GitHub Advisory Database). Zero-day or uncataloged vulnerabilities are outside the scanner's detection scope.
- **Non-Packaged Assets**: Pre-trained model weights (`.onnx`) and web assembly files (`.wasm`) are verified via cryptographic SHA-256 digest manifests rather than package index vulnerability databases.
- **Dynamic Imports**: Vulnerability scanning inspects declared dependency trees; dynamic network fetches during runtime are governed by Content Security Policy (CSP) and asset integrity checks rather than package audit tooling.
