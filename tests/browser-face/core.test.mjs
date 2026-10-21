import {test} from 'node:test';
import assert from 'node:assert/strict';
import {normalize,cosine,aggregate,similarityTransform,alignRGB,displayBox,quality,decodeYuNet} from '../../pf/js/browser-face/core.mjs';
import {AutomaticCapture} from '../../pf/js/browser-face/capture.mjs';
import {readFileSync} from 'node:fs';
const config = JSON.parse(readFileSync(new URL('../../pf/js/browser-face/contract.json',import.meta.url)));
test('normalization rejects invalid vectors and normalizes real dimensions',()=>{
    assert.deepEqual(normalize([3,4]),[.6,.8]);
    for(const vector of [[0,0],[NaN,1],[Infinity,1]]) assert.throws(()=>normalize(vector));
    assert.throws(()=>normalize([1,0],128));
    assert.ok(Math.abs(cosine(normalize(Array(128).fill(1)),normalize(Array(128).fill(1)))-1)<1e-12);
});
test('five point alignment handles scale rotation and translation',()=>{
    const source = config.target_landmarks.map(([x,y])=>[2*y+20,-2*x+300]);
    const [a,b,tx,c,d,ty] = similarityTransform(source,config.target_landmarks);
    source.forEach(([x,y],i)=> {assert.ok(Math.abs(a*x+b*y+tx-config.target_landmarks[i][0])<1e-8);
        assert.ok(Math.abs(c*x+d*y+ty-config.target_landmarks[i][1])<1e-8);});
    assert.throws(()=>similarityTransform(Array(5).fill([0,0]),config.target_landmarks));
});
test('crop uses RGB NCHW without mean normalization and pads outside image',()=>{
    const rgb=alignRGB(new Uint8Array([10,20,30,255]),1,1,[1,0,0,0,1,0],2);
    assert.deepEqual(Array.from(rgb),[10,0,0,0,20,0,0,0,30,0,0,0]);
});
test('mirrored outline accounts for portrait/landscape letterboxing',()=>{
    assert.deepEqual(displayBox({x:10,y:20,width:30,height:40},100,200,400,400,true),{x:220,y:40,width:60,height:80});
    assert.deepEqual(displayBox({x:10,y:20,width:30,height:40},200,100,400,400,false),{x:20,y:140,width:60,height:80});
});
test('blur dark and bright inputs cannot advance capture',()=>{
    for(const value of [0,120,255]) assert.equal(quality(new Float32Array(3*112*112).fill(value),config).valid,false);
});
test('detector distinguishes no face and two separate faces',()=>{
    const outputs={};
    for(const stride of [8,16,32]) for(const [name,width] of [['cls',1],['obj',1],['bbox',4],['kps',10]])
        outputs[name+'_'+stride]={data:new Float32Array((640/stride)**2*width)};
    assert.equal(decodeYuNet(outputs,config).length,0);
    for(const i of [1600,3200]){outputs.cls_8.data[i]=1; outputs.obj_8.data[i]=1;}
    assert.equal(decodeYuNet(outputs,config).length,2);
});
test('aggregation is deterministic and excludes an obvious outlier',()=>{
    const samples=[[1,.01],[1,.02],[1,0],[1,-.01],[-1,0]];
    const result=aggregate(samples);
    assert.equal(result.retained,4);assert.ok(cosine(result.template,[1,0])>.999);
    assert.deepEqual(aggregate(samples),result);assert.deepEqual(aggregate(samples.slice().reverse()),result);
    assert.throws(()=>aggregate([[1,0],[1,0]]));
});
test('multiple/no face clears partial collection and stop discards cancelled work',async()=>{
    const canvas={getContext:()=>({clearRect(){}})};
    let batches=0;
    const capture=new AutomaticCapture({},canvas,config,{status(){},progress(){},metrics(){},batch(){batches++;},error(){}});
    capture.draw=()=>{};capture.active=true;capture.samples=[[1,0],[1,0]];
    await capture.observe({face_count:2,box:null,embedding:null});clearTimeout(capture.timer);
    assert.equal(capture.samples.length,0);assert.equal(batches,0);
    capture.samples=[[1,0]];
    await capture.observe({face_count:0,box:null,embedding:null});clearTimeout(capture.timer);
    assert.equal(capture.samples.length,0);
    capture.stop();assert.equal(capture.active,false);assert.equal(capture.processing,false);
});
test('a discontinuous attendance single-face track cannot finish the preceding face capture',async()=>{
    const canvas={getContext:()=>({clearRect(){}})};let batches=[];
    const capture=new AutomaticCapture({},canvas,config,{status(){},progress(){},batch(batch){batches.push(batch);},error(){}});
    capture.draw=()=>{};capture.active=true;capture.mode='attendance';
    const feed=async(box,embedding)=>{capture.lastSample=performance.now()-config.sample_interval_ms-1;await capture.observe({face_count:1,box,embedding});clearTimeout(capture.timer);};
    try{
        for(let i=0;i<4;i++)await feed({x:10+i,y:20,width:100,height:120},[1,0]);
        await feed({x:400,y:20,width:100,height:120},[0,1]);
        assert.equal(batches.length,0,'new face must not complete the preceding four observations');
        assert.equal(capture.samples.length,1);
        for(let i=0;i<4;i++)await feed({x:400+i,y:20,width:100,height:120},[0,1]);
        assert.equal(batches.length,1);assert.deepEqual(batches[0].template,[0,1]);
    }finally{capture.stop();}
});
test('a ready attendance camera waits for arrivals without enrollment timeout',async()=>{
    const names=['navigator','isSecureContext','Worker','setTimeout','clearTimeout'];
    const previous=new Map(names.map(name=>[name,Object.getOwnPropertyDescriptor(globalThis,name)]));const timers=new Map();let sequence=0;
    const track={stop(){},addEventListener(){}},stream={getTracks:()=>[track],getVideoTracks:()=>[track]};
    const values={navigator:{mediaDevices:{getUserMedia:async()=>stream}},isSecureContext:true,
        Worker:class {postMessage(){this.onmessage({data:{type:'ready'}});}terminate(){}},
        setTimeout:(callback)=>{const id=++sequence;timers.set(id,callback);return id;},clearTimeout:id=>timers.delete(id)};
    for(const name of names)Object.defineProperty(globalThis,name,{configurable:true,writable:true,value:values[name]});
    const capture=new AutomaticCapture({play:async()=>{}},{getContext:()=>({clearRect(){}})},config,{status(){},progress(){},error(){}});capture.tick=()=>{};
    try{await capture.start('attendance');for(const callback of Array.from(timers.values()))callback();assert.equal(capture.active,true,'healthy empty attendance camera must keep waiting');}
    finally{capture.stop();for(const name of names){const descriptor=previous.get(name);if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}}
});
test('a terminated worker error cannot stop a restarted camera; current errors still release it',async()=>{
    const names=['navigator','isSecureContext','Worker'];
    const previous=new Map(names.map(name=>[name,Object.getOwnPropertyDescriptor(globalThis,name)]));
    const errors=[],tracks=[];
    const values={navigator:{mediaDevices:{getUserMedia:async()=>{
        const track={stopped:false,stop(){this.stopped=true;},addEventListener(){}};tracks.push(track);
        return {getTracks:()=>[track],getVideoTracks:()=>[track]};
    }}},isSecureContext:true,Worker:class {postMessage(){}terminate(){this.terminated=true;}}};
    for(const name of names)Object.defineProperty(globalThis,name,{configurable:true,writable:true,value:values[name]});
    const capture=new AutomaticCapture({play:async()=>{}},{getContext:()=>({clearRect(){}})},config,{status(){},error:message=>errors.push(message)});
    try{
        await capture.start('enroll');const old=capture.worker,queuedError=old.onerror;
        await capture.start('enroll');const current=capture.worker;
        assert.equal(old.terminated,true);assert.equal(tracks[0].stopped,true);
        queuedError();
        assert.equal(capture.active,true,'queued old-worker error must not stop the new camera');
        assert.equal(capture.worker,current);assert.equal(tracks[1].stopped,false);assert.equal(errors.length,0);
        current.onerror();assert.equal(capture.active,true);assert.equal(capture.recovering,true);assert.equal(tracks[1].stopped,false);
        assert.equal(current.terminated,true);assert.equal(errors.length,0);
        // EmbeddedFaceFlow creates a new capture object around the same video.
        await capture.start('enroll');const oldInstanceError=capture.worker.onerror;
        capture.stop();
        const replacement=new AutomaticCapture(capture.video,capture.canvas,config,{status(){},error:message=>errors.push(message)});
        try{
            await replacement.start('enroll');const stream=replacement.stream;
            oldInstanceError();
            assert.equal(capture.video.srcObject,stream,'old capture must not clear the replacement preview');
            assert.equal(replacement.active,true);assert.equal(errors.length,0);
        }finally{replacement.stop();}
    }finally{capture.stop();for(const name of names){const descriptor=previous.get(name);if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}}
});

