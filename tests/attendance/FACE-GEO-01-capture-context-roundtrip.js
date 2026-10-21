#!/usr/bin/env node

'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const start = controller.indexOf('function videoContentRect');
const end = controller.indexOf('\n    function freezeSubmittedFrame', start);
assert(start >= 0 && end > start, 'capture geometry helpers must be extractable');

function helpers(facingMode) {
    const sandbox = {$scope: {cameraFacingMode: facingMode}, result: null};
    vm.runInNewContext(controller.slice(start, end) + '\nresult = {videoContentRect, buildCaptureContext};', sandbox);
    return sandbox.result;
}

function fixture(rawWidth, rawHeight, displayWidth, displayHeight, guide, facingMode) {
    const frame = {querySelector: () => guide};
    const video = {
        videoWidth: rawWidth,
        videoHeight: rawHeight,
        clientWidth: displayWidth,
        clientHeight: displayHeight,
        offsetWidth: displayWidth,
        offsetHeight: displayHeight,
        closest: () => frame,
        parentNode: frame
    };
    const canvas = {width: rawWidth, height: rawHeight};
    return {video, canvas, context: helpers(facingMode).buildCaptureContext(video, canvas)};
}

function roundTrip(capture) {
    const c = capture.context;
    const content = c.display.content_rect;
    const displayedRawX = c.mirrored_preview ? 1 - c.guide.cx : c.guide.cx;
    return {
        x: content.x + displayedRawX * content.width,
        y: content.y + c.guide.cy * content.height
    };
}

[
    [1280, 720, 400, 400],
    [720, 1280, 400, 600],
    [1920, 1080, 360, 640],
    [640, 480, 1024, 600]
].forEach(([rw, rh, dw, dh]) => {
    ['environment', 'user'].forEach(mode => {
        const content = helpers(mode).videoContentRect({videoWidth: rw, videoHeight: rh, clientWidth: dw, clientHeight: dh});
        const guide = {
            offsetLeft: content.x + content.width * 0.25,
            offsetTop: content.y + content.height * 0.15,
            offsetWidth: content.width * 0.5,
            offsetHeight: content.height * 0.7
        };
        const capture = fixture(rw, rh, dw, dh, guide, mode);
        const display = roundTrip(capture);
        assert(Math.abs(display.x - (guide.offsetLeft + guide.offsetWidth / 2)) < 1e-6, 'mirror/raw X must round-trip');
        assert(Math.abs(display.y - (guide.offsetTop + guide.offsetHeight / 2)) < 1e-6, 'raw Y must round-trip');
        assert.strictEqual(capture.context.display.object_fit, 'contain');
        assert.strictEqual(capture.context.frame.width, rw);
        assert.strictEqual(capture.context.frame.height, rh);
    });
});

// Guides touching each content edge remain normalized and bounded.
const edgeContent = helpers('environment').videoContentRect({videoWidth: 1000, videoHeight: 1000, clientWidth: 500, clientHeight: 500});
[
    {offsetLeft: 0, offsetTop: 150, offsetWidth: 100, offsetHeight: 200},
    {offsetLeft: 400, offsetTop: 150, offsetWidth: 100, offsetHeight: 200},
    {offsetLeft: 200, offsetTop: 0, offsetWidth: 100, offsetHeight: 200},
    {offsetLeft: 200, offsetTop: 300, offsetWidth: 100, offsetHeight: 200}
].forEach(guide => {
    const c = fixture(1000, 1000, 500, 500, guide, 'environment').context;
    assert(c.guide.cx - c.guide.rx >= 0 && c.guide.cx + c.guide.rx <= 1, 'horizontal guide edge must be bounded');
    assert(c.guide.cy - c.guide.ry >= 0 && c.guide.cy + c.guide.ry <= 1, 'vertical guide edge must be bounded');
    assert.strictEqual(JSON.stringify(c.display.content_rect), JSON.stringify(edgeContent));
});

console.log('PASS FACE-GEO-01 capture-context coordinate round-trip contract');
