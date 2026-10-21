const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream',
    '--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('real-model automatic five-frame collection completes without shutter and restarts',async({page})=>{
    await page.goto('/tests/browser-face/inference.html');
    await page.evaluate(async()=>{
        const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');
        const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
        const video=document.createElement('video'),canvas=document.createElement('canvas');document.body.append(video,canvas);
        window.batches=[];window.captureErrors=[];window.timings={};
        window.capture=new AutomaticCapture(video,canvas,config,{status(){},progress(){},
            metrics(data){for(const key of ['camera_start_ms','detection_ms','inference_ms','completion_ms'])if(data[key])timings[key]=data[key];},
            batch(data){batches.push(data);},error(error){captureErrors.push(error);}});
        await capture.start('enroll');
    });
    await expect.poll(()=>page.evaluate(()=>batches.length)).toBe(1);
    const first=await page.evaluate(()=>({count:batches[0].observations.length,norm:Math.hypot(...batches[0].template),active:capture.active,errors:captureErrors,timings}));
    expect(first.count).toBe(5);expect(first.norm).toBeCloseTo(1,6);expect(first.active).toBe(false);expect(first.errors).toEqual([]);
    console.log(JSON.stringify({simulated_camera_enrollment:first.timings}));
    await page.evaluate(()=>capture.start('enroll'));
    await expect.poll(()=>page.evaluate(()=>batches.length),{timeout:20000}).toBe(2);
    const similarity=await page.evaluate(()=>batches[0].template.reduce((s,v,i)=>s+v*batches[1].template[i],0));
    expect(similarity).toBeGreaterThan(.999);
});
