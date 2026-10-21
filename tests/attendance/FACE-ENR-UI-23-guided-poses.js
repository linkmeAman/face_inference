'use strict';
const {assert, face, empty, startEnrollment, runCases} = require('./helpers/face-controller-harness');
const capability = {pose_protocol:'guided-five-v1',template_count:6,windows:{
    centre_yaw_max:.06,centre_pitch_min:.35,centre_pitch_max:.75,side_yaw_min:.08,side_yaw_max:.16,
    pitch_tolerance:.08,pitch_delta_min:.05,pitch_delta_max:.10,stable_ms:300}};
const poses = [[0,.55],[-.12,.55],[.12,.55],[0,.48],[0,.62],[0,.55],[0,.55],[0,.55]];
function observation(index=0, override={}) {
    const body=face();
    body.guided_enrollment=capability;
    Object.assign(body.faces[0].quality,{accepted:true,pose_yaw:poses[index][0],pose_pitch:poses[index][1]},override);
    return body;
}
async function begin(h) {
    h.scope.startFaceEnrollment(h.scope.list[0]);
    await h.resolveRequest('beginEnrollment',{state:'READY',capture_session_token:'guided',has_pending_reference:true,
        pending_reference:{id:1,portrait_data_url:'data:fixture'}});
    await h.resolveCamera(); await h.resolveRequest('previewEnrollment',observation());
    h.scope.captureFaceEnrollment();
}
async function pump(h, body) {
    for(const r of h.pending('previewEnrollment')) await h.resolveRequest(r,typeof body==='function'?body():body);
}
runCases({
    async pose_needs_fresh_300ms_hold(h) {
        await begin(h); await pump(h,observation());
        assert.equal(h.scope.faceEnrollmentFrameCount,0,'one valid pose observation must not capture');
        await h.advanceClock(299,()=>pump(h,observation()));
        assert.equal(h.scope.faceEnrollmentFrameCount,0);
        await h.advanceClock(301,()=>pump(h,observation()));
        assert.equal(h.scope.faceEnrollmentFrameCount,1,'steady centre advances once');
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(),/slightly.*(left|right)/i);
    },
    async missing_pose_or_wrong_pose_cannot_advance(h) {
        await begin(h);
        await h.advanceClock(1500,()=>pump(h,observation(0,{pose_yaw:null})));
        assert.equal(h.scope.faceEnrollmentFrameCount,0,'missing measurements cannot advance');
        await h.advanceClock(900,()=>pump(h,observation()));
        assert.equal(h.scope.faceEnrollmentFrameCount,1);
        await h.advanceClock(1500,()=>pump(h,observation(2)));
        assert.equal(h.scope.faceEnrollmentFrameCount,1,'opposite yaw cannot satisfy left target');
    },
    async guided_sequence_keeps_face_present_and_submits_exact_checked_pixels(h) {
        await begin(h);
        const accepted=[];
        for(let i=0;i<120 && h.scope.faceEnrollmentState!=='LEAVE_GUIDE';i++)
            await h.advanceClock(50,async()=>{
                for(const r of h.pending('previewEnrollment')) {
                    const count=h.scope.faceEnrollmentFrameCount;
                    await h.resolveRequest(r,observation(Math.min(h.scope.faceEnrollmentRequestedPoseIndex,7)));
                    if(h.scope.faceEnrollmentFrameCount>count) accepted.push(await r.data.get('frame').text());
                }
            });
        assert.equal(h.scope.faceEnrollmentState,'LEAVE_GUIDE','five poses require final pause');
        assert.equal(h.scope.faceEnrollmentFrameCount,5);
        await h.advanceClock(700,()=>pump(h,observation(5)));
        assert.equal(h.scope.faceEnrollmentState,'LEAVE_GUIDE','750 ms separation cannot be bypassed');
        await h.advanceClock(600,async()=>{
            for(const r of h.pending('previewEnrollment')) {
                const count=h.scope.faceEnrollmentFrameCount;
                await h.resolveRequest(r,observation(Math.min(h.scope.faceEnrollmentRequestedPoseIndex,7)));
                if(h.scope.faceEnrollmentFrameCount>count) accepted.push(await r.data.get('frame').text());
            }
        });
        await h.advanceClock(4000,async()=>{
            for(const r of h.pending('previewEnrollment')) {
                const count=h.scope.faceEnrollmentFrameCount;
                await h.resolveRequest(r,observation(Math.min(h.scope.faceEnrollmentRequestedPoseIndex,7)));
                if(h.scope.faceEnrollmentFrameCount>count) accepted.push(await r.data.get('frame').text());
            }
        });
        const request=h.pending('enrollFace')[0];
        assert.ok(request,'guided capture submits');
        assert.equal(request.data.get('pose_protocol'),'guided-five-v1');
        assert.equal(accepted.length,8);
        const sent=[...request.data.getAll('burst_a[]'),...request.data.getAll('burst_b[]')];
        assert.deepEqual(await Promise.all(sent.map(b=>b.text())),accepted);
    },
    async retake_discards_guided_progress_and_old_observation(h) {
        await begin(h); await h.advanceClock(900,()=>pump(h,observation()));
        assert.equal(h.scope.faceEnrollmentFrameCount,1);
        await h.advanceClock(300);
        const old=h.pending('previewEnrollment')[0];
        h.scope.clearFaceReference();
        if(old) await h.resolveRequest(old,observation(1));
        assert.equal(h.scope.faceEnrollmentFrameCount,0);
        assert.equal(h.scope.faceEnrollmentHasReference,false);
        assert.equal(h.pending('enrollFace').length,0);
    },
    async missing_capability_preserves_legacy_capture(h) {
        await startEnrollment(h); h.scope.captureFaceEnrollment(); await pump(h,face());
        assert.equal(h.scope.faceEnrollmentFrameCount,1,'legacy first valid frame remains immediate');
        assert.ok(!h.scope.faceEnrollmentPoseProtocol);
    },
    async mirrored_prompt_uses_display_direction(h) {
        await begin(h);
        await h.advanceClock(900,()=>pump(h,observation()));
        assert.equal(h.scope.faceEnrollmentFrameCount,1);
        // The user-facing preview is mirrored. Canonical negative yaw points right on screen.
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(),/slightly.*right.*preview/i);
    }
});
