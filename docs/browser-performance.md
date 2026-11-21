# Browser Performance Measurement & Benchmarking Guide

## Overview

The `face_inference` application performs client-side face detection and recognition entirely within the user's browser using ONNX Runtime Web (WASM).
- Production automatic capture is initiated at [app.mjs:39](../src/face_inference/web/app.mjs).
- Browser worker configuration and model graph loading are managed at [worker.mjs:6](../src/face_inference/web/browser-face/worker.mjs).
- Model and binary assets are inventoried at [datasets.md:34](datasets.md) and in [manifest.json](../src/face_inference/web/vendor/browser-face/manifest.json).

To measure and monitor real-world client performance without compromising user privacy, this repository provides a dedicated, opt-in browser performance measurement harness under `tools/browser_benchmark/`.

## Architecture & Principles

1. **Local & Opt-In Only**:
   The measurement harness runs strictly on demand. It is isolated from production capture workflows and requires explicit operator consent before running. No benchmarks trigger automatically on application load.

2. **Zero Biometric & Identifier Collection**:
   The benchmark harness **never** collects, logs, or exports:
   - Facial image frames, bitmaps, or base64 data URLs.
   - Biometric embeddings, templates, or 128-dimensional vectors.
   - Authentication tokens, session credentials, or Bearer headers.
   - Unique device identifiers, camera device IDs (e.g. `deviceId`), or hardware serial numbers.
   All reports undergo automated sanitization via `redactBenchmarkReport` in `tools/browser_benchmark/metrics.mjs` before display or serialization.

3. **No Automatic External Upload**:
   Benchmark results are retained strictly in local browser memory. Results can be manually copied or downloaded as JSON by the consented operator. The harness contains no network beaconing, telemetry endpoints, or external upload logic.

4. **Honest Measurement Reporting**:
   When hardware acceleration, isolation headers, or specific performance APIs are unavailable (e.g. running headlessly in Node.js, on unisolated web origins, or on devices without WebGPU), the harness explicitly reports `not_measured`, `unavailable`, or the specific fallback status. It never fabricates simulated numbers as real measurements.

---

## Metrics & Measurement Methodology

### 1. Asset Transfer & Memory Footprint

Model and WASM assets are measured using standard browser `PerformanceResourceTiming` APIs (`performance.getEntriesByType('resource')`). The harness distinguishes between network transfer size and decoded memory size. Exact decoded byte sizes are defined in `src/face_inference/web/vendor/browser-face/manifest.json`:

| Asset | Role | Exact Decoded Body Size (`manifest.json`) | Transferred Size |
|---|---|---|---|
| `yunet.onnx` | Face detector graph | 100,416 B | measured by the harness at run time (varies with compression and caching) |
| `sface.onnx` | Feature recognizer graph | 10,660,286 B | measured by the harness at run time (varies with compression and caching) |
| `ort-wasm-simd-threaded.wasm` | ONNX Runtime WASM binary | 12,361,745 B | measured by the harness at run time (varies with compression and caching) |
| `ort.wasm.min.mjs` | ONNX Runtime loader | 49,997 B | measured by the harness at run time (varies with compression and caching) |
| `ort-wasm-simd-threaded.mjs` | Threading glue script | 24,274 B | measured by the harness at run time (varies with compression and caching) |
| **Total Bundle** | | **23,196,718 B** | measured by the harness at run time (varies with compression and caching) |

- **Cold Cache**: Initial page load or cleared browser cache where `transferSize > 0`.
- **Warm Cache**: Subsequent loads where assets are served from disk/memory cache (`transferSize === 0` and `decodedBodySize > 0`).
- **Timing-Allow-Origin (TAO)**: When assets are served cross-origin without TAO headers, browser security restrictions report `transferSize === 0`. The harness notes this state and relies on decoded sizes where available.

### 2. Startup Latency

- **Cold Startup Latency**: Wall-clock duration (`performance.now()`) required to fetch, compile WASM modules, and initialize initial `ort.InferenceSession` instances for both YuNet and SFace.
- **Warm Startup Latency**: Wall-clock duration required to create subsequent inference sessions when WASM binaries and model weights are already compiled and cached in browser memory.
- **Status**: Not measured on any device yet.

### 3. Inference Latency & Distribution

Inference latency is sampled across multiple iterations using high-resolution timestamps (`performance.now()`):
- **p50 (Median Latency)**: 50th percentile of frame inference duration.
- **p95 (Tail Latency)**: 95th percentile of frame inference duration, capturing garbage collection pauses or thread scheduling jitter.
- **Sample Count & Bounds**: Reports sample count, min, max, and mean alongside p50 and p95.
- **Status**: Not measured on any device yet.

