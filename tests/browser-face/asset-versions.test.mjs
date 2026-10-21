import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,readdirSync} from 'node:fs';
// A module graph served with mismatched ?v= strings makes an old module and a new one run together
// (or an old one stay cached). Every reference to a face module must carry one agreed version, and
// every module reachable from attendance.mjs must be in the page's modulepreload list.
const root=new URL('../../',import.meta.url);
const read=path=>readFileSync(new URL(path,root),'utf8');
const dir='pf/js/browser-face/';
const importRe=/(?:from\s*|import\()\s*['"]\.\/([\w-]+\.mjs)(?:\?v=([\w.-]+))?['"]/g;
function imports(file){return [...read(dir+file).matchAll(importRe)].map(m=>({from:file,module:m[1],version:m[2] || ''}));}
const graph=[];const seen=new Set();
(function walk(file){if(seen.has(file))return;seen.add(file);for(const ref of imports(file)){graph.push(ref);walk(ref.module);}})('attendance.mjs');
const page=read('pf/attendance.php');
const preload=Object.fromEntries([...page.match(/foreach\(\[([^\]]+)\] as \$faceModule\)/)[1].matchAll(/'([\w-]+\.mjs)\?v=([\w.-]+)'/g)].map(m=>[m[1],m[2]]));
const controller=read('pf/js/controllers/batchAttendanceController.js');
const entry=controller.match(/js\/browser-face\/attendance\.mjs\?v=([\w.-]+)/)[1];
test('every importer of a module uses the same version, and none is unversioned',()=>{
 const byModule=new Map();
 for(const ref of graph){assert.notEqual(ref.version,'',`${ref.from} imports ${ref.module} without a version`);(byModule.get(ref.module) || byModule.set(ref.module,new Set()).get(ref.module)).add(ref.version);}
 for(const [module,versions] of byModule)assert.equal(versions.size,1,`${module} is imported with versions ${[...versions]}`);
});
test('the page preloads exactly the versioned module graph the controller will import',()=>{
 const wanted={'attendance.mjs':entry};for(const ref of graph)wanted[ref.module]=ref.version;
 assert.deepEqual(Object.keys(preload).sort(),Object.keys(wanted).sort());
 for(const [module,version] of Object.entries(wanted))assert.equal(preload[module],version,`${module} preload version`);
});
test('the release label in PHP matches the current module version family',()=>{
 const label=read('controllers/browserFace.php').match(/'release_version'\s*=>\s*'([\w.-]+)'/)[1];
 assert.equal(read('classes/services/BrowserFaceAudit.php').includes(`'release'=>'${label}'`),true);
 assert.equal(entry,label,'entry module version must be bumped with every release');
});
test('the worker and model contract files are not versioned away from their importers',()=>{
 assert.ok(readdirSync(new URL(dir,root)).includes('worker.mjs'));
 assert.match(read(dir+'capture.mjs'),/worker\.mjs\?v=[\w.-]+/);
});
