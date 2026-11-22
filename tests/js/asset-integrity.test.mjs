import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {webcrypto} from 'node:crypto';
import {pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
import {AutomaticCapture} from '../../src/face_inference/web/browser-face/capture.mjs';

const root=resolve('src/face_inference/web');
const manifest=JSON.parse(await readFile(`${root}/vendor/browser-face/manifest.json`));
const files=Object.fromEntries(await Promise.all(['yunet.onnx','sface.onnx','ort-wasm-simd-threaded.wasm'].map(async name=>[name,await readFile(`${root}/vendor/browser-face/${name}`)])));

test('prepared pins match the registered manifest and verified bytes reach inference',async()=>{
 const {modelPins,verifyModel}=await import('../../src/face_inference/web/browser-face/model-pins.mjs');
 for(const name of Object.keys(files)){
  assert.equal(modelPins[name],manifest.files[name].sha256);
  const old=globalThis.fetch;
  try{globalThis.fetch=async()=>new Response(files[name]);for(const base of ['https://app.example/vendor/browser-face/','https://cdn.example/'])assert.deepEqual(Buffer.from(await verifyModel(base+name,name)),files[name]);}finally{globalThis.fetch=old;}
 }
});

test('rejects tampered bytes, unknown pins, failed fetch and unavailable crypto',async()=>{
 const {verifyModel}=await import('../../src/face_inference/web/browser-face/model-pins.mjs');
 const fetchDescriptor=Object.getOwnPropertyDescriptor(globalThis,'fetch'),cryptoDescriptor=Object.getOwnPropertyDescriptor(globalThis,'crypto');
 try{
  globalThis.fetch=async()=>new Response('tampered');
  await assert.rejects(verifyModel('https://evil.example/?secret=123','yunet.onnx'),/integrity/i);
  await assert.rejects(verifyModel('https://evil.example/','unknown'),/integrity/i);
  globalThis.fetch=async()=>new Response('',{status:503});
  await assert.rejects(verifyModel('https://evil.example/','yunet.onnx'),/integrity/i);
  globalThis.fetch=async()=>{throw Error('secret URL');};
  await assert.rejects(verifyModel('https://evil.example/','yunet.onnx'),/integrity/i);
  Object.defineProperty(globalThis,'crypto',{configurable:true,value:undefined});
  await assert.rejects(verifyModel('https://evil.example/','yunet.onnx'),/integrity/i);
 }finally{Object.defineProperty(globalThis,'fetch',fetchDescriptor);Object.defineProperty(globalThis,'crypto',cryptoDescriptor);}
});

test('worker verifies both models before creating sessions and sanitizes integrity errors',async()=>{
 const original=await readFile(`${root}/browser-face/worker.mjs`,'utf8');
 const source=original.replace(/import \* as ort from [^;]+;/,"const ort=globalThis.__ort;")
  .replace(/import \{normalize[^;]+;/,"const normalize=()=>{}, similarityTransform=()=>{}, alignRGB=()=>{}, quality=()=>{}, decodeYuNet=()=>{}, selectGuideFace=()=>{};")
  .replace("'./model-pins.mjs'",JSON.stringify(pathToFileURL(`${root}/browser-face/model-pins.mjs`).href))
  .replace("new URL('../'+config.assets_base, import.meta.url).href", "'https://evil.example/secret/'")
  .replace("new URL('../vendor/browser-face/ort-wasm-simd-threaded.mjs', import.meta.url).href", JSON.stringify(pathToFileURL(`${root}/vendor/browser-face/ort-wasm-simd-threaded.mjs`).href));
 const saved=Object.fromEntries(['self','fetch','__ort','OffscreenCanvas','crypto'].map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)]));
 const sessions=[],messages=[];
 try{
  Object.defineProperty(globalThis,'crypto',{configurable:true,value:webcrypto});
  globalThis.self={postMessage:m=>messages.push(m)};
  globalThis.__ort={env:{wasm:{}},InferenceSession:{create:async bytes=>{sessions.push(bytes);return {};}}};
  globalThis.OffscreenCanvas=class{getContext(){return {};}};
  globalThis.fetch=async url=>new Response(url.includes('sface')?'tampered':files[url.includes('.wasm')?'ort-wasm-simd-threaded.wasm':'yunet.onnx']);
  await import(`data:text/javascript,${encodeURIComponent(source)}`);
  await self.onmessage({data:{type:'init',config:{assets_base:'vendor/browser-face/',detector_file:'yunet.onnx',recognizer_file:'sface.onnx'}}});
  assert.equal(sessions.length,0);
  assert.equal(messages[0].code,'MODEL_INTEGRITY');
  assert.doesNotMatch(JSON.stringify(messages),/evil|secret/);
  for (const failure of ['tampered', 'fetch', 'crypto']) {
   messages.length=0;
   globalThis.fetch=async url=>url.includes('.wasm') ? (failure==='fetch' ? new Response('',{status:503}) : new Response('tampered')) : new Response(files[url.includes('sface')?'sface.onnx':'yunet.onnx']);
   if(failure==='crypto') Object.defineProperty(globalThis,'crypto',{configurable:true,value:undefined});
   await self.onmessage({data:{type:'init',config:{assets_base:'vendor/browser-face/',detector_file:'yunet.onnx',recognizer_file:'sface.onnx'}}});
   assert.equal(sessions.length,0); assert.equal(messages[0].code,'MODEL_INTEGRITY');
   assert.doesNotMatch(JSON.stringify(messages),/evil|secret/);
   Object.defineProperty(globalThis,'crypto',{configurable:true,value:webcrypto});
  }
  globalThis.fetch=async url=>new Response(files[url.includes('sface')?'sface.onnx':url.includes('.wasm')?'ort-wasm-simd-threaded.wasm':'yunet.onnx']);
  await self.onmessage({data:{type:'init',config:{assets_base:'vendor/browser-face/',detector_file:'yunet.onnx',recognizer_file:'sface.onnx',detector_size:1,input_size:1}}});
  assert.equal(messages.at(-1).type,'ready');
  assert.deepEqual(Buffer.from(__ort.env.wasm.wasmBinary),files['ort-wasm-simd-threaded.wasm']);
  assert.match(__ort.env.wasm.wasmPaths.mjs,/vendor\/browser-face\/ort-wasm-simd-threaded\.mjs$/);
  assert.deepEqual(sessions.map(bytes=>Buffer.from(bytes)),[files['yunet.onnx'],files['sface.onnx']]);
 }finally{for(const [k,d] of Object.entries(saved))if(d)Object.defineProperty(globalThis,k,d);else delete globalThis[k];}
});


