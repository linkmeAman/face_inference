# Private face-inference spike

This service is a no-write inference component for `FACE-ML-01`. It binds to
loopback by default and exposes no public Nginx route. It receives only opaque
candidate references, encrypted/decrypted-in-memory embeddings for the active
scan, and bounded compressed frame payloads. It does not receive PHP cookies,
member names, membership data, or database credentials.

## Run locally

From this directory:

```sh
python3 -m app.main
```

## Testing host service

The deployable unit is `systemd/face-inference.service`. It runs the isolated
virtual environment as the dedicated `ccc-face-inference` system user, binds
through the private environment configuration, restarts after failure, and
uses resource limits so model inference is preferred for termination over
MySQL or Apache during system-wide memory pressure.

Install it as `/etc/systemd/system/face-inference.service`, then run
`systemctl daemon-reload` and `systemctl enable --now face-inference.service`.
Validate the live boundary with:

```sh
php tests/attendance/FACE-ENR-03-runtime-supervision.php
```

Rollback disables/removes only `face-inference.service` and reloads systemd;
it does not remove the isolated virtual environment, model files, revoked
templates, or application data.

Optional configuration is provided through environment variables:

- `FACE_INFERENCE_HOST` and `FACE_INFERENCE_PORT`;
- `FACE_INFERENCE_SHARED_SECRET` for the `X-Face-Inference-Token` header;
- `FACE_MODEL_DIR`, `FACE_YUNET_MODEL`, `FACE_SFACE_MODEL`, and
  `FACE_PAD_MODEL`;
- `FACE_MODEL_MANIFEST` for the verified `MODEL-MANIFEST.json`;
- `FACE_MODEL_VERSION`;
- `FACE_MAX_FRAMES`, `FACE_MAX_FRAME_BYTES`, `FACE_SCAN_TTL_SECONDS`;
- `FACE_CALIBRATION_FILE` points to the approved calibration JSON produced by
  `tools/calibrate.py`. The file must contain an approved license, matching
  model checksums, zero false accepts, and a passing P95 latency target.
  Thresholds are loaded only from that file; standalone threshold environment
  values cannot enable automatic acceptance. Until approval and held-out
  calibration succeed, the service stays in review-only mode.
- `FACE_PAD_CALIBRATION_FILE` points to the separately approved PAD policy
  produced by `tools/calibrate_pad.py`. Missing or invalid PAD calibration
  never permits automatic acceptance.
- `FACE_MAX_ATTEMPTS_PER_MINUTE`, `FACE_REPLAY_WINDOW_SECONDS`,
  `FACE_CHALLENGE_TTL_SECONDS`, and `FACE_CHALLENGE_HEAD_TURN_DELTA` bound
  replay attempts and the randomized head-turn challenge. Only bounded,
  in-memory SHA-256 batch digests are retained during the replay window.
- For this repository's Testing branch, use
  `calibration/calibration-approved.json`; the directory instructions and
  generated artifact files are kept out of Git.

The local feasibility bundle is recorded in `models/MODEL-MANIFEST.json` and
uses the exact YuNet and SFace files listed there. Copy
`face-inference.env.example` to the application's private configuration
directory and generate secrets outside Git. Raw score records may be JSON or
JSONL input to the calibration tool; its generated approved policy artifact is
the file referenced by `FACE_CALIBRATION_FILE`.

## Endpoints

- `GET /health`
- `GET /model-info` (private model/checksum/license metadata)
- `POST /internal/scans/{scan_id}/roster`
- `POST /internal/scans/{scan_id}/attempts`
- `DELETE /internal/scans/{scan_id}`
- `POST /internal/enrollments/encode`
- `POST /internal/reference/encode` (encodes a verified reference burst using FaceGuide selection, centered square `1.5x` PAD preprocessing, median consensus, and the sharpest valid SFace frame)
- `POST /v1/detect` (private testing evidence: boxes, five landmarks, quality)
- `POST /v1/infer` (private testing evidence: normalized embedding and opaque candidate scores)

Internal POST/DELETE requests use JSON and base64-encoded frame bytes. The
service validates the maximum of three frames and one megabyte per frame,
processes them in memory, and does not log request bodies. Scan embeddings are
removed on deletion or TTL expiry. Enrollment encoding accepts 7–10 bounded
frames, returns up to three selected embeddings plus quality and same-day
verification metadata, and retains no frames. Reference encoding accepts 1–3
bounded frames, evaluates PAD consensus with versioned centered-square preprocessing, and
extracts an embedding from the sharpest frame. The PHP application encrypts
returned embeddings before storage. This spike never calls the PHP attendance
domain service.

Both `/v1/` routes require the same private token as the internal routes. They
accept one base64 `image`; `/v1/infer` additionally accepts `candidates` with
opaque `candidate_ref` values and embeddings. They are evidence-only testing
routes and never write a database or attendance record. Images larger than
1280 pixels on either axis are reduced before detection, and model work is
serialized to keep CPU and memory behavior predictable.

Measure local HTTP latency with an approved non-sensitive test image:

```sh
set -a; . ../../.env; set +a
python3 tools/benchmark_local.py /path/to/test-image.jpg --endpoint detect
python3 tools/benchmark_local.py /path/to/test-image.jpg --endpoint infer
```

## Tests

```sh
/var/lib/creativeculturalcentre/face-inference/venv/bin/python -m unittest discover -s tests
python3 tests/test_contract.py
python3 -m compileall -q app tests
```

The contract tests use fake matcher results and do not require model weights or
biometric images.
