'use strict';
const {assert, setup, face, empty, startEnrollment, runCases} = require('./helpers/face-controller-harness');
async function reachSeparation(h) {
    await startEnrollment(h); h.scope.captureFaceEnrollment();
    await h.advanceClock(1100, async () => {
        for (const r of h.pending('previewEnrollment')) await h.resolveRequest(r, face());
    });
    setup(h.scope.faceEnrollmentState === 'LEAVE_GUIDE', 'burst A must finish');
}
runCases({
    async submitted_frame_equals_checked_frame(h) {
        await startEnrollment(h); h.scope.captureFaceEnrollment();
        const checked = [];
        let leaveAt = null;
        await h.advanceClock(5000, async () => {
            for (const r of h.pending('previewEnrollment')) {
                if (r.enrollmentState === 'CAPTURING') checked.push(r.data);
                if (h.scope.faceEnrollmentState === 'LEAVE_GUIDE' && leaveAt === null) leaveAt = h.now();
                await h.resolveRequest(r, face());
            }
        });
        const submission = h.pending('enrollFace')[0];
        setup(submission && checked.length === 8, 'eight checked frames must reach submission');
        const frames = submission.data.getAll('frames[]');
        const contexts = JSON.parse(submission.data.get('capture_contexts'));
        assert.equal(frames.length, 8);
        for (let i = 0; i < 8; i++) {
            assert.equal(await frames[i].text(), await checked[i].get('frame').text(), 'submitted JPEG must equal checked JPEG');
            assert.deepEqual(contexts[i], JSON.parse(checked[i].get('capture_context')), 'submitted context must equal checked context');
        }
    },
    async unavailable_is_not_burst_separation(h) {
        await reachSeparation(h);
        await h.advanceClock(1200, async () => {
            for (const r of h.pending('previewEnrollment')) await h.resolveRequest(r, null, true);
        });
        assert.equal(h.scope.faceEnrollmentState, 'LEAVE_GUIDE', 'detector outage must not satisfy burst separation');
        assert.equal(h.pending('enrollFace').length, 0);
    },
    async old_student_callback_is_ignored(h) {
        await startEnrollment(h); h.scope.captureFaceEnrollment();
        const old = h.pending('previewEnrollment')[0]; setup(old, 'capture validation pending');
        h.scope.cancelFaceEnrollment(); await h.flush();
        h.scope.startFaceEnrollment(h.scope.list[1]); await h.flush();
        await h.resolveRequest(old, face());
        assert.equal(h.scope.faceEnrollmentFrameCount, 0);
        assert.equal(h.scope.faceEnrollmentMemberName, 'Fixture 102');
        assert.equal(h.pending('enrollFace').length, 0);
    }
});
