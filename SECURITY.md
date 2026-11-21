# Security Policy

## Supported Versions

| Version | Supported | Notes |
|---|---|---|
| `0.x` | :white_check_mark: | Latest release line |
| `< 0.x` | :x: | Legacy / unsupported |

## Reporting a Vulnerability

We take the security of this project seriously.

If you discover a security vulnerability, please do **not** disclose it in public issues, discussions, or pull requests.

Instead, please report it privately through GitHub:
1. Navigate to the repository's main page on GitHub.
2. Click on the **Security** tab.
3. Under "Reporting", click **Report a vulnerability** to submit an advisory report directly to the maintainers.

All reports will be acknowledged and reviewed promptly.

## Threat Model Summary

- **Presentation Attack / Liveness Limitation:**
  The system is **not** liveness- or presentation-attack-safe. Embedded models (YuNet detector and SFace recognizer) perform detection and embedding extraction only; they do not detect presentation attacks (such as printed photographs, video replays, screens, or 3D masks). The system configuration explicitly reflects `liveness_validated=False`. Unattended or high-risk authentication workflows must not rely solely on this toolkit without external anti-spoofing / liveness mechanisms.

- **Local-Bind Default:**
  The server default configuration binds strictly to localhost (`127.0.0.1`), preventing unintentional exposure on external network interfaces. Any public or remote access should be mediated by a hardened reverse proxy with TLS termination.

- **Token Authentication:**
  All state-mutating and identification API endpoints enforce token-based bearer authentication. API requests must provide a valid authentication token.

- **Template Encryption at Rest:**
  Biometric templates and capture observation vectors are encrypted at rest using symmetric authenticated encryption (Fernet / AES-128-CBC with HMAC-SHA256) before persistence in SQLite. Database file permissions are restricted to user-only read/write (`0600`).
