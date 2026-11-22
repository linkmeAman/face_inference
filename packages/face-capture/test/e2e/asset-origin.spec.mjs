import { test, expect } from '@playwright/test';
import net from 'node:net';
import http from 'node:http';
import { spawn, spawnSync } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const pkgRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(pkgRoot, '../..');
const webRoot = path.join(repoRoot, 'src/face_inference/web');

function getPythonExecutable() {
  const venvPython = path.join(repoRoot, '.venv/bin/python');
  if (fs.existsSync(venvPython)) return venvPython;
  return 'python3';
}

async function getFreePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.listen(0, '127.0.0.1', () => {
      const port = srv.address().port;
      srv.close(() => resolve(port));
    });
    srv.on('error', reject);
  });
}

const fixture = process.env.FACE_STANDALONE_CAMERA_FIXTURE;
const useChrome = process.platform === 'darwin' && fs.existsSync('/Applications/Google Chrome.app');

test.use({
  launchOptions: {
    ...(useChrome ? { channel: 'chrome' } : {}),
    args: [
      '--use-fake-device-for-media-stream',
      '--use-fake-ui-for-media-stream',
      ...(fixture && fs.existsSync(fixture) ? [`--use-file-for-fake-video-capture=${fixture}`] : []),
    ],
  },
});

