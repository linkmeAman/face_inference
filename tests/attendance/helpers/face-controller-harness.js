'use strict';

// Execute the complete production controller. Only browser/transport boundaries
// are simulated; camera pixels below are identity tokens, not biometric evidence.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {FaceAttendanceStateMachine} = require('../../../pf/js/controllers/faceAttendanceController.js');

class FormDataFixture {
    constructor() { this.entries = []; }
    append(name, value) { this.entries.push([name, value]); }
    get(name) { return this.getAll(name)[0] ?? null; }
    getAll(name) { return this.entries.filter(([key]) => key === name).map(([, value]) => value); }
}
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return {promise, resolve, reject};
}
function setup(condition, message) {
    if (!condition) throw new Error('HARNESS_SETUP: ' + message);
}
function createHarness(autoEarlyIdentity = true) {
    let now = 1000000, serial = 0, capture = 0;
    let edgePreview = 'face';
    const timers = new Map(), requests = [], cameras = [], streams = [], events = {}, errors = [];
    const scope = {$evalAsync: fn => fn && fn(), $apply: fn => fn && fn(), $on() {}};
    const elements = {};
    function element() {
        return {style: {}, clientWidth: 640, clientHeight: 480, offsetWidth: 640, offsetHeight: 480,
            setAttribute() {}, addEventListener() {}, removeEventListener() {},
            getBoundingClientRect: () => ({left: 0, top: 0, width: 640, height: 480})};
    }
    for (const name of ['faceAttendance', 'faceEnrollment']) {
        const frame = element(), guide = element(), video = element();
        Object.assign(guide, {offsetLeft: 187, offsetTop: 55, offsetWidth: 266, offsetHeight: 355});
        Object.assign(video, {readyState: 4, videoWidth: 640, videoHeight: 480, srcObject: null,
            play: () => Promise.resolve(), closest: () => frame, parentNode: frame});
        frame.querySelector = sel => sel === '.face-camera-guide' ? guide : sel === 'video' ? video : null;
        frame.appendChild = child => { child.parentNode = frame; };
        frame.removeChild = child => { child.parentNode = null; };
        elements[name + 'Video'] = video;
        elements[name + 'Frame'] = frame;
    }
    elements.faceEnrollmentTrackerOverlay = element();
    const document = {hidden: false, getElementById: id => elements[id] || null,
        querySelectorAll: () => [], addEventListener: (name, fn) => { events[name] = fn; },
        createElement(name) {
            if (name !== 'canvas') return element();
            let identity;
            return {width: 640, height: 480,
                getContext: () => ({drawImage() { identity = ++capture; }}),
                toDataURL: () => 'data:image/jpeg;base64,' + Buffer.from('synthetic-frame-' + identity).toString('base64'),
                toBlob(fn) { fn(new Blob(['synthetic-frame-' + identity], {type: 'image/jpeg'})); }};
        }};
    function request(action, data, route) {
        const item = {...deferred(), action, data, route, at: now, settled: false,
            enrollmentState: scope.faceEnrollmentState};
        requests.push(item);
        return item.promise;
    }
    class PlanarWorkerFixture {
        constructor() { this.onmessage = null; this.onerror = null; }
        postMessage(message) {
            if (message.type === 'init') {
                Promise.resolve().then(() => this.onmessage && this.onmessage({data: {type: 'ready'}}));
            } else if (message.type === 'frame') {
                const noFace = edgePreview === 'empty';
                const unavailable = edgePreview === 'unavailable';
                Promise.resolve().then(() => this.onmessage && this.onmessage({data: {
                    type: 'observation', requestId: message.requestId,
                    epoch: Number.isInteger(message.epoch) ? message.epoch : 0,
                    isValid: !noFace && !unavailable && message.aspectRatio === 0.75,
                    errors: noFace ? ['NO_FACE'] : unavailable ? ['QUALITY_UNAVAILABLE']
                        : message.aspectRatio === 0.75 ? [] : ['PLANAR_UNAVAILABLE'],
                    metrics: {client_z_variance: !noFace && !unavailable && message.aspectRatio === 0.75 ? 0.10 : null,
                        yaw: 0, pitch: 0, roll: 0},
                    box: noFace ? null : {x: .25, y: .15, width: .52, height: .56},
                    faces: noFace ? [] : [{client_z_variance: message.aspectRatio === 0.75 ? 0.10 : null}],
                }}));
            }
        }
        terminate() {}
    }
    const window = {document, FaceAttendanceStateMachine, faceAttendanceFeatureEnabled: true,
        faceTeacherSelectionEnabled: true, faceEnrollmentFeatureEnabled: true,
        location: {origin: 'https://fixture.invalid'}, localStorage: {getItem: () => null, setItem() {}},
        Worker: PlanarWorkerFixture,
        createImageBitmap: () => Promise.resolve({close() {}}),
        setTimeout(fn, delay = 0) { const id = ++serial; timers.set(id, {fn, at: now + delay}); return id; },
        clearTimeout: id => timers.delete(id),
        setInterval(fn, delay = 0) { const id = ++serial; const repeat = () => { fn(); timers.set(id, {fn: repeat, at: now + delay}); }; timers.set(id, {fn: repeat, at: now + delay}); return id; },
        clearInterval: id => timers.delete(id), addEventListener: (name, fn) => { events[name] = fn; }};
    class ClockDate extends Date { static now() { return now; } }
    const jquery = () => ({fadeIn() {}, fadeOut() {}, prop() {}, attr() {}, modal() {}, val() {},
        on() {}, select2() {}, text() {}, addClass() {}, removeClass() {}});
    const globals = {window, document, navigator: {userAgent: 'fixture', mediaDevices: {
        getUserMedia(constraints) { const item = {...deferred(), constraints}; cameras.push(item); return item.promise; }}},
        Date: ClockDate, Blob, Uint8Array, FormData: FormDataFixture,
        atob: text => Buffer.from(text, 'base64').toString('binary'),
        btoa: text => Buffer.from(text, 'binary').toString('base64'),
        setTimeout: window.setTimeout, clearTimeout: window.clearTimeout,
        console: {log() {}, warn() {}, error: (...args) => errors.push(args)},
        moment: () => ({day: () => 5, format: format => format === 'YYYY-MM-DD' ? '2026-10-02' : '02-10-2026'}),
        session: {bid: 7, user_id: 9001, position_id: 1, user_type: 'E', session_id: 'fixture', token: 'fixture'},
        session_id: 'fixture', json_header: {}, $_GET: {}, $: jquery,
        angular: {identity: value => value, forEach: (items, fn) => items.forEach(fn)},
        Swal: {fire: () => Promise.resolve({isConfirmed: true})}, show_snack() {},
        app: {controller(name, controller) {
            controller(scope, {callEndpoint: (data, route, action) => request(action, data, route)},
                config => request(config.params.action, config.data, config.url));
        }}};
    window.atob = globals.atob;
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,
        '../../../pf/js/controllers/batchAttendanceController.js'), 'utf8'), globals,
        {filename: 'batchAttendanceController.js'});
    scope.list = [101, 102].map((id, i) => ({id, member_id: id, contact_id: 1101 + i,
        fullname: 'Fixture ' + id, type: 'M', negative: 1, selected: false, skip: false, face_mapping_active: true}));
    Object.assign(scope.batch, {id: 11, session_id: 4, time: '09:00:00'});
    scope.attendanceStage = 'READY';
    async function flush() { for (let i = 0; i < 24; i++) await Promise.resolve(); }
    async function advanceClock(ms, pump) {
        const end = now + ms;
        for (let count = 0; count < 10000; count++) {
            await flush();
            if (pump) { await pump(); await flush(); }
            const next = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) { now = end; await flush(); if (pump) await pump(); await flush(); return; }
            timers.delete(next[0]); now = next[1].at; next[1].fn();
        }
        throw new Error('HARNESS_SETUP: timer runaway');
    }
    function pending(action) { return requests.filter(r => !r.settled && (!action || r.action === action)); }
    async function resolveRequest(requestOrAction, body, reject = false) {
        const item = typeof requestOrAction === 'string' ? pending(requestOrAction)[0] : requestOrAction;
        setup(item && !item.settled, 'missing pending request ' + requestOrAction);
        item.settled = true;
        if (reject) item.reject(new Error('simulated transport loss'));
        else item.resolve({data: body});
        await flush();
        // Older capture cases exercise later poses and final submission. Their
        // synthetic identity index is clear; focused early-identity cases opt out.
        if (autoEarlyIdentity) {
            for (const early of pending('checkEarlyEnrollmentIdentity')) {
                early.settled = true;
                early.resolve({data: {state:'CLEAR', reason_code:'IDENTITY_CLEAR'}});
                await flush();
            }
        }
    }
    async function resolveCamera(index = cameras.length - 1) {
        setup(cameras[index], 'missing camera request');
        const track = {readyState: 'live', stops: 0, addEventListener() {},
            stop() { this.readyState = 'ended'; this.stops++; }};
        const stream = {id: index, getTracks: () => [track], getVideoTracks: () => [track], track};
        streams.push(stream); cameras[index].resolve(stream); await flush(); return stream;
    }
    async function dispose() { if (events.pagehide) events.pagehide(); await flush(); timers.clear();
        streams.forEach(stream => stream.track.stop()); }
    return {scope, requests, cameras, streams, elements, errors, timers, document, window,
        advanceClock, resolveRequest, resolveCamera, pending, flush, dispose,
        emit: name => events[name] && events[name](), now: () => now,
        setEdgePreview: value => { edgePreview = value === unavailable ? 'unavailable' : value === empty ? 'empty' : 'face'; }};
}
const face = () => ({state: 'PREVIEW', capture_state: 'CLEAR', reference_match_state: 'MATCH',
    faces: [{x: 230, y: 130, width: 180, height: 200,
        quality: {inside_guide: true, yaw_ok: true, tilt_ok: true, facing_camera: true, reasons: []}}],
    selected_face_index: 0, reason_code: 'QUALITY_ACCEPTED'});
