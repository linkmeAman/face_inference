'use strict';

/* FACE-API-05 non-session batch contract, created before the fix. */
const fs = require('fs');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const service = fs.readFileSync('classes/services/FaceAttendanceApiService.php', 'utf8');
const stateMachine = fs.readFileSync('pf/js/controllers/faceAttendanceController.js', 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

const start = controller.slice(controller.indexOf('function startFaceScan'), controller.indexOf('function startFacePunchIn'));
assert(start.includes('var selectedTime'), 'face start must derive machine time from the selected batch time');
assert(start.includes('selected_time: selectedTime'), 'face start must send machine time, not the display label');
assert(start.includes('var attendanceSessionId'), 'face start must derive the attendance session from the selected time when batch state is empty');
assert(start.includes('attendance_session_id: attendanceSessionId'), 'face start must always send the selected attendance session, including zero');
assert(start.includes('stopFailedFaceScan()'), 'face start must close the scanner on a server failure');
assert(controller.includes('onError: function (reason, message)'), 'face error callback must accept the optional server message');
assert(stateMachine.includes('function (reason, message)'), 'face state machine must carry an error message');
assert(service.includes("$ids[2] < 0"), 'face start must permit session_id zero for non-session batches');

console.log('PASS FACE-API-05 non-session batch contract');
