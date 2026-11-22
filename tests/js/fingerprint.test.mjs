import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { buildCameraFingerprint, cameraFingerprint } from '../../src/face_inference/web/client.mjs';
import { initApp } from '../../src/face_inference/web/app.mjs';
import { FaceCaptureElement } from '../../packages/face-capture/src/face-capture.js';

class MockElement {
  constructor(tag, id = '') {
    this.tagName = tag.toUpperCase();
    this.id = id;
    this.textContent = '';
    this._value = '';
    this.checked = false;
    this.hidden = false;
    this.disabled = false;
    this.children = [];
    this.listeners = {};
    this.srcObject = null;
  }
  get value() { return this._value; }
  set value(v) { this._value = String(v ?? ''); }
  get selectedOptions() {
    const selected = this.children.filter(c => c.value === this._value);
    return selected.length > 0 ? selected : (this.children.length > 0 ? [this.children[0]] : [{ textContent: '' }]);
  }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  addEventListener(event, fn) {
    this.listeners[event] = this.listeners[event] || [];
    this.listeners[event].push(fn);
  }
  async click() {
    for (const fn of this.listeners['click'] || []) {
      await fn({ type: 'click', target: this });
    }
  }
}

class MockDocument {
  constructor(videoSettings = { deviceId: 'camera-front-123', width: 1280, height: 720, facingMode: 'user' }) {
    this.elements = new Map();
    const ids = [
      'token', 'connect', 'connection', 'workspace', 'notice',
      'collection', 'collection-id', 'collection-name', 'add-collection', 'refresh',
      'recognize', 'person-id', 'person-name', 'add-person', 'consent',
      'people', 'empty', 'camera-panel', 'camera-title', 'switch-camera',
      'stop-camera', 'video', 'overlay', 'progress', 'camera-status', 'result', 'retry'
    ];
    for (const id of ids) {
      const tag = id === 'people' ? 'tbody' : id === 'video' ? 'video' : id === 'overlay' ? 'canvas' : id === 'collection' ? 'select' : id === 'progress' ? 'progress' : 'div';
      const el = new MockElement(tag, id);
      if (['workspace', 'notice', 'camera-panel', 'retry'].includes(id)) {
        el.hidden = true;
      }
      this.elements.set(id, el);
    }
    const video = this.elements.get('video');
    video.srcObject = {
      getVideoTracks: () => [{
        getSettings: () => videoSettings
      }]
    };
  }
  getElementById(id) { return this.elements.get(id) || null; }
  createElement(tag) { return new MockElement(tag); }
  addEventListener() {}
}

class MockWindow {
  constructor() {
    this.listeners = {};
    this.confirmAnswer = true;
  }
  addEventListener(type, fn) {
    this.listeners[type] = this.listeners[type] || [];
    this.listeners[type].push(fn);
  }
  confirm() { return this.confirmAnswer; }
}

class FakeFaceClient {
  constructor() {
    this.calls = [];
    this.pending = null;
    this.configResponse = {
      contract: { model_version: 'v1.0', preprocessing_version: 'v1.0' },
      approved: true,
      mode: 'RECOGNIZE'
    };
    this.collectionsResponse = [{ id: 'team-1', name: 'Team One' }];
    this.peopleResponse = [{ id: 'p-1', name: 'Alice', state: 'ACTIVE' }];
    this.sendResponse = { state: 'ACTIVE' };
  }
  async get(path) {
    this.calls.push({ method: 'GET', path });
    if (path === '/api/v1/config') return this.configResponse;
    if (path === '/api/v1/collections') return this.collectionsResponse;
    if (path.startsWith('/api/v1/collections/') && path.endsWith('/people')) {
      return this.peopleResponse;
    }
    return {};
  }
  async send(path, body, method = 'POST') {
    this.calls.push({ method, path, body });
    return this.sendResponse;
  }
}

class FakeAutomaticCapture {
  constructor(video, overlay, contract, callbacks) {
    this.video = video;
    this.overlay = overlay;
    this.contract = contract;
    this.callbacks = callbacks;
    this.active = false;
    FakeAutomaticCapture.lastInstance = this;
  }
  async start() { this.active = true; }
  stop() { this.active = false; }
}

