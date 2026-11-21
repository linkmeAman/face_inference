import {
  parseConfig,
  validateConfig,
  checkConsentGating,
  mapCameraError,
} from './config.js';
import { FaceClient } from '../dist/vendor-js/client.mjs';
import { AutomaticCapture } from '../dist/vendor-js/browser-face/capture.mjs';

const template = `
<style>
  :host {
    display: block;
    position: relative;
    box-sizing: border-box;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    max-width: 100%;
    width: 640px;
    margin: 0 auto;
  }
  .container {
    display: flex;
    flex-direction: column;
    align-items: center;
    background: #111827;
    color: #f3f4f6;
    border-radius: 8px;
    overflow: hidden;
    padding: 16px;
    box-sizing: border-box;
    gap: 12px;
  }
  .video-stage {
    position: relative;
    width: 100%;
    aspect-ratio: 4 / 3;
    max-height: 480px;
    background: #000;
    border-radius: 6px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  canvas {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
  }
  .status-bar {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .status-text {
    font-size: 14px;
    min-height: 20px;
    text-align: center;
    color: #9ca3af;
  }
  progress {
    width: 100%;
    height: 6px;
    border-radius: 3px;
    border: none;
    accent-color: #3b82f6;
    background-color: #374151;
  }
  progress::-webkit-progress-bar {
    background-color: #374151;
    border-radius: 3px;
  }
  progress::-webkit-progress-value {
    background-color: #3b82f6;
    border-radius: 3px;
  }
  .consent-panel {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #1f2937;
    border-radius: 6px;
    font-size: 13px;
    box-sizing: border-box;
  }
  .consent-panel[hidden] {
    display: none !important;
  }
  .consent-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    user-select: none;
  }
  .controls {
    display: flex;
    gap: 8px;
    width: 100%;
    justify-content: center;
  }
  button {
    font-family: inherit;
    font-size: 14px;
    font-weight: 500;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    transition: background-color 0.2s;
  }
  .btn-start {
    background: #2563eb;
    color: #fff;
  }
  .btn-start:hover:not(:disabled) {
    background: #1d4ed8;
  }
  .btn-stop {
    background: #4b5563;
    color: #fff;
  }
  .btn-stop:hover:not(:disabled) {
    background: #374151;
  }
  button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  .error-text {
    color: #ef4444 !important;
  }
</style>
<div class="container">
  <div class="video-stage">
    <video playsinline muted></video>
    <canvas></canvas>
  </div>
  <div class="status-bar">
    <div class="status-text">Ready</div>
    <progress max="1" value="0"></progress>
  </div>
  <div class="consent-panel" hidden>
    <label class="consent-label">
      <input type="checkbox" class="consent-checkbox">
      <span>Confirm consent for face enrollment and template creation</span>
    </label>
  </div>
  <div class="controls">
    <button type="button" class="btn-start">Start</button>
    <button type="button" class="btn-stop" disabled>Stop</button>
  </div>
</div>
`;

const ElementBase = typeof HTMLElement !== 'undefined' ? HTMLElement : class {};

export class FaceCaptureElement extends ElementBase {
  static get observedAttributes() {
    return [
      'api-url',
      'token',
      'collection',
      'mode',
      'person-id',
      'model-base-url',
      'autostart',
    ];
  }

  constructor() {
    super();
    this._token = '';
    this._generation = 0;
    this._capture = null;
    this._client = null;
    this._apiUrl = '';
    this._collection = '';
    this._mode = 'recognize';
    this._personId = '';
    this._modelBaseUrl = '';
    this._autostart = false;
  }

  // Properties mirroring attributes
  get apiUrl() {
    return (typeof this.getAttribute === 'function' ? this.getAttribute('api-url') : this._apiUrl) || '';
  }
  set apiUrl(val) {
    this._apiUrl = val;
    if (typeof this.setAttribute === 'function') this.setAttribute('api-url', val);
  }

  get token() {
    return this._token !== undefined && this._token !== ''
      ? this._token
      : ((typeof this.getAttribute === 'function' ? this.getAttribute('token') : '') || '');
  }
  set token(val) {
    this._token = val || '';
  }

