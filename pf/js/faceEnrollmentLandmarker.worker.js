import {FaceLandmarker, FilesetResolver} from '../vendor/face-landmarker/0.10.34/vision_bundle.js';

// Browser checks make feedback immediate. They are never the trust boundary:
// Python still validates every submitted frame before MN3 and SFace run.
const BLUR_VARIANCE_MIN = 35.0;
const BRIGHTNESS_MIN = 40.0;
const BRIGHTNESS_MAX = 220.0;
const PLANAR_RATIO_MIN = 0.10;
const PROCESS_INTERVAL_MS = 100;
const QUALITY_WIDTH = 160;
const QUALITY_HEIGHT = 120;
let landmarker = null;
let lastTimestamp = 0;
let lastProcessTime = 0;
// Reused bounded buffers: no per-frame canvas or grayscale allocation.
let qualityCanvas = null;
let qualityContext = null;
const qualityGray = new Float32Array(QUALITY_WIDTH * QUALITY_HEIGHT);

function finite(value) { return Number.isFinite(value); }
function clamp(value, low, high) { return Math.min(high, Math.max(low, value)); }

function boxFromPoints(points) {
    if (!Array.isArray(points) || points.length === 0) return null;
    let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
    for (const point of points) {
        if (!point || !finite(point.x) || !finite(point.y)) return null;
        left = Math.min(left, point.x); right = Math.max(right, point.x);
        top = Math.min(top, point.y); bottom = Math.max(bottom, point.y);
    }
    left = clamp(left, 0, 1); right = clamp(right, 0, 1);
    top = clamp(top, 0, 1); bottom = clamp(bottom, 0, 1);
    return right > left && bottom > top ? {x: left, y: top, width: right - left, height: bottom - top} : null;
}

function clientPlanarZVariance(points, aspectRatio) {
    const nose = points[1], leftCheek = points[234], rightCheek = points[454];
    if (!nose || !leftCheek || !rightCheek || !finite(nose.x) || !finite(nose.z)
        || !finite(leftCheek.x) || !finite(leftCheek.y) || !finite(leftCheek.z)
        || !finite(rightCheek.x) || !finite(rightCheek.y) || !finite(rightCheek.z)
        || !finite(aspectRatio) || aspectRatio <= 0) return null;
    // Landmarks have independently-normalized x/y. Scale y by H/W before
    // measuring cheek distance, so this depth-to-width ratio is scale-invariant.
    const deltaX = rightCheek.x - leftCheek.x;
    const deltaY = (rightCheek.y - leftCheek.y) * aspectRatio;
    const faceWidth = Math.sqrt(deltaX ** 2 + deltaY ** 2);
    if (!finite(faceWidth) || faceWidth <= 0) return null;
    const rawDeltaZ = Math.abs(nose.z - ((leftCheek.z + rightCheek.z) / 2));
    return rawDeltaZ / faceWidth;
}

function poseDegrees(points, matrix) {
    // MediaPipe's 4x4 face transform provides camera-relative Euler angles.
    // These are guidance metrics only, never identity or spoof evidence.
    if (Array.isArray(matrix) && matrix.length === 16 && matrix.every(finite)) {
        const d = 180 / Math.PI;
        return {yaw: Math.atan2(-matrix[8], Math.hypot(matrix[0], matrix[4])) * d,
            pitch: Math.atan2(matrix[9], matrix[10]) * d,
            roll: Math.atan2(matrix[4], matrix[0]) * d};
    }
    const leftEye = points[33], rightEye = points[263], nose = points[1];
    if (!leftEye || !rightEye || !nose) return {yaw: null, pitch: null, roll: null};
    const dx = rightEye.x - leftEye.x, dy = rightEye.y - leftEye.y;
    const eyeDistance = Math.hypot(dx, dy);
    if (!finite(eyeDistance) || eyeDistance < 0.001) return {yaw: null, pitch: null, roll: null};
    const eyeMidX = (leftEye.x + rightEye.x) / 2;
    return {yaw: Math.asin(clamp(2 * (nose.x - eyeMidX) / eyeDistance, -0.7, 0.7)) * 180 / Math.PI,
        pitch: null, roll: Math.atan2(dy, dx) * 180 / Math.PI};
}

