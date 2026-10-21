#!/usr/bin/env python3
"""Fixed P0–P2 gates. Expected assertion failures are evidence, never a fix.

No discovery globs, test-output files, live endpoints, or inference requests.
Missing cases, errors, skips, timeouts and changed failure reasons always fail.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
TESTS = Path('tests/attendance')
GUARDS = [
    'FACE-UI-01-state-machine.js', 'FACE-UI-04-cancelled-response-contract.js',
    'FACE-UI-09-next-child-gate-contract.js', 'FACE-UI-14-readiness-before-camera.js',
    'FACE-GEO-01-capture-context-roundtrip.js', 'FACE-CAM-01-dual-camera-contract.js',
    'FACE-CAM-02-mirror-stability-contract.js', 'FACE-ENR-UI-07-capture-approval-contract.js',
    'FACE-API-07-transient-inference-contract.js',
    'FACE-API-01-scan-lifecycle.php', 'FACE-PILOT-01-camera-smoke-test.php',
    'FACE-API-10-openapi-contract.php',
    'FACE-ENR-08-reference-consistency.php',
    'FACE-ENR-09-guided-enrollment.php',
    'FACE-ENR-10-candidate-selection.php',
    'FACE-ENR-11-selection-persistence.php',
    'FACE-ENR-UI-24-candidate-selection.js',
    'FACE-ENR-UI-25-live-guidance.js',
    'FACE-ENR-UI-27-adaptive-tracker.js',
    'FACE-ENR-UI-28-adaptive-capture.js',
    'FACE-ENR-UI-29-early-identity.js',
    'FACE-ENR-12-adaptive-evidence.php',
    'FACE-ENR-13-early-identity.php',
    'FACE-UI-17-scan-timing.js',
    'FACE-UI-18-auto-attendance-behavior.js',
    'services/face-inference/tests/test_guided_enrollment.py',
    'services/face-inference/tests/test_guided_selection.py',
    'services/face-inference/tests/test_guided_template_roster.py',
    'services/face-inference/tests/test_reference_frame_selection.py',
    'services/face-inference/tests/test_guided_adaptive_v2.py',
]
# A None value means an existing behavior that MUST pass at every phase.
# Otherwise: (phase that fixes it, exact first line of the observed assertion).
CASES = {
    'FACE-API-13-guided-template-roster.php': {
        'all_six_actual_templates_reach_inference': None,
        'legacy_three_templates_are_unchanged': None,
        'corrupt_sixth_template_still_blocks_scan': None,
    },
    'FACE-ENR-UI-23-guided-poses.js': {
        'pose_needs_fresh_300ms_hold': None,
        'missing_pose_or_wrong_pose_cannot_advance': None,
        'guided_sequence_keeps_face_present_and_submits_exact_checked_pixels': None,
        'retake_discards_guided_progress_and_old_observation': None,
        'missing_capability_preserves_legacy_capture': None,
        'mirrored_prompt_uses_display_direction': None,
    },
    'FACE-ENR-UI-22-reference-recovery.js': {
        'reference_thumbnail_uses_selected_frame': None,
        'mismatch_blocks_immediately_with_one_instruction': None,
        'three_mismatches_need_one_second_and_fresh_observations': None,
        'three_fast_mismatches_do_not_enable_retake': None,
        'detector_failure_and_match_reset_mismatch_run': None,
        'retake_discards_progress_and_ignores_old_capture': None,
        'late_clear_response_does_not_replace_new_student': None,
        'enrollment_card_has_one_primary_instruction_and_real_actions': None,
    },
    'FACE-CAM-03-capture-lifecycle-behavior.js': {
        'late_camera_after_cancel': (1, 'late switched camera must be stopped'),
        'latest_switch_owns_stream': (1, 'newest camera switch must retain ownership'),
        'initial_camera_cancel_guard': None, 'hidden_page_stops_tracks': None,
    },
    'FACE-UI-15-departure-behavior.js': {
        'same_person_never_rearms_by_timeout': (1, 'continuous presence must not rearm recognition'),
        'departure_requires_750ms': (1, 'short empty interval must not rearm recognition'),
        'preview_failure_is_not_absence': None,
    },
    'FACE-ENR-UI-18-capture-integrity-behavior.js': {
        'submitted_frame_equals_checked_frame': (1, 'submitted JPEG must equal checked JPEG'),
        'unavailable_is_not_burst_separation': (1, 'detector outage must not satisfy burst separation'),
        'old_student_callback_is_ignored': None,
    },
    'FACE-ENR-UI-21-leave-guide-ux.js': {
        'final_pause_keeps_face_in_guide': None,
        'face_present_fills_pause_indicator': None,
        'missing_face_does_not_start_final_capture': None,
        'continuous_face_resumes_final_capture': None,
        'detector_failure_ends_with_specific_error': None,
        'deferred_evalasync_does_not_abort_final_capture': None,
    },
    'FACE-UI-16-attempt-recovery-behavior.js': {
        **{'temporary_failure_preserves_selection_' + reason:
           (2, 'temporary failure must preserve accepted selection')
           for reason in ['INFERENCE_BUSY', 'INFERENCE_OFFLINE', 'INFERENCE_TIMEOUT']},
        'lost_response_checks_status_first': (2, 'lost response must check attempt status before recapture or cancel'),
        'bounded_retry_and_cancel': (2, 'status recovery must use bounded 1s, 2s, 4s backoff'),
        'cancel_ignores_late_attempt': None,
    },
    'FACE-API-12-attempt-recovery.php': {
        'same_completed_attempt_keeps_nonce': (2, 'completed attempt with original nonce must return stored result'),
        'changed_payload_conflicts': (2, 'same attempt ID with changed frames must conflict'),
        'status_finds_attempt': (2, 'status must identify the requested completed attempt'),
        'exactly_three_frames': (2, 'one frame must fail the unconditional three-frame contract'),
        'temporary_readiness_failure_keeps_scan': (2, 'classified temporary outage must retain active scan'),
        'legacy_ready_provider_works': None, 'legacy_unready_provider_stops': None,
        'owner_browser_nonce_and_closed_scan_guards': None,
        'recognition_never_commits_attendance_or_deductions': None,
    },
    'FACE-INFRA-04-readiness-classification.php': {
        'legacy_boolean_readiness_transport': None,
        'readiness_failure_classification': (2, 'concrete client must expose optional readiness classification'),
        'offline_transport_is_not_ready': None,
        'offline_readiness_classification': (2, 'concrete client must classify connection refusal'),
    },
}


def run(filename, env):
    if filename.endswith('.py'):
        return subprocess.run(['/var/lib/creativeculturalcentre/face-inference/venv/bin/python', filename, '-b'],
                              cwd=ROOT, env={**env, 'PYTHONDONTWRITEBYTECODE': '1'}, text=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)
    command = ['node' if filename.endswith('.js') else 'php', str(TESTS / filename)]
    if filename == 'FACE-API-01-scan-lifecycle.php':
        command.append('--required')
    return subprocess.run(command, cwd=ROOT, env=env, text=True,
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--phase', choices=['p0', 'p1', 'p2'], required=True)
    parser.add_argument('--baseline', action='store_true', help='record precise P0 expected failures')
    args = parser.parse_args()
    if args.baseline and args.phase != 'p0':
        parser.error('--baseline is only valid for p0')
    phase = int(args.phase[1])
    env = os.environ.copy()
    # Never trust an inherited deployment database name or nonce flag for fixtures.
    env.update(ATTENDANCE_TEST_DB='ccc_database_test', ATTENDANCE_TEST_CENTRAL_DB='ccc_central_test',
               ATTENDANCE_TEST_REQUIRED='1', FACE_CAPTURE_NONCE_REQUIRED='0')
    blocked = False
    passed = expected = 0
    for filename in GUARDS + list(CASES):
        try:
            result = run(filename, env)
        except (OSError, subprocess.TimeoutExpired) as error:
            print(f'BLOCKED {filename}: {type(error).__name__}')
            blocked = True
            continue
        if filename in GUARDS:
            output = result.stdout + result.stderr
            bad = result.returncode != 0 or re.search(r'\b(?:BLOCKED|SKIPPED|FAIL)\b', output)
            # Prevent a silently truncated or emptied DB test from becoming a pass.
            if filename == 'FACE-API-01-scan-lifecycle.php':
                bad = bad or '20 FACE-API-01 scenarios, 0 failed.' not in output
            if filename == 'FACE-PILOT-01-camera-smoke-test.php':
                bad = bad or len(re.findall(r'^PASS ', output, re.M)) != 51
            if filename == 'FACE-ENR-08-reference-consistency.php':
                # This suite includes the unchanged assurance cases, whose PASS
                # names include "CONFLICT and BLOCKED". Check result lines and
                # both exact summaries, not those words inside passing names.
                bad = (result.returncode != 0
                       or bool(re.search(r'^(?:BLOCKED|SKIPPED|FAIL)\b', output, re.M))
                       or '27 FACE-ENR-06 scenarios, 0 failed.' not in output
                       or '10 FACE-ENR-08 scenarios, 0 failed.' not in output
                       or len(re.findall(r'^PASS ', output, re.M)) != 37)
            if filename == 'FACE-ENR-09-guided-enrollment.php':
                bad = (result.returncode != 0
                       or bool(re.search(r'^(?:BLOCKED|SKIPPED|FAIL)\b', output, re.M))
                       or '27 FACE-ENR-06 scenarios, 0 failed.' not in output
                       or '9 FACE-ENR-09 scenarios, 0 failed.' not in output
                       or len(re.findall(r'^PASS ', output, re.M)) != 36)
            if filename == 'FACE-ENR-11-selection-persistence.php':
                bad = (result.returncode != 0
                       or bool(re.search(r'^(?:BLOCKED|SKIPPED|FAIL)\b', output, re.M))
                       or '27 FACE-ENR-06 scenarios, 0 failed.' not in output
                       or '2 FACE-ENR-11 scenarios, 0 failed.' not in output
                       or len(re.findall(r'^PASS ', output, re.M)) != 29)
            if filename.endswith('test_guided_template_roster.py'):
                bad = bad or not re.search(r'Ran 3 tests\b', output) or not re.search(r'^OK$', output, re.M)
            if filename.endswith('test_guided_enrollment.py'):
                bad = bad or not re.search(r'Ran 10 tests\b', output) or not re.search(r'^OK$', output, re.M)
            if filename.endswith('test_reference_frame_selection.py'):
                bad = bad or not re.search(r'Ran 5 tests\b', output) or not re.search(r'^OK$', output, re.M)
            print(f'{"BLOCKED" if bad else "PASS"} {filename}')
            if bad:
                print(output.strip())
            blocked = bool(bad) or blocked
            continue
        records = []
        try:
            records = [json.loads(line) for line in result.stdout.splitlines()]
            names = [record['case'] for record in records]
            if len(names) != len(set(names)) or set(names) != set(CASES[filename]):
                raise ValueError('missing, duplicate or unexpected cases')
            failure_count = sum(record['status'] != 'PASS' for record in records)
            if result.stderr or result.returncode != (1 if failure_count else 0):
                raise ValueError('unexpected stderr or process exit')
        except (ValueError, KeyError, TypeError) as error:
            print(f'BLOCKED {filename}: invalid test evidence ({error})')
            print((result.stdout + result.stderr).strip())
            blocked = True
            continue
        for record in records:
            name, status = record['case'], record['status']
            contract = CASES[filename][name]
            may_fail = contract and ((phase == 0 and args.baseline) or (phase == 1 and contract[0] > 1))
            if status == 'PASS':
                # P0 is a reproducible baseline: an unexpected pass needs review.
                label = 'UNEXPECTED_PASS' if contract and args.baseline else 'PASS'
                passed += 1
            elif status == 'FAIL' and may_fail and record.get('detail', '').splitlines()[0] == contract[1]:
                label = 'EXPECTED_FAIL'
                expected += 1
            else:
                label = 'BLOCKED' if status == 'ERROR' else 'FAIL'
            print(f'{label} {filename}:{name}')
            if label not in ('PASS', 'EXPECTED_FAIL'):
                print(record.get('detail', 'Observed behavior differs from the recorded baseline.'))
                blocked = True
    print(f'{passed} behavior cases passed; {expected} expected failures; ' +
          ('GATE FAILED' if blocked else 'GATE PASSED (not a deployment or accuracy claim)'))
    return 1 if blocked else 0


if __name__ == '__main__':
    sys.exit(main())
