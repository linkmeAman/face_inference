import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { FaceCaptureElement } from '../src/face-capture.js';
import { AutomaticCapture } from '../dist/vendor-js/browser-face/capture.mjs';
import { patchWorker, patchFaceCapture } from '../scripts/sync.mjs';

const __filename = fileURLToPath(import.meta.url);
const pkgRoot = resolve(dirname(__filename), '..');

test('Trust: source and dist do not import executable code from model-base-url or attribute origins', () => {
  const srcCode = readFileSync(resolve(pkgRoot, 'src/face-capture.js'), 'utf8');
  const distCode = readFileSync(resolve(pkgRoot, 'dist/face-capture.js'), 'utf8');

  // Should never dynamically import from modelBaseUrl or attribute URLs
  assert.doesNotMatch(
    srcCode,
    /import\(\s*(?:\/\*.*?\*\/)?\s*url\s*\)/,
    'src/face-capture.js must not perform dynamic import on attribute URL'
  );
  assert.doesNotMatch(
    distCode,
    /import\(\s*(?:\/\*.*?\*\/)?\s*url\s*\)/,
    'dist/face-capture.js must not perform dynamic import on attribute URL'
  );

  // _resolveCaptureClass must return AutomaticCapture without remote import
  const el = new FaceCaptureElement();
  assert.ok(typeof el._resolveCaptureClass === 'function');
});

test('Trust: hostile model-base-url does not attempt remote code import and uses shipped implementation', async () => {
  const el = new FaceCaptureElement();
  el.getAttribute = (name) => {
    const map = {
      'api-url': 'http://127.0.0.1:6122',
      token: 'test-token',
      collection: 'test-col',
      mode: 'recognize',
      'model-base-url': 'https://attacker.evil.com/models/',
    };
    return map[name] || '';
  };

  const resolved = await el._resolveCaptureClass('https://attacker.evil.com/models/');
  assert.equal(resolved, AutomaticCapture, 'Must resolve to local shipped AutomaticCapture');
});

test('Trust: asset URLs honor configuration and point to asset origin without loading remote code', async () => {
  const el = new FaceCaptureElement();
  let capturedConfig = null;

  el.dispatchEvent = () => true;
  el.getAttribute = (name) => {
    const map = {
      'api-url': 'http://127.0.0.1:6122',
      token: 'test-token',
      collection: 'test-col',
      mode: 'recognize',
      'model-base-url': 'https://cdn.example.com/models/',
    };
    return map[name] || '';
  };

  // Mock global fetch for /api/v1/config
  const origFetch = globalThis.fetch;
  globalThis.fetch = async (url) => {
    if (String(url).includes('/api/v1/config')) {
      return {
        ok: true,
        status: 200,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => ({
          data: {
            contract: {
              assets_base: 'vendor/browser-face/',
              detector_file: 'yunet.onnx',
              recognizer_file: 'sface.onnx',
            },
          },
        }),
      };
    }
    throw new Error('Unexpected network call: ' + url);
  };

  // Stub navigator.mediaDevices to avoid camera hardware requirement
  const origMediaDevices = globalThis.navigator?.mediaDevices;
  Object.defineProperty(globalThis.navigator, 'mediaDevices', {
    value: {
      getUserMedia: async () => ({
        getVideoTracks: () => [{ stop: () => {}, getSettings: () => ({}) }],
        getTracks: () => [{ stop: () => {} }],
      }),
    },
    configurable: true,
  });

  try {
    // Intercept capture initialization
    const origCapture = AutomaticCapture;
    class MockCapture extends origCapture {
      constructor(video, canvas, config, callbacks) {
        super(video, canvas, config, callbacks);
        capturedConfig = config;
      }
      start() { return Promise.resolve(); }
      stop() {}
    }

    el._resolveCaptureClass = async () => MockCapture;
    await el.start();

    assert.ok(capturedConfig, 'Capture engine should be instantiated with configuration');
    assert.match(
      capturedConfig.assets_url || capturedConfig.assets_base,
      /^https:\/\/cdn\.example\.com\/models\//,
      'Configured asset URL must point to model-base-url asset origin'
    );
  } finally {
    globalThis.fetch = origFetch;
    if (origMediaDevices) {
      Object.defineProperty(globalThis.navigator, 'mediaDevices', {
        value: origMediaDevices,
        configurable: true,
      });
    }
  }
});

