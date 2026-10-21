# Face Inference Operations & Runbook Guide

This document is the operational guide for running, configuring, and maintaining the face attendance inference runtime on the current server environment (**Nginx + PHP 8.3 FPM + systemd**).

---

## 1. Architecture & Component Overview

```
[ Browser Client ]
       │  (HTTPS: 443)
       ▼
[ Nginx Web Server ]
       │  (FastCGI Unix Socket)
       ▼
[ PHP 8.3-FPM Backend ] ──── (HTTP: 127.0.0.1:6120) ────► [ Face Inference Service ]
   - Controllers & Routes                                     - Python 3.12 Virtualenv
   - AttendanceDomainService                                  - YuNet/SFace + anti-spoof-mn3
   - MySQL Database                                           - Loopback-only daemon
```

1. **Private Face Inference Service**:
   - Systemd unit: `face-inference.service`
   - System user: `ccc-face-inference`
   - Virtualenv: `/var/lib/creativeculturalcentre/face-inference/venv`
   - Address: `127.0.0.1:6120` (loopback only, never exposed publicly)
   - Models directory: `/var/www/admin.creativeculturalcentre.in/services/face-inference/models/`
   - Environment file: `/var/www/admin.creativeculturalcentre.in/.env`

2. **Web Backend (PHP 8.3-FPM)**:
   - Web server: Nginx (`/etc/nginx/sites-available/admin.creativeculturalcentre.in`)
   - Runtime settings: `/var/www/admin.creativeculturalcentre.in/.env`, loaded by `FaceAttendanceConfig`
   - The included Nginx snippet intentionally contains no `FACE_*` parameters.

---

## 2. Configuration Files & Environment Variables

Face runtime variables have one operational source: `.env`.

### A. Python Service Configuration (`.env`)
- **File**: `/var/www/admin.creativeculturalcentre.in/.env`
- Loaded automatically by systemd upon service startup.
- Key settings:
  - `FACE_INFERENCE_HOST=127.0.0.1`
  - `FACE_INFERENCE_PORT=6120`
  - `FACE_INFERENCE_SHARED_SECRET=<secret>`
  - `FACE_YUNET_MODEL=<path-to-yunet-onnx>`
  - `FACE_SFACE_MODEL=<path-to-sface-onnx>`
  - `FACE_PAD_MODEL=<path-to-anti-spoof-mn3-onnx>`
  - `FACE_PAD_CALIBRATION_FILE=<approved-score-only-policy>` (leave unset
    until the representative spoof-fixture run is formally approved)
  - `FACE_EMBEDDING_KEY=<key>`
  - `FACE_EMBEDDING_KEY_ID=face-export-fixture-key-id`
  - `FACE_RECOGNITION_PROVIDER_ID=local-onnx`
  - `FACE_RECOGNITION_PROVIDER_VERSION=2`
  - `FACE_MODEL_VERSION_ID=2` (v3 remains `PENDING_CALIBRATION` until field approval)
  - `FACE_CALIBRATION_POLICY_VERSION=face-policy-v3`
  - `FACE_ACCEPT_THRESHOLD`, `FACE_MIN_MARGIN`, `FACE_ENROLLMENT_VERIFY_THRESHOLD`,
    `single_candidate_accept_threshold`, `template_support_threshold`, and
    `min_template_support` must come from a v3 artifact bound to
    `sface-opencv-aligncrop-rgb-raw-v2`. Environment values may raise an
    approved operating point; they do not make an uncalibrated model valid.
  - `FACE_ENROLLMENT_TEMPLATES=3` (legacy enrollment selection: two references
    from Burst A and one from Burst B). `guided-five-v1` uses its named protocol
    to store six actual embeddings: all five Burst A poses and the Burst B
    embedding nearest that burst's centroid. Recognition transports up to six
    independent templates while legacy records continue to use three.

Guided enrollment is advertised only when the Python matcher and PHP provider
both support its protocol. The browser uses the returned pose windows to guide
the user, while Python validates yaw and pitch from the submitted pixels. An
accepted pose logs its measured yaw and pitch without logging frames or
embeddings. The existing quality, reference, consistency, holdout, duplicate,
and recognition thresholds remain unchanged. Current tests use synthetic
evidence; use controlled real-person captures to review logged pose
measurements before changing any guidance windows. Such validation and a service
restart/deployment remain separate operational work. The B1/B2 commits are
present in the repository. As of 4 October 2026, the newer
`guided-adaptive-v2` protocol is enabled for the user-authorized device pilot
through `.env`, and the Python inference service has been
restarted. The checked-in flag default remains off. Real-device accuracy,
identity trials, and attendance compatibility are still release gates; see
`guided-adaptive-v2-pilot.md`.