  get collection() {
    return (typeof this.getAttribute === 'function' ? this.getAttribute('collection') : this._collection) || '';
  }
  set collection(val) {
    this._collection = val;
    if (typeof this.setAttribute === 'function') this.setAttribute('collection', val);
  }

  get mode() {
    return (typeof this.getAttribute === 'function' ? this.getAttribute('mode') : this._mode) || 'recognize';
  }
  set mode(val) {
    this._mode = val;
    if (typeof this.setAttribute === 'function') this.setAttribute('mode', val);
  }

  get personId() {
    return (typeof this.getAttribute === 'function' ? this.getAttribute('person-id') : this._personId) || '';
  }
  set personId(val) {
    this._personId = val;
    if (typeof this.setAttribute === 'function') this.setAttribute('person-id', val);
  }

  get modelBaseUrl() {
    return (typeof this.getAttribute === 'function' ? this.getAttribute('model-base-url') : this._modelBaseUrl) || '';
  }
  set modelBaseUrl(val) {
    this._modelBaseUrl = val;
    if (typeof this.setAttribute === 'function') this.setAttribute('model-base-url', val);
  }

  get autostart() {
    return typeof this.hasAttribute === 'function' ? this.hasAttribute('autostart') : Boolean(this._autostart);
  }
  set autostart(val) {
    this._autostart = Boolean(val);
    if (typeof this.setAttribute === 'function') {
      if (val) this.setAttribute('autostart', '');
      else if (typeof this.removeAttribute === 'function') this.removeAttribute('autostart');
    }
  }

  attributeChangedCallback(name, oldValue, newValue) {
    if (oldValue === newValue) return;
    if (name === 'mode') {
      this._updateModeVisibility();
    }
  }

  connectedCallback() {
    if (typeof this.attachShadow === 'function' && !this.shadowRoot) {
      this.attachShadow({ mode: 'open' });
      this.shadowRoot.innerHTML = template;
      this._bindElements();
    }
    this._updateModeVisibility();

    if (this.autostart) {
      if (this.mode !== 'enroll' || this._consentCheckbox?.checked) {
        this.start().catch((err) => {
          const mapped = mapCameraError(err);
          this.dispatchEvent(new CustomEvent('face-error', {
            bubbles: true,
            composed: true,
            detail: mapped,
          }));
        });
      }
    }
  }

  disconnectedCallback() {
    this.stop();
  }

  _bindElements() {
    if (!this.shadowRoot) return;
    this._video = this.shadowRoot.querySelector('video');
    this._canvas = this.shadowRoot.querySelector('canvas');
    this._statusText = this.shadowRoot.querySelector('.status-text');
    this._progress = this.shadowRoot.querySelector('progress');
    this._consentPanel = this.shadowRoot.querySelector('.consent-panel');
    this._consentCheckbox = this.shadowRoot.querySelector('.consent-checkbox');
    this._btnStart = this.shadowRoot.querySelector('.btn-start');
    this._btnStop = this.shadowRoot.querySelector('.btn-stop');

    this._btnStart?.addEventListener('click', () => {
      this.start().catch((err) => {
        const mapped = mapCameraError(err);
        this._setStatus(mapped.message, true);
        this.dispatchEvent(new CustomEvent('face-error', {
          bubbles: true,
          composed: true,
          detail: mapped,
        }));
      });
    });

    this._btnStop?.addEventListener('click', () => {
      this.stop();
    });
  }

  _updateModeVisibility() {
    if (!this._consentPanel) return;
    if (this.mode === 'enroll') {
      this._consentPanel.removeAttribute('hidden');
    } else {
      this._consentPanel.setAttribute('hidden', '');
    }
  }

  _setStatus(text, isError = false) {
    if (!this._statusText) return;
    this._statusText.textContent = text;
    if (isError) {
      this._statusText.classList.add('error-text');
    } else {
      this._statusText.classList.remove('error-text');
    }
  }

