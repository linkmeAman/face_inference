'use strict';
const {assert, face, empty, runCases} = require('./helpers/face-controller-harness');
const capability={pose_protocol:'guided-five-select-v1',template_count:6,burst_a_count:10,burst_b_count:3,samples_per_pose:2,
    windows:{centre_yaw_max:.06,centre_pitch_min:.35,centre_pitch_max:.75,side_yaw_min:.08,
        side_yaw_max:.16,pitch_tolerance:.08,pitch_delta_min:.05,pitch_delta_max:.10,stable_ms:300}};
const poses=[[0,.55],[-.12,.55],[.12,.55],[0,.48],[0,.62]];
function observation(index=0){
    const body=face(); body.guided_enrollment=capability;
    const pose=index<10?poses[Math.floor(index/2)]:poses[0];
    Object.assign(body.faces[0].quality,{accepted:true,pose_yaw:pose[0],pose_pitch:pose[1]});
    return body;
}
async function begin(h){
    h.scope.startFaceEnrollment(h.scope.list[0]);
    await h.resolveRequest('beginEnrollment',{state:'READY',capture_session_token:'selection',has_pending_reference:true,
        pending_reference:{id:1,portrait_data_url:'data:fixture'}});
    await h.resolveCamera(); await h.resolveRequest('previewEnrollment',observation());
    h.scope.captureFaceEnrollment();
}
async function pump(h,body){for(const r of h.pending('previewEnrollment')) await h.resolveRequest(r,body);}
runCases({
    async ten_pose_candidates_then_final_pause(h){
        await begin(h);
        for(let i=0;i<260 && h.scope.faceEnrollmentState!=='LEAVE_GUIDE';i++)
            await h.advanceClock(50,async()=>{
                for(const r of h.pending('previewEnrollment')) await h.resolveRequest(r,observation(h.scope.faceEnrollmentFrameCount));
            });
        assert.equal(h.scope.faceEnrollmentFrameCount,10);
        assert.equal(h.scope.faceEnrollmentState,'LEAVE_GUIDE');
        await h.advanceClock(700,()=>pump(h,observation(10)));
        assert.equal(h.scope.faceEnrollmentState,'LEAVE_GUIDE');
    },
    async thirteen_frames_uploaded_once(h){
        await begin(h);
        await h.advanceClock(13000,async()=>{
            for(const r of h.pending('previewEnrollment')) await h.resolveRequest(r,observation(h.scope.faceEnrollmentFrameCount));
        });
        await h.advanceClock(1300,()=>pump(h,observation(10)));
        await h.advanceClock(5000,async()=>{
            for(const r of h.pending('previewEnrollment')) await h.resolveRequest(r,observation(h.scope.faceEnrollmentFrameCount));
        });
        const request=h.pending('enrollFace')[0];
        assert.ok(request,'new capture submits');
        assert.equal(request.data.get('pose_protocol'),'guided-five-select-v1');
        assert.equal(request.data.getAll('burst_a[]').length,10);
        assert.equal(request.data.getAll('burst_b[]').length,3);
        assert.equal(request.data.getAll('frames[]').length,0,'do not upload the same thirteen images twice');
    }
});
