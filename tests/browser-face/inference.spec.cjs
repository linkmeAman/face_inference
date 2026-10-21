const {test,expect} = require('@playwright/test');
const fs = require('fs');
const {execFileSync}=require('child_process');
test('real browser WASM YuNet/SFace matches reference and rejects empty/multiple scenes',async({page})=>{
    if(!process.env.FACE_PUBLIC_FIXTURE || !process.env.FACE_REFERENCE_VECTOR) throw Error('Set FACE_PUBLIC_FIXTURE and FACE_REFERENCE_VECTOR; real inference must not be skipped');
    await page.route('**/public-fixture.jpg',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(process.env.FACE_PUBLIC_FIXTURE)}));
    await page.goto('/tests/browser-face/inference.html');
    await page.waitForFunction(()=>typeof window.runInference==='function');
    const result=await page.evaluate(()=>runInference('/public-fixture.jpg'));
    expect(result.face_count).toBe(1);
    expect(result.embedding.length).toBe(128);
    expect(Math.hypot(...result.embedding)).toBeCloseTo(1,6);
    // Canvas and OpenCV resize/decode are different. Compare the recognizer on
    // identical raster and landmarks, rather than silently changing tolerance.
    const raster=await page.evaluate(async()=>{
        const bitmap=await createImageBitmap(await(await fetch('/public-fixture.jpg')).blob());
        const canvas=document.createElement('canvas');canvas.width=640;canvas.height=640;
        const ctx=canvas.getContext('2d');ctx.fillStyle='black';ctx.fillRect(0,0,640,640);
        const scale=Math.min(640/bitmap.width,640/bitmap.height);ctx.drawImage(bitmap,0,0,bitmap.width*scale,bitmap.height*scale);bitmap.close();
        return canvas.toDataURL('image/png').split(',')[1];
    });
    fs.writeFileSync('/tmp/ccc-public-browser-raster.png',Buffer.from(raster,'base64'));
    fs.writeFileSync('/tmp/ccc-public-browser-landmarks.json',JSON.stringify(result.landmarks));
    execFileSync(process.env.FACE_TEST_PYTHON || '/var/lib/creativeculturalcentre/face-inference/venv/bin/python',
        ['tests/browser-face/reference_encoder.py','/tmp/ccc-public-browser-raster.png','/tmp/ccc-public-browser-landmarks.json','/tmp/ccc-public-browser-reference.json']);
    const reference=JSON.parse(fs.readFileSync('/tmp/ccc-public-browser-reference.json'));
    const similarity=result.embedding.reduce((sum,value,i)=>sum+value*reference[i],0);
    expect(similarity).toBeGreaterThan(.99);
    console.log(JSON.stringify({reference_cosine:similarity,detection_ms:result.detection_ms,inference_ms:result.inference_ms}));
    for(const [file,count] of [['blank',0],['multiple',2],['portrait',1]]) {
        const path=process.env['FACE_'+file.toUpperCase()+'_FIXTURE'];
        if(!path) throw Error('Missing '+file+' fixture');
        await page.route('**/'+file+'.jpg',route=>route.fulfill({contentType:'image/jpeg',body:fs.readFileSync(path)}));
        const observed=await page.evaluate(url=>runInference(url),'/'+file+'.jpg');
        expect(observed.face_count).toBe(count);
        if(count!==1) expect(observed.embedding).toBeNull();
    }
});
