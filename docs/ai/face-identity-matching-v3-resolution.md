# Face Identity Matching v3 — Retrospective and Remediation

Date: 2026-09-29

## Root cause

The recognition model was not receiving the input format required by OpenCV SFace. The implementation aligned faces but then applied `(RGB - 127.5) / 128`; OpenCV `FaceRecognizerSF` uses aligned RGB float pixels with scale `1` and mean `0`. This created an incompatible embedding space and inflated cross-person similarity.

Three decision defects amplified the encoder problem:

- attendance averaged all stored templates into one member vector;
- a one-member roster used top-2 `0`, manufacturing a large margin;
- enrollment hard-blocked moderate maximum-pair similarity and production substituted three selected templates for the real 5+3 burst samples.

The defect originated in `34c9f0b2`. Protocol-v2 commit `50f5edba` added useful multi-frame gates but also added the moderate two-burst block and did not transport real burst embeddings. `f5ee3ff9` exposed the false-collision behavior earlier by enabling duplicate preview before reference setup. FaceGuide/capture/PAD corrections in `ff59da7b` remain valid and are preserved.

## Corrected decisions

- SFace now uses the official five-point similarity alignment, raw RGB float input, and one validated L2 normalization. Preprocessing version is `sface-opencv-aligncrop-rgb-raw-v2`.
- Attendance retains up to three templates per identity. Per-frame identity score is the median of the best two template scores, with an explicit supporting-template count.
- Three-frame attendance requires every quality-valid frame to choose the same identity. Multi-identity rosters require absolute score, per-frame floor, template support, and a real top-1/top-2 margin.
- A one-identity roster reports `top2=null` and `margin=null` and uses a separately calibrated threshold. Uncalibrated v3 decisions return `REVIEW`.
- Ambiguous/PAD-unresolved results contain no candidate reference and cannot select a roster row.
- Enrollment duplicate blocking requires the same existing identity to receive high evidence on at least 4/5 Burst-A frames and 3/3 Burst-B frames, with two stored references supporting those frames. Moderate similarity alone is clear, while one-sided high evidence is `ADMIN_REVIEW`.
- Single-frame preview similarity is advisory. Reference records remain `PENDING_ENROLLMENT` until the complete two-burst enrollment succeeds.

## Compatibility and rollout

Existing v1 embeddings and calibration are incompatible and must not be reused. Migration `20260929_01_face_identity_v3.sql`, applied through `scripts/migrate_face_identity_v3.php`, changes model uniqueness to checksum plus preprocessing version, registers v3 as `PENDING_CALIBRATION`, retires model row 1, and revokes/supersedes its mappings without deleting audit rows.

On 2026-09-29 the guarded migration was applied to `ccc_database` and `ccc_database_test` after creating `/tmp/ccc_database_face_v3_20260929.sql`. Production model row 2 is the pending v3 model; three v1 templates were revoked and one v1 reference was superseded. No biometric audit row was deleted.

The old identity calibration is rejected with `CALIBRATION_PREPROCESSING_MISMATCH`, and the stale PAD calibration is rejected with `PAD_PREPROCESSING_VERSION_MISMATCH`. V3 automatic matching remains disabled until labeled field calibration provides the absolute, single-candidate, margin, and template-support thresholds with zero held-out impostor accepts and validates PAD under the corrected crop. Shadow mode and live-write disabled remain mandatory during validation. The deployed runtime reports model id 2, provider version 2, policy v3, corrected preprocessing, and both stale-calibration rejections; Nginx, PHP-FPM, face inference, and the public site remained healthy after reload.

## Verification evidence

- Python: 89 tests pass, including new lookalike ambiguity, wrong-template support, unanimous-frame, nullable-margin, raw-SFace-input, and L2-normalization regressions.
- JavaScript: all 35 camera/enrollment/attendance contracts pass.
- PHP: attendance lifecycle 16/16, two-burst assurance 25/25, camera smoke 49/49, provider/PAD/API contracts pass.
- Corrected preprocessing reduced the inspected unrelated-face cosine examples from `0.434/0.658` to `0.114/0.134`. These examples demonstrate the preprocessing defect but are not calibration evidence.
- Known operational blockers: the destructive migration harness requires a disposable empty database; the readiness check remains blocked until v3 registry/configuration and field calibration are approved; members must be remapped after retirement.

No frames, portraits, or embeddings are added to logs. Concise recognition logs contain only opaque candidate references, scores, margin, support, consensus, liveness, stage, and reason.
