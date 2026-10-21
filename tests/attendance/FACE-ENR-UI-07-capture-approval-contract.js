#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
assert(!page.includes(">Save Face</button>") && !page.includes(">Retake</button>"), "captured face must not expose a manual approval step");
assert(!page.includes("Review and approve"), "captured face must not ask staff for approval");
assert(!controller.includes("READY_TO_APPROVE"), "capture must not enter an approval state");
assert(controller.includes("Face captured. Saving automatically"), "capture must save automatically after the required frames");
assert(controller.includes("$scope.faceEnrollmentState !== \"CAPTURING\""), "automatic save must require a complete capture");
assert(!controller.includes("Teacher confirmed"), "enrollment must not present confirmation language to staff");
assert(controller.includes("if (parseInt(enrollmentMember.id, 10) > 0) form.append(\"member_id\", enrollmentMember.id);"), "invalid member IDs must be omitted so the authorized contact can be resolved server-side");
assert(controller.includes('verification_score'), 'model evidence must remain available internally');
assert(!page.includes('model calibration'), 'internal model approval language must not be shown to staff');
assert(page.includes('Set Up Another Face</button>') && page.includes('Start Attendance Scan</button>'), 'completed enrollment must show next-step actions');
assert(controller.includes("$scope.continueFaceSetup = continueFaceSetup;") && controller.includes("function continueFaceSetup()"), "another-face action must return to setup roster");
assert(controller.includes("$scope.startAttendanceAfterEnrollment = startAttendanceAfterEnrollment;") && controller.includes("function startAttendanceAfterEnrollment()") && controller.includes("startFacePunchIn();"), "attendance action must launch punch-in scanning");
assert(controller.includes('$scope.faceEnrollmentState = "COMPLETE";') && controller.includes('Choose what to do next.'), 'completion must remain visible until staff selects a next step');
console.log("PASS FACE-ENR-UI-07 automatic enrollment contract");
