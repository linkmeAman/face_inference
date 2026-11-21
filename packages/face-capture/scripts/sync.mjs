import { readdir, readFile, writeFile, mkdir, copyFile } from 'node:fs/promises';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const pkgRoot = resolve(__dirname, '..');
const srcWebDir = resolve(pkgRoot, '../../src/face_inference/web');
const distDir = resolve(pkgRoot, 'dist');
const distVendorDir = resolve(distDir, 'vendor-js');
const distBrowserFaceDir = resolve(distVendorDir, 'browser-face');

async function build() {
  console.log('Building face-capture package...');

  // Ensure output directories exist
  await mkdir(distBrowserFaceDir, { recursive: true });

  // 1. Copy client.mjs
  const clientSrc = resolve(srcWebDir, 'client.mjs');
  const clientDest = resolve(distVendorDir, 'client.mjs');
  await copyFile(clientSrc, clientDest);
  console.log(`Copied client.mjs -> dist/vendor-js/client.mjs`);

  // 2. Copy browser-face/*.mjs and contract.json
  const browserFaceSrcDir = resolve(srcWebDir, 'browser-face');
  const browserFaceFiles = await readdir(browserFaceSrcDir);
  let copiedCount = 0;
  for (const file of browserFaceFiles) {
    if (file.endsWith('.mjs') || file.endsWith('.json')) {
      await copyFile(resolve(browserFaceSrcDir, file), resolve(distBrowserFaceDir, file));
      copiedCount++;
    }
  }
  console.log(`Copied ${copiedCount} files from browser-face -> dist/vendor-js/browser-face/`);

  // 3. Copy src/config.js to dist/config.js
  const configSrc = resolve(pkgRoot, 'src/config.js');
  const configDest = resolve(distDir, 'config.js');
  await copyFile(configSrc, configDest);
  console.log(`Copied config.js -> dist/config.js`);

  // 4. Build dist/face-capture.js with relative vendor imports
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
