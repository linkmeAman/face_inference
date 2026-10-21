(function(global) {
    'use strict';
    const outline = [10,338,297,332,284,251,389,356,454,323,361,288,397,365,379,378,400,377,
        152,148,176,149,150,136,172,58,132,93,234,127,162,21,54,103,67,109];
    function Tracker(video, canvas, facing, onUpdate, onFailure) {
        this.video = video;
        this.canvas = canvas;
        this.facing = facing;
        this.onUpdate = onUpdate;
        this.onFailure = onFailure;
        this.worker = null;
        this.active = false;
        this.busy = false;
        this.ready = false;
        this.target = 0;
        this.extraDirection = 1;
        this.stableSince = null;
        this.yawHistory = [];
        this.latest = null;
        this.timer = null;
        this.rafId = null;
        this.currentReticle = null;
        this.targetReticle = null;
        this.lastCentroid = null;
        this.visibilityHandler = null;
    }
    Tracker.prototype.getVideoBox = function(width, height) {
        if (!this.video || typeof this.video.videoWidth !== 'number' || typeof this.video.videoHeight !== 'number'
            || this.video.videoWidth <= 0 || this.video.videoHeight <= 0) {
            return { vx: 0, vy: 0, vw: width, vh: height };
        }
        const videoAspect = this.video.videoWidth / this.video.videoHeight;
        const boxAspect = width / height;
        if (videoAspect > boxAspect) {
            const vw = width;
            const vh = width / videoAspect;
            return { vx: 0, vy: (height - vh) / 2, vw: vw, vh: vh };
        } else {
            const vh = height;
            const vw = height * videoAspect;
            return { vx: (width - vw) / 2, vy: 0, vw: vw, vh: vh };
        }
    };
    Tracker.prototype.startRafLoop = function() {
        if (!global.requestAnimationFrame || this.rafId) return;
        const self = this;
        function loop() {
            if (!self.active) return;
            self.interpolate();
            self.draw();
            self.rafId = global.requestAnimationFrame(loop);
        }
        this.rafId = global.requestAnimationFrame(loop);
    };
    Tracker.prototype.interpolate = function() {
        if (!this.targetReticle) return;
        if (!this.currentReticle) {
            this.currentReticle = {
                cx: this.targetReticle.cx, cy: this.targetReticle.cy,
                rx: this.targetReticle.rx, ry: this.targetReticle.ry,
                yaw: this.targetReticle.yaw, opacity: this.targetReticle.opacity,
                stable: this.targetReticle.stable
            };
            return;
        }
        const alpha = 0.22;
        this.currentReticle.cx += (this.targetReticle.cx - this.currentReticle.cx) * alpha;
        this.currentReticle.cy += (this.targetReticle.cy - this.currentReticle.cy) * alpha;
        this.currentReticle.rx += (this.targetReticle.rx - this.currentReticle.rx) * alpha;
        this.currentReticle.ry += (this.targetReticle.ry - this.currentReticle.ry) * alpha;
        this.currentReticle.yaw += (this.targetReticle.yaw - this.currentReticle.yaw) * alpha;
        this.currentReticle.opacity += (this.targetReticle.opacity - this.currentReticle.opacity) * alpha;
        this.currentReticle.stable = this.targetReticle.stable;
    };
    Tracker.prototype.updateReticleTarget = function() {
        if (!this.latest || !this.latest.face) {
            if (this.targetReticle) this.targetReticle.opacity = 0;
            return;
        }
        const width = this.canvas && this.canvas.clientWidth ? this.canvas.clientWidth : 400;
        const height = this.canvas && this.canvas.clientHeight ? this.canvas.clientHeight : 300;
        const box = this.getVideoBox(width, height);
        const mirror = this.facing === 'user';
        const x = p => box.vx + (mirror ? 1 - p[0] : p[0]) * box.vw;
        const y = p => box.vy + p[1] * box.vh;
        const points = this.latest.face.points;
        const xs = points.map(x), ys = points.map(y);
        const left = Math.min.apply(null, xs), right = Math.max.apply(null, xs);
        const top = Math.min.apply(null, ys), bottom = Math.max.apply(null, ys);
        const fcx = (left + right) / 2, fcy = (top + bottom) / 2;
        const rx = Math.max(20, (right - left) * 0.65);
        const ry = Math.max(25, (bottom - top) * 0.72);
        const isDeparture = this.target === 8 || this.target === 9;
        this.targetReticle = {
            cx: fcx, cy: fcy, rx: rx, ry: ry,
            yaw: typeof this.latest.yaw === 'number' && Number.isFinite(this.latest.yaw) ? this.latest.yaw : 0,
            opacity: isDeparture ? 0 : 1,
            stable: !!this.latest.stable
        };
        if (!this.currentReticle) {
            this.currentReticle = {
                cx: this.targetReticle.cx, cy: this.targetReticle.cy,
                rx: this.targetReticle.rx, ry: this.targetReticle.ry,
                yaw: this.targetReticle.yaw, opacity: this.targetReticle.opacity,
                stable: this.targetReticle.stable
            };
        }
    };
    Tracker.prototype.start = function() {
        if (!global.Worker || !global.createImageBitmap) { this.onFailure('LOCAL_TRACKING_UNSUPPORTED'); return; }
        try {
            this.worker = new Worker(global.faceLandmarkerWorkerUrl || 'js/faceEnrollmentLandmarker.worker.js', {type: 'module'});
            this.active = true;
            const self = this;
            if (typeof document !== 'undefined' && document.addEventListener) {
                this.visibilityHandler = function() {
                    if (document.hidden) {
                        if (self.rafId && global.cancelAnimationFrame) {
                            global.cancelAnimationFrame(self.rafId);
                            self.rafId = null;
                        }
                    } else if (self.active && !self.rafId && global.requestAnimationFrame) {
                        self.startRafLoop();
                    }
                };
                document.addEventListener('visibilitychange', this.visibilityHandler);
            }
            this.worker.onmessage = function(event) {
                const data = event.data || {};
                if (data.type === 'error') { self.stop(); self.onFailure(data.reason); return; }
                if (data.type === 'ready') {
                    self.ready = true;
                    self.startRafLoop();
                    self.tick();
                    return;
                }
                if (data.type !== 'observation') return;
                self.busy = false;
                self.latest = self.measure(data);
                if (self.latest.stable && Number.isFinite(self.latest.yaw)) {
                    self.yawHistory.push(self.latest.yaw);
                    if (self.yawHistory.length > 6) self.yawHistory.shift();
                } else self.yawHistory = [];
                self.updateReticleTarget();
                if (!global.requestAnimationFrame) {
                    self.draw();
                }
                self.onUpdate(self.latest);
                self.timer = global.setTimeout(function(){ self.tick(); }, 75);
            };
            this.worker.onerror = function(){ self.stop(); self.onFailure('LANDMARKER_WORKER_FAILED'); };
            this.worker.postMessage({type: 'init'});
        } catch (error) { this.stop(); this.onFailure('LANDMARKER_WORKER_FAILED'); }
    };
    Tracker.prototype.stop = function() {
        this.active = false;
        this.ready = false;
        this.busy = false;
        if (this.rafId && global.cancelAnimationFrame) {
            global.cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
        if (this.visibilityHandler && typeof document !== 'undefined' && document.removeEventListener) {
            document.removeEventListener('visibilitychange', this.visibilityHandler);
            this.visibilityHandler = null;
        }
        if (this.timer) global.clearTimeout(this.timer);
        if (this.worker) this.worker.terminate();
        this.worker = null;
        this.currentReticle = null;
        this.targetReticle = null;
        this.lastCentroid = null;
        const context = this.canvas && this.canvas.getContext('2d');
        if (context) context.clearRect(0, 0, this.canvas.width, this.canvas.height);
    };
    Tracker.prototype.tick = async function() {
        if (!this.active || !this.ready || this.busy) return;
        if (!this.video || this.video.readyState < 2) {
            const self = this;
            this.timer = global.setTimeout(function(){ self.tick(); }, 100);
            return;
        }
        this.busy = true;
        try {
            const bitmap = await global.createImageBitmap(this.video);
            if (!this.active) { bitmap.close(); return; }
            const aspectRatio = this.video.videoWidth > 0 && this.video.videoHeight > 0
                ? this.video.videoHeight / this.video.videoWidth : null;
            this.worker.postMessage({type: 'frame', bitmap: bitmap,
                timestamp: performance.now(), observedAt: Date.now(), aspectRatio: aspectRatio}, [bitmap]);
        } catch (error) { this.stop(); this.onFailure('LOCAL_FRAME_UNAVAILABLE'); }
    };
    Tracker.prototype.setTarget = function(slot) {
        if (this.target !== slot) {
            this.target = slot; this.stableSince = null;
            this.yawHistory = [];
            if (this.latest) { this.latest.stable = false; this.latest.stableMs = 0; }
            this.updateReticleTarget();
        }
        this.draw();
    };
    Tracker.prototype.resetHold = function() {
        this.stableSince = null;
        this.yawHistory = [];
        if (this.latest) this.latest.stableMs = 0;
    };
    Tracker.prototype.measure = function(data) {
        const faces = data.faces || [];
        const now = Date.now();
        const result = {observedAt: data.observedAt, faceCount: faces.length,
            face: faces[0] || null, yaw: null, matrixYaw: null, light: data.light,
            yawDegrees: data.metrics && data.metrics.yaw, pitchDegrees: data.metrics && data.metrics.pitch,
            rollDegrees: data.metrics && data.metrics.roll, planarZVariance: data.metrics && data.metrics.client_z_variance,
            edgeErrors: Array.isArray(data.errors) ? data.errors.slice() : ['QUALITY_UNAVAILABLE'],
            edgeValid: data.isValid === true,
            correction: 'Position one face in the preview.', stable: false,
            inferMs: data.inferMs};
        if (faces.length !== 1) {
            result.correction = faces.length > 1 ? 'Keep only one face in view.' : 'Move into the camera view.';
            if (faces.length > 1 && this.lastCentroid) {
                let bestFace = faces[0], bestDist = Infinity;
                for (let i = 0; i < faces.length; i++) {
                    const pts = faces[i].points;
                    const xs = pts.map(p => p[0]), ys = pts.map(p => p[1]);
                    const cx = (Math.min.apply(null, xs) + Math.max.apply(null, xs)) / 2;
                    const cy = (Math.min.apply(null, ys) + Math.max.apply(null, ys)) / 2;
                    const d = Math.hypot(cx - this.lastCentroid[0], cy - this.lastCentroid[1]);
                    if (d < bestDist) { bestDist = d; bestFace = faces[i]; }
                }
                result.face = bestFace;
            }
            if (faces.length === 0) this.lastCentroid = null;
        } else {
            const points = faces[0].points;
            const xs = points.map(p => p[0]), ys = points.map(p => p[1]);
            const left = Math.min.apply(null, xs), right = Math.max.apply(null, xs);
            const top = Math.min.apply(null, ys), bottom = Math.max.apply(null, ys);
            const centreX = (left + right) / 2, centreY = (top + bottom) / 2;
            this.lastCentroid = [centreX, centreY];
            const eyeLeft = points[33], eyeRight = points[263], nose = points[1];
            result.yaw = (nose[0] - (eyeLeft[0] + eyeRight[0]) / 2) / Math.max(0.001, Math.abs(eyeRight[0] - eyeLeft[0]));
            const matrix = faces[0].matrix;
            if (matrix && matrix.length === 16 && matrix.every(Number.isFinite))
                result.matrixYaw = Math.atan2(matrix[8], matrix[0]);
            const desired = this.target === 2 ? -1 : this.target === 3 ? 1 :
                this.target === 4 ? this.extraDirection : 0;
            if (!result.edgeValid) {
                if (result.edgeErrors.indexOf('BLUR') !== -1) result.correction = 'Hold still so the camera can focus.';
                else if (result.edgeErrors.indexOf('LIGHTING') !== -1) result.correction = 'Move into even lighting.';
                else if (result.edgeErrors.indexOf('SPOOF') !== -1) result.correction = 'Show a real face, not a photo or screen.';
                else result.correction = 'Checking camera quality…';
            } else if (left < 0.02 || right > 0.98 || top < 0.02 || bottom > 0.98 || Math.abs(centreX - 0.5) > 0.15 || Math.abs(centreY - 0.5) > 0.15)
                result.correction = 'Move your face toward the centre.';
            else if (bottom - top < 0.37) result.correction = 'Move a little closer.';
            else if (bottom - top > 0.86) result.correction = 'Move a little farther away.';
            else if (data.light !== null && data.light < 55) result.correction = 'Move toward better light.';
            else if (data.light !== null && data.light > 225) result.correction = 'Move out of bright light.';
            else if (desired === 0 && Math.abs(result.yaw) > 0.08) result.correction = 'Look straight at the camera.';
            else if (desired !== 0 && desired * result.yaw < -0.02)
                result.correction = 'Turn toward the ' + (desired < 0 !== (this.facing === 'user') ? 'left' : 'right') + ' side of the preview.';
            else if (desired !== 0 && desired * result.yaw < 0.05) result.correction = 'Turn a little more.';
            else if (desired !== 0 && desired * result.yaw > 0.22) result.correction = 'Turn back slightly.';
            else { result.correction = 'Hold still.'; result.stable = true; }
        }
        if (!result.stable || !Number.isFinite(data.observedAt) || now - data.observedAt > 400) this.stableSince = null;
        else if (this.stableSince === null) this.stableSince = data.observedAt;
        result.stableMs = this.stableSince === null ? 0 : Math.max(0, data.observedAt - this.stableSince);
        return result;
    };
    Tracker.prototype.readyForCandidate = function() {
        return !!this.latest && this.latest.stable && this.latest.stableMs >= 300
            && Date.now() - this.latest.observedAt < 400;
    };
    Tracker.prototype.steadinessScore = function() {
        if (this.yawHistory.length < 3) return null;
        let motion = 0;
        for (let i = 1; i < this.yawHistory.length; i++)
            motion += Math.abs(this.yawHistory[i] - this.yawHistory[i - 1]);
        return motion / (this.yawHistory.length - 1);
    };
    Tracker.prototype.draw = function() {
        const canvas = this.canvas;
        if (!canvas) return;
        const width = canvas.clientWidth, height = canvas.clientHeight;
        if (!width || !height) return;
        const scale = global.devicePixelRatio || 1;
        if (canvas.width !== Math.round(width * scale) || canvas.height !== Math.round(height * scale)) {
            canvas.width = Math.round(width * scale); canvas.height = Math.round(height * scale);
        }
        const context = canvas.getContext('2d');
        if (!context) return;
        context.setTransform(scale, 0, 0, scale, 0, 0);
        context.clearRect(0, 0, width, height);

        const box = this.getVideoBox(width, height);
        const mirror = this.facing === 'user';
        const x = p => box.vx + (mirror ? 1 - p[0] : p[0]) * box.vw;
        const y = p => box.vy + p[1] * box.vh;

        if (!this.latest || !this.latest.face || Date.now() - this.latest.observedAt > 500) return;
        const points = this.latest.face.points;

        // 1. Draw facial outline path (begins with moveTo for test contract compatibility)
        context.strokeStyle = this.latest.stable ? '#69f5a7' : '#ffd577';
        context.fillStyle = context.strokeStyle;
        context.lineWidth = 2.5;
        context.beginPath();
        outline.forEach((index, i) => { const point = points[index]; if (!point) return;
            if (i === 0) context.moveTo(x(point), y(point)); else context.lineTo(x(point), y(point)); });
        context.closePath(); context.stroke();

        // 2. Landmark anchor dots (eyes and nose tip)
        [33,263,1].forEach(index => { const point = points[index]; if (!point) return;
            context.beginPath(); context.arc(x(point), y(point), 3, 0, Math.PI * 2); context.fill(); });

        // 3. Dynamic Face-Following Reticle (Halo)
        const xs = points.map(x), ys = points.map(y);
        const left = Math.min.apply(null, xs), right = Math.max.apply(null, xs);
        const top = Math.min.apply(null, ys), bottom = Math.max.apply(null, ys);
        const fcx = (left + right) / 2, fcy = (top + bottom) / 2;
        const hrx = Math.max(20, (right - left) * 0.65);
        const hry = Math.max(25, (bottom - top) * 0.72);
        const reticle = this.currentReticle || {
            cx: fcx, cy: fcy, rx: hrx, ry: hry,
            yaw: this.latest.yaw || 0, opacity: 1, stable: this.latest.stable
        };
        if (context.ellipse && reticle.opacity > 0.05) {
            if (context.save) context.save();
            context.strokeStyle = reticle.stable ? '#25d366' : '#f0ad4e';
            context.lineWidth = 3;
            const tilt = Math.max(-0.25, Math.min(0.25, (reticle.yaw || 0) * (mirror ? -0.4 : 0.4)));
            context.beginPath();
            context.ellipse(reticle.cx, reticle.cy, reticle.rx, reticle.ry, tilt, 0, Math.PI * 2);
            context.stroke();
            if (context.restore) context.restore();
        }

        // 4. Directional turn target crosshair (ends with target arc for test contract compatibility)
        const desired = this.target === 2 ? -1 : this.target === 3 ? 1 :
            this.target === 4 ? this.extraDirection : 0;
        const targetOnLeft = desired < 0 !== mirror;
        let tx = desired === 0 ? (left + right) / 2 : targetOnLeft ? left - 17 : right + 17;
        let ty = this.target < 2 || this.target >= 5 ? top - 17 : Math.max(20, top + 22);
        tx = Math.max(20, Math.min(width - 20, tx)); ty = Math.max(20, Math.min(height - 20, ty));
        context.beginPath(); context.arc(tx, ty, 8, 0, Math.PI * 2); context.stroke();
        context.beginPath(); context.moveTo(tx - 12, ty); context.lineTo(tx + 12, ty);
        context.moveTo(tx, ty - 12); context.lineTo(tx, ty + 12); context.stroke();
    };
    global.FaceEnrollmentLocalTracker = Tracker;
})(window);
