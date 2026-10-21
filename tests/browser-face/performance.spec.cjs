const {test,expect}=require('@playwright/test');
const fs=require('fs');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream',
    '--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('sustained real inference records scoped memory and latency and cleans up',async({page,context})=>{
    const cdp=await context.browser().newBrowserCDPSession();
    async function memory(){
        const {processInfo}=await cdp.send('SystemInfo.getProcessInfo');
        return processInfo.map(row=>{
            let rss=null;try{rss=Number(fs.readFileSync('/proc/'+row.id+'/status','utf8').match(/^VmRSS:\s+(\d+)/m)?.[1]);}catch{}
            return {type:row.type,rss_kib:rss,cpu_seconds:row.cpuTime};
        });
    }
    await page.goto('/tests/browser-face/inference.html');
    const before=await memory();
    await page.evaluate(async()=>{
        const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');
        const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
        const video=document.createElement('video'),canvas=document.createElement('canvas');document.body.append(video,canvas);
        window.count=0;window.failures=[];window.detections=[];window.recognitions=[];window.starts=[];
        window.capture=new AutomaticCapture(video,canvas,config,{status(){},progress(){},
            batch(){count++;},error(message){failures.push(message);},
            metrics(data){if(data.detection_ms)detections.push(data.detection_ms);if(data.inference_ms)recognitions.push(data.inference_ms);if(data.camera_start_ms)starts.push(data.camera_start_ms);}});
        await capture.start('attendance');
    });
    await expect.poll(()=>page.evaluate(()=>count),{timeout:60000}).toBeGreaterThanOrEqual(5);
    const after=await memory();
    const metrics=await page.evaluate(()=>{
        const tracks=capture.stream.getTracks();capture.stop();
        return {batches:count,observations:detections.length,detection_ms:detections,recognition_ms:recognitions,camera_start_ms:starts[0],
            page_js_heap_used:performance.memory?.usedJSHeapSize || null,tracks_ended:tracks.every(track=>track.readyState==='ended'),failures};
    });
    expect(metrics.failures).toEqual([]);expect(metrics.tracks_ended).toBe(true);
    const percentile=(values,p)=>values.slice().sort((a,b)=>a-b)[Math.floor((values.length-1)*p)];
    console.log(JSON.stringify({scope:'Headless server Chromium, public-photo camera; RSS per process includes shared pages, not total unique memory',
        batches:metrics.batches,observations:metrics.observations,camera_start_ms:metrics.camera_start_ms,
        detection_p50_ms:percentile(metrics.detection_ms,.5),detection_p95_ms:percentile(metrics.detection_ms,.95),
        recognition_p50_ms:percentile(metrics.recognition_ms,.5),recognition_p95_ms:percentile(metrics.recognition_ms,.95),
        page_js_heap_used:metrics.page_js_heap_used,before,after}));
    await cdp.detach();
});
