// PHP response states and local lifecycle failures share one operator message.
// Never interpolate raw server/worker exceptions or diagnostic payloads.
export function responseMessage(response) {
    const state=response.state;
    if(state==='RECOGNIZED')return `${response.person_name} recognized · Marked Present`;
    if(state==='TEST_RECOGNIZED')return `${response.person_name} recognized`;
    if(state==='UNKNOWN' || state==='TEST_UNKNOWN')return ['AMBIGUOUS','CONFLICTING_OBSERVATIONS','GALLERY_CONFLICT'].includes(response.reason)?"Couldn't identify. Try again.":response.reason==='NO_ELIGIBLE_REFERENCE'?'No saved faces yet. Enroll a member first.':'Face not recognized';
    if(['DUPLICATE','REJECTED_DUPLICATE'].includes(state) && response.capture_decision==='AMBIGUOUS_DUPLICATE')return "Couldn't verify face. Please try again.";
    if(['PENDING','ENROLLMENT_PENDING'].includes(state) && response.test_gallery_conflict)return 'Face saved · Needs attention';
    if(['PENDING','ENROLLMENT_PENDING'].includes(state) && response.test_ready===true)return 'Face saved';
    if(['PENDING','ENROLLMENT_PENDING'].includes(state) && response.test_only===true)return 'Face saved · Not ready for scanning';
    if(['DUPLICATE','REJECTED_DUPLICATE'].includes(state)){
        if(response.duplicate_visible===true && typeof response.duplicate_person_name==='string' && response.duplicate_person_name.trim()){
            const branch=typeof response.duplicate_branch_name==='string' && response.duplicate_branch_name.trim()?` at ${response.duplicate_branch_name.trim()}`:'';
            return `Face already registered to ${response.duplicate_person_name.trim()}${branch}`;
        }
        return 'Face already registered';
    }
    if(response.suggestion)return response.suggestion.requires_independent_verification?`${response.suggestion.person_name} suggested for review. Please verify.`:`${response.suggestion.person_name} added for review.`;
    if(state==='CONFIRMED')return 'Attendance confirmed.';
    if(state==='PARTIAL_CONFIRMATION')return 'Some members could not be saved. Review and retry.';
    if(['ACTIVE','ENROLLED','ALREADY_ENROLLED'].includes(state))return !response.enrollment_state || response.enrollment_state==='ACTIVE'?'Face enrolled':'Face saved · Setup pending';
    if(['PENDING','ENROLLMENT_PENDING'].includes(state) && response.reason==='PILOT_NOT_APPROVED')return 'Face captured. Only Test One can be scanned in this practice session.';
    if(state==='SHADOW_EVALUATED' && response.diagnostics?.decision==='NO_ELIGIBLE_REFERENCE')return 'No enrolled faces are available in this class. Enroll a member first.';
    const messages={DISABLED:'Face capture and scanning are not enabled for this class. Please use manual attendance.',DUPLICATE:'Face already registered',REJECTED_DUPLICATE:'Face already registered',
        RECAPTURE_REQUIRED:'Please try again',PENDING:'Face saved · Setup pending',ENROLLMENT_PENDING:'Face saved · Setup pending',
        SHADOW_EVALUATED:'Face check complete. No attendance was marked.',SHADOW_LIMIT:'Face checks paused. Please contact the administrator.',CAPTURE_ONLY:'Face capture is available. Scanning is not enabled for this class yet.',SCAN_MODE_CHANGED:'Face scanning changed. Please reopen the scanner.',
        UNKNOWN:'Face not recognized',SESSION_CUTOFF:'This attendance session has ended.',FINALIZED:'Session attendance finalized.',PARTIAL_FINALIZATION:'Some attendance records need attention. Please contact an administrator.',ALREADY_MARKED:'Attendance already marked.',RECORDED:response.person_name?`Attendance marked for ${response.person_name}.`:'Attendance marked.',
        CALIBRATION_SAVED:'Face check saved.',VALIDATION_SAVED:'Face check saved.',NO_ENROLLMENTS:'Enroll a member before scanning.',
        CALIBRATION_REQUIRED:'Face scanning is not ready yet. Please use manual attendance.',INCOMPATIBLE_TEMPLATES:'Face scanning is not ready yet. Please use manual attendance.',
        MIGRATION_SCOPE_BLOCKED:'Face scanning is not ready yet. Please use manual attendance.',REJECTED:'Unable to mark attendance. Please use manual attendance.'};
    return messages[state] || 'Please try again';
}
export function errorMessage(error) {
    const code=error?.code || '',message=typeof error==='string'?error:error?.message || '';
    if(/Capture a face for an eligible member|Enroll a member before scanning|No enrolled faces|No saved faces/i.test(message))return 'No saved faces are available in this class. Enroll a member first.';
    if(/Scanning is not enabled/i.test(message))return 'Face capture is available. Scanning is not enabled for this class yet.';
    if(/Batch is not authorized|Scheduled attendance context required/i.test(message))return 'Select a current class and session, then try again.';
    if(/later visit|captured today/i.test(message))return 'Face already captured today. Check it at a later visit.';
    if(/not eligible|not authorized.*branch/i.test(message))return 'This member is not available in the selected class.';
    if(/Consent/i.test(message))return 'Confirm consent before capturing this face.';
    if(/Face checks paused/i.test(message))return 'Face checks paused. Please contact the administrator.';
    if(/Face scanning changed/i.test(message))return 'Face scanning changed. Please reopen the scanner.';
    if(code==='FIELD_RELEASE_DISABLED' || message.includes('FIELD_RELEASE_DISABLED') || /Face capture and scanning are not enabled/i.test(message))return 'Face capture and scanning are not enabled for this class. Please use manual attendance.';
    if(['AUTH_REQUIRED','CSRF_REQUIRED','ORIGIN_REJECTED','ATTENDANCE_ACCESS'].includes(code) || /AUTH_REQUIRED|CSRF_REQUIRED|Scan session|session.*expired|session.*closed|Attendance.*session/i.test(message))return 'Session changed or expired. Please reopen the scanner.';
    if(error?.httpStatus===429)return 'Please wait a moment, then tap Retry.';
    if(error?.httpStatus>=500)return 'Server busy. Please try again.';
    if(error?.name==='NotAllowedError' || /Allow camera|permission/i.test(message))return 'Camera unavailable. Allow camera access.';
    if(/Face capture timed out/i.test(message))return 'Capture timed out. Please try again.';
    if(/previous submitted/i.test(message))return 'Tap Retry to finish the previous request.';
    if(/Connection|Network|response unavailable/i.test(message))return 'Connection lost';
    if(/ready|setup|calibrat|threshold/i.test(message))return 'Face scanning is not ready yet. Please use manual attendance.';
    if(/Camera stopped|Camera paused/i.test(message))return 'Camera stopped. Tap Retry.';
    if(/camera|HTTPS|browser/i.test(message))return 'Camera unavailable. Allow camera access.';
    return 'Please try again';
}
