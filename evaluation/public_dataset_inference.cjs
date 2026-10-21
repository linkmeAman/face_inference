// Evaluation-only localhost server; imports the production browser worker unchanged.
const fs=require('fs'),path=require('path'),http=require('http'),crypto=require('crypto');
const {chromium}=require('playwright');
async function main(){
 const folder=path.resolve(process.argv[2]||''),output=path.resolve(process.argv[3]||'');
 const root=path.resolve(__dirname,'..');
 if(folder.startsWith(root+path.sep)||output.startsWith(root+path.sep)||!process.argv[3])throw Error('Private external dataset and output required');
 const manifest=JSON.parse(fs.readFileSync(path.join(folder,'manifest.json')));
 const diagnostics=process.argv.includes('--development-diagnostics');
 const selected=diagnostics?manifest.images.filter(row=>row.partition==='development'):manifest.images;
 const diagnosticFolder=output+'.diagnostics';
 if(diagnostics)fs.mkdirSync(diagnosticFolder,{mode:0o700});
 const contract=JSON.parse(fs.readFileSync(path.join(root,'pf/js/browser-face/contract.json')));
 const assets=JSON.parse(fs.readFileSync(path.join(root,'pf/vendor/browser-face/manifest.json')));
 const allowedImages=new Map(manifest.images.map(r=>['/image/'+r.file,path.join(folder,r.file)]));
 for(const r of manifest.images)if(crypto.createHash('sha256').update(fs.readFileSync(path.join(folder,r.file))).digest('hex')!==r.sha256)throw Error('Image checksum mismatch');
 for(const name of ['yunet.onnx','sface.onnx']){const sha=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'pf/vendor/browser-face',name))).digest('hex');if(sha!==assets[name])throw Error('Model checksum not verified: '+name);}
 const server=http.createServer((req,res)=>{
  const url=new URL(req.url,'http://localhost').pathname;
  if(url==='/'){res.setHeader('Content-Type','text/html');res.end('<!doctype html><title>Offline face evaluation</title>');return;}
  const file=allowedImages.get(url)||(url.startsWith('/pf/js/browser-face/')||url.startsWith('/pf/vendor/browser-face/')?path.join(root,url):null);
  if(!file||!file.startsWith(root+'/pf/')&&!allowedImages.has(url)||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
  res.setHeader('Content-Type',file.endsWith('.mjs')?'text/javascript':file.endsWith('.wasm')?'application/wasm':file.endsWith('.json')?'application/json':file.endsWith('.png')?'image/png':'application/octet-stream');fs.createReadStream(file).pipe(res);
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
 try{
  browser=await chromium.launch({headless:true});const page=await browser.newPage();await page.goto('http://127.0.0.1:'+server.address().port);
  await page.evaluate(async ({contract,diagnostics})=>{
   const {alignRGB,similarityTransform,quality}=await import('/pf/js/browser-face/core.mjs');
   window.diagnoseImage=async(url,landmarks)=>{
    const bitmap=await createImageBitmap(await(await fetch(url)).blob());
    const canvas=new OffscreenCanvas(contract.detector_size,contract.detector_size),ctx=canvas.getContext('2d',{willReadFrequently:true});
    const scale=Math.min(canvas.width/bitmap.width,canvas.height/bitmap.height);ctx.fillStyle='black';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(bitmap,0,0,bitmap.width*scale,bitmap.height*scale);bitmap.close();
    const rgba=ctx.getImageData(0,0,canvas.width,canvas.height).data,transform=similarityTransform(landmarks,contract.target_landmarks);
    const rgb=alignRGB(rgba,canvas.width,canvas.height,transform,112),metrics=quality(rgb,contract);
    const crop=new OffscreenCanvas(112,112),cctx=crop.getContext('2d'),pixels=cctx.createImageData(112,112);
    for(let i=0;i<112*112;i++){for(let k=0;k<3;k++)pixels.data[4*i+k]=rgb[k*112*112+i];pixels.data[4*i+3]=255;}cctx.putImageData(pixels,0,0);
    const encode=async canvas=>{const bytes=new Uint8Array(await(await canvas.convertToBlob({type:'image/png'})).arrayBuffer());let text='';for(const byte of bytes)text+=String.fromCharCode(byte);return btoa(text);};
    return {raster:await encode(canvas),aligned:await encode(crop),transform,quality:metrics};
   };
   window.evalWorker=new Worker('/pf/js/browser-face/worker.mjs',{type:'module'});
   await new Promise((resolve,reject)=>{evalWorker.onerror=e=>reject(Error(e.message));evalWorker.onmessage=({data})=>data.type==='ready'?resolve():data.type==='error'?reject(Error(data.message)):null;evalWorker.postMessage({type:'init',config:{...contract,diagnostics}});});
   window.encodeImage=async url=>{const bitmap=await createImageBitmap(await(await fetch(url)).blob());return await new Promise((resolve,reject)=>{evalWorker.onmessage=({data})=>data.type==='observation'?resolve(data):data.type==='error'?reject(Error(data.message)):null;evalWorker.postMessage({type:'frame',bitmap,epoch:1},[bitmap]);});};
  },{contract,diagnostics});
  const results=[];
  for(const row of selected){const result=await page.evaluate(url=>encodeImage(url),'/image/'+row.file);
   if(diagnostics&&result.landmarks){const diagnostic=await page.evaluate(({url,landmarks})=>diagnoseImage(url,landmarks),{url:'/image/'+row.file,landmarks:result.landmarks});
    fs.writeFileSync(path.join(diagnosticFolder,row.file+'.raster.png'),Buffer.from(diagnostic.raster,'base64'),{mode:0o600});fs.writeFileSync(path.join(diagnosticFolder,row.file+'.aligned.png'),Buffer.from(diagnostic.aligned,'base64'),{mode:0o600});
    result.alignment_transform=diagnostic.transform;result.quality_metrics=diagnostic.quality;}
   results.push({...row,...result});console.log('Inferred '+results.length+'/'+selected.length);}
  // Identical input repeat checks numerical determinism without inventing trials.
  const first=results.find(r=>r.embedding);const repeat=first?await page.evaluate(url=>encodeImage(url),'/image/'+first.file):null;
  const repeatCosine=repeat?.embedding?first.embedding.reduce((s,v,i)=>s+v*repeat.embedding[i],0):null;
  await page.evaluate(()=>evalWorker.terminate());
  fs.writeFileSync(output,JSON.stringify({dataset:manifest.dataset,license:manifest.license,revision:manifest.revision,attribution:manifest.attribution,source:'public-synthetic-research',production_approved:false,contract,asset_manifest:assets,repeat_cosine:repeatCosine,images:results},null,2),{mode:0o600,flag:'wx'});
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
}
main().catch(e=>{console.error(e.message);process.exitCode=1;});