  _resultMessage(result) {
    const text = {
      PENDING: 'Face saved for evaluation. Approval is pending.',
      ACTIVE: 'Face enrolled.',
      REJECTED_DUPLICATE: result.ambiguous
        ? 'This capture conflicts with multiple saved people.'
        : 'This face is already registered to another person.',
      OBSERVATION_SAVED: 'Labeled observation saved without changing the enrolled reference.',
      UNKNOWN: 'Face not recognized. ' + (result.reason || ''),
      COLLECTION_ONLY: 'This workspace is configured for collection only.',
    };
    if (result.state === 'SUGGESTED') {
      return 'Evaluation suggestion: ' + result.person_name + ' (unverified)';
    }
    if (result.state === 'MATCHED') {
      return 'Matched: ' + result.person_name;
    }
    return text[result.state] || result.state;
  }

  async _resolveCaptureClass() {
    return AutomaticCapture;
  }

  async start() {
    const config = parseConfig({
      apiUrl: this.apiUrl,
      token: this.token,
      collection: this.collection,
      mode: this.mode,
      personId: this.personId,
      modelBaseUrl: this.modelBaseUrl,
      autostart: this.autostart,
    });

    const validation = validateConfig(config);
    if (!validation.valid) {
      this._setStatus(validation.message, true);
      this.dispatchEvent(new CustomEvent('face-error', {
        bubbles: true,
        composed: true,
        detail: { code: validation.code, message: validation.message },
      }));
      return;
    }

    const consentGate = checkConsentGating({
      mode: config.mode,
      consentChecked: this._consentCheckbox?.checked || false,
    });
    if (!consentGate.allowed) {
      this._setStatus(consentGate.message, true);
      this.dispatchEvent(new CustomEvent('face-error', {
        bubbles: true,
        composed: true,
        detail: { code: consentGate.code, message: consentGate.message },
      }));
      return;
    }

    this.stop();

    if (this._btnStart) this._btnStart.disabled = true;
    if (this._btnStop) this._btnStop.disabled = false;
    this._setStatus('Connecting to face service...');

    const generation = ++this._generation;
    const captureSession = typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID()
      : Math.random().toString(36).slice(2);

    let serverConfig;
    try {
      this._client = new FaceClient({ base: config.apiUrl, token: config.token });
      serverConfig = await this._client.get('/api/v1/config');
      if (this._generation !== generation) return;
    } catch (err) {
      if (this._generation === generation) {
        const errorDetail = {
          code: err.code || 'SERVICE_UNAVAILABLE',
          message: err.message || 'Face service unavailable',
        };
        this._setStatus(errorDetail.message, true);
        this.dispatchEvent(new CustomEvent('face-error', {
          bubbles: true,
          composed: true,
          detail: errorDetail,
        }));
        this.stop();
      }
      return;
    }

    let CaptureClass;
    try {
      CaptureClass = await this._resolveCaptureClass();
    } catch (err) {
      if (this._generation === generation) {
        const errorDetail = {
          code: 'ENGINE_LOAD_ERROR',
          message: 'Failed to load face capture engine: ' + (err.message || String(err)),
        };
        this._setStatus(errorDetail.message, true);
        this.dispatchEvent(new CustomEvent('face-error', {
          bubbles: true,
          composed: true,
          detail: errorDetail,
        }));
        this.stop();
      }
      return;
    }

    let held = '';
    const modelBase = config.modelBaseUrl || (config.apiUrl ? (config.apiUrl.endsWith('/') ? config.apiUrl : config.apiUrl + '/') : '');
    const contractAssetsBase = serverConfig?.contract?.assets_base || 'vendor/browser-face/';
    const assetsUrl = modelBase
      ? (modelBase.endsWith('vendor/browser-face/') ? modelBase : new URL(contractAssetsBase, modelBase).href)
      : '';

    this._capture = new CaptureClass(
      this._video,
      this._canvas,
      {
        ...(serverConfig?.contract || {}),
        diagnostics: false,
        ...(assetsUrl ? { assets_url: assetsUrl, assets_base: assetsUrl } : {}),
      },
      {
        status: (val) => {
          if (this._generation === generation && !held) this._setStatus(val);
        },
        progress: (val) => {
          if (this._generation === generation && this._progress) this._progress.value = val;
        },
        camera: () => {},
        running: () => {
          if (this._generation === generation) {
            this.dispatchEvent(new CustomEvent('face-ready', {
              bubbles: true,
              composed: true,
            }));
          }
        },
        trackReset: () => {
          held = '';
        },
        error: (err) => {
          if (this._generation === generation) {
            const mapped = mapCameraError(typeof err === 'string' ? new Error(err) : err);
            this._setStatus(mapped.message, true);
            this.dispatchEvent(new CustomEvent('face-error', {
              bubbles: true,
              composed: true,
              detail: mapped,
            }));
          }
        },
        batch: async (batch) => {
          const isEnroll = config.mode === 'enroll';
          const resource = isEnroll ? 'enrollments' : 'recognitions';
          const body = {
            capture_session: isEnroll ? captureSession : (crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2)),
            observations: batch.observations,
            model_version: serverConfig.contract.model_version,
            preprocessing_version: serverConfig.contract.preprocessing_version,
          };
          if (isEnroll) {
            const settings = this._video?.srcObject?.getVideoTracks?.()[0]?.getSettings?.() || {};
            let device = 'camera-default';
            try {
              const bytes = await crypto.subtle.digest(
                'SHA-256',
                new TextEncoder().encode(JSON.stringify({
                  deviceId: settings.deviceId || '',
                  width: settings.width,
                  height: settings.height,
                  facing: settings.facingMode || '',
                }))
              );
              device = 'camera-' + Array.from(new Uint8Array(bytes)).map(b => b.toString(16).padStart(2, '0')).join('').slice(0, 24);
            } catch (_) {}
            Object.assign(body, {
              person_id: config.personId,
              consent: true,
              template: batch.template,
              device,
              lighting: batch.brightness < 70 ? 'measured-dark' : batch.brightness > 180 ? 'measured-bright' : 'measured-normal',
            });
          }
          if (this._generation !== generation) return false;
          try {
            const endpoint = '/api/v1/collections/' + encodeURIComponent(config.collection) + '/' + resource;
            const result = await this._client.send(endpoint, body);
            if (this._generation !== generation) return false;
            held = this._resultMessage(result);
            this._setStatus(held);
            this.dispatchEvent(new CustomEvent('face-result', {
              bubbles: true,
              composed: true,
              detail: result,
            }));
            if (isEnroll) {
              this.stop();
              return false;
            }
            if (['MATCHED', 'SUGGESTED'].includes(result.state)) {
              return { waitForDeparture: true };
            }
            return true;
          } catch (failure) {
            if (this._generation === generation) {
              const errorDetail = {
                code: failure.code || 'REQUEST_FAILED',
                message: failure.message || 'Request failed',
              };
              this._setStatus(errorDetail.message, true);
              this.dispatchEvent(new CustomEvent('face-error', {
                bubbles: true,
                composed: true,
                detail: errorDetail,
              }));
            }
            return this._client?.pending ? { pending: true } : false;
          }
        },
      }
    );

    try {
      await this._capture.start(config.mode === 'recognize' ? 'attendance' : 'enroll');
    } catch (err) {
      if (this._generation === generation) {
        const mapped = mapCameraError(err);
        this._setStatus(mapped.message, true);
        this.dispatchEvent(new CustomEvent('face-error', {
          bubbles: true,
          composed: true,
          detail: mapped,
        }));
        this.stop();
      }
    }
  }

  stop() {
    this._generation++;
    if (this._capture) {
      try {
        this._capture.stop();
      } catch (_) {}
      this._capture = null;
    }
    if (this._video && this._video.srcObject) {
      try {
        this._video.srcObject.getTracks().forEach((track) => track.stop());
        this._video.srcObject = null;
      } catch (_) {}
    }
    if (this._btnStart) this._btnStart.disabled = false;
    if (this._btnStop) this._btnStop.disabled = true;
    if (this._progress) this._progress.value = 0;
  }
}

if (typeof customElements !== 'undefined' && !customElements.get('face-capture')) {
  customElements.define('face-capture', FaceCaptureElement);
}

export {
  parseConfig,
  validateConfig,
  checkConsentGating,
  mapCameraError,
};
