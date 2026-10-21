const {test,expect}=require('@playwright/test');
const {sleep,setup,rows,open}=require('./attendance-ui-helpers.cjs');
// Real attendance.php, real AutomaticCapture and real worker. The fake camera plays a
// video whose LEFT half is white and RIGHT half is black, so which way the preview is
// flipped is measured from rendered pixels, not inferred from CSS. getUserMedia is
// wrapped only to report a chosen facingMode/label/device list (Chromium's file camera
// reports none), exactly what a phone, iPhone or laptop would report.
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-asym.y4m']}});

const ANDROID='Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const IPHONE='Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

// report: 'user' | 'environment' | 'asked' (echo the side requested) | null (omit facingMode, like a desktop webcam)
async function installCamera(page,cfg){
 await page.addInitScript(cfg=>{
  const md=navigator.mediaDevices,get=md.getUserMedia.bind(md);
  window.__camRequests=[];window.__tracks=[];window.__bitmaps=[];
  md.getUserMedia=async constraints=>{
   window.__camRequests.push(JSON.parse(JSON.stringify(constraints)));
   const stream=await get(constraints),track=stream.getVideoTracks()[0],real=track.getSettings.bind(track);
   const asked=constraints.video?.facingMode?.ideal || constraints.video?.facingMode?.exact || null;
   const facing=cfg.report==='asked'?(asked || 'user'):cfg.report;
   track.getSettings=()=>{const s=real();if(facing)s.facingMode=facing;else delete s.facingMode;s.deviceId=cfg.deviceIdFor?cfg.deviceIdFor(facing):(facing || 'device0');return s;};
   const label=cfg.labels?cfg.labels[facing || 'none']:cfg.label;if(label)Object.defineProperty(track,'label',{configurable:true,get:()=>label});
   if(cfg.noCapabilities)track.getCapabilities=()=>({});
   window.__tracks.push(track);return stream;
  };
  md.enumerateDevices=async()=>(cfg.devices || ['device0']).map(id=>({kind:'videoinput',deviceId:id,label:''}));
  // What the model receives: createImageBitmap(video) ignores CSS transforms. Record where the white half is.
  const orig=window.createImageBitmap.bind(window);
  window.createImageBitmap=async(source,...rest)=>{
   const bitmap=await orig(source,...rest);
   if(source instanceof HTMLVideoElement && window.__bitmaps.length<8){
    const c=document.createElement('canvas');c.width=bitmap.width;c.height=bitmap.height;const x=c.getContext('2d');x.drawImage(bitmap,0,0);
    const q=Math.floor(c.width/4),row=Math.floor(c.height/2),mean=d=>{let t=0;for(let i=0;i<d.length;i+=4)t+=d[i];return t/(d.length/4);};
    window.__bitmaps.push({left:mean(x.getImageData(0,row,q,1).data),right:mean(x.getImageData(c.width-q,row,q,1).data)});
   }
   return bitmap;
  };
 },cfg);
}

// Mean brightness of the left and right quarter of what is painted for the <video> (overlay hidden, not removed).
async function previewBrightness(page){
 await page.addStyleTag({content:'#automaticFaceOverlay{opacity:0!important}'});
 const png=(await page.locator('#automaticFaceVideo').screenshot()).toString('base64');
 return page.evaluate(async b64=>{
  const image=new Image();image.src='data:image/png;base64,'+b64;await image.decode();
  const c=document.createElement('canvas');c.width=image.width;c.height=image.height;const x=c.getContext('2d');x.drawImage(image,0,0);
  const q=Math.floor(c.width/4),row=Math.floor(c.height/2),mean=d=>{let t=0;for(let i=0;i<d.length;i+=4)t+=(d[i]+d[i+1]+d[i+2])/3;return t/(d.length/4);};
  return {left:mean(x.getImageData(0,row,q,1).data),right:mean(x.getImageData(c.width-q,row,q,1).data)};
 },png);
}
const cameraState=page=>page.evaluate(()=>{
 const video=document.getElementById('automaticFaceVideo'),chain=[];
 for(let e=video;e && e!==document.body;e=e.parentElement)chain.push(getComputedStyle(e).transform);
 return {transform:video.style.transform,computed:getComputedStyle(video).transform,facing:video.getAttribute('data-camera-facing'),mirrored:video.getAttribute('data-preview-mirrored'),ancestorTransforms:chain.slice(1).filter(t=>t!=='none'),requests:window.__camRequests.map(r=>r.video.facingMode?.ideal || null),live:window.__tracks.filter(t=>t.readyState==='live').length,ended:window.__tracks.filter(t=>t.readyState==='ended').length};
});
const waitCamera=page=>expect.poll(()=>page.evaluate(()=>{const s=angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope();return !!(s.automaticFace && s.automaticFace.busy && s.automaticFace.guide);}),{timeout:25000}).toBe(true);
async function openCamera(page,mode){
 await open(page,'16:00:00');await expect(rows(page)).toHaveCount(2,{timeout:15000});
 if(mode==='scan'){await page.getByRole('button',{name:/Scan Arrivals$/}).click();}
 else{const first=rows(page).filter({hasText:'Public B'});await expect(first.getByRole('button',{name:/^(Enroll|Update) Face$/})).toBeVisible({timeout:15000});await first.getByRole('button',{name:/^(Enroll|Update) Face$/}).click();}
 await waitCamera(page);await sleep(700);
}
const WHITE_LEFT=b=>{expect(b.left).toBeGreaterThan(180);expect(b.right).toBeLessThan(70);};
const WHITE_RIGHT=b=>{expect(b.right).toBeGreaterThan(180);expect(b.left).toBeLessThan(70);};

