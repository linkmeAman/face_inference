# Export verification — 2026-10-21

Performed on the staged copy using this server's existing Python 3.12 face
environment, Node and PHP. The CCC source checkout remains unchanged.

| Check | Actual result |
| --- | --- |
| Copied source/model/data hashes | PASS; all original/copied file hashes recorded, six explicitly sanitized credential-bearing files |
| Synthetic dataset | PASS; all 144 original images / 48 identities match the original per-image manifest |
| Browser ONNX prepared models | PASS; both hashes match the deployed browser asset manifest |
| Portable runtime | PASS; copied identity/PAD calibration path relocation, model hash verification and all three real model loads |
| Python service test suite | PASS; 132 tests, including temporary localhost HTTP contract tests |
| Browser JavaScript unit/contract tests | PASS; all 9 `*.test.mjs` test files (Node reports file-level counts) |
| Evaluator tests | PASS; 15 Python tests, including database-free actual PHP matcher parity probes |
| PHP authorization | PASS; 6 assertions |
| PHP policy loader | PASS; 10 assertions |
| PHP rollout administration | PASS; 72 assertions |
| Python syntax and PHP lint | PASS; exact file counts in `static-verification.json` |
| Production credential comparison | PASS; no source environment secrets or inline source database password found in exported text; fixture substitutions recorded |
| Isolated Python HTTP/model smoke | PASS; independent temporary loopback service, unauthenticated model-info 401, authenticated 200, real synthetic image face detection and normalized 128-dimensional SFace output |
| Fresh full browser image inference | NOT RUN successfully; cached Playwright Chromium is absent. Attempt log in `browser-inference.log`; original public evaluation results are preserved separately |
| Full CCC PHP/MySQL/UI integration | Not rerun by export; requires a separate host application and owned test database |
| Physical camera / live recognition accuracy / liveness | Not measured by this export |

The sandbox initially denied temporary HTTP sockets in eight Python test setups.
The unchanged suite was rerun with localhost sockets permitted and passed all
132 tests. The `FAIL:` messages near the end of `python-tests.log` are expected
negative fixtures for invalid model hashes/missing manifests; unittest ends `OK`.

`isolated-http-result.json` contains bounded smoke-test evidence without vectors,
raw images or token values. Its server was terminated after testing. Source port
6120, rollout files, databases and installed services were not changed.

The snapshot preserves historical calibration artifacts and documentation. Their
recorded approval/count claims were not independently re-established. Current
browser rollout remains SHADOW; its Testing report exception has expired and no
browser production calibration file is configured.

`export-manifest.json` gives source provenance and transformations.
`bundle-files.json` and root `SHA256SUMS` cover the assembled export, excluding
generated runtime state, virtual environments and caches.