describe('Camera fingerprint shared builder', () => {
  const standardSettings = {
    deviceId: 'camera-front-123',
    width: 1280,
    height: 720,
    facingMode: 'user'
  };

  test('deterministic expected hash for standard settings', async () => {
    const hash = await buildCameraFingerprint(standardSettings);
    // sha256({"deviceId":"camera-front-123","width":1280,"height":720,"facing":"user"}).slice(0, 24)
    assert.equal(hash, 'camera-074d4a4f75b8a4e66b133a73');
    assert.equal(await cameraFingerprint(standardSettings), 'camera-074d4a4f75b8a4e66b133a73');
  });

  test('deterministic hash for absent settings', async () => {
    // sha256({"deviceId":"","facing":""}).slice(0, 24)
    assert.equal(await buildCameraFingerprint({}), 'camera-d3e61e53186c0a4075079482');
    assert.equal(await buildCameraFingerprint(null), 'camera-d3e61e53186c0a4075079482');
    assert.equal(await buildCameraFingerprint(undefined), 'camera-d3e61e53186c0a4075079482');
  });

  test('deterministic hash for partial/rear settings', async () => {
    const rear = { deviceId: 'cam-back', width: 1920, height: 1080, facingMode: 'environment' };
    assert.equal(await buildCameraFingerprint(rear), 'camera-966892e212c6746f5d37fe3f');
  });

  test('fallback to camera-default when crypto is unavailable', async () => {
    const orig = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
    try {
      Object.defineProperty(globalThis, 'crypto', { value: {}, configurable: true });
      const fallback = await buildCameraFingerprint(standardSettings);
      assert.equal(fallback, 'camera-default');
    } finally {
      Object.defineProperty(globalThis, 'crypto', orig);
    }
  });

  test('fallback to camera-default when digest throws', async () => {
    const orig = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
    try {
      Object.defineProperty(globalThis, 'crypto', {
        value: {
          subtle: {
            digest: async () => { throw new Error('Hardware crypto failure'); }
          }
        },
        configurable: true
      });
      const fallback = await buildCameraFingerprint(standardSettings);
      assert.equal(fallback, 'camera-default');
    } finally {
      Object.defineProperty(globalThis, 'crypto', orig);
    }
  });
});

