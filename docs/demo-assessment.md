# Face capture demo: assessment

Review started against commit `34678b5e4e0bdc8d45ef30e7491effeff9d35767` on branch `refactor/v1`. The demo uses the existing standalone application; see [demo.md](demo.md).

## Initial assessment

The project is promising for local evaluation: it includes an in-browser inference pipeline, a working service and UI, consent gating, encrypted template storage, model integrity checks, and automated tests. These are source and documentation observations, not an independent certification.

### Priority gaps before real-world use

1. **Accuracy is not established for the intended population and cameras.** The [recorded synthetic evaluation](datasets.md) reports 18/30 known queries correctly identified, 12/30 unmarked, and 1/22 unknown queries accepted. These numbers are not a real-world accuracy estimate. Evaluate representative, consenting participants, devices, lighting and repeat visits before making decisions based on matches.
2. **No liveness or presentation-attack detection.** Photos and screen replays may be accepted; do not use this as an unattended security gate. See [README](../README.md#privacy--operational-limits) and [PAD decision](pad-integration-decision.md).
3. **Client trust and credential handling matter.** The service accepts client-submitted embeddings; it cannot establish that they came from a real camera. Operator tokens have broad privileges. Use short-lived scoped tokens for embedded clients, protect the host page, and review [server boundary](server-boundary.md) and [security audit](security-audit.md).
4. **Biometric operations need deliberate deployment.** Consent, retention, deletion, protected backups and encryption-key management must fit the actual application; see [privacy](../PRIVACY.md), [deletion](deletion.md) and [backup](backup.md). Encrypted templates are still sensitive biometric data.
5. **Distribution and deployment need verification.** The README describes local installation as the current path and marks Docker as not verified on a live daemon. Verify the specific deployment, browser and devices before relying on it.

## Verification log

- Model verification: **PASS**, six assets checked, using Python 3.12 and the existing environment with `PYTHONPATH=src`.
- Python suite: **673 passed**, 64 subtests passed, including synthetic-embedding HTTP enrollment and recognition. Run with loopback socket access.
- Browser JavaScript suite: **92 passed** after building the component; component suite: **42 passed** with a temporary npm cache. These are automated tests, not a live-camera trial.
- Local service smoke test: **PASS** for an isolated data directory, token, health endpoint, served UI, and collection creation using an unused loopback port and the Python client. The default port 6122 was already occupied on this machine.
- A fresh Python 3.12 virtual environment installed the package successfully; the installed CLI verified all six assets. An isolated server on port 6123 served the page, connected, displayed a newly created collection and person, and refused enrollment until consent was checked.
- **Not verified:** the guide's PowerShell commands, Docker, or physical-camera enrollment and recognition with a consenting person. Automated synthetic embeddings do not prove live inference accuracy. The first sandboxed Python run could not bind local sockets; the full suite passed when local socket access was allowed.

The standalone demo already existed; the new guide makes its isolated setup and cleanup explicit. The prior embedded example instructed the server and token commands to use different data directories and described session tokens as upcoming; those instructions were corrected. The first browser-suite run also exposed a test-order dependency on the component build; it passed after the build completed.