test.describe('Hostile Asset Origin Security & Integrity', () => {
  test.describe.configure({ mode: 'serial' });

  let pythonPort;
  let staticPort;
  let hostilePort;
  let serverProc;
  let staticServer;
  let hostileServer;
  let tempHome;
  let apiToken;

  let currentVariant = 'honest';
  const hostileHits = [];
  const beacons = [];

  const mimeTypes = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.wasm': 'application/wasm',
    '.onnx': 'application/octet-stream',
  };

  const tweak = (name, body) => {
    if (currentVariant === 'tampered-wasm' && name.endsWith('.wasm')) {
      const tampered = Buffer.from(body);
      tampered[100] ^= 0xff;
      return tampered;
    }
    if (currentVariant === 'evil-glue' && name === 'ort-wasm-simd-threaded.mjs') {
      return Buffer.from(`fetch('http://127.0.0.1:${staticPort}/pwned');\n` + body.toString());
    }
    return body;
  };

  test.beforeAll(async () => {
    pythonPort = await getFreePort();
    staticPort = await getFreePort();
    hostilePort = await getFreePort();

    tempHome = fs.mkdtempSync(path.join(os.tmpdir(), 'face-hostile-home-'));
    const python = getPythonExecutable();
    const env = {
      ...process.env,
      PYTHONPATH: path.join(repoRoot, 'src'),
      FACE_INFERENCE_HOME: tempHome,
      FACE_INFERENCE_ALLOWED_ORIGINS: `http://127.0.0.1:${staticPort}`,
    };

    // 1. Obtain API token and initialize home config
    const tokenRes = spawnSync(python, ['-m', 'face_inference', 'token'], { cwd: repoRoot, env });
    if (tokenRes.status !== 0) {
      throw new Error('face_inference token failed: ' + tokenRes.stderr.toString());
    }
    apiToken = tokenRes.stdout.toString().trim();

    // 2. Start Python server
    serverProc = spawn(python, ['-m', 'face_inference', 'serve', '--port', String(pythonPort)], {
      cwd: repoRoot,
      env,
      stdio: 'ignore',
    });

    let serverReady = false;
    for (let i = 0; i < 50; i++) {
      try {
        const res = await fetch(`http://127.0.0.1:${pythonPort}/health`);
        if (res.ok) {
          serverReady = true;
          break;
        }
      } catch (_) {}
      await new Promise((r) => setTimeout(r, 100));
    }
    if (!serverReady) {
      throw new Error('Python face_inference server failed to start within timeout');
    }

    // 3. Create demo collection
    const collRes = await fetch(`http://127.0.0.1:${pythonPort}/api/v1/collections`, {
      method: 'POST',
      headers: {
        Authorization: 'Bearer ' + apiToken,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        id: 'demo',
        name: 'Demo',
        idempotency_key: 'hostile-test-collection',
      }),
    });
    if (!collRes.ok) {
      throw new Error(`Failed to create demo collection: ${collRes.status} ${await collRes.text()}`);
    }

    // 4. Start static server (embedding page + component package)
    staticServer = http.createServer((req, res) => {
      const u = new URL(req.url, `http://127.0.0.1:${staticPort}`);
      const pathname = decodeURIComponent(u.pathname);

      if (pathname === '/pwned') {
        beacons.push({ pathname, query: u.search, time: Date.now() });
        res.writeHead(200, {
          'Content-Type': 'text/plain',
          'Access-Control-Allow-Origin': '*',
        });
        res.end('pwned');
        return;
      }

      if (pathname === '/page.html' || pathname === '/') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Hostile Origin Test</title>
</head>
<body>
  <script type="module" src="/dist/face-capture.js"></script>
  <face-capture
    id="fc"
    api-url="http://127.0.0.1:${pythonPort}"
    token="${apiToken}"
    collection="demo"
    mode="recognize"
    model-base-url="http://127.0.0.1:${hostilePort}/">
  </face-capture>
</body>
</html>`);
        return;
      }

      const filePath = path.join(pkgRoot, pathname);
      if (!filePath.startsWith(pkgRoot) || !fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not found: ' + pathname);
        return;
      }

      const ext = path.extname(filePath).toLowerCase();
      res.writeHead(200, {
        'Content-Type': mimeTypes[ext] || 'application/octet-stream',
        'Cache-Control': 'no-store, no-cache, must-revalidate',
      });
      fs.createReadStream(filePath).pipe(res);
    });

    await new Promise((resolve) => staticServer.listen(staticPort, '127.0.0.1', resolve));

    // 5. Start hostile CDN server
    hostileServer = http.createServer((req, res) => {
      const u = new URL(req.url, `http://127.0.0.1:${hostilePort}`);
      hostileHits.push(u.pathname);

      if (req.method === 'OPTIONS') {
        res.writeHead(204, {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'GET, HEAD, OPTIONS',
          'Access-Control-Allow-Headers': '*',
        });
        res.end();
        return;
      }

      const filePath = path.join(webRoot, u.pathname);
      if (!filePath.startsWith(webRoot) || !fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
        res.writeHead(404, { 'Content-Type': 'text/plain', 'Access-Control-Allow-Origin': '*' });
        res.end('Not found: ' + u.pathname);
        return;
      }

      let body = fs.readFileSync(filePath);
      const name = path.basename(filePath);
      body = tweak(name, body);

      const ext = path.extname(filePath).toLowerCase();
      res.writeHead(200, {
        'Content-Type': mimeTypes[ext] || 'application/octet-stream',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'no-store, no-cache, must-revalidate',
      });
      res.end(body);
    });

    await new Promise((resolve) => hostileServer.listen(hostilePort, '127.0.0.1', resolve));
  });

  test.afterAll(async () => {
    if (serverProc) {
      serverProc.kill('SIGTERM');
    }
    if (staticServer) {
      await new Promise((resolve) => staticServer.close(resolve));
    }
    if (hostileServer) {
      await new Promise((resolve) => hostileServer.close(resolve));
    }
    if (tempHome && fs.existsSync(tempHome)) {
      fs.rmSync(tempHome, { recursive: true, force: true });
    }
  });

  test.beforeEach(async () => {
    hostileHits.length = 0;
    beacons.length = 0;
  });

  test('honest: loads models and wasm from hostile origin without requesting JS glue and emits face-ready', async ({ page, context }) => {
    currentVariant = 'honest';
    await context.grantPermissions(['camera'], { origin: `http://127.0.0.1:${staticPort}` });

    await page.addInitScript(() => {
      window.__events = [];
      addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('fc');
        for (const n of ['face-ready', 'face-error', 'face-result']) {
          el.addEventListener(n, (e) => window.__events.push({ type: n, detail: e.detail }));
        }
      });
    });

    await page.goto(`http://127.0.0.1:${staticPort}/page.html`);
    await page.evaluate(() => document.getElementById('fc').start?.());

    const outcome = await page.waitForFunction(
      () => (window.__events || []).find((e) => e.type === 'face-ready' || e.type === 'face-error'),
      null,
      { timeout: 20000 }
    ).then((h) => h.jsonValue());

    expect(outcome.type).toBe('face-ready');

    // The hostile origin received requests for the two model files and the wasm
    const requestedFiles = hostileHits.map((p) => path.basename(p));
    expect(requestedFiles).toContain('yunet.onnx');
    expect(requestedFiles).toContain('sface.onnx');
    expect(requestedFiles).toContain('ort-wasm-simd-threaded.wasm');

    // And for NO .mjs/.js file
    const jsRequests = hostileHits.filter((p) => /\.(m?js)($|\?)/i.test(p));
    expect(jsRequests).toEqual([]);

    await page.evaluate(() => document.getElementById('fc')?.stop?.());
  });

  test('tampered-wasm: emits face-error with integrity message and starts no inference session', async ({ page, context }) => {
    currentVariant = 'tampered-wasm';
    await context.grantPermissions(['camera'], { origin: `http://127.0.0.1:${staticPort}` });

    await page.addInitScript(() => {
      window.__events = [];
      addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('fc');
        for (const n of ['face-ready', 'face-error', 'face-result']) {
          el.addEventListener(n, (e) => window.__events.push({ type: n, detail: e.detail }));
        }
      });
    });

    await page.goto(`http://127.0.0.1:${staticPort}/page.html`);
    await page.evaluate(() => document.getElementById('fc').start?.());

    const outcome = await page.waitForFunction(
      () => (window.__events || []).find((e) => e.type === 'face-ready' || e.type === 'face-error'),
      null,
      { timeout: 20000 }
    ).then((h) => h.jsonValue());

    expect(outcome.type).toBe('face-error');
    expect(outcome.detail?.message).toMatch(/integrity/i);

    // NO inference session started (no face-ready)
    const readyEvents = await page.evaluate(() =>
      (window.__events || []).filter((e) => e.type === 'face-ready')
    );
    expect(readyEvents).toHaveLength(0);

    // Hostile origin was asked for wasm
    const requestedFiles = hostileHits.map((p) => path.basename(p));
    expect(requestedFiles).toContain('ort-wasm-simd-threaded.wasm');

    // And for NO .mjs/.js file
    const jsRequests = hostileHits.filter((p) => /\.(m?js)($|\?)/i.test(p));
    expect(jsRequests).toEqual([]);
  });

  test('evil-glue: never requests hostile glue, never hits beacon, and emits face-ready', async ({ page, context }) => {
    currentVariant = 'evil-glue';
    await context.grantPermissions(['camera'], { origin: `http://127.0.0.1:${staticPort}` });

    await page.addInitScript(() => {
      window.__events = [];
      addEventListener('DOMContentLoaded', () => {
        const el = document.getElementById('fc');
        for (const n of ['face-ready', 'face-error', 'face-result']) {
          el.addEventListener(n, (e) => window.__events.push({ type: n, detail: e.detail }));
        }
      });
    });

    await page.goto(`http://127.0.0.1:${staticPort}/page.html`);
    await page.evaluate(() => document.getElementById('fc').start?.());

    const outcome = await page.waitForFunction(
      () => (window.__events || []).find((e) => e.type === 'face-ready' || e.type === 'face-error'),
      null,
      { timeout: 20000 }
    ).then((h) => h.jsonValue());

    expect(outcome.type).toBe('face-ready');

    // The /pwned beacon was never hit (hostile glue executed beacon)
    expect(beacons).toHaveLength(0);

    // No request for any .mjs from the hostile origin
    const mjsRequests = hostileHits.filter((p) => /\.mjs($|\?)/i.test(p));
    expect(mjsRequests).toEqual([]);

    await page.evaluate(() => document.getElementById('fc')?.stop?.());
  });
});