Calculations follow standard linear interpolation across sorted samples to ensure deterministic verification in test fixtures.

---

## Hardware Acceleration, Threading & Fallback Assessment

### 1. WASM Multi-Threading & Isolation
- **Requirements**: Multi-threaded WASM execution in ONNX Runtime requires `SharedArrayBuffer`, which modern browsers restrict to cross-origin isolated contexts.
- **Isolation Headers**: Requires Cross-Origin-Opener-Policy (`COOP: same-origin`) and Cross-Origin-Embedder-Policy (`COEP: require-corp` or `credentialless`).
- **Production Setting**: In `src/face_inference/web/browser-face/worker.mjs:8`, the application specifies:
  ```javascript
  ort.env.wasm.numThreads = 1; // Works without SharedArrayBuffer/COOP/COEP, including Safari.
  ```
  This ensures universal portability across embedded web components, iframes, and mobile browsers without requiring isolation headers.
- **Harness Probe**: The benchmark checks `window.crossOriginIsolated` and `typeof SharedArrayBuffer !== 'undefined'`. If either is false, it records:
  ```json
  "wasmThreads": {
    "supported": false,
    "status": "fallback_single_thread",
    "threads": 1
  }
  ```

### 2. WebGPU Support
- Evaluated via `Boolean(navigator.gpu)`.
- If unavailable, the harness notes fallback to CPU WASM/SIMD execution (`"status": "fallback_wasm"`).
- WebGPU acceleration status: not measured on any device yet.

---

## Running the Benchmark

### Interactive Local Harness (Consented Operator)

1. Start the local `face_inference` server:
   ```bash
   .venv/bin/python -m face_inference run
   ```
2. Open `tools/browser_benchmark/index.html` in your target browser (e.g. `http://127.0.0.1:6122/tools/browser_benchmark/index.html` or served via any local HTTP server).
3. Review the privacy policy and check the operator consent box:
   > *"I am a consented operator and consent to running this local browser benchmark on this device."*
4. Configure the desired iteration count (default: 10).
5. Click **Run Benchmark**.
6. Inspect the live summary cards and copy or download the sanitized JSON report.

### Automated Test Suite

To verify percentile calculations, size calculations, privacy redaction, and fallback handling:
```bash
node --test tools/browser_benchmark/*.test.mjs
```

---

## Sample Sanitized Benchmark Report

> **Measurement Status**: Not measured on any device yet. The benchmark harness reports all capabilities, cache states, asset sizes, and latencies in a sanitized JSON schema. When a run has not yet been executed on a live browser or when APIs are unavailable, metrics report `null` values and `not_measured` status rather than fabricating numbers:

```json
{
  "benchmarkVersion": "1.0.0",
  "timestamp": "2026-11-22T00:00:00.000Z",
  "browser": {
    "name": "Unknown",
    "majorVersion": "Unknown"
  },
  "os": "Unknown",
  "deviceClass": "desktop",
  "platformCapabilities": {
    "crossOriginIsolated": false,
    "wasmThreads": {
      "supported": false,
      "status": "fallback_single_thread",
      "threads": 1
    },
    "webgpu": {
      "supported": false,
      "status": "fallback_wasm"
    }
  },
  "modelVersions": {
    "detector": "yunet640-similarity5-rgb255-v1",
    "recognizer": "sface-int8bq-fb143eea"
  },
  "cacheState": "not_measured",
  "sampleCount": 0,
  "latencies": {
    "coldStartupMs": null,
    "warmStartupMs": null,
    "inference": {
      "count": 0,
      "min": null,
      "max": null,
      "mean": null,
      "p50": null,
      "p95": null,
      "status": "not_measured"
    }
  },
  "sizes": {
    "totalTransferBytes": 0,
    "totalDecodedBytes": 23196718,
    "resources": {
      "yunet.onnx": {
        "transferBytes": 0,
        "decodedBytes": 100416
      },
      "sface.onnx": {
        "transferBytes": 0,
        "decodedBytes": 10660286
      },
      "ort-wasm-simd-threaded.wasm": {
        "transferBytes": 0,
        "decodedBytes": 12361745
      },
      "ort.wasm.min.mjs": {
        "transferBytes": 0,
        "decodedBytes": 49997
      },
      "ort-wasm-simd-threaded.mjs": {
        "transferBytes": 0,
        "decodedBytes": 24274
      }
    }
  }
}
```
