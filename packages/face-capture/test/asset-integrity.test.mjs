import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';

test('packed worker imports shipped model pins alongside patched CDN asset support',async()=>{
 const worker=await readFile(new URL('../dist/vendor-js/browser-face/worker.mjs',import.meta.url),'utf8');
 const pins=await readFile(new URL('../dist/vendor-js/browser-face/model-pins.mjs',import.meta.url),'utf8');
 assert.match(worker,/from ['"]\.\/model-pins\.mjs['"]/);
 assert.match(worker,/assets_url/);
 assert.match(pins,/487a139eb85ccd5ff30aef7d0add7f12d2bb7abb90c5f08a7f481b452a660e00/);
 const packed=JSON.parse(execFileSync('npm',['pack','--dry-run','--json','--ignore-scripts'],{cwd:new URL('..',import.meta.url),encoding:'utf8'}));
 const pack=Array.isArray(packed)?packed[0]:Object.values(packed)[0];
 for(const name of ['worker.mjs','model-pins.mjs'])assert.ok(pack.files.some(f=>f.path===`dist/vendor-js/browser-face/${name}`));
});

test('packed WASM pin matches the manifest and glue stays relative to the worker',async()=>{
 const {modelPins}=await import('../dist/vendor-js/browser-face/model-pins.mjs');
 const manifest=JSON.parse(await readFile(new URL('../../../src/face_inference/web/vendor/browser-face/manifest.json',import.meta.url)));
 const worker=await readFile(new URL('../dist/vendor-js/browser-face/worker.mjs',import.meta.url),'utf8');
 assert.equal(modelPins['ort-wasm-simd-threaded.wasm'],manifest.files['ort-wasm-simd-threaded.wasm'].sha256);
 assert.match(worker,/wasmPaths\s*=\s*\{mjs:\s*new URL\('\.\.\/vendor\/browser-face\/ort-wasm-simd-threaded\.mjs',\s*import\.meta\.url\)/);
 assert.match(worker,/wasmBinary\s*=\s*wasmBytes/);
});
