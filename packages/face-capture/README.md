# face-capture

`<face-capture>` is a vanilla JavaScript Web Component for browser-based face enrollment and recognition, powered by `face_inference`.

- **Pure Web Component**: Custom Elements v1 + Shadow DOM. No framework, no bundler, no compile step required.
- **Privacy by Default**: Sensitive API tokens are read directly from attributes/properties, never stored in `localStorage`, and never logged.
- **Client-Side Biometrics**: Face detection and feature extraction run locally in the browser via YuNet + SFace using WebAssembly.
- **Mandatory Consent**: Enrollment requires explicit user confirmation in the interface before any face template is recorded.

---

## 6-Line Embed

```html
<script type="module" src="node_modules/face-capture/dist/face-capture.js"></script>

<face-capture
  api-url="http://127.0.0.1:6122"
  token="YOUR_PRIVATE_TOKEN"
  collection="default"
  mode="recognize"
  autostart>
</face-capture>
```

---

## Element API

### Attributes & Properties

| Attribute | Property | Type | Description |
|---|---|---|---|
| `api-url` | `apiUrl` | `string` | Base URL of the `face_inference` server (e.g. `http://127.0.0.1:6122`). Required. |
| `token` | `token` | `string` | Private Bearer API token for authentication. Never logged or stored. Required. |
| `collection` | `collection` | `string` | ID of the collection to match against or enroll into. Required. |
| `mode` | `mode` | `'recognize' \| 'enroll'` | Operation mode. Defaults to `'recognize'`. |
| `person-id` | `personId` | `string` | Person ID to enroll into collection. Required when `mode="enroll"`. |
| `model-base-url` | `modelBaseUrl` | `string` | Base URL for YuNet/SFace ONNX models and WASM assets. Defaults to `<api-url>/`. Executable code is always resolved locally from the package and never imported from attribute-controlled origins. |
| `autostart` | `autostart` | `boolean` | Automatically starts camera stream when attached. Defaults to `false`. |

### Methods

- `start(): Promise<void>`: Validates configuration, checks consent gating, and opens the camera.
- `stop(): void`: Releases camera tracks, stops worker processing, and resets progress.

### Events

All events are bubbling and composed (`bubbles: true`, `composed: true`):

- **`face-ready`**: Fired when camera access is granted and WASM inference sessions are ready.
- **`face-result`**: Fired when the server returns an evaluation decision. Event detail contains the server JSON response:
  ```json
  {
    "state": "MATCHED",
    "person_id": "alice",
    "person_name": "Alice Smith"
  }
  ```
- **`face-error`**: Fired when validation, camera access, or network requests fail:
  ```json
  {
    "code": "CAMERA_DENIED",
    "message": "Camera access denied. Please allow camera permissions and retry."
  }
  ```

---

## Consent Requirement

When `mode="enroll"`, the component automatically renders an accessible consent confirmation checkbox. The component strictly refuses to initiate capture until consent is explicitly checked.

---

## Security & Code Integrity

`<face-capture>` strictly separates trusted executable logic from model/WASM data assets:
- **Shipped Code Integrity**: All executable JavaScript modules (including element implementation, client, Web Worker, and ONNX Runtime Web scripts) are shipped directly in the package and resolved locally. The element never imports executable scripts from `model-base-url`, `api-url`, or any attribute-controlled origin.
- **Configurable Asset Origin**: Heavy model weights (`yunet.onnx`, `sface.onnx`) and WebAssembly binaries (`ort-wasm-simd-threaded.wasm`) are loaded on-demand from the configured `model-base-url` (or the `face_inference` server), keeping the npm package lightweight (~100 KB) while supporting separate CDN hosting for binary assets.

---

## Camera Fingerprinting & Compatibility

`<face-capture>` and the `face_inference` web application share a single camera fingerprint builder (exported via `client.mjs`).

- **Consistent Payload & Key Order**: The fingerprint is computed as a SHA-256 digest of `{ deviceId, width, height, facing }` (`deviceId: settings.deviceId || ''`, `width: settings.width`, `height: settings.height`, `facing: settings.facingMode || ''`) and formatted as `camera-<hex24>`.
- **Unavailable Digest Fallback**: If Web Crypto digest is unavailable (e.g. non-secure contexts) or digest computation throws, the fingerprint deliberately falls back to `'camera-default'`. This failure behavior is preserved intentionally rather than silently broadening to dynamic or unconstrained alternatives.
- **Historical Evidence Compatibility**: Early revisions of the standalone web application hashed a payload with the property key `device` rather than `deviceId`. Existing capture records and metadata stored in database evidence retain their historical labels and must not be rewritten. Future web application labels use `deviceId` (aligning with `<face-capture>`), which produces distinct hashes that cannot be correlated automatically with legacy app hashes.

---

## Building and Testing

```bash
# Run unit tests (Node.js test runner)
npm test

# Build dist/ bundle and vendor sync
npm run build
```

---

## License

MIT