function grayscaleQuality(bitmap, box) {
    if (typeof OffscreenCanvas === 'undefined' || !box) return null;
    const sourceWidth = Number(bitmap.width), sourceHeight = Number(bitmap.height);
    if (!finite(sourceWidth) || !finite(sourceHeight) || sourceWidth < 3 || sourceHeight < 3) return null;
    try {
        if (!qualityCanvas) {
            qualityCanvas = new OffscreenCanvas(QUALITY_WIDTH, QUALITY_HEIGHT);
            qualityContext = qualityCanvas.getContext('2d', {willReadFrequently: true});
        }
        if (!qualityContext) return null;
        qualityContext.drawImage(bitmap, 0, 0, QUALITY_WIDTH, QUALITY_HEIGHT);
        const x = clamp(Math.floor(box.x * QUALITY_WIDTH), 0, QUALITY_WIDTH - 3);
        const y = clamp(Math.floor(box.y * QUALITY_HEIGHT), 0, QUALITY_HEIGHT - 3);
        const right = clamp(Math.ceil((box.x + box.width) * QUALITY_WIDTH), x + 3, QUALITY_WIDTH);
        const bottom = clamp(Math.ceil((box.y + box.height) * QUALITY_HEIGHT), y + 3, QUALITY_HEIGHT);
        const cropWidth = right - x, cropHeight = bottom - y;
        const pixels = qualityContext.getImageData(x, y, cropWidth, cropHeight).data;
        let brightnessSum = 0;
        const pixelCount = cropWidth * cropHeight;
        for (let i = 0, pixel = 0; i < pixelCount; i++, pixel += 4) {
            const value = 0.299 * pixels[pixel] + 0.587 * pixels[pixel + 1] + 0.114 * pixels[pixel + 2];
            qualityGray[i] = value; brightnessSum += value;
        }
        let laplacianSum = 0, laplacianSquaredSum = 0, samples = 0;
        for (let row = 1; row < cropHeight - 1; row++) for (let column = 1; column < cropWidth - 1; column++) {
            const i = row * cropWidth + column;
            const laplacian = qualityGray[i - cropWidth] + qualityGray[i + cropWidth]
                + qualityGray[i - 1] + qualityGray[i + 1] - 4 * qualityGray[i];
            laplacianSum += laplacian; laplacianSquaredSum += laplacian * laplacian; samples++;
        }
        if (!samples) return null;
        const mean = laplacianSum / samples;
        return {brightness: brightnessSum / pixelCount,
            blur_variance: Math.max(0, laplacianSquaredSum / samples - mean * mean)};
    } catch (error) {
        console.warn('[face-worker] canvas quality check unavailable', error && error.message ? error.message : error);
        return null;
    }
}

