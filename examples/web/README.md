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
  > The API token input in `index.html` accepts the full operator credential for local testing only. Never expose long-lived operator tokens in public client-side applications. Scoped, short-lived session-token minting lands in upcoming task **A3**.

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

### 2. Start the local `face_inference` server

In a terminal, start a temporary local server instance:

```bash
PYTHONPATH=src FACE_INFERENCE_HOME=$(mktemp -d) python3 -m face_inference
```

Output:
```
Face service: http://127.0.0.1:6122
Use python -m face_inference token in your terminal to get the private API token.
```

### 3. Retrieve the API token

In a separate terminal, fetch the generated token:

```bash
PYTHONPATH=src python3 -m face_inference token
```

### 4. Serve the examples directory

Web camera access (`navigator.mediaDevices.getUserMedia`) and ES module imports require serving the page over HTTP/HTTPS rather than `file://`:

```bash
python3 -m http.server 8000
```

Open your browser to:
[http://127.0.0.1:8000/examples/web/index.html](http://127.0.0.1:8000/examples/web/index.html)

Paste the API token into the **API Token** input, select your mode, and click **Apply & Start Camera**.

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