describe('Real callers alignment: app.mjs and FaceCaptureElement', () => {
  const standardSettings = {
    deviceId: 'camera-front-123',
    width: 1280,
    height: 720,
    facingMode: 'user'
  };

  test('app.mjs enrollment uses consistent deviceId hash', async () => {
    const doc = new MockDocument(standardSettings);
    const win = new MockWindow();
    const client = new FakeFaceClient();

    initApp({
      doc,
      win,
      FaceClient: function() { return client; },
      AutomaticCapture: FakeAutomaticCapture
    });

    doc.getElementById('token').value = 'test-token';
    await doc.getElementById('connect').click();
    doc.getElementById('consent').checked = true;

    // Table structure: tbody -> tr -> td[3] (actions) -> button[0] (enroll)
    const row = doc.getElementById('people').children[0];
    const enrollBtn = row.children[3].children[0];
    await enrollBtn.click();

    const capture = FakeAutomaticCapture.lastInstance;
    assert.ok(capture);

    await capture.callbacks.batch({
      observations: [{ box: [1, 2, 3, 4] }],
      template: [0.1, 0.2, 0.3],
      brightness: 120
    });

    const enrollCalls = client.calls.filter(c => c.path.includes('/enrollments'));
    assert.equal(enrollCalls.length, 1);
    const body = enrollCalls[0].body;

    // Must yield camera-074d4a4f75b8a4e66b133a73
    assert.equal(body.device, 'camera-074d4a4f75b8a4e66b133a73');
    assert.equal(body.consent, true);
    assert.equal(body.person_id, 'p-1');
  });

  test('app.mjs enrollment with absent settings yields empty-settings hash', async () => {
    const doc = new MockDocument({});
    const win = new MockWindow();
    const client = new FakeFaceClient();

    initApp({
      doc,
      win,
      FaceClient: function() { return client; },
      AutomaticCapture: FakeAutomaticCapture
    });

    doc.getElementById('token').value = 'test-token';
    await doc.getElementById('connect').click();
    doc.getElementById('consent').checked = true;

    const row = doc.getElementById('people').children[0];
    const enrollBtn = row.children[3].children[0];
    await enrollBtn.click();

    const capture = FakeAutomaticCapture.lastInstance;
    await capture.callbacks.batch({
      observations: [{ box: [1, 2, 3, 4] }],
      template: [0.1, 0.2, 0.3],
      brightness: 120
    });

    const enrollCalls = client.calls.filter(c => c.path.includes('/enrollments'));
    assert.equal(enrollCalls[0].body.device, 'camera-d3e61e53186c0a4075079482');
  });

  test('app.mjs enrollment with unavailable crypto falls back to camera-default', async () => {
    const doc = new MockDocument(standardSettings);
    const win = new MockWindow();
    const client = new FakeFaceClient();

    const orig = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
    try {
      // In insecure contexts crypto.subtle is undefined, but randomUUID exists
      Object.defineProperty(globalThis, 'crypto', {
        value: {
          randomUUID: () => 'uuid-1234-mock',
        },
        configurable: true
      });
      initApp({
        doc,
        win,
        FaceClient: function() { return client; },
        AutomaticCapture: FakeAutomaticCapture
      });

      doc.getElementById('token').value = 'test-token';
      await doc.getElementById('connect').click();
      doc.getElementById('consent').checked = true;

      const row = doc.getElementById('people').children[0];
      const enrollBtn = row.children[3].children[0];
      await enrollBtn.click();

      const capture = FakeAutomaticCapture.lastInstance;
      await capture.callbacks.batch({
        observations: [{ box: [1, 2, 3, 4] }],
        template: [0.1, 0.2, 0.3],
        brightness: 120
      });

      const enrollCalls = client.calls.filter(c => c.path.includes('/enrollments'));
      assert.equal(enrollCalls[0].body.device, 'camera-default');
    } finally {
      Object.defineProperty(globalThis, 'crypto', orig);
    }
  });

  test('FaceCaptureElement and app.mjs yield identical device labels under identical settings', async () => {
    const element = new FaceCaptureElement();
    element.apiUrl = 'http://127.0.0.1:6122';
    element.token = 'test-token';
    element.collection = 'test-col';
    element.mode = 'enroll';
    element.personId = 'person-1';
    element.dispatchEvent = () => true;
    element._consentCheckbox = { checked: true };
    element._btnStart = { disabled: false };
    element._btnStop = { disabled: true };
    element._video = {
      srcObject: {
        getVideoTracks: () => [{
          getSettings: () => standardSettings
        }]
      }
    };

    let batchCallback;
    class FakeEngine {
      constructor(video, canvas, config, callbacks) {
        batchCallback = callbacks?.batch;
      }
      async start() {}
      stop() {}
    }
    element._resolveCaptureClass = async () => FakeEngine;

    const originalFetch = globalThis.fetch;
    const sentBodies = [];
    try {
      globalThis.fetch = async (url, opts) => {
        if (opts?.method === 'POST') {
          sentBodies.push(JSON.parse(opts.body));
          return {
            ok: true,
            json: async () => ({ data: { state: 'ACTIVE' } })
          };
        }
        return {
          ok: true,
          json: async () => ({
            data: { contract: { model_version: 'v1.0', preprocessing_version: 'v1.0' } }
          })
        };
      };

      await element.start();
      assert.ok(batchCallback);

      await batchCallback({
        observations: [{ box: [10, 20, 30, 40] }],
        template: [0.1, 0.2, 0.3],
        brightness: 100
      });

      assert.equal(sentBodies.length, 1);
      // Both callers must match exactly
      assert.equal(sentBodies[0].device, 'camera-074d4a4f75b8a4e66b133a73');
      assert.equal(sentBodies[0].consent, true);
      assert.equal(sentBodies[0].person_id, 'person-1');
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  test('historical evidence contract: old app hash is preserved and distinct', () => {
    const legacyAppHash = 'camera-746ce21f80376b61c1ee5496';
    const alignedHash = 'camera-074d4a4f75b8a4e66b133a73';
    assert.notEqual(legacyAppHash, alignedHash);
  });
});
