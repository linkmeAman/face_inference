# Web Component Embed Example

This directory demonstrates embedding the `<face-capture>` web component (from `packages/face-capture`) in a plain, framework-free HTML page (`index.html`).

---

## Overview

- **No framework required**: Built entirely with standard Custom Elements v1, Shadow DOM, and ES modules.
- **Client-side inference**: YuNet face detection and SFace feature extraction run locally in the browser via WebAssembly.
- **Dynamic configuration**: Interactive UI controls allow testing both `recognize` and `enroll` modes, custom collections, and local server endpoints.
- **Security Notice**:
  > [!WARNING]
  > **Dev only — use session tokens in production.**
  > The API token input in `index.html` accepts the full operator credential for local testing only. Never expose long-lived operator tokens in public client-side applications. Scoped, short-lived session tokens are available; see [the session-token guide](../../docs/guide.md).

---

## Running the Web Example

### 1. Build the web component package

Ensure the web component bundle and browser assets are synchronized:

```bash
cd packages/face-capture && npm run build && cd ../..
```

Output:
```
Building face-capture package...
Copied client.mjs -> dist/vendor-js/client.mjs
Copied 11 files from browser-face -> dist/vendor-js/browser-face/
Copied config.js -> dist/config.js
Generated dist/face-capture.js (ES module)
Build complete successfully.
```

### 2. Start the service with an isolated demo directory

From the repository root, install the Python package as described in the [local demo](../../docs/demo.md), then run the following in one terminal:

```bash
export FACE_INFERENCE_HOME="$(mktemp -d "${TMPDIR:-/tmp}/face-inference-demo.XXXXXX")"
printf 'Demo data directory: %s\n' "$FACE_INFERENCE_HOME"
export FACE_INFERENCE_ALLOWED_ORIGINS="http://127.0.0.1:8000"
.venv-demo/bin/face-inference token
.venv-demo/bin/face-inference serve
```

Keep the printed path and token private. The token and service must use the same `FACE_INFERENCE_HOME`.

### 3. Create a collection and a person

Open [http://127.0.0.1:6122](http://127.0.0.1:6122), connect with the token, and create a collection and person as described in the [demo guide](../../docs/demo.md#enroll-and-recognize).

### 4. Serve the example

From the repository root in another terminal:

```bash
python3 -m http.server 8000 --bind 127.0.0.1
```

Open [http://127.0.0.1:8000/examples/web/index.html](http://127.0.0.1:8000/examples/web/index.html). Paste the token, enter the collection ID, choose enroll mode and enter the person ID. Confirm consent in the component and start capture; then switch to recognize mode. This operator token is for local testing only. After testing, stop both servers and delete only the printed demo directory as described in the [cleanup guide](../../docs/demo.md#troubleshooting-and-cleanup).

---

## Element Attributes & Events Reference

### Attributes

| Attribute | Type | Default | Description |
|---|---|---|---|
| `api-url` | string | `http://127.0.0.1:6122` | Base URL of the face_inference server |
| `token` | string | `""` | Bearer token (operator token in dev; session token in prod) |
| `collection` | string | `"default"` | Target collection identifier |
| `mode` | string | `"recognize"` | `'recognize'` or `'enroll'` |
| `person-id` | string | `""` | Required when `mode="enroll"` |
| `autostart` | boolean | `false` | Automatically initialize camera stream on mount |

### Custom Events

- **`face-ready`**: Dispatched when camera stream is active and WASM workers are ready.
- **`face-result`**: Dispatched on server evaluation. Event payload (`event.detail`) contains result JSON (e.g. `state: "MATCHED"`, `person_id: "alice"`).
- **`face-error`**: Dispatched when errors occur (e.g. `CAMERA_DENIED`, `INVALID_TOKEN`).

---

## Framework Integration

- [React integration snippet (<25 lines)](react.md)
- [Vue 3 SFC snippet (<25 lines)](vue.md)