test('capture reports integrity failures while transient worker errors recover',()=>{
 const descriptor=Object.getOwnPropertyDescriptor(globalThis,'Worker');
 try{
  globalThis.Worker=class{postMessage(){} terminate(){}};
  const capture=new AutomaticCapture({}, {}, {}, {}), failures=[], recoveries=[];
  capture.active=true;capture.health={};capture.fail=message=>failures.push(message);capture.recover=component=>recoveries.push(component);
  capture.createWorker();
  capture.worker.onmessage({data:{type:'error',code:'MODEL_INTEGRITY',message:'secret'}});
  assert.match(failures[0],/integrity/i);assert.doesNotMatch(failures[0],/secret/);
  capture.worker.onmessage({data:{type:'error'}});
  assert.deepEqual(recoveries,['worker']);
 }finally{if(descriptor)Object.defineProperty(globalThis,'Worker',descriptor);else delete globalThis.Worker;}
});

test('WASM pin verifies registered bytes before ORT initialization',async()=>{
 const {modelPins,verifyModel}=await import('../../src/face_inference/web/browser-face/model-pins.mjs');
 const name='ort-wasm-simd-threaded.wasm', bytes=await readFile(`${root}/vendor/browser-face/${name}`);
 assert.equal(modelPins[name],manifest.files[name].sha256);
 const old=globalThis.fetch;
 try{
  globalThis.fetch=async()=>new Response(bytes);
  assert.deepEqual(Buffer.from(await verifyModel('https://cdn.example/'+name,name)),bytes);
  globalThis.fetch=async()=>new Response('tampered');
  await assert.rejects(verifyModel('https://cdn.example/'+name,name),/integrity/i);
 }finally{globalThis.fetch=old;}
});