for(const mode of ['scan','enroll']){
 test(`${mode}: FRONT camera shows a mirrored preview while the model still gets the unflipped frame`,async({page,context})=>{
  await installCamera(page,{report:'user'});await setup(page,context,{clear:false});
  await openCamera(page,mode);
  const state=await cameraState(page);expect(state).toMatchObject({transform:'scaleX(-1)',computed:'matrix(-1, 0, 0, 1, 0, 0)',facing:'user',mirrored:'true',ancestorTransforms:[]});
  WHITE_RIGHT(await previewBrightness(page));
  await expect.poll(()=>page.evaluate(()=>window.__bitmaps.length),{timeout:20000}).toBeGreaterThan(0);
  for(const b of await page.evaluate(()=>window.__bitmaps))WHITE_LEFT(b);
 });
 test(`${mode}: REAR camera is natural (not mirrored) and the model frame is identical`,async({page,context})=>{
  await installCamera(page,{report:'environment'});await setup(page,context,{clear:false});
  await openCamera(page,mode);
  const state=await cameraState(page);expect(state).toMatchObject({transform:'none',computed:'none',facing:'environment',mirrored:'false',ancestorTransforms:[]});
  WHITE_LEFT(await previewBrightness(page));
  await expect.poll(()=>page.evaluate(()=>window.__bitmaps.length),{timeout:20000}).toBeGreaterThan(0);
  for(const b of await page.evaluate(()=>window.__bitmaps))WHITE_LEFT(b);
 });
}

test('desktop/laptop webcam that reports no facingMode is treated as front and mirrored',async({page,context})=>{
 await installCamera(page,{report:null,devices:['device0'],noCapabilities:true});await setup(page,context,{clear:false});
 await openCamera(page,'scan');
 expect(await cameraState(page)).toMatchObject({transform:'scaleX(-1)',mirrored:'true'});WHITE_RIGHT(await previewBrightness(page));
 expect(await page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.diagnostics?.facing_source ?? 'n/a')).not.toBe('settings');
});

test('manual switching rear -> front -> rear flips the preview each time, releases the old track and never stacks transforms',async({page,context})=>{
 await installCamera(page,{report:'asked',devices:['environment','user'],deviceIdFor:f=>f});await setup(page,context,{clear:false});
 await openCamera(page,'scan');
 let state=await cameraState(page);expect(state).toMatchObject({transform:'none',mirrored:'false',live:1});WHITE_LEFT(await previewBrightness(page));
 const switchButton=page.locator('.browser-face-panel').getByRole('button',{name:/Switch Camera/});await expect(switchButton).toBeVisible();
 await switchButton.click();await expect.poll(async()=>(await cameraState(page)).mirrored,{timeout:20000}).toBe('true');await waitCamera(page);await sleep(700);
 state=await cameraState(page);expect(state).toMatchObject({transform:'scaleX(-1)',computed:'matrix(-1, 0, 0, 1, 0, 0)',facing:'user',live:1,ended:1,ancestorTransforms:[]});expect(state.requests).toEqual(['environment','user']);
 WHITE_RIGHT(await previewBrightness(page));
 await expect.poll(()=>page.evaluate(()=>window.__bitmaps.filter(b=>b.left>b.right).length),{timeout:20000}).toBeGreaterThan(0);
 expect(await page.evaluate(()=>window.__bitmaps.every(b=>b.left>b.right))).toBe(true); // model frames never flipped
 await switchButton.click();await expect.poll(async()=>(await cameraState(page)).mirrored,{timeout:20000}).toBe('false');await waitCamera(page);await sleep(700);
 state=await cameraState(page);expect(state).toMatchObject({transform:'none',computed:'none',facing:'environment',live:1,ended:2});WHITE_LEFT(await previewBrightness(page));
});

