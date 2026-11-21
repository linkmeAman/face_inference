// Shared pure operations; inference runs in worker.mjs.
export function normalize(values, dimension = values.length) {
    if (!values || values.length !== dimension || !Array.from(values).every(Number.isFinite)) throw Error('Invalid embedding');
    const norm = Math.hypot(...values);
    if (!Number.isFinite(norm) || norm < 1e-8) throw Error('Empty embedding');
    return Array.from(values, value => value / norm);
}
export function cosine(a, b) {
    if (a.length !== b.length) throw Error('Dimension mismatch');
    return a.reduce((sum, value, i) => sum + value * b[i], 0);
}
function median(values) {
    const sorted = values.slice().sort((a, b) => a - b), middle = sorted.length >> 1;
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}
export function aggregate(samples, minimum = 3) {
    if (samples.length < minimum || samples.length > 10) throw Error('Insufficient observations');
    const vectors = samples.map(v => normalize(v, samples[0].length));
    // Medoid and MAD remove obvious batch outliers without an identity threshold.
    const distances = vectors.map(a => median(vectors.map(b => 1 - cosine(a, b))));
    const medoid = vectors[distances.indexOf(Math.min(...distances))];
    const deviation = vectors.map(a => Math.max(0, 1 - cosine(a, medoid)));
    const centre = median(deviation), mad = median(deviation.map(v => Math.abs(v - centre)));
    const retained = vectors.filter((_, i) => deviation[i] <= centre + 3 * Math.max(mad, 1e-6));
    if (retained.length < minimum) throw Error('Unstable observations; retry');
    return {template: normalize(retained[0].map((_, i) => retained.reduce((s, v) => s + v[i], 0) / retained.length)),
        retained: retained.length};
}
export function similarityTransform(source, target) {
    if (source.length !== 5 || target.length !== 5) throw Error('Five landmarks required');
    const mean = points => [0, 1].map(axis => points.reduce((s, p) => s + p[axis], 0) / 5);
    const s = mean(source), t = mean(target);
    let real = 0, imag = 0, denominator = 0;
    for (let i = 0; i < 5; i++) {
        const x = source[i][0] - s[0], y = source[i][1] - s[1];
        const u = target[i][0] - t[0], v = target[i][1] - t[1];
        real += x * u + y * v; imag += x * v - y * u; denominator += x * x + y * y;
    }
    if (!Number.isFinite(denominator) || denominator < 1e-8) throw Error('Invalid alignment');
    const a = real / denominator, b = imag / denominator;
    return [a, -b, t[0] - a * s[0] + b * s[1], b, a, t[1] - b * s[0] - a * s[1]];
}
// Inverse affine sampling; OpenCV INTER_LINEAR uses 1/32-pixel interpolation.
export function alignRGB(rgba, width, height, matrix, size = 112, output = new Float32Array(3 * size * size)) {
    const [a,b,tx,c,d,ty] = matrix, det = a*d-b*c;
    if (!Number.isFinite(det) || Math.abs(det) < 1e-10) throw Error('Invalid alignment');
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
        const sx = (d*(x-tx)-b*(y-ty))/det, sy = (-c*(x-tx)+a*(y-ty))/det;
        const qx = Math.round(sx*32)/32, qy = Math.round(sy*32)/32;
        const ix = Math.floor(qx), iy = Math.floor(qy), fx = qx-ix, fy = qy-iy;
        for (let channel = 0; channel < 3; channel++) {
            let value = 0;
            for (let row = 0; row < 2; row++) for (let col = 0; col < 2; col++) {
                const px = ix+col, py = iy+row;
                if (px >= 0 && px < width && py >= 0 && py < height)
                    value += rgba[(py*width+px)*4+channel]*(col ? fx : 1-fx)*(row ? fy : 1-fy);
            }
            output[channel*size*size+y*size+x] = Math.round(value);
        }
    }
    return output;
}
export function quality(rgb, config, gray = new Float32Array(config.input_size**2)) {
    const size = config.input_size, count = size*size;
    let sum = 0, clipped = 0, lap = 0, lap2 = 0, n = 0;
    for (let i = 0; i < count; i++) {
        const value = .299*rgb[i]+.587*rgb[count+i]+.114*rgb[2*count+i];
        gray[i] = value; sum += value; if (value < 5 || value > 250) clipped++;
    }
    for (let y = 1; y < size-1; y++) for (let x = 1; x < size-1; x++) {
        const i = y*size+x, value = gray[i-1]+gray[i+1]+gray[i-size]+gray[i+size]-4*gray[i];
        lap += value; lap2 += value*value; n++;
    }
    const brightness = sum/count, blur = Math.max(0, lap2/n-(lap/n)**2);
    return {brightness, blur, clipped_fraction:clipped/count, valid: brightness >= config.brightness_min && brightness <= config.brightness_max
        && clipped/count < config.clipped_fraction_max && blur >= config.blur_variance_min};
}
function iou(a,b) {
    const intersection = Math.max(0, Math.min(a.x+a.width,b.x+b.width)-Math.max(a.x,b.x))
        * Math.max(0, Math.min(a.y+a.height,b.y+b.height)-Math.max(a.y,b.y));
    return intersection/(a.width*a.height+b.width*b.height-intersection);
}
export function decodeYuNet(outputs, config) {
    const faces = [], size = config.detector_size;
    for (const stride of [8,16,32]) {
        const columns = size/stride, scores = outputs['cls_'+stride].data, objects = outputs['obj_'+stride].data;
        const boxes = outputs['bbox_'+stride].data, points = outputs['kps_'+stride].data;
        for (let i = 0; i < scores.length; i++) {
            const score = Math.sqrt(Math.max(0, Math.min(1,scores[i]))*Math.max(0,Math.min(1,objects[i])));
            if (score < config.detector_score) continue;
            const column = i%columns, row = Math.floor(i/columns);
            const w = Math.exp(boxes[4*i+2])*stride, h = Math.exp(boxes[4*i+3])*stride;
            faces.push({x:(column+boxes[4*i])*stride-w/2, y:(row+boxes[4*i+1])*stride-h/2,
                width:w, height:h, score, landmarks: Array.from({length:5}, (_,k) =>
                    [(column+points[10*i+2*k])*stride,(row+points[10*i+2*k+1])*stride])});
        }
    }
    faces.sort((a,b) => b.score-a.score);
    const kept = [];
    for (const face of faces) if (kept.every(other => iou(face,other) <= config.nms_iou)) kept.push(face);
    return kept;
}
// Capture geometry is independent of model preprocessing. The same transform
// paints the window and maps it back to the unmirrored, uncropped camera frame.
export function faceGuide(width,height) {
    return {x:width/2,y:height/2,radius:Math.min(width,height)*0.4};
}
export function captureGuide(sourceWidth,sourceHeight,width,height,fit='contain',mirror=false) {
    if(![sourceWidth,sourceHeight,width,height].every(value=>Number.isFinite(value) && value>0))return null;
    const scale=(fit==='cover'?Math.max:Math.min)(width/sourceWidth,height/sourceHeight);
    const offsetX=(width-sourceWidth*scale)/2,offsetY=(height-sourceHeight*scale)/2;
    const display=faceGuide(Math.min(width,sourceWidth*scale),Math.min(height,sourceHeight*scale));
    display.x=width/2;display.y=height/2;
    const x=(display.x-offsetX)/scale;
    return {display,source:{x:mirror?sourceWidth-x:x,y:(display.y-offsetY)/scale,radius:display.radius/scale},scale,offsetX,offsetY};
}
export function alignmentCorners(matrix,size=112) {
    const [a,b,tx,c,d,ty]=matrix,det=a*d-b*c;
    if(!Number.isFinite(det) || Math.abs(det)<1e-10)throw Error('Invalid alignment');
    return [[0,0],[size-1,0],[size-1,size-1],[0,size-1]].map(([x,y])=>[(d*(x-tx)-b*(y-ty))/det,(-c*(x-tx)+a*(y-ty))/det]);
}
export function insideGuide([x,y],guide) {
    return Number.isFinite(x) && Number.isFinite(y) && Math.hypot(x-guide.x,y-guide.y)<=guide.radius;
}
export function selectGuideFace(faces,guide,width,height,target,inputSize=112) {
    if(!guide || ![guide.x,guide.y,guide.radius].every(Number.isFinite) || guide.radius<=0)return {face:null,reason:'OUTSIDE_GUIDE',status:'Center face',count:0};
    const candidates=faces.filter(face=>insideGuide([face.x+face.width/2,face.y+face.height/2],guide) || face.landmarks.some(point=>insideGuide(point,guide)));
    if(candidates.length!==1)return {face:null,reason:candidates.length?'MULTIPLE_FACES':faces.length?'OUTSIDE_GUIDE':'NO_FACE',status:candidates.length?'Only one face at a time':faces.length?'Center face':'No face detected',count:candidates.length};
    const face=candidates[0],bounds=[[face.x,face.y],[face.x+face.width,face.y],[face.x+face.width,face.y+face.height],[face.x,face.y+face.height]];
    const points=[...face.landmarks,...bounds,...alignmentCorners(similarityTransform(face.landmarks,target),inputSize)];
    const visible=points.every(([x,y])=>x>=0 && y>=0 && x<width && y<height);
    const contained=points.every(point=>insideGuide(point,guide));
    if(!visible || !contained){
        // A centered crop that is too large needs distance, not precise posing.
        const span=Math.max(...points.map(([x,y])=>Math.hypot(x-(face.x+face.width/2),y-(face.y+face.height/2))));
        return {face,reason:'OUTSIDE_GUIDE',status:!visible || span>guide.radius?'Move back slightly':'Center face',count:1};
    }
    return {face,reason:'SUITABLE',status:'Capturing...',count:1};
}
export function positioningHint(box,landmarks,width,height) {
    if(!box || !width || !height)return '';
    if(box.x<0 || box.y<0 || box.x+box.width>width || box.y+box.height>height
        || landmarks.some(([x,y])=>x<0 || y<0 || x>=width || y>=height))return 'Move slightly back';
    const guide=faceGuide(width,height);
    if(Math.max(box.width,box.height)>guide.radius*2)return 'Move slightly back';
    if(Math.hypot(box.x+box.width/2-guide.x,box.y+box.height/2-guide.y)>guide.radius
        || landmarks.some(([x,y])=>Math.hypot(x-guide.x,y-guide.y)>guide.radius))return 'Center face';
    return '';
}
export function displayBox(box, sourceWidth, sourceHeight, displayWidth, displayHeight, mirror) {
    const scale = Math.min(displayWidth/sourceWidth, displayHeight/sourceHeight);
    const offsetX = (displayWidth-sourceWidth*scale)/2, offsetY = (displayHeight-sourceHeight*scale)/2;
    return {x:offsetX+(mirror ? sourceWidth-box.x-box.width : box.x)*scale,
        y:offsetY+box.y*scale,width:box.width*scale,height:box.height*scale};
}
