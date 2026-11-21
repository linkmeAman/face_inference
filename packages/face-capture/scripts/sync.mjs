import { readFile, writeFile, mkdir, copyFile, rm } from 'node:fs/promises';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const pkgRoot = resolve(__dirname, '..');
const srcWebDir = resolve(pkgRoot, '../../src/face_inference/web');
const distDir = resolve(pkgRoot, 'dist');
const distVendorDir = resolve(distDir, 'vendor-js');
const distBrowserFaceDir = resolve(distVendorDir, 'browser-face');
const distVendorVendorDir = resolve(distVendorDir, 'vendor/browser-face');

async function build() {
  console.log('Building face-capture package...');

  // Ensure fresh output directories
  await rm(distDir, { recursive: true, force: true });
  await mkdir(distBrowserFaceDir, { recursive: true });
  await mkdir(distVendorVendorDir, { recursive: true });

  // 1. Copy client.mjs
  const clientSrc = resolve(srcWebDir, 'client.mjs');
  const clientDest = resolve(distVendorDir, 'client.mjs');
  await copyFile(clientSrc, clientDest);
  console.log(`Copied client.mjs -> dist/vendor-js/client.mjs`);

  // 2. Copy live browser-face/*.mjs and contract.json
  const browserFaceFiles = [
    'capture.mjs',
    'continuity.mjs',
    'contract.json',
    'core.mjs',
    'facing.mjs',
  ];
  const browserFaceSrcDir = resolve(srcWebDir, 'browser-face');
  let copiedCount = 0;
  for (const file of browserFaceFiles) {
    await copyFile(resolve(browserFaceSrcDir, file), resolve(distBrowserFaceDir, file));
    copiedCount++;
  }

  // Copy worker.mjs with configured asset origin support
  let workerCode = await readFile(resolve(browserFaceSrcDir, 'worker.mjs'), 'utf8');
  workerCode = workerCode.replace(
    "const assets = new URL('../'+config.assets_base, import.meta.url).href;",
    "const assets = config.assets_url || (config.assets_base && /^[a-z]+:\\/\\//i.test(config.assets_base) ? config.assets_base : new URL('../'+config.assets_base, import.meta.url).href);"
  );
  await writeFile(resolve(distBrowserFaceDir, 'worker.mjs'), workerCode, 'utf8');
  copiedCount++;
  console.log(`Copied ${copiedCount} files from browser-face -> dist/vendor-js/browser-face/`);

  // 3. Copy runtime ORT vendor modules (scripts only, no large wasm/onnx binaries)
  const vendorSrcDir = resolve(srcWebDir, 'vendor/browser-face');
  const vendorFiles = [
    'ort.wasm.min.mjs',
    'ort-wasm-simd-threaded.mjs',
    'ORT-LICENSE',
  ];
  let vendorCopied = 0;
  for (const file of vendorFiles) {
    const srcFile = resolve(vendorSrcDir, file);
    const destFile = resolve(distVendorVendorDir, file);
    await copyFile(srcFile, destFile);
    vendorCopied++;
  }
  console.log(`Copied ${vendorCopied} runtime vendor modules -> dist/vendor-js/vendor/browser-face/`);

  // 4. Copy src/config.js to dist/config.js
  const configSrc = resolve(pkgRoot, 'src/config.js');
  const configDest = resolve(distDir, 'config.js');
  await copyFile(configSrc, configDest);
  console.log(`Copied config.js -> dist/config.js`);

  // 5. Build dist/face-capture.js with relative vendor imports
  const elementSrc = resolve(pkgRoot, 'src/face-capture.js');
  let elementCode = await readFile(elementSrc, 'utf8');

  // Replace relative paths from src/ (../dist/vendor-js/) to dist/ (./vendor-js/)
  elementCode = elementCode.replaceAll('../dist/vendor-js/', './vendor-js/');

  const elementDest = resolve(distDir, 'face-capture.js');
  await writeFile(elementDest, elementCode, 'utf8');
  console.log(`Generated dist/face-capture.js (ES module)`);

  console.log('Build complete successfully.');
}

build().catch((err) => {
  console.error('Build failed:', err);
  process.exit(1);
});
