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
