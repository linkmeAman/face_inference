import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { FaceCaptureElement } from '../src/face-capture.js';
import { buildCameraFingerprint, cameraFingerprint } from '../dist/vendor-js/client.mjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const standardSettings = {
  deviceId: 'camera-front-123',
  width: 1280,
  height: 720,
  facingMode: 'user',
};

test('Fingerprint: shared helper calculates deterministic expected hash', async () => {
  const hash = await buildCameraFingerprint(standardSettings);
  assert.equal(hash, 'camera-074d4a4f75b8a4e66b133a73');
  assert.equal(await cameraFingerprint(standardSettings), 'camera-074d4a4f75b8a4e66b133a73');
});

test('Fingerprint: shared helper handles absent or empty settings', async () => {
  assert.equal(await buildCameraFingerprint({}), 'camera-d3e61e53186c0a4075079482');
  assert.equal(await buildCameraFingerprint(null), 'camera-d3e61e53186c0a4075079482');
  assert.equal(await buildCameraFingerprint(undefined), 'camera-d3e61e53186c0a4075079482');
});

test('Fingerprint: shared helper falls back to camera-default when crypto fails or unavailable', async () => {
  const orig = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
  try {
    Object.defineProperty(globalThis, 'crypto', { value: {}, configurable: true });
    assert.equal(await buildCameraFingerprint(standardSettings), 'camera-default');

    Object.defineProperty(globalThis, 'crypto', {
      value: {
        subtle: {
          digest: async () => { throw new Error('digest failed'); },
        },
      },
      configurable: true,
    });
    assert.equal(await buildCameraFingerprint(standardSettings), 'camera-default');
  } finally {
    Object.defineProperty(globalThis, 'crypto', orig);
  }
});

test('Fingerprint: FaceCaptureElement uses shared helper in enroll batch', async () => {
  const element = new FaceCaptureElement();
  element.apiUrl = 'http://127.0.0.1:6122';
  element.token = 'test-token';
  element.collection = 'col-1';
  element.mode = 'enroll';
  element.personId = 'person-1';
  element.dispatchEvent = () => true;
  element._consentCheckbox = { checked: true };
  element._btnStart = { disabled: false };
  element._btnStop = { disabled: true };
  element._video = {
    srcObject: {
      getVideoTracks: () => [{
        getSettings: () => standardSettings,
      }],
    },
  };

  let batchFn;
  class FakeCapture {
    constructor(video, canvas, config, callbacks) {
      batchFn = callbacks?.batch;
    }
    async start() {}
    stop() {}
  }
  element._resolveCaptureClass = async () => FakeCapture;

  const originalFetch = globalThis.fetch;
  const sentBodies = [];
  try {
    globalThis.fetch = async (url, opts) => {
      if (opts?.method === 'POST') {
        sentBodies.push(JSON.parse(opts.body));
        return {
          ok: true,
          json: async () => ({ data: { state: 'ACTIVE' } }),
        };
      }
      return {
        ok: true,
        json: async () => ({
          data: { contract: { model_version: 'v1.0', preprocessing_version: 'v1.0' } },
        }),
      };
    };

    await element.start();
    assert.ok(batchFn);
    await batchFn({
      observations: [{ box: [1, 2, 3, 4] }],
      template: [0.1, 0.2, 0.3],
      brightness: 110,
    });

    assert.equal(sentBodies.length, 1);
    assert.equal(sentBodies[0].device, 'camera-074d4a4f75b8a4e66b133a73');
    assert.equal(sentBodies[0].consent, true);
    assert.equal(sentBodies[0].person_id, 'person-1');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('Fingerprint: FaceCaptureElement falls back to camera-default when crypto is unavailable', async () => {
  const element = new FaceCaptureElement();
  element.apiUrl = 'http://127.0.0.1:6122';
  element.token = 'test-token';
  element.collection = 'col-1';
  element.mode = 'enroll';
  element.personId = 'person-1';
  element.dispatchEvent = () => true;
  element._consentCheckbox = { checked: true };
  element._btnStart = { disabled: false };
  element._btnStop = { disabled: true };
  element._video = {
    srcObject: {
      getVideoTracks: () => [{
        getSettings: () => standardSettings,
      }],
    },
  };

  let batchFn;
  class FakeCapture {
    constructor(video, canvas, config, callbacks) {
      batchFn = callbacks?.batch;
    }
    async start() {}
    stop() {}
  }
  element._resolveCaptureClass = async () => FakeCapture;

  const originalFetch = globalThis.fetch;
  const orig = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
  const sentBodies = [];
  try {
    Object.defineProperty(globalThis, 'crypto', {
      value: {
        randomUUID: () => 'uuid-element-test',
      },
      configurable: true,
    });

    globalThis.fetch = async (url, opts) => {
      if (opts?.method === 'POST') {
        sentBodies.push(JSON.parse(opts.body));
        return {
          ok: true,
          json: async () => ({ data: { state: 'ACTIVE' } }),
        };
      }
      return {
        ok: true,
        json: async () => ({
          data: { contract: { model_version: 'v1.0', preprocessing_version: 'v1.0' } },
        }),
      };
    };

    await element.start();
    assert.ok(batchFn);
    await batchFn({
      observations: [{ box: [1, 2, 3, 4] }],
      template: [0.1, 0.2, 0.3],
      brightness: 110,
    });

    assert.equal(sentBodies.length, 1);
    assert.equal(sentBodies[0].device, 'camera-default');
  } finally {
    globalThis.fetch = originalFetch;
    Object.defineProperty(globalThis, 'crypto', orig);
  }
});

test('Built package verification: built bundle imports and uses shared helper', async () => {
  const distCapturePath = resolve(__dirname, '../dist/face-capture.js');
  const distCaptureCode = await readFile(distCapturePath, 'utf8');

  // Verify that face-capture.js imports buildCameraFingerprint from vendor client.mjs
  assert.match(distCaptureCode, /import\s*\{[^}]*buildCameraFingerprint[^}]*\}\s*from\s*['"]\.\/vendor-js\/client\.mjs['"]/);

  // Verify that the vendor client.mjs exports buildCameraFingerprint
  const distClientPath = resolve(__dirname, '../dist/vendor-js/client.mjs');
  const distClientCode = await readFile(distClientPath, 'utf8');
  assert.match(distClientCode, /export\s+(?:async\s+)?function\s+buildCameraFingerprint/);
});
