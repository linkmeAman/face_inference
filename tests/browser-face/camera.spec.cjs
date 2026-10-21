const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream']}});
test('camera starts and cancellation ends media tracks and stale work',async({page})=>{
    await page.goto('/tests/browser-face/inference.html');
    const result=await page.evaluate(async()=>{
        const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');
        const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
        const video=document.createElement('video'),canvas=document.createElement('canvas');document.body.append(video,canvas);
        const errors=[];const capture=new AutomaticCapture(video,canvas,config,{status(){},progress(){},batch(){throw Error('Unexpected batch');},error(e){errors.push(e);}});
        await capture.start('enroll');const tracks=capture.stream?.getTracks() || [];const started=tracks.length;
        capture.stop();await new Promise(resolve=>setTimeout(resolve,100));
        return {started,ended:tracks.every(t=>t.readyState==='ended'),active:capture.active,worker:capture.worker,errors};
    });
    expect(result.started).toBe(1);expect(result.ended).toBe(true);expect(result.active).toBe(false);expect(result.worker).toBeNull();expect(result.errors).toEqual([]);
});
test('denied permission and capture timeout produce clean retryable errors',async({page})=>{
    await page.goto('/tests/browser-face/inference.html');
    const result=await page.evaluate(async()=>{
        const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');
        const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
        const video=document.createElement('video'),canvas=document.createElement('canvas');document.body.append(video,canvas);
        const errors=[];const capture=new AutomaticCapture(video,canvas,config,{status(){},progress(){},batch(){},error(e){errors.push(e);}});
        const original=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
        navigator.mediaDevices.getUserMedia=async()=>{throw new DOMException('Denied','NotAllowedError');};
        await capture.start('enroll');const denied=!capture.active && capture.worker===null && errors[0].includes('Allow camera');
        navigator.mediaDevices.getUserMedia=original;capture.config={...config,capture_timeout_ms:10};
        await capture.start('enroll');await new Promise(resolve=>setTimeout(resolve,100));
        return {denied,stopped:!capture.active && capture.worker===null && video.srcObject===null,errors};
    });
    expect(result.denied).toBe(true);expect(result.stopped).toBe(true);expect(result.errors[1]).toContain('timed out');
});
test('real-model attendance camera keeps waiting when no child has arrived',async({page})=>{
    await page.goto('/tests/browser-face/inference.html');
    await page.evaluate(async()=>{
        const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');
        const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
        const video=document.createElement('video'),canvas=document.createElement('canvas');document.body.append(video,canvas);
        window.idleErrors=[];window.idleEmptyFrames=0;
        window.idleCamera=new AutomaticCapture(video,canvas,{...config,capture_timeout_ms:5000},{status(){},progress(){},batch(){throw Error('Empty camera must not submit');},metrics(data){if(data.face_count===0)idleEmptyFrames++;},error(error){idleErrors.push(error);}});
        await idleCamera.start('attendance');
    });
    await expect.poll(()=>page.evaluate(()=>idleEmptyFrames),{timeout:5000}).toBeGreaterThan(0);
    await page.waitForTimeout(5500);
    expect(await page.evaluate(()=>idleCamera.active && !!idleCamera.worker && idleCamera.stream.getTracks().every(track=>track.readyState==='live'))).toBe(true);
    expect(await page.evaluate(()=>idleErrors)).toEqual([]);
    await page.evaluate(()=>idleCamera.stop());expect(await page.evaluate(()=>idleCamera.stream===null && idleCamera.worker===null)).toBe(true);
});