const empty = () => ({state: 'PREVIEW', capture_state: 'CLEAR', faces: [], selected_face_index: null, reason_code: 'NO_FACE'});
async function startScan(h, liveWrite = false) {
    h.scope.startFacePunchIn(); await h.resolveRequest('getFaceReadiness', {state: 'READY'});
    await h.resolveCamera(); await h.resolveRequest('startFaceScan', {state: 'STARTED', scan_id: 1, scan_token: 'fixture', capture_nonce: 'nonce-1', live_write: liveWrite});
    await h.advanceClock(100);
    setup(h.scope.faceState === 'SCANNING', 'scan did not start');
}
async function pumpScan(h, preview = face) {
    h.setEdgePreview(preview);
    for (const r of h.pending()) {
        if (r.action === 'previewFace') await h.resolveRequest(r, preview(), preview === unavailable);
        if (r.action === 'getCaptureNonce') await h.resolveRequest(r, {state: 'READY', capture_nonce: 'nonce-' + h.now()});
    }
}
function unavailable() { return null; }
async function acceptFirst(h) {
    await startScan(h); await h.advanceClock(1000, () => pumpScan(h));
    setup(h.pending('recognizeFaceAttempt').length === 1, 'first attempt missing');
    await h.resolveRequest('recognizeFaceAttempt', {state: 'RECORDED', decision: 'ACCEPT', effect: 'SELECT_PRESENT',
        member_id: 101, contact_id: 1101, member_name: 'Fixture 101', liveness: 'LIVE', next_capture_nonce: 'next'});
    setup(h.scope.list[0].selected === true, 'first selection missing');
}
async function startEnrollment(h, row = h.scope.list[0]) {
    h.scope.startFaceEnrollment(row);
    await h.resolveRequest('beginEnrollment', {state: 'READY', capture_session_token: 'fixture-enrollment',
        has_pending_reference: true, pending_reference: {id: 1, portrait_data_url: 'data:fixture'}});
    await h.resolveCamera(); await h.resolveRequest('previewEnrollment', face());
    setup(h.scope.faceEnrollmentState === 'READY' && h.scope.faceEnrollmentDetected, 'enrollment not ready');
}
async function runCases(cases, autoEarlyIdentity = true) {
    let failed = false;
    for (const [name, test] of Object.entries(cases)) {
        const h = createHarness(autoEarlyIdentity);
        try { await test(h); process.stdout.write(JSON.stringify({case: name, status: 'PASS'}) + '\n'); }
        catch (error) { failed = true; process.stdout.write(JSON.stringify({case: name,
            status: error instanceof assert.AssertionError ? 'FAIL' : 'ERROR', detail: error.message}) + '\n'); }
        finally { await h.dispose(); }
    }
    process.exitCode = failed ? 1 : 0;
}
module.exports = {createHarness, setup, assert, face, empty, unavailable, startScan, pumpScan, acceptFirst, startEnrollment, runCases};