test('Trust: patchWorker on real source contains assets_url', () => {
  const srcWorkerPath = resolve(pkgRoot, '../../src/face_inference/web/browser-face/worker.mjs');
  const realSource = readFileSync(srcWorkerPath, 'utf8');
  const patched = patchWorker(realSource);
  assert.ok(patched.includes('assets_url'), 'patched worker must contain assets_url');
});

test('Trust: patchWorker on mutated source throws', () => {
  const srcWorkerPath = resolve(pkgRoot, '../../src/face_inference/web/browser-face/worker.mjs');
  const realSource = readFileSync(srcWorkerPath, 'utf8');
  const targetPattern = "const assets = new URL('../'+config.assets_base, import.meta.url).href;";
  assert.ok(realSource.includes(targetPattern), 'real source must contain target pattern');
  const mutatedSource = realSource.replace(
    targetPattern,
    "const assets = new URL('../'+''+config.assets_base, import.meta.url).href;"
  );
  assert.notEqual(realSource, mutatedSource, 'mutation must modify source');
  assert.throws(
    () => patchWorker(mutatedSource),
    {
      name: 'Error',
      message: 'worker.mjs asset patch did not apply',
    }
  );
});

test('Trust: patchFaceCapture throws on mutated source missing target import', () => {
  assert.throws(
    () => patchFaceCapture('const a = 1;'),
    {
      name: 'Error',
      message: 'face-capture.js import patch did not apply',
    }
  );
});

test('Trust: packaged engine includes transitive ORT module and excludes model/WASM binaries', () => {
  // 1. Check vendor-js output structure
  const distVendorOrt = resolve(pkgRoot, 'dist/vendor-js/vendor/browser-face/ort.wasm.min.mjs');
  const distVendorOrtSimd = resolve(pkgRoot, 'dist/vendor-js/vendor/browser-face/ort-wasm-simd-threaded.mjs');
  const distWorker = resolve(pkgRoot, 'dist/vendor-js/browser-face/worker.mjs');

  assert.ok(existsSync(distWorker), 'worker.mjs must exist in dist/vendor-js/browser-face/');
  assert.ok(existsSync(distVendorOrt), 'ort.wasm.min.mjs must exist in dist/vendor-js/vendor/browser-face/');
  assert.ok(existsSync(distVendorOrtSimd), 'ort-wasm-simd-threaded.mjs must exist in dist/vendor-js/vendor/browser-face/');

  // 2. Transitive import check and asset-origin patch verification
  const workerContent = readFileSync(distWorker, 'utf8');
  assert.match(
    workerContent,
    /from\s+['"]\.\.\/vendor\/browser-face\/ort\.wasm\.min\.mjs['"]/,
    'worker.mjs must import ort module from relative vendor path'
  );
  assert.ok(
    workerContent.includes('assets_url'),
    'dist/vendor-js/browser-face/worker.mjs must contain assets_url'
  );

  // 3. Tree walk check: NO .wasm or .onnx files anywhere under dist
  const distDir = resolve(pkgRoot, 'dist');
  const allDistFiles = readdirSync(distDir, { recursive: true });
  const distBinaries = allDistFiles.filter((file) => /\.(onnx|wasm)$/i.test(file));
  assert.deepEqual(
    distBinaries,
    [],
    'No .wasm or .onnx file must exist anywhere under dist'
  );

  // 4. Tarball package contents check
  const json = execFileSync('npm', ['pack', '--dry-run', '--json', '--ignore-scripts'], {
    cwd: pkgRoot,
    encoding: 'utf8',
  });
  const packed = JSON.parse(json);
  const pack = Array.isArray(packed) ? packed[0] : Object.values(packed)[0];
  const files = new Set(pack.files.map((file) => file.path));

  assert.ok(
    files.has('dist/vendor-js/vendor/browser-face/ort.wasm.min.mjs'),
    'ort.wasm.min.mjs must be present in npm tarball'
  );
  assert.ok(
    files.has('dist/vendor-js/vendor/browser-face/ort-wasm-simd-threaded.mjs'),
    'ort-wasm-simd-threaded.mjs must be present in npm tarball'
  );

  // Ensure NO model binaries (.onnx) or wasm binaries (.wasm) are packaged in npm
  const bundledBinaries = [...files].filter((file) => /\.(onnx|wasm)$/i.test(file));
  assert.deepEqual(bundledBinaries, [], 'Model or WASM binaries must not be bundled in npm package');
});
