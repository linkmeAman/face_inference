'use strict';

const {assert, face, startScan, pumpScan, runCases} = require('./helpers/face-controller-harness');

async function beginAttempt(h) {
    await startScan(h);
    await h.advanceClock(1000, () => pumpScan(h));
    const request = h.pending('recognizeFaceAttempt')[0];
    assert.ok(request, 'recognition attempt must be pending');
    return request;
}

runCases({
    async recorded_face_attendance_updates_the_roster_without_a_second_commit(h) {
        const request = await beginAttempt(h);
        await h.resolveRequest(request, {state: 'RECORDED', decision: 'ACCEPT', effect: 'ATTENDANCE_RECORDED',
            attendance_state: 'RECORDED', attendance_id: 501, member_id: 101, contact_id: 1101,
            member_name: 'Fixture 101', liveness: 'LIVE'});
        assert.equal(h.scope.list[0].attendance_id, '501');
        assert.equal(h.scope.list[0].negative, 0);
        assert.equal(h.scope.list[0].selected, false, 'saved attendance must not remain queued for Punch Attendance');
        assert.equal(h.scope.list[0].skip, true);
    },
    async already_present_face_attendance_does_not_create_a_client_selection(h) {
        const request = await beginAttempt(h);
        await h.resolveRequest(request, {state: 'RECORDED', decision: 'ACCEPT', effect: 'ALREADY_PRESENT',
            attendance_state: 'ALREADY_PRESENT', attendance_id: 501, member_id: 101, contact_id: 1101,
            member_name: 'Fixture 101', liveness: 'LIVE'});
        assert.equal(h.scope.list[0].attendance_id, '501');
        assert.equal(h.scope.list[0].selected, false);
        assert.equal(h.scope.faceMatched.length, 1);
    },
    async live_scan_completion_does_not_enter_the_manual_punch_flow(h) {
        await startScan(h, true);
        h.scope.finishFaceScan();
        await h.resolveRequest('completeFaceScan', {state: 'COMPLETED', live_write: true, attendance_written: true});
        assert.equal(h.scope.attendanceStage, 'COMPLETE');
        assert.equal(h.scope.faceLiveWriteMode, true);
        assert.match(h.scope.faceMessage, /unscanned children were not changed/i);
    }
});
