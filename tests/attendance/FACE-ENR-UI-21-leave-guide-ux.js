'use strict';
const {assert, setup, face, empty, startEnrollment, runCases} = require('./helpers/face-controller-harness');

async function reachFinalPause(h) {
    await startEnrollment(h);
    h.scope.captureFaceEnrollment();
    await h.advanceClock(1100, async () => {
        for (const r of h.pending('previewEnrollment')) await h.resolveRequest(r, face());
    });
    setup(h.scope.faceEnrollmentState === 'LEAVE_GUIDE', 'first five frames must finish');
}

async function pump(h, observation) {
    for (const r of h.pending('previewEnrollment')) await h.resolveRequest(r, observation);
}

runCases({
    async final_pause_keeps_face_in_guide(h) {
        await reachFinalPause(h);
        assert.match(h.scope.faceEnrollmentMessage, /Hold still for the final check/);
        assert.doesNotMatch(h.scope.faceEnrollmentMessage, /move out|step out|come back/i);
        assert.ok(h.scope.faceEnrollmentDetectionStatusClass.includes('is-leave-guide'));
    },
    async face_present_fills_pause_indicator(h) {
        await reachFinalPause(h);
        await h.advanceClock(350, () => pump(h, face()));
        assert.equal(h.scope.faceEnrollmentState, 'LEAVE_GUIDE');
        assert.ok(h.scope.faceEnrollmentLeaveHoldPct > 0);
        assert.ok(h.scope.faceEnrollmentLeaveHoldPct < 100);
    },
    async missing_face_does_not_start_final_capture(h) {
        await reachFinalPause(h);
        await h.advanceClock(1200, () => pump(h, empty()));
        assert.equal(h.scope.faceEnrollmentState, 'LEAVE_GUIDE');
        assert.equal(h.scope.faceEnrollmentFrameCount, 5);
        assert.match(h.scope.faceEnrollmentMessage, /Keep your face in the guide/);
        await h.advanceClock(250, () => pump(h, face()));
        assert.equal(h.scope.faceEnrollmentState, 'LEAVE_GUIDE',
            'returning face still needs fresh stable observations');
        await h.advanceClock(350, () => pump(h, face()));
        assert.equal(h.scope.faceEnrollmentState, 'CAPTURING');
    },
    async continuous_face_resumes_final_capture(h) {
        await reachFinalPause(h);
        await h.advanceClock(1200, () => pump(h, face()));
        assert.equal(h.scope.faceEnrollmentState, 'CAPTURING');
        await h.advanceClock(900, () => pump(h, face()));
        assert.equal(h.scope.faceEnrollmentFrameCount, 8);
        assert.equal(h.pending('enrollFace').length, 1);
    },
    async detector_failure_ends_with_specific_error(h) {
        await reachFinalPause(h);
        await h.advanceClock(10500, async () => {
            for (const r of h.pending('previewEnrollment')) await h.resolveRequest(r, null, true);
        });
        assert.equal(h.scope.faceEnrollmentState, 'ERROR');
        assert.match(h.scope.faceEnrollmentMessage, /secure camera check is unavailable/i);
        assert.match(h.scope.faceEnrollmentMessage, /Try Again/);
    },
    async deferred_evalasync_does_not_abort_final_capture(h) {
        await reachFinalPause(h);
        const originalEvalAsync = h.scope.$evalAsync;
        const deferredQueue = [];
        await h.advanceClock(600, () => pump(h, face()));
        h.scope.$evalAsync = fn => { if (fn) deferredQueue.push(fn); };
        try {
            await h.advanceClock(400, () => pump(h, face()));
            assert.equal(h.scope.faceEnrollmentState, 'CAPTURING');
            assert.ok(h.pending('previewEnrollment').length > 0 || h.timers.size > 0,
                'final capture must have a pending check or scheduled observation');
            while (deferredQueue.length) deferredQueue.shift()();
        } finally {
            h.scope.$evalAsync = originalEvalAsync;
        }
    }
});
