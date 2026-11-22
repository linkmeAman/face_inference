# face-capture

`<face-capture>` is a vanilla JavaScript Web Component for browser-based face enrollment and recognition, powered by `face_inference`.

- **Pure Web Component**: Custom Elements v1 + Shadow DOM. No framework, no bundler, no compile step required.
- **Privacy by Default**: Sensitive API tokens are read directly from attributes/properties, never stored in `localStorage`, and never logged.
- **Client-Side Biometrics**: Face detection and feature extraction run locally in the browser via YuNet + SFace using WebAssembly.
- **Mandatory Consent**: Enrollment requires explicit user confirmation in the interface before any face template is recorded.
- **Accessible & Resilient**: Accessible names, ARIA live region status announcements, full keyboard navigation, safe i18n text customization, and pending-save retry recovery.

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
| — | `strings` | `FaceCaptureStrings` | Object mapping UI string keys to customized text labels. |
| — | `hasPendingSave` | `boolean` | Read-only. Returns `true` if an unresolved save request is pending recovery. |

### Methods

- `start(): Promise<void>`: Validates configuration, checks consent gating, and opens the camera. Blocks starting if an unresolved pending save exists (`PENDING_SAVE_UNRESOLVED`).
- `stop(): void`: Releases camera tracks, stops worker processing, and resets progress. Preserves any unresolved pending save.
- `retry(): Promise<FaceResultDetail>`: Retries an unresolved pending save using the original request body and idempotency key. Resumes capture on confirmed resolution and dispatches result or error events.
- `setStrings(customStrings: Partial<FaceCaptureStrings>): void`: Merges custom text strings for user-facing labels and messages.

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
- **`face-error`**: Fired when validation, camera access, network requests, or unresolved saves fail:
  ```json
  {
    "code": "CAMERA_DENIED",
    "message": "Camera access denied. Please allow camera permissions and retry."
  }
  ```

---

## Pending-Save Recovery & Retry

When network requests fail due to transient connection drops or server errors (e.g. 5xx status codes or timeouts), the underlying request is not discarded. `<face-capture>` retains the pending save state and its original idempotency key to prevent duplicate creations:

1. **Unresolved Guard**: While a request is unresolved, starting a new capture is blocked (`code: "PENDING_SAVE_UNRESOLVED"`) to prevent silent data loss or conflicting requests.
2. **Interactive & Programmatic Retry**: An interactive "Retry save" button becomes visible in the component controls, and consumers can call `await element.retry()` programmatically.
3. **In-Flight Deduplication**: Duplicate retry clicks or concurrent `retry()` calls reuse the same in-flight promise and disable the retry button while the request is in progress.
4. **Resolution & Capture Resume**: Upon successful resolution, the pending state clears, `face-result` is dispatched, and recognition capture resumes seamlessly via `resumeAfterRequest()`. In enrollment mode, capture completes after verified enrollment.

---

## Accessibility (a11y) & Keyboard Navigation

The component is structured for screen readers and keyboard users according to WCAG recommendations:

- **Landmark & Regions**: The host container declares `role="region"` and an accessible landmark name (`Face capture`).
- **Accessible Names**: All visual and control elements (`<video aria-label="Camera preview">`, `<canvas aria-hidden="true">`, `<progress aria-label="Capture progress">`, and action `<button>` elements) expose clear, accessible names.
- **Live Status Announcements**: The status bar uses `role="status"` and `aria-live="polite"` to announce capture guidance and progress updates, automatically elevating to `aria-live="assertive"` on error states.
- **Keyboard Navigation**: All interactive elements (consent checkbox, Start, Stop, and Retry buttons) support standard Tab navigation and keyboard activation via Space and Enter.

---

## Language Customization (i18n) & Safe Text Injection

`<face-capture>` provides a lightweight text customization mechanism for all user-facing strings without external dependencies or invented translations:

```javascript
const captureElement = document.querySelector('face-capture');

// Customize user-facing strings
captureElement.strings = {
  start: 'Commencer',
  stop: 'Arrêter',
  retry: 'Réessayer',
  ready: 'Prêt',
  consentLabel: 'J’autorise l’enregistrement du visage',
  resultMatched: 'Reconnu: {name}',
};
```

All custom strings are strictly assigned to DOM elements via `.textContent` and attribute setters (`setAttribute`), never using `innerHTML`. This guarantees that user-provided or localized text can never inject untrusted HTML or execute malicious scripts.

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

Real-browser end-to-end tests verify enrollment flows and prove that hostile model asset origins cannot execute untrusted code or bypass WASM integrity verification.

```bash
# Run unit tests (Node.js test runner)
npm test

# Run real-browser E2E tests (Playwright)
npm run test:e2e

# Build dist/ bundle and vendor sync
npm run build
```

---

## License

MIT
