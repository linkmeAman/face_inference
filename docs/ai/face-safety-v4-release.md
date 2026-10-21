# Face safety v4 release record — 2026-09-29

> **Update (2026-10-02)**: Teacher-facing biometric row selection was activated via `FaceSelectionGate` (`face_selection_control.enabled = 1` and `FACE_TEACHER_SELECTION_ENABLED = 1`), with `FACE_ATTENDANCE_LIVE_WRITE = 0` remaining enforced so attendance writes stay strictly behind manual teacher Punch confirmation. See `handoff-context.md`.

## Historical 2026-09-29 Baseline Decision

Teacher-facing biometric row selection remains **disabled**. The ignored server
configuration `nginx-face-params.conf` sets `FACE_TEACHER_SELECTION_ENABLED "0"`,
`FACE_ATTENDANCE_SHADOW_MODE "1"`, and `FACE_ATTENDANCE_LIVE_WRITE "0"`.
Manual attendance remains available. No labeled held-out face or PAD dataset
exists yet, and the current identity calibration fails its preprocessing check.
These are release blockers, not capture errors for teachers to retry.

## Deployed safeguards

- Inference and PHP responses expose a named outcome. A response other than
  `ACCEPT` clears identity fields and cannot select a roster row.
- Scan start checks readiness before camera access. Scan attempts recheck
  readiness, recognition generation, active scan status, and current member
  eligibility. A generation change or failed service closes the scan.
- A scan is pinned to the identity model, preprocessing, calibration, PAD model,
  PAD preprocessing, PAD calibration, and policy. Three distinct captures and a
  short-lived single-use nonce bound to scan, actor, and browser session are
  required at the public PHP boundary. Nonce consumption is persisted in SQL.
- Invalid active templates are quarantined and the scan fails closed. Enrollment
  test bypass creates `VALIDATION_ONLY` records; attendance cannot change
  templates. Withdrawing consent erases stored template and reference biometric
  blobs, revokes the records, and cancels open scans.
- The browser offers one guided capture retry for correctable acquisition or
  quality problems, then directs the teacher to ordinary attendance.

## Migration and recovery

The pre-migration production dump is
`/tmp/ccc_database_face_safety_v4_20260929.sql` (109,611,592 bytes, mode 0600).
`scripts/migrate_face_safety_v4.php` was reviewed in dry-run mode, then applied
with that backup. The final dry run reported zero remaining active uncalibrated
or unknown-preprocessing records. Migration quarantined one active uncalibrated
identity reference and three active templates with missing preprocessing.

Keep the three selection/write gates above disabled during rollback. Restore a
compatible build before re-enabling face selection; old code must not interpret
the wider status columns. If database restoration is necessary, use the named
dump under a maintenance window and review data written after the dump first.
The server config is gitignored and must be checked on every deployment.

## Verification

- Python inference suite: 93 tests passed with loopback socket permission.
- PHP attendance fixtures: passed, excluding live-data audits and the separate
  lifecycle suite; lifecycle suite: 17 scenarios passed; camera smoke fixture:
  51 assertions passed; enrollment assurance fixture: 27 scenarios passed.
- All JavaScript attendance contracts passed.
- The migration's final dry run reports no pending operations.

`FACE-INFRA-01` intentionally fails its live `/readyz` assertion while the
identity calibration mismatch keeps the service unavailable for selection.

The smoke fixture uses synthetic data and does not constitute a supervised
pilot. The live enrollment readiness check remains blocked by the identity
calibration preprocessing mismatch. Historical remap audit prerequisites are
absent in this environment.

## Release work still required

Collect consented, versioned labels partitioned by subject, capture session,
and device into `TRAIN_TUNE`, `CALIBRATION`, `HELD_OUT_ACCEPTANCE`, and `PILOT`.
Freeze identity and PAD policies before held-out testing. Require at least 50
subjects, 1,000 genuine and 30,000 impostor held-out comparisons, no wrong-person
accepts, the stated retry success rates and device minimums, exact one-sided
95% bounds, subject-clustered uncertainty, and physical PAD category results.
Investigate any PAD failure and replace/recalibrate the model if necessary.
Then perform a supervised pilot. Until these gates pass, keep teacher selection
disabled. Production monitoring, label versioning,
revalidation scheduling, and automatic rollout suspension/restoration controls
remain follow-up implementation work; the environment gate is the current
manual safety control.
