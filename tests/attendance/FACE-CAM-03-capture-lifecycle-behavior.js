'use strict';
const {assert, startScan, runCases} = require('./helpers/face-controller-harness');
runCases({
    async late_camera_after_cancel(h) {
        await startScan(h); h.scope.switchFaceCamera();
        h.scope.cancelFaceScan(); await h.flush();
        const late = await h.resolveCamera();
        assert.equal(late.track.readyState, 'ended', 'late switched camera must be stopped');
        assert.equal(h.elements.faceAttendanceVideo.srcObject, null);
    },
    async latest_switch_owns_stream(h) {
        await startScan(h); h.scope.switchFaceCamera(); h.scope.switchFaceCamera();
        const newest = await h.resolveCamera(2), older = await h.resolveCamera(1);
        assert.equal(h.elements.faceAttendanceVideo.srcObject, newest, 'newest camera switch must retain ownership');
        assert.equal(older.track.readyState, 'ended');
    },
    async initial_camera_cancel_guard(h) {
        h.scope.startFacePunchIn(); await h.resolveRequest('getFaceReadiness', {state: 'READY'});
        h.scope.cancelFaceScan(); await h.flush(); const late = await h.resolveCamera();
        assert.equal(late.track.readyState, 'ended');
        assert.equal(h.pending('startFaceScan').length, 0);
    },
    async hidden_page_stops_tracks(h) {
        await startScan(h); h.document.hidden = true; h.emit('visibilitychange'); await h.flush();
        assert.ok(h.streams.every(s => s.track.readyState === 'ended'));
        assert.equal(h.elements.faceAttendanceVideo.srcObject, null);
    }
});