Do not reactivate provider/model v1 or reuse its embeddings and calibration.
Those records use an incompatible SFace input space and are retired by the v3
migration. See `face-identity-matching-v3-resolution.md` before changing any
identity or PAD gate.

### B. PHP Runtime Environment (`.env`)
- **File**: `/var/www/admin.creativeculturalcentre.in/.env` (root-owned, group-readable only by `www-data`).
- [`classes/services/FaceAttendanceConfig.php`](file:///var/www/admin.creativeculturalcentre.in/classes/services/FaceAttendanceConfig.php) reads this file for every PHP face workflow.
- `.env` values override legacy database feature flags. The database remains a fallback for legacy administration screens.
- `/etc/nginx/snippets/face-attendance-params.conf` stays included for compatibility but must contain no `FACE_*` parameters.
- The experiment writer DSN is deliberately not in `.env`; it is root-only in `/etc/ccc/face-experiment/writer.env` and loaded only by the inference services.

### C. Database-Authoritative Feature Configuration (`face_attendance_config` & `attendance_settings`)
- **Fallback Source**: MySQL database tables. Explicit `.env` values take precedence.
- **Tables**:
  - `face_attendance_config`: Global singleton row (`id = 1`) applied across databases (`ccc_database`, `ccc_database_test`, `ccc_central`).
  - `attendance_settings`: Per-branch overrides (`bid = ?`).
- **Access Service**: [`classes/services/FaceAttendanceConfig.php`](file:///var/www/admin.creativeculturalcentre.in/classes/services/FaceAttendanceConfig.php) (`get()`, `getFlag()`, `set()`).
- **Web Admin Console**: [`/pf/face-pilot-config.php`](file:///var/www/admin.creativeculturalcentre.in/pf/face-pilot-config.php) writes only the legacy database fallback. It does not edit Nginx and cannot override an explicit `.env` value.

---

## 3. Supervised Pilot Mode

The supported production-assisted mode is teacher review only:

In `/var/www/admin.creativeculturalcentre.in/.env`:
```dotenv
FACE_ATTENDANCE_ENABLED=1
FACE_ENROLLMENT_ENABLED=1
FACE_ATTENDANCE_SHADOW_MODE=1
FACE_ATTENDANCE_LIVE_WRITE=0
FACE_TEACHER_SELECTION_ENABLED=0
FACE_CAPTURE_NONCE_REQUIRED=1
FACE_PUNCH_OUT_ENABLED=0
FACE_ALLOWED_BRANCH_IDS=1,2,4,5
FACE_ATTENDANCE_REQUIRE_HTTPS=1
FACE_ATTENDANCE_ALLOWED_ORIGINS=https://admin.creativeculturalcentre.in
```

Recognition has no automatic attendance writer, regardless of legacy flag
values. An accepted identity can return only `SELECT_PRESENT`; the teacher
reviews the register and uses the existing Punch Attendance action. Do not
document or deploy a live-write toggle. Immediate punch-in and punch-out need
separate future decisions after their gates are approved.

PAD must be presented as `DEFERRED`. Identity calibration may support the
reversible row-selection effect; it does not imply validated liveness or
authorize an attendance/membership transaction.

---

## 4. Operating Commands & Procedures

### Supervised v1 deployment order

1. Put the face feature behind its branch allowlist and keep manual attendance
   available.
2. Back up the face schema and verify the base
   `20260911_02_face_attendance_tables.sql` migration is present.
3. For the current-server test activation, run the exact-name-guarded installer
   in dry-run mode first. It targets only `ccc_database_test` and
   `ccc_central_test`; it validates provider, model, calibration, manifest,
   parent-table, registry, and checksum prerequisites before any DDL:

   ```bash
   set -a; . /var/www/admin.creativeculturalcentre.in/.env; set +a
   php tools/setup_face_test_databases.php
   ```

   Apply only after explicit test-database approval:

   ```bash
   php tools/setup_face_test_databases.php --apply
   ```

   For production, use the reviewed additive migrations through the normal
   deployment process; do not point the test installer at production names.
   Confirm the active `face_model_version` row matches provider ID/version,
   registry model name/ID, embedding dimension, and calibration policy.
4. Install the reviewed Nginx FastCGI parameters, run `nginx -t`, reload Nginx,
   and restart PHP-FPM.
5. Restart `face-inference.service` so the versioned `identity_decision` field
   is available.
6. Run the read-only readiness verifier in required mode, then perform a real
   camera smoke test that confirms row selection while attendance and
   membership counters remain unchanged until Punch Attendance.

```bash
FACE_SETUP_REQUIRED=1 php tests/attendance/FACE-SETUP-01-enrollment-readiness.php --required
```

As verified on 2026-09-22, the approved additive setup created the seven
persistent face tables and active model registry row `1` in
`ccc_database_test`; `ccc_central_test` was used for guarded connectivity
validation. The required read-only preflight now passes schema, transport,
inference readiness, identity calibration, provider/model configuration,
PAD-deferred status, and the supervised no-write boundary.
The private inference service was restarted at 06:54 IST and is healthy. The
the `.env` change has been validated and followed by PHP-FPM reload and
face-inference restart.

Rollback disables the face feature and restores the prior Nginx snippet and
service revision. The additive provider/scan columns should be retained; do
not drop them or delete enrollment/audit data during rollback.

### When editing Python files (`app/*.py`) or `.env`:
Any `.env` change requires reloading PHP-FPM and restarting the inference service:
```bash
sudo systemctl reload php8.3-fpm
sudo systemctl restart face-inference.service
```

### When editing Nginx:
Face settings do not require an Nginx reload. Reload Nginx only after an
actual Nginx configuration change:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Checking Service Status:
```bash
systemctl status face-inference.service
```

### Checking Live Inference Logs:
```bash
journalctl -u face-inference.service -n 50 -f
```

---

## 5. Bounded Concurrency Limit & Overload Protection

To prevent model execution from overwhelming host CPU and memory on the shared server, inference is strictly bounded:

- **Maximum Concurrency**: **4 slots** (enforced by `BoundedSemaphore(4)` in `LimitedThreadingHTTPServer`).
- **Connection Management**:
  - `FaceInferenceClient.php` sends `Connection: close` on all cURL requests.
  - `main.py` explicitly sends `Connection: close` and sets `self.close_connection = True`.
  - This prevents idle PHP-FPM sockets from consuming semaphore slots across keep-alive intervals.
- **Micro-Burst Queueing & Overload Handling**:
  - `LimitedThreadingHTTPServer::process_request` uses `acquire(blocking=True, timeout=0.5)` to absorb momentary 50–200ms bursts while preceding frames process.
  - If all 4 execution slots remain continuously occupied past the 500ms grace period, the request receives an **HTTP 503 Service Unavailable** response with header `Retry-After: 1` and payload:
    ```json
    {
      "error": "CONCURRENCY_LIMIT_EXCEEDED",
      "message": "Inference service concurrency limit reached (maximum 4 concurrent requests), please retry"
    }
    ```
  - The server **does not** sever the TCP connection abruptly; it gracefully informs the caller with HTTP 503.
- **Client Resilience & Flow Control**:
  - `FaceInferenceClient.php` detects HTTP 503 and throws `FaceInferenceOverloadedException`.
  - `FaceAttendanceApiService.php` catches this and returns status `INFERENCE_BUSY` with retryable intent.
  - `batchAttendanceController.js` handles `INFERENCE_BUSY` automatically with a non-blocking 800ms backoff retry without halting the scan machine or popping up modal dialogues.
  - `pollFaceModelPreview()` pauses while `faceMachine.state === 'CAPTURING'` so preview detection does not contend with multi-frame recognition.

---

## 6. Probes & Health Monitoring

The service exposes three distinct HTTP GET endpoints on loopback `127.0.0.1:6120`:

### A. Liveness Probe (`/livez`)
Checks if the Python process is alive and its event loop responsive.
```bash
curl -s http://127.0.0.1:6120/livez
```
Response:
```json
{"status": "alive", "uptime_seconds": 125.4}
```

### B. Readiness Probe (`/readyz`)
Checks if ONNX models are loaded into memory and integrity is verified. Returns HTTP 200 when ready, HTTP 503 when unready.
```bash
curl -s http://127.0.0.1:6120/readyz
```
Response:
```json
{
  "status": "ready",
  "model_ready": true,
  "model_integrity_verified": true,
  "model_error": null,
  "model_version": "opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3"
}
```

### C. Diagnostic & Telemetry (`/health`)
Provides full system telemetry, including memory RSS and concurrency status:
```bash
curl -s http://127.0.0.1:6120/health
```
Response:
```json
{
  "status": "ok",
  "model": {
    "model_ready": true,
    "model_version": "opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3",
    "pad_model_ready": true,
    "pad_calibration_approved": false
  },
  "model_integrity_verified": true,
  "model_error": null,
  "threshold_configured": true,
  "calibration_approved": true,
  "calibration_error": null,
  "pad_calibration_approved": false,
  "pad_calibration_error": "PAD_CALIBRATION_MISSING",
  "model_version_id": 1,
  "auth_enabled": true,
  "active_scans": 0,
  "uptime_seconds": 125.4,
  "memory": {
    "rss_mb": 144.3,
    "max_rss_mb": 144.3
  },
  "concurrency": {
    "active_slots": 1,
    "max_slots": 4
  }
}
```

---

## 7. Verification, Profiling & Monitoring Tools

### PAD calibration (`calibrate_pad.py`)

The runtime is deliberately review-only until the representative score-only
attack-fixture set has been collected and signed off. Generate the artifact
only after the required coverage is complete:

```bash
/var/lib/creativeculturalcentre/face-inference/venv/bin/python services/face-inference/tools/calibrate_pad.py approval-manifest.json pad-scores.json pad-calibration-approved.json
```

The tool rejects missing fixtures/slices, live/attack overlap, attack misses,
and latency failures. Do not place raw frames or embeddings in its input.

### A. Pre-Flight Verification (`verify_runtime.py`)
Run before daemon startup (`ExecStartPre`) or after updates:
```bash
/var/lib/creativeculturalcentre/face-inference/venv/bin/python services/face-inference/tools/verify_runtime.py
```
Validates Python version, `requirements.lock` dependencies, model directory permissions, and model file SHA-256 hashes against `MODEL-MANIFEST.json`.

### B. Capacity & Stress Profiler (`profile_capacity.py`)
Measures cold-start, P50/P95 latency under sequential load, memory RSS headroom, and burst concurrency rejection:
```bash
/var/lib/creativeculturalcentre/face-inference/venv/bin/python services/face-inference/tools/profile_capacity.py --iterations 50
```

### C. Host Watchdog Monitor (`health_monitor.sh`)
The installed systemd timer runs the read-only watchdog every five minutes as
`ccc-face-inference`. It fails when the service or probes are unavailable, the
health payload is invalid, or RSS reaches 400 MB. It records results in
journald and does not restart inference automatically.

```bash
services/face-inference/tools/health_monitor.sh

systemctl status face-inference-health-monitor.timer
systemctl list-timers face-inference-health-monitor.timer --all
journalctl -u face-inference-health-monitor.service -n 50 --no-pager
```

Install or refresh the repository-managed units:

```bash
sudo install -m 0644 services/face-inference/systemd/face-inference-health-monitor.service /etc/systemd/system/face-inference-health-monitor.service
sudo install -m 0644 services/face-inference/systemd/face-inference-health-monitor.timer /etc/systemd/system/face-inference-health-monitor.timer
sudo systemctl daemon-reload
sudo systemctl enable --now face-inference-health-monitor.timer
sudo systemctl start face-inference-health-monitor.service
```

Rollback removes only the monitor schedule; it does not stop inference:

```bash
sudo systemctl disable --now face-inference-health-monitor.timer
sudo rm /etc/systemd/system/face-inference-health-monitor.service /etc/systemd/system/face-inference-health-monitor.timer
sudo systemctl daemon-reload
```

---

## 8. Running the Test Suite

```bash
# Verify client resilience, probes, and error mapping
php tests/attendance/FACE-INFRA-01-resilience.php

# Verify service availability
php tests/attendance/FACE-ML-03-service-availability.php

# Verify PHP 8 embedding null-safety
php tests/attendance/FACE-API-08-empty-embeddings-php8.php

# Run the disposable migration, attendance idempotency, scan, and enrollment suite
tests/face-test-env/run.sh test

# Verify the supervised provider/API boundary
php tests/attendance/FACE-API-09-supervised-provider-contract.php

# Verify the generated web/Android/iOS OpenAPI contract
php tests/attendance/FACE-API-10-openapi-contract.php

# Verify exact-name guards and pre-DDL safety for persistent test setup
php tests/attendance/FACE-TEST-DB-02-setup-contract.php

# Run all Python tests (probes, concurrency, contracts)
PYTHONPATH=services/face-inference /var/lib/creativeculturalcentre/face-inference/venv/bin/python -m unittest discover -s services/face-inference/tests
```