test('shared guide maps cover cropping, contain letterboxing, orientation and mirroring',async()=>{
    const {captureGuide,insideGuide}=await import('../../pf/js/browser-face/core.mjs');
    for(const [sw,sh,w,h] of [[640,480,390,300],[480,640,390,300],[640,480,844,214],[1920,1080,320,480]])for(const fit of ['cover','contain'])for(const mirrored of [false,true]){
        const guide=captureGuide(sw,sh,w,h,fit,mirrored);
        for(const [dx,dy,inside] of [[0,0,true],[guide.display.radius*.9,0,true],[guide.display.radius*1.1,0,false],[0,guide.display.radius*1.1,false]]){
            const visualX=guide.display.x+dx,visualY=guide.display.y+dy;
            const x=(visualX-guide.offsetX)/guide.scale,y=(visualY-guide.offsetY)/guide.scale;
            assert.equal(insideGuide([mirrored?sw-x:x,y],guide.source),inside);
        }
    }
    assert.equal(captureGuide(0,480,300,400),null);
});
test('guide rejects outside/cut-off/alignment crops and competing faces before inference',async()=>{
    const {selectGuideFace,faceGuide}=await import('../../pf/js/browser-face/core.mjs');
    const face={x:260,y:200,width:120,height:120,landmarks:config.target_landmarks.map(([x,y])=>[x*2+208,y*2+128])},guide=faceGuide(640,480);
    const check=faces=>selectGuideFace(faces,guide,640,480,config.target_landmarks);
    assert.equal(check([face]).reason,'SUITABLE');
    const outside={...face,x:20,landmarks:face.landmarks.map(([x,y])=>[x-260,y])};
    assert.equal(check([outside]).reason,'OUTSIDE_GUIDE');assert.equal(check([outside]).count,0);
    assert.equal(check([face,outside]).reason,'SUITABLE','background faces cannot be selected');
    assert.equal(check([face,{...face,x:360,landmarks:face.landmarks.map(([x,y])=>[x+80,y])}]).reason,'MULTIPLE_FACES');
    assert.equal(check([{...face,y:-1}]).reason,'OUTSIDE_GUIDE');
    assert.equal(check([{...face,landmarks:face.landmarks.map(([x,y])=>[x+120,y])}]).reason,'OUTSIDE_GUIDE','aligned crop must fit even when landmarks alone are visible');
});
test('moving outside the guide discards partial enrollment and stale layout observations',async()=>{
    const capture=new AutomaticCapture({}, {getContext:()=>({clearRect(){}})},config,{status(){},progress(){},error(){}});capture.draw=()=>{};capture.active=true;
    capture.samples=Array(4).fill([1,0]);capture.track={box:{x:200,y:180,width:100,height:120}};
    await capture.observe({face_count:1,box:capture.track.box,quality_reason:'OUTSIDE_GUIDE',embedding:null});clearTimeout(capture.timer);
    assert.equal(capture.samples.length,0);
    capture.guideKey='new';await capture.observe({face_count:1,box:{x:200,y:180,width:100,height:120},guide_key:'old',embedding:[1,0]});clearTimeout(capture.timer);
    assert.equal(capture.samples.length,0);capture.stop();
});
test('confirmed attendance parks the same track and rearms after departure',async()=>{
    let batches=0;const capture=new AutomaticCapture({}, {getContext:()=>({clearRect(){}})},config,{status(){},progress(){},error(){},batch(){batches++;return {waitForDeparture:true};}});
    capture.draw=()=>{};capture.active=true;capture.mode='attendance';capture.started=performance.now();const box={x:200,y:180,width:100,height:120};
    const feed=async data=>{capture.lastSample=performance.now()-config.sample_interval_ms-1;await capture.observe(data);clearTimeout(capture.timer);};
    for(let i=0;i<5;i++)await feed({face_count:1,box,embedding:[1,0],quality_reason:'SUITABLE'});
    assert.equal(batches,1);assert.equal(capture.waitForDeparture,true);
    await feed({face_count:1,box,embedding:null,quality_reason:'TRACK_HELD'});assert.equal(capture.waitForDeparture,true);assert.equal(batches,1);
    await feed({face_count:0,box:null,embedding:null,quality_reason:'NO_FACE'});assert.equal(capture.waitForDeparture,false);
    for(let i=0;i<5;i++)await feed({face_count:1,box,embedding:[0,1],quality_reason:'SUITABLE'});
    assert.equal(batches,2);capture.stop();
});
