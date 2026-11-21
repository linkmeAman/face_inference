# Contributing to face-inference

Thank you for contributing to `face-inference`! We welcome contributions that adhere to our code quality, privacy, and security principles.

---

## Code of Conduct

All contributors and maintainers are expected to follow our [Code of Conduct](CODE_OF_CONDUCT.md). Please report any unacceptable behavior by opening a private security advisory or contacting the maintainer via GitHub.

---

## Privacy & Biometrics Rule (Mandatory)

> [!CAUTION]
> **NEVER COMMIT REAL FACE IMAGES OR REAL BIOMETRIC DATA TO THIS REPOSITORY.**
>
> - Do **NOT** commit photos, webcam captures, or identifiable face images of real individuals.
> - The `datasets/` directory contains strictly synthetic or approved public test fixtures.
> - Do **NOT** commit private encryption keys, API tokens, passwords, or production database files (`faces.sqlite3`, `config.json`).
> - All unit and integration test fixtures must use synthetic vectors, mocked frames, or mock embeddings.

---

## Development Setup

The project uses Python 3.10+ (pyproject.toml with Hatchling) and Node.js 20+. We recommend using `uv` for fast, isolated Python dependency management.

### 1. Create a Virtual Environment

```bash
uv venv
source .venv/bin/activate
```

### 2. Install Dependencies in Editable Mode

Install the package in editable mode along with testing and linting tools:

```bash
uv pip install -e . pytest pytest-xdist ruff
```

### 3. Run Linter

Ensure the codebase conforms to linting and style rules:

```bash
ruff check src tests
```

### 4. Run Python Tests

Run the Python test suite:

```bash
pytest -q
```

### 5. Run JavaScript Tests

Run the core JavaScript and browser client tests using Node's built-in test runner:

```bash
node --test tests/js/*.test.mjs
```

### 6. Run Web Component Package Tests

For the `<face-capture>` custom element package:

```bash
cd packages/face-capture
npm run build
npm test
cd ../..
```

---

## Branch and Commit Conventions

### Branch Naming
- Features: `feature/<short-description>`
- Bugfixes: `fix/<short-description>`
- Migrations/Tasks: `mig/<task-id>` (e.g. `mig/h2`)

### Commit Messages
Write clear, concise, **real one-line commit messages** in the imperative mood describing what the commit accomplishes:
- Good: `Add token-bucket rate limiter and trusted-proxy client IP resolution`
- Good: `Add Caddy and Nginx deployment examples with TLS guide`
- Bad: `fix stuff`
- Bad: `WIP`
- Bad: `Update files`

---

## Development Principles

1. **Minimal Dependencies**: The Python client requires zero third-party dependencies (Python 3.10+ standard library). The server depends only on `cryptography`. Keep third-party dependencies strictly minimal.
2. **Never Shape Production Code for Tests**: Avoid test detection flags, test-only conditionals, `getattr`/`hasattr` on mocks in production paths, or swallowing broad exceptions. Fix test fakes instead.
3. **No Weakened Assertions**: Never edit, skip, or weaken an existing test assertion without explicit review authorization.
4. **Red-Green Testing**: Write focused, failing tests first, followed by the minimal code required to make them pass. Verify that tests fail for the expected reason before implementing the fix.