function observationFor(bitmap, result, aspectRatio) {
    const detected = result.faceLandmarks || [];
    const matrices = result.facialTransformationMatrixes || [];
    const errors = [];
    if (detected.length === 0) errors.push('NO_FACE');
    if (detected.length > 1) errors.push('MULTIPLE_FACES');
    const points = detected.length === 1 ? detected[0] : null;
    const matrix = points && matrices[0] ? Array.from(matrices[0].data || matrices[0]) : null;
    const box = points ? boxFromPoints(points) : null;
    const clientZVariance = points ? clientPlanarZVariance(points, aspectRatio) : null;
    const quality = grayscaleQuality(bitmap, box);
    const pose = points ? poseDegrees(points, matrix) : {yaw: null, pitch: null, roll: null};
    if (!quality) errors.push('QUALITY_UNAVAILABLE');
    else {
        if (quality.blur_variance < BLUR_VARIANCE_MIN) errors.push('BLUR');
        if (quality.brightness < BRIGHTNESS_MIN || quality.brightness > BRIGHTNESS_MAX) errors.push('LIGHTING');
    }
    if (!finite(clientZVariance)) errors.push('PLANAR_UNAVAILABLE');
    else if (clientZVariance < PLANAR_RATIO_MIN) errors.push('SPOOF');
    // A low client depth signal is guidance/telemetry only.  It must never
    // prevent server-side MN3 and SFace from evaluating the submitted pixels.
    const blockingErrors = errors.filter(error => error !== 'SPOOF');
    return {isValid: blockingErrors.length === 0, errors: errors,
        metrics: {yaw: pose.yaw, pitch: pose.pitch, roll: pose.roll, client_z_variance: clientZVariance,
            blur_variance: quality ? quality.blur_variance : null, brightness: quality ? quality.brightness : null},
        box: box,
        // Preserve the legacy tracker input while exposing the new contract.
        // Keep every detected face here so the UI can explicitly reject a
        // multi-person frame instead of mistaking it for no face.
        faces: detected.map(function(facePoints, index){
            const faceMatrix = matrices[index] ? Array.from(matrices[index].data || matrices[index]) : null;
            return {points: facePoints.map(point => [point.x, point.y, point.z]), matrix: faceMatrix,
                box: boxFromPoints(facePoints),
                client_z_variance: clientPlanarZVariance(facePoints, aspectRatio),
                metrics: poseDegrees(facePoints, faceMatrix)};
        }),
        light: quality ? quality.brightness : null};
}

self.onmessage = async function(event) {
    const message = event.data || {};
    if (message.type === 'init') {
        try {
            const base = new URL('../vendor/face-landmarker/0.10.34/', import.meta.url).href;
            const vision = await FilesetResolver.forVisionTasks(base + 'wasm');
            landmarker = await FaceLandmarker.createFromOptions(vision, {
                baseOptions: {modelAssetPath: base + 'face_landmarker.task', delegate: 'CPU'}, runningMode: 'VIDEO', numFaces: 2,
                minFaceDetectionConfidence: 0.5, minFacePresenceConfidence: 0.5, minTrackingConfidence: 0.5,
                outputFacialTransformationMatrixes: true});
            self.postMessage({type: 'ready'});
        } catch (error) {
            console.error('[face-worker] MediaPipe initialization failed', error);
            self.postMessage({type: 'error', reason: 'LANDMARKER_INIT_FAILED'});
        }
        return;
    }
    if (message.type !== 'frame' || !message.bitmap) return;
    const bitmap = message.bitmap;
    try {
        if (!landmarker) throw new Error('LANDMARKER_NOT_READY');
        const started = performance.now();
        if (started - lastProcessTime < PROCESS_INTERVAL_MS) {
            self.postMessage({type: 'throttled', requestId: message.requestId || null,
                epoch: Number.isInteger(message.epoch) ? message.epoch : 0,
                observedAt: message.observedAt});
            return;
        }
        lastProcessTime = started;
        const timestamp = Math.max(lastTimestamp + 1, started); lastTimestamp = timestamp;
        const observation = observationFor(bitmap, landmarker.detectForVideo(bitmap, timestamp), Number(message.aspectRatio));
        self.postMessage(Object.assign({type: 'observation', observedAt: message.observedAt,
            requestId: message.requestId || null, epoch: Number.isInteger(message.epoch) ? message.epoch : 0,
            inferMs: performance.now() - started}, observation));
    } catch (error) {
        console.error('[face-worker] frame processing failed', error);
        self.postMessage({type: 'error', requestId: message.requestId || null,
            epoch: Number.isInteger(message.epoch) ? message.epoch : 0, reason: 'LANDMARKER_TRACK_FAILED'});
    } finally { bitmap.close(); }
};
