import { test, expect } from '@playwright/test';
import net from 'node:net';
import http from 'node:http';
import { spawn } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const pkgRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(pkgRoot, '../..');

// Helper to determine Python binary
function getPythonExecutable() {
  const venvPython = path.join(repoRoot, '.venv/bin/python');
  if (fs.existsSync(venvPython)) return venvPython;
  return 'python3';
}

// Find an available local port
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

// Detect optional synthetic camera fixture
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

test.describe('FaceCaptureElement Real-Browser E2E', () => {
  let staticServer;
  let staticPort;
  let pythonPort;
  let serverProc;
  let tempHome;
  let apiToken;

  test.beforeAll(async () => {
    staticPort = await getFreePort();
    pythonPort = await getFreePort();
    tempHome = fs.mkdtempSync(path.join(os.tmpdir(), 'face-e2e-home-'));
    const configPath = path.join(tempHome, 'config.json');
    const python = getPythonExecutable();

    // 1. Initialize standalone server config in temporary home
    const initProc = spawn(python, ['-m', 'face_inference', 'init', '--config', configPath], {
      cwd: repoRoot,
      env: { ...process.env, PYTHONPATH: path.join(repoRoot, 'src'), FACE_INFERENCE_HOME: tempHome },
    });
    await new Promise((resolve, reject) => {
      initProc.on('exit', (code) => {
        if (code === 0) resolve();
        else reject(new Error('face_inference init failed with code ' + code));
      });
    });

    // 2. Read config and update port
    const conf = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    conf.port = pythonPort;
    fs.writeFileSync(configPath, JSON.stringify(conf, null, 2), { mode: 0o600 });

    // 3. Obtain API token using CLI
    const tokenProc = spawn(python, ['-m', 'face_inference', 'token', '--config', configPath], {
      cwd: repoRoot,
      env: { ...process.env, PYTHONPATH: path.join(repoRoot, 'src'), FACE_INFERENCE_HOME: tempHome },
    });
    let tokenOutput = '';
    tokenProc.stdout.on('data', (chunk) => {
      tokenOutput += chunk.toString();
    });
    await new Promise((resolve, reject) => {
      tokenProc.on('exit', (code) => {
        if (code === 0) resolve();
        else reject(new Error('face_inference token failed with code ' + code));
      });
    });
    apiToken = tokenOutput.trim() || conf.api_token;

    // 4. Start Python server
    serverProc = spawn(python, ['-m', 'face_inference', 'serve', '--config', configPath, '--port', String(pythonPort)], {
      cwd: repoRoot,
      env: { ...process.env, PYTHONPATH: path.join(repoRoot, 'src'), FACE_INFERENCE_HOME: tempHome },
    });

    // Poll until healthy
    let serverReady = false;
    for (let i = 0; i < 40; i++) {
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

    // 5. Start static server with backend proxy for same-origin API & model assets
    const mimeTypes = {
      '.html': 'text/html; charset=utf-8',
      '.js': 'application/javascript; charset=utf-8',
      '.mjs': 'application/javascript; charset=utf-8',
      '.json': 'application/json; charset=utf-8',
      '.css': 'text/css; charset=utf-8',
      '.wasm': 'application/wasm',
      '.onnx': 'application/octet-stream',
    };

    staticServer = http.createServer((req, res) => {
      const url = new URL(req.url, `http://127.0.0.1:${staticPort}`);
      const pathname = decodeURIComponent(url.pathname);

      if (pathname === '/favicon.ico') {
        res.writeHead(204);
        res.end();
        return;
      }

      // Proxy API requests and served browser-face model assets to Python service
      if (pathname.startsWith('/api/') || pathname.startsWith('/browser-face/') || pathname.startsWith('/vendor/')) {
        const proxyReq = http.request({
          hostname: '127.0.0.1',
          port: pythonPort,
          path: req.url,
          method: req.method,
          headers: {
            ...req.headers,
            host: `127.0.0.1:${pythonPort}`,
            origin: `http://127.0.0.1:${pythonPort}`,
          },
        }, (proxyRes) => {
          res.writeHead(proxyRes.statusCode, proxyRes.headers);
          proxyRes.pipe(res);
        });
        proxyReq.on('error', (err) => {
          res.writeHead(502, { 'Content-Type': 'text/plain' });
          res.end('Proxy error: ' + err.message);
        });
        req.pipe(proxyReq);
        return;
      }

      // Serve face-capture static files
      let filePath;
      if (pathname === '/' || pathname === '/examples/embed.html') {
        filePath = path.join(pkgRoot, 'examples/embed.html');
      } else if (pathname.startsWith('/dist/')) {
        filePath = path.join(pkgRoot, pathname);
      } else {
        filePath = path.join(pkgRoot, pathname);
      }

      if (!fs.existsSync(filePath) || fs.statSync(filePath).isDirectory()) {
        res.writeHead(404, { 'Content-Type': 'text/plain' });
        res.end('Not found: ' + pathname);
        return;
      }

      const ext = path.extname(filePath).toLowerCase();
      res.writeHead(200, {
        'Content-Type': mimeTypes[ext] || 'application/octet-stream',
      });
      fs.createReadStream(filePath).pipe(res);
    });

    await new Promise((resolve) => staticServer.listen(staticPort, '127.0.0.1', resolve));
  });

  test.afterAll(async () => {
    if (serverProc) {
      serverProc.kill('SIGTERM');
    }
    if (staticServer) {
      await new Promise((resolve) => staticServer.close(resolve));
    }
    if (tempHome && fs.existsSync(tempHome)) {
      fs.rmSync(tempHome, { recursive: true, force: true });
    }
  });

  function getEmbedUrl(overrides = {}) {
    const params = new URLSearchParams({
      autostart: 'false',
      'api-url': `http://127.0.0.1:${staticPort}`,
      token: apiToken,
      collection: 'demo-collection',
      mode: 'enroll',
      'person-id': 'alice-synthetic-1',
      ...overrides,
    });
    return `http://127.0.0.1:${staticPort}/examples/embed.html?${params.toString()}`;
  }

  test('consent gate blocks start until checked in enroll mode', async ({ page }) => {
    await page.goto(getEmbedUrl({ mode: 'enroll' }));

    const component = page.locator('face-capture');
    await expect(component).toBeVisible();

    // Verify consent panel is displayed
    const consentPanel = component.locator('.consent-panel');
    await expect(consentPanel).toBeVisible();

    // Listen for face-error on element
    const errorPromise = page.evaluate(() => {
      return new Promise((resolve) => {
        const el = document.querySelector('face-capture');
        el.addEventListener('face-error', (e) => resolve(e.detail), { once: true });
      });
    });

    // Click Start without checking consent
    const startBtn = component.locator('.btn-start');
    await startBtn.click();

    const errorDetail = await errorPromise;
    expect(errorDetail.code).toBe('CONSENT_REQUIRED');
    expect(errorDetail.message).toMatch(/consent/i);

    // Verify status text shows the consent error
    const statusText = component.locator('.status-text');
    await expect(statusText).toContainText(/consent/i);

    // Start button remains enabled
    await expect(startBtn).toBeEnabled();
  });

  test('face-error fires with readable message when camera is denied', async ({ page }) => {
    await page.goto(getEmbedUrl({ mode: 'enroll' }));

    const component = page.locator('face-capture');
    await expect(component).toBeVisible();

    // Check the consent checkbox
    const consentCheckbox = component.locator('.consent-checkbox');
    await consentCheckbox.check();

    // Simulate camera permission denial in the page environment
    await page.evaluate(() => {
      navigator.mediaDevices.getUserMedia = async () => {
        throw Object.assign(new Error('Permission denied by user'), { name: 'NotAllowedError' });
      };
    });

    const errorPromise = page.evaluate(() => {
      return new Promise((resolve) => {
        const el = document.querySelector('face-capture');
        el.addEventListener('face-error', (e) => resolve(e.detail), { once: true });
      });
    });

    const startBtn = component.locator('.btn-start');
    await startBtn.click();

    const errorDetail = await errorPromise;
    expect(['CAMERA_DENIED', 'CAMERA_ERROR']).toContain(errorDetail.code);
    expect(errorDetail.message).toMatch(/camera|permission|allow camera/i);

    // Status text shows human readable error
    const statusText = component.locator('.status-text');
    await expect(statusText).toHaveClass(/error-text/);
    await expect(statusText).toContainText(/camera|permission|allow camera/i);
  });

  test('happy-path camera capture emits face-ready or face-result against synthetic camera', async ({ page }) => {
    await page.goto(getEmbedUrl({ mode: 'enroll' }));

    const component = page.locator('face-capture');
    await expect(component).toBeVisible();

    const consentCheckbox = component.locator('.consent-checkbox');
    await consentCheckbox.check();

    // Set up event listeners for happy-path outcome
    const eventPromise = page.evaluate(() => {
      return new Promise((resolve) => {
        const el = document.querySelector('face-capture');
        let done = false;

        const onReady = () => {
          if (!done) {
            done = true;
            cleanup();
            resolve({ type: 'face-ready' });
          }
        };

        const onResult = (e) => {
          if (!done) {
            done = true;
            cleanup();
            resolve({ type: 'face-result', detail: e.detail });
          }
        };

        const onError = (e) => {
          if (!done) {
            done = true;
            cleanup();
            resolve({ type: 'face-error', detail: e.detail });
          }
        };

        function cleanup() {
          el.removeEventListener('face-ready', onReady);
          el.removeEventListener('face-result', onResult);
          el.removeEventListener('face-error', onError);
        }

        el.addEventListener('face-ready', onReady);
        el.addEventListener('face-result', onResult);
        el.addEventListener('face-error', onError);
      });
    });

    const startBtn = component.locator('.btn-start');
    await startBtn.click();

    // Wait for face-ready or face-result (worker model initialization may take up to 20s in headless browser)
    const outcome = await eventPromise;
    expect(['face-ready', 'face-result']).toContain(outcome.type);

    if (outcome.type === 'face-result') {
      expect(outcome.detail).toBeDefined();
      expect(typeof outcome.detail.state).toBe('string');
    } else {
      expect(outcome.type).toBe('face-ready');
    }

    // Stop button is enabled while running
    const stopBtn = component.locator('.btn-stop');
    await expect(stopBtn).toBeEnabled();

    // Stop the component and ensure cleanup
    await stopBtn.click();
    await expect(startBtn).toBeEnabled();
    await expect(stopBtn).toBeDisabled();
  });
});
