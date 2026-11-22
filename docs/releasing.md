# Maintainer Release Guide

This document outlines the release process for `face-inference`, including PyPI Trusted Publishing, npm package publishing with SLSA provenance, GitHub Releases, and standalone model asset releases.

---

## Architecture Overview

1. **Python Package (`face-inference`)**:
   - Built and distributed via [uv](https://docs.astral.sh/uv/) (`sdist` and `wheel`).
   - Published to PyPI via **Trusted Publishing** (OIDC). No long-lived API tokens or credentials are stored in GitHub repository secrets.
   - Pinned GitHub environment: `pypi`.
2. **Web Component Package (`face-capture`)**:
   - Built from `packages/face-capture`.
   - Published to npm registry with `--provenance --access public`.
   - Authenticated using `NODE_AUTH_TOKEN` stored in GitHub repository secret `NPM_TOKEN`.
   - Only published when `packages/face-capture/package.json` version equals the release tag version.
3. **GitHub Release**:
   - Creates a tagged release on GitHub.
   - Automatically extracts changelog notes for the release version from `CHANGELOG.md` (with `--generate-notes` fallback).
   - Attaches built Python distribution artifacts (`.whl` and `.tar.gz`).
4. **Model Assets Release (`models-release.yml`)**:
   - Model binaries (`sface.onnx`, `yunet.onnx`, `ort-wasm-simd-threaded.wasm`) total ~23 MB and are currently still bundled into the PyPI wheel until the `models-v1` release is published.
   - A dedicated workflow packages assets using `tools/package_assets.py` (which verifies `manifest.json` SHA-256 hashes and writes `SHA256SUMS`) and attaches them to a GitHub release tag (default `models-v1`).
   - `face-inference models fetch` downloads from:
     `https://github.com/linkmeAman/face_inference/releases/download/models-v1/`

---

## One-Time Setup

Before triggering the automated release workflow, perform the following one-time configuration steps.

### 1. Configure GitHub Environment (`pypi`)

PyPI Trusted Publishing requires a dedicated environment on GitHub:

1. In the GitHub repository, navigate to **Settings** -> **Environments** (`https://github.com/linkmeAman/face_inference/settings/environments`).
2. Click **New environment**.
3. Name the environment exactly `pypi`.
4. Click **Configure environment**.
5. *(Optional)* Add deployment branch rules (e.g. restrict to tags or `main`) and required reviewers if desired.

### 2. Configure PyPI Trusted Publisher

PyPI validates OIDC identity tokens minted by GitHub Actions during the workflow run:

1. Log into [pypi.org](https://pypi.org).
2. Go to **Account Settings** -> **Publishing** (`https://pypi.org/manage/account/publishing/`).
3. Under **Add a publisher**, choose **GitHub**:
   - **PyPI Project Name**: `face-inference` *(for a new project, create as a "Pending Publisher")*
   - **Owner**: `linkmeAman` (or your GitHub organization/username)
   - **Repository name**: `face_inference`
   - **Workflow name**: `release.yml`
   - **Environment name**: `pypi`
4. Click **Add**.

> [!NOTE]
> Do NOT create or add any PyPI API tokens in GitHub Secrets. The workflow uses `pypa/gh-action-pypi-publish` with `permissions: id-token: write` for cryptographic OIDC authentication.

### 3. Configure npm Access Token (`NPM_TOKEN`)

To publish `@linkmeAman/face-capture` or `face-capture` to the npm registry with provenance:

1. Log into [npmjs.com](https://www.npmjs.com).
2. Click your profile avatar -> **Access Tokens** (`https://www.npmjs.com/settings/tokens`).
3. Click **Generate New Token** -> Select **Granular Access Token** (or Classic Automation token).
4. Configure token:
   - **Token Name**: `face-capture-github-actions`
   - **Expiration**: As required by your security policy.
   - **Permissions**: **Read and write** for packages and scopes containing `face-capture`.
5. Click **Generate Token** and copy the generated secret string.
6. In the GitHub repository, navigate to **Settings** -> **Secrets and variables** -> **Actions** (`https://github.com/linkmeAman/face_inference/settings/secrets/actions`).
7. Under **Repository secrets**, click **New repository secret**.
   - **Name**: `NPM_TOKEN`
   - **Secret**: Paste the copied npm access token.
8. Click **Add secret**.

---

## Release Checklist

Follow these steps for every release:

### 1. Version Bump

Ensure all version declarations are aligned:

- **Python Version**: Edit `src/face_inference/__init__.py`:
  ```python
  __version__ = "X.Y.Z"
  ```
  *(Note: `pyproject.toml` uses dynamic versioning pointing to `src/face_inference/__init__.py`).*

- **Web Component Version**: If releasing an update to the web component, edit `packages/face-capture/package.json`:
  ```json
  "version": "X.Y.Z"
  ```
  *(If `packages/face-capture/package.json` version does not match the tag, the workflow skips npm publishing while continuing PyPI and GitHub releases).*

- **Changelog**: In `CHANGELOG.md`, move changes from `## [Unreleased]` into a new section:
  ```markdown
  ## [X.Y.Z] - YYYY-MM-DD

  ### Added
  - Feature description...

  ### Fixed
  - Fix description...
  ```

### 2. Local Verification

Run local linters, tests, and build checks before committing:

```bash
# 1. Lint and code format check
.venv/bin/ruff check src tests

# 2. Run Python test suite
.venv/bin/python -m pytest -q

# 3. Test Python wheel build
uv build

# 4. Run web component tests
cd packages/face-capture && npm test && cd ../..
```

Clean up temporary build files:
```bash
rm -rf dist/
```

### 3. Commit and Tag

Commit the version bump on a branch, merge to `main`, and create an annotated tag:

```bash
git checkout main
git pull origin main

# Create annotated release tag
git tag -a vX.Y.Z -m "Release vX.Y.Z"

# Push tag to trigger release workflow
git push origin vX.Y.Z
```

---

## What the Automated Release Workflow Does

When tag `v*.*.*` is pushed, `.github/workflows/release.yml` executes:

1. **`verify` Job**:
   - Checks out the repository.
   - Sets up `uv` (pinned major `astral-sh/setup-uv@v10`).
   - Runs `uv build`.
   - Asserts that the Git tag equals `face_inference.__version__`. Fails immediately on mismatch.
   - Checks whether `packages/face-capture/package.json` version matches the tag, setting output `npm_publish`.
   - Runs `ruff check` and `pytest`.
   - Uploads built distributions (`dist/`) as workflow artifacts.

2. **`pypi` Job**:
   - Depends on `verify`.
   - Runs in GitHub environment `pypi`.
   - Exchanges GitHub Actions OIDC token (`permissions: id-token: write`) for a temporary PyPI upload token.
   - Publishes wheel and sdist via `pypa/gh-action-pypi-publish@release/v1`.

3. **`npm` Job**:
   - Condition: Only runs if `needs.verify.outputs.npm_publish == 'true'`.
   - Sets up Node.js 22 (`actions/setup-node@v7`) configured for the npm registry.
   - Runs `npm test` in `packages/face-capture` (which builds the package via `pretest` before testing; no `npm ci` is needed as the package has no external dependencies).
   - Executes `npm publish --provenance --access public` using secret `NPM_TOKEN`.

4. **`github-release` Job**:
   - Depends on `verify` and `pypi`.
   - Downloads built distributions from `verify`.
   - Extracts release notes matching version `X.Y.Z` from `CHANGELOG.md`.
   - Creates GitHub Release via `gh release create` and attaches the `.whl` and `.tar.gz` packages.

---

## Publishing Model Assets (`models-release.yml`)

Model binaries are packaged and uploaded separately to conserve bandwidth and repository size.

### Source of Truth
- Packaging script: `tools/package_assets.py` (added in task B2; inspect `tools/package_assets.py` or `git show mig/b2:tools/package_assets.py`).
- Assets packaged: `sface.onnx`, `yunet.onnx`, `ort-wasm-simd-threaded.wasm`, `ort-wasm-simd-threaded.mjs`, `ort.wasm.min.mjs`, and `manifest.json`.
- Output: `dist-assets/*` and `dist-assets/SHA256SUMS`.
- Default tag: `models-v1`.

### Triggering via GitHub Web UI
1. Navigate to repository **Actions** tab (`https://github.com/linkmeAman/face_inference/actions`).
2. In the left sidebar, select **Models Release**.
3. Click the **Run workflow** dropdown.
4. Input the release tag (default: `models-v1`).
5. Click **Run workflow**.

### Triggering via GitHub CLI (`gh`)
```bash
gh workflow run models-release.yml -f tag=models-v1
```

### Verifying Published Model Assets
Once the workflow completes, test fetching model assets using the CLI:
```bash
FACE_INFERENCE_HOME=/tmp/test-models face-inference models fetch
FACE_INFERENCE_HOME=/tmp/test-models face-inference models verify
```

---

## Rollback & Emergency Procedures (Yank / Deprecate)

### 1. PyPI Release Yanking
PyPI does **not** allow deleting and re-uploading a file with the same version string. If a release contains a critical regression:

1. Log into [pypi.org](https://pypi.org).
2. Navigate to `https://pypi.org/project/face-inference/` -> **Manage**.
3. In the left navigation, click **Releases**.
4. Locate the affected version -> Click **Options** -> Select **Yank release**.
5. Enter a clear explanation for the yank (e.g., *"Critical regression in matching logic; please upgrade to vX.Y.Z+1"*).
6. Click **Yank release**.

*(Yanked releases remain available for explicitly pinned dependency locks, but `pip install face-inference` will skip yanked versions).*

### 2. npm Package Deprecation / Unpublishing
- **Deprecating (Recommended)**:
  ```bash
  npm deprecate face-capture@X.Y.Z "Critical bug discovered in video stream lifecycle. Please upgrade to X.Y.Z+1"
  ```
- **Unpublishing (Only within 72 hours of publication and if no dependents exist)**:
  ```bash
  npm unpublish face-capture@X.Y.Z --force
  ```

### 3. GitHub Release Rollback
To remove or hide a compromised GitHub release:
```bash
# Delete release asset or release metadata
gh release delete vX.Y.Z --yes

# Delete local and remote tag if required
git tag -d vX.Y.Z
git push origin :refs/tags/vX.Y.Z
```
