'use strict';
const {assert, face, runCases} = require('./helpers/face-controller-harness');
const capability = {pose_protocol: 'guided-five-select-v1', template_count: 6,
    burst_a_count: 10, burst_b_count: 3, samples_per_pose: 2,
    windows: {centre_yaw_max: .06, centre_pitch_min: .35, centre_pitch_max: .75,
        side_yaw_min: .08, side_yaw_max: .16, pitch_tolerance: .08,
        pitch_delta_min: .05, pitch_delta_max: .10, stable_ms: 300}};
const targets = [[0,.55],[-.12,.55],[.12,.55],[0,.48],[0,.62]];
function observation(yaw, pitch, extra = {}) {
    const body = face(); body.guided_enrollment = capability;
    Object.assign(body.faces[0].quality, {accepted: true, pose_yaw: yaw, pose_pitch: pitch, sharpness: 60}, extra);
    return body;
}
async function pump(h, response) {
    for (const request of h.pending('previewEnrollment')) await h.resolveRequest(request, response());
}
async function begin(h) {
    h.scope.startFaceEnrollment(h.scope.list[0]);
    await h.resolveRequest('beginEnrollment', {state: 'READY', capture_session_token: 'coaching',
        has_pending_reference: true, pending_reference: {id: 1, portrait_data_url: 'data:fixture'}});
    await h.resolveCamera(); await h.resolveRequest('previewEnrollment', observation(0,.55));
    h.scope.captureFaceEnrollment();
}
async function reach(h, index) {
    for (let attempt=0; attempt<100 && h.scope.faceEnrollmentFrameCount<index; attempt++) {
        await h.advanceClock(300, () => pump(h, () => {
            const pose = targets[Math.floor(h.scope.faceEnrollmentFrameCount / 2)];
            return observation(...pose);
        }));
    }
    assert.equal(h.scope.faceEnrollmentFrameCount, index);
}
runCases({
    async every_direction_coaches_small_correct_excessive_and_opposite_motion(h) {
        await begin(h);
        for (const [index, samples] of [
            [2, [[-.03,.55,/turn a little more/i],[-.12,.55,/Good.*hold still/i],[-.20,.55,/too far.*turn back/i],[.12,.55,/turn the other way/i]]],
            [4, [[.03,.55,/turn a little more/i],[.12,.55,/Good.*hold still/i],[.20,.55,/too far.*turn back/i],[-.12,.55,/turn the other way/i]]],
            [6, [[0,.53,/lift a little more/i],[0,.48,/Good.*hold still/i],[0,.42,/too far.*lower/i],[0,.62,/lift a little more/i]]],
            [8, [[0,.57,/lower a little more/i],[0,.62,/Good.*hold still/i],[0,.70,/too far.*lift/i],[0,.48,/lower a little more/i]]]
        ]) {
            await reach(h,index);
            for (const [yaw,pitch,message] of samples) {
                await h.advanceClock(300, () => pump(h, () => observation(yaw,pitch)));
                assert.match(h.scope.faceEnrollmentPrimaryInstruction(), message, `pose ${index}, ${yaw}, ${pitch}`);
                assert.equal(h.scope.faceEnrollmentDetectionStatusClass, /Good/.test(message.source) ? 'is-ready' : 'is-warning');
                assert.equal(h.scope.faceEnrollmentFrameCount,index);
            }
        }
    },
    async two_photo_markers_and_camera_mirror_are_explicit(h) {
        await begin(h); await reach(h,2);
        assert.equal(h.scope.faceEnrollmentVisualDirection,'right');
        assert.deepEqual(Array.from(h.scope.faceEnrollmentPhotoMarkers),[false,false]);
        assert.deepEqual(Array.from(h.scope.faceEnrollmentPosePhotoCounts),[2,0,0,0,0]);
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(),/shoulders still/i);
        for (let attempt=0; attempt<10 && h.scope.faceEnrollmentFrameCount<3; attempt++)
            await h.advanceClock(300, () => pump(h, () => observation(-.12,.55)));
        assert.equal(h.scope.faceEnrollmentFrameCount,3);
        assert.deepEqual(Array.from(h.scope.faceEnrollmentPhotoMarkers),[true,false]);
        assert.deepEqual(Array.from(h.scope.faceEnrollmentPosePhotoCounts),[2,1,0,0,0]);
        h.scope.cameraFacingMode='environment';
        await h.advanceClock(300, () => pump(h, () => observation(0,.55)));
        assert.equal(h.scope.faceEnrollmentVisualDirection,'left');
        await reach(h,4);
        assert.equal(h.scope.faceEnrollmentVisualDirection,'right');
        assert.deepEqual(Array.from(h.scope.faceEnrollmentPosePhotoCounts),[2,2,0,0,0]);
    },
    async missing_pose_never_shows_green_or_advances(h) {
        await begin(h); await reach(h,2);
        await h.advanceClock(1500, () => pump(h, () => observation(null,.55)));
        assert.equal(h.scope.faceEnrollmentFrameCount,2);
        assert.equal(h.scope.faceEnrollmentDetectionStatusClass,'is-warning');
        await h.advanceClock(2000, () => pump(h, () => observation(-.02,null)));
        assert.equal(h.scope.faceEnrollmentFrameCount,2);
        assert.equal(h.scope.faceEnrollmentShowPoseTip,true);
        assert.ok(h.scope.faceEnrollmentDiagnosticSamples.every(sample =>
            sample.step_elapsed_ms === null || Number.isFinite(sample.step_elapsed_ms)));
    },
    async sharper_second_centre_photo_sets_pitch_baseline(h) {
        await begin(h);
        for (let attempt=0; attempt<10 && h.scope.faceEnrollmentFrameCount<1; attempt++)
            await h.advanceClock(300, () => pump(h, () => observation(0,.54,{sharpness:40})));
        assert.equal(h.scope.faceEnrollmentFrameCount,1);
        for (let attempt=0; attempt<10 && h.scope.faceEnrollmentFrameCount<2; attempt++)
            await h.advanceClock(300, () => pump(h, () => observation(0,.60,{sharpness:90})));
        assert.equal(h.scope.faceEnrollmentFrameCount,2);
        await h.advanceClock(7000, () => pump(h, () => {
            const index=h.scope.faceEnrollmentFrameCount;
            return index < 4 ? observation(-.12,.60) : index < 6 ? observation(.12,.60) : observation(0,.53);
        }));
        assert.ok(h.scope.faceEnrollmentFrameCount >= 7, 'up pose uses sharper centre pitch');
    },
    async turned_reference_mismatch_requires_frontal_confirmation_without_retake(h) {
        await begin(h); await reach(h,2);
        await h.advanceClock(1500, () => pump(h, () => ({...observation(-.12,.55),
            capture_state:'CONFLICT',reference_match_state:'MISMATCH'})));
        assert.equal(h.scope.faceEnrollmentFrameCount,2);
        assert.equal(h.scope.faceEnrollmentCanRetakeReference,false);
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(),/straight.*confirm/i);
        await h.advanceClock(600, () => pump(h, () => observation(-.12,.55)));
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(),/straight.*confirm/i);
        await h.advanceClock(700, () => pump(h, () => observation(0,.55)));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference,false);
    }
});