test('Android Chrome without facingMode metadata: the requested side decides, the new side after switching',async({browser})=>{
 const context=await browser.newContext({userAgent:ANDROID,viewport:{width:412,height:915},isMobile:true,hasTouch:true,deviceScaleFactor:2,permissions:['camera']});const page=await context.newPage();
 await installCamera(page,{report:null,devices:['cam0','cam1'],noCapabilities:true,deviceIdFor:()=>'cam0'});await setup(page,context,{clear:false});
 await openCamera(page,'scan');
 let state=await cameraState(page);expect(state).toMatchObject({transform:'none',facing:'environment'});WHITE_LEFT(await previewBrightness(page));
 await page.locator('.browser-face-panel').getByRole('button',{name:/Switch Camera/}).click();
 await expect.poll(async()=>(await cameraState(page)).mirrored,{timeout:20000}).toBe('true');await waitCamera(page);await sleep(700);
 state=await cameraState(page);expect(state).toMatchObject({transform:'scaleX(-1)',facing:'user',ended:1});WHITE_RIGHT(await previewBrightness(page));
 await context.close();
});

test('iPhone Safari style: no settings, labelled devices decide the mirror',async({browser})=>{
 const context=await browser.newContext({userAgent:IPHONE,viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:3,permissions:['camera']});const page=await context.newPage();
 await installCamera(page,{report:null,devices:['front','back'],noCapabilities:true,labels:{none:'Back Camera'},deviceIdFor:()=>'back'});await setup(page,context,{clear:false});
 await openCamera(page,'scan');
 expect(await cameraState(page)).toMatchObject({transform:'none',facing:'environment'});WHITE_LEFT(await previewBrightness(page));
 await context.close();
 const front=await browser.newContext({userAgent:IPHONE,viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:3,permissions:['camera']});const page2=await front.newPage();
 await installCamera(page2,{report:null,devices:['front','back'],noCapabilities:true,labels:{none:'Front Camera'},deviceIdFor:()=>'front'});await setup(page2,front,{clear:false});
 await openCamera(page2,'enroll');
 expect(await cameraState(page2)).toMatchObject({transform:'scaleX(-1)',facing:'user'});WHITE_RIGHT(await previewBrightness(page2));
 await front.close();
});

for(const [label,mirrored] of [['front',true],['rear',false]])
 test(`circular guide stays centered and symmetric on desktop and phone (${label}, object-fit cover)`,async({browser})=>{
  for(const viewport of [{width:1366,height:900},{width:390,height:844,isMobile:true,hasTouch:true,deviceScaleFactor:2}]){
   const context=await browser.newContext({...viewport,permissions:['camera']});const page=await context.newPage();
   await installCamera(page,{report:mirrored?'user':'environment'});await setup(page,context,{clear:false});
   await openCamera(page,'scan');
   const geometry=await page.evaluate(()=>{
    const box=document.querySelector('.browser-face-camera').getBoundingClientRect(),canvas=document.getElementById('automaticFaceOverlay'),x=canvas.getContext('2d');
    const s=angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope(),g=s.automaticFace.guide;
    const alpha=(px,py)=>x.getImageData(Math.round(px),Math.round(py),1,1).data[3];
    return {boxW:box.width,boxH:box.height,guide:g,canvasW:canvas.width,canvasH:canvas.height,center:alpha(g.x,g.y),left:alpha(g.x-g.radius*0.8,g.y),right:alpha(g.x+g.radius*0.8,g.y),above:alpha(g.x,g.y-g.radius*0.8),below:alpha(g.x,g.y+g.radius*0.8),outsideLeft:alpha(g.x-g.radius*1.1,g.y),outsideRight:alpha(g.x+g.radius*1.1,g.y),corner:alpha(2,2)};
   });
   expect(Math.abs(geometry.guide.x-geometry.canvasW/2)).toBeLessThan(1.5);expect(Math.abs(geometry.guide.y-geometry.canvasH/2)).toBeLessThan(1.5);
   expect(Math.abs(geometry.canvasW-geometry.boxW)).toBeLessThan(1.5);
   for(const key of ['center','left','right','above','below'])expect(geometry[key],key).toBe(0);
   for(const key of ['outsideLeft','outsideRight','corner'])expect(geometry[key],key).toBe(255);
   expect((await cameraState(page)).mirrored).toBe(String(mirrored));
   await context.close();
  }
 });

test('mirroring flips only the video: buttons, status text and the progress label are not mirrored',async({page,context})=>{
 await installCamera(page,{report:'user'});await setup(page,context,{clear:false});
 await openCamera(page,'scan');
 const transforms=await page.evaluate(()=>['.browser-face-dialog','.browser-face-header','.browser-face-feedback','.browser-face-actions','.browser-face-actions .btn','#automaticFaceOverlay','.browser-face-camera'].map(sel=>[sel,getComputedStyle(document.querySelector(sel)).transform]));
 for(const [selector,transform] of transforms)expect(transform,selector).toBe('none');
 expect(await page.evaluate(()=>getComputedStyle(document.getElementById('automaticFaceVideo')).transform)).toBe('matrix(-1, 0, 0, 1, 0, 0)');
});
