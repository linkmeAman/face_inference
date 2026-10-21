const {test,expect}=require('@playwright/test');
const fs=require('fs');
test('real detector excludes outside faces before SFace and preserves accepted embeddings',async({page})=>{
 await page.route('**/guide-fixture.png',route=>route.fulfill({contentType:'image/png',body:fs.readFileSync('/tmp/ccc-public-guided.png')}));
 await page.goto('/tests/browser-face/inference.html');
 const result=await page.evaluate(async()=>{
  const image=await createImageBitmap(await(await fetch('/guide-fixture.png')).blob());
  const scene=(placements)=>{const canvas=document.createElement('canvas');canvas.width=640;canvas.height=480;const ctx=canvas.getContext('2d');ctx.fillStyle='black';ctx.fillRect(0,0,640,480);for(const [x,y] of placements)ctx.drawImage(image,x,y);return canvas.toDataURL();};
  const {captureGuide}=await import('/pf/js/browser-face/core.mjs');
  const guide=captureGuide(640,480,520,390,'cover',false).source;
  const original=await runInference(scene([[0,0]]));
  const accepted=await runInference(scene([[0,0]]),guide);
  const outside=await runInference(scene([[-250,0]]),guide);
  // Copy the subject rectangle only, so the second image cannot overwrite it.
  const two=document.createElement('canvas');two.width=640;two.height=480;const ctx=two.getContext('2d');ctx.fillStyle='black';ctx.fillRect(0,0,640,480);
  for(const x of [100,300])ctx.drawImage(image,200,80,240,320,x,80,240,320);
  const competing=await runInference(two.toDataURL(),guide);
  const cropped=await runInference(scene([[0,-170]]),guide);
  const held=await runInference(scene([[0,0]]),guide,false);
  image.close();
  return {cosine:original.embedding.reduce((sum,v,i)=>sum+v*accepted.embedding[i],0),accepted:{count:accepted.face_count,quality:accepted.quality_reason},outside:{detected:outside.detected_face_count,count:outside.face_count,quality:outside.quality_reason,embedding:outside.embedding,inference:outside.inference_ms},competing:{detected:competing.detected_face_count,count:competing.face_count,quality:competing.quality_reason,embedding:competing.embedding},cropped:{quality:cropped.quality_reason,embedding:cropped.embedding},held:{quality:held.quality_reason,embedding:held.embedding}};
 });
 expect(result.accepted).toEqual({count:1,quality:'SUITABLE'});expect(result.cosine).toBeCloseTo(1,12);
 expect(result.outside.detected).toBe(1);expect(result.outside.count).toBe(0);expect(result.outside.quality).toBe('OUTSIDE_GUIDE');expect(result.outside.embedding).toBeNull();expect(result.outside.inference).toBeUndefined();
 expect(result.competing.detected).toBe(2);expect(result.competing.count).toBe(2);expect(result.competing.quality).toBe('MULTIPLE_FACES');expect(result.competing.embedding).toBeNull();
 expect(result.cropped.embedding).toBeNull();expect(result.cropped.quality).not.toBe('SUITABLE');
 expect(result.held).toEqual({quality:'TRACK_HELD',embedding:null});
 console.log(JSON.stringify({guide_gate:result}));
});
