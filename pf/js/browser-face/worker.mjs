import * as ort from '../../vendor/browser-face/ort.wasm.min.mjs';
import {normalize, similarityTransform, alignRGB, quality, decodeYuNet, selectGuideFace} from './core.mjs?v=stability-20261010-final';
let config, detector, recognizer, canvas, context, input, aligned, gray, busy = false;
async function init(contract) {
    config = contract;
    const assets = new URL('../../'+config.assets_base, import.meta.url).href;
    ort.env.wasm.wasmPaths = assets;
    ort.env.wasm.numThreads = 1; // Works without SharedArrayBuffer/COOP/COEP, including Safari.
    detector = await ort.InferenceSession.create(assets+config.detector_file,{executionProviders:['wasm']});
    recognizer = await ort.InferenceSession.create(assets+config.recognizer_file,{executionProviders:['wasm']});
    canvas = new OffscreenCanvas(config.detector_size, config.detector_size);
    context = canvas.getContext('2d',{willReadFrequently:true});
    input = new Float32Array(3*config.detector_size**2);
    aligned = new Float32Array(3*config.input_size**2);
    gray = new Float32Array(config.input_size**2);
}
async function process(bitmap,guide,recognize=true) {
    const started = performance.now(), size = config.detector_size;
    const scale = Math.min(size/bitmap.width,size/bitmap.height);
    context.fillStyle = 'black'; context.fillRect(0,0,size,size);
    context.drawImage(bitmap,0,0,bitmap.width*scale,bitmap.height*scale);
    const rgba = context.getImageData(0,0,size,size).data;
    // YuNet consumes BGR raw floats, SFace consumes RGB raw floats.
    for (let i = 0; i < size*size; i++) for (let channel=0;channel<3;channel++)
        input[channel*size*size+i] = rgba[4*i+2-channel];
    const tensor = new ort.Tensor('float32',input,[1,3,size,size]);
    let outputs;
    try { outputs = await detector.run({input:tensor}); } finally { tensor.dispose(); }
    let faces;
    try { faces = decodeYuNet(outputs,config).filter(face => face.x < bitmap.width*scale && face.y < bitmap.height*scale); }
    finally { for (const value of Object.values(outputs)) value.dispose(); }
    const detectionMs = performance.now()-started;
    // Camera frames carry the exact displayed window in source coordinates.
    // Offline reference-image inference has no preview/window; alignment and
    // model tensors are identical in both paths. Never mask/crop their pixels.
    const selection=guide?selectGuideFace(faces.map(face=>({...face,x:face.x/scale,y:face.y/scale,width:face.width/scale,height:face.height/scale,landmarks:face.landmarks.map(([x,y])=>[x/scale,y/scale])})),guide,bitmap.width,bitmap.height,config.target_landmarks,config.input_size):null;
    const face = selection?.face ? faces.find(face=>face.x/scale===selection.face.x && face.y/scale===selection.face.y) : selection?null:faces.length === 1 ? faces[0] : null;
    const box = face ? {x:face.x/scale,y:face.y/scale,width:face.width/scale,height:face.height/scale} : null;
    const result = {face_count:selection?.count ?? faces.length,detected_face_count:faces.length,box,detection_ms:detectionMs,embedding:null};
    if (config.diagnostics) result.landmarks = face?.landmarks;
    if(selection && selection.reason!=='SUITABLE')return {...result,quality_reason:selection.reason,status:selection.status};
    if (!face) return {...result,quality_reason:faces.length?'MULTIPLE_FACES':'NO_FACE',status: faces.length ? 'Only one person at a time' : 'Look toward the camera'};
    if (Math.min(box.width,box.height) < config.minimum_face_pixels) return {...result,quality_reason:'FACE_TOO_SMALL',status:'Move closer to the camera'};
    if(!recognize)return {...result,quality_reason:'TRACK_HELD',status:'Ready to scan'};
    const rgb = alignRGB(rgba,size,size,similarityTransform(face.landmarks,config.target_landmarks),config.input_size,aligned);
    const metrics = quality(rgb,config,gray);
    Object.assign(result,{brightness:metrics.brightness,blur:metrics.blur,clipped_fraction:metrics.clipped_fraction});
    if (!metrics.valid) return {...result,quality_reason:metrics.brightness<config.brightness_min?'TOO_DARK':metrics.brightness>config.brightness_max?'TOO_BRIGHT':metrics.clipped_fraction>=config.clipped_fraction_max?'CLIPPED_EXPOSURE':'BLUR',status:'Keep your face clear in even lighting'};
    const recognitionStart = performance.now(), blob = new ort.Tensor('float32',rgb,[1,3,112,112]);
    let feature;
    try { feature = await recognizer.run({data:blob}); } finally { blob.dispose(); }
    try { result.embedding = normalize(feature.fc1.data,config.dimension); }
    finally { for (const value of Object.values(feature)) value.dispose(); }
    return {...result,status:'Capturing...',quality_reason:'SUITABLE',inference_ms:performance.now()-recognitionStart};
}
self.onmessage = async ({data}) => {
    if (busy) { data.bitmap?.close(); return; }
    busy = true;
    try {
        if (data.type === 'init') { await init(data.config); self.postMessage({type:'ready'}); }
        else if (data.type === 'frame') self.postMessage({type:'observation',epoch:data.epoch,guide_key:data.guide_key,...await process(data.bitmap,data.guide,data.recognize)});
    } catch (error) { self.postMessage({type:'error',epoch:data.epoch,message:'Face processing failed; restart the camera'}); }
    finally { data.bitmap?.close(); busy = false; }
};

self.onunhandledrejection=event=>{event.preventDefault();self.postMessage({type:'error',message:'Face processing interrupted'});};
