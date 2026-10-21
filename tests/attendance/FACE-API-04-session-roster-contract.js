#!/usr/bin/env node
/* FACE-API-04 selected-session roster contract, created before the fix. */

const fs = require('fs');

const api = fs.readFileSync('classes/services/FaceAttendanceApiService.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(api.includes('invoice_invoiceitem_module_view'),
    'PHP face roster must use the application roster view that exposes session_id');
assert(api.includes('attendance_date, session_id, event_type') && api.includes("'{$command['session_id']}'"),
    'PHP face scan must persist the selected attendance session on the scan');
assert(controller.includes('attendance_session_id: attendanceSessionId'),
    'browser face scan must send the selected attendance session');

console.log('PASS FACE-API-04 selected-session roster contract');
