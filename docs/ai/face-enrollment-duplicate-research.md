# Face Enrollment Duplicate-Identity Research

Date: 2026-09-12

## Incident finding

The Testing attendance screenshot showed all five synthetic children as mapped.
The database confirmed three active templates each for members `616`–`620`, all
using model version `1`. An in-memory audit decrypted templates without printing
or storing embeddings and compared member centroids plus individual templates.

All ten cross-member pairs exceeded the deployment collision threshold `0.75`.
Centroid cosine scores ranged from `0.7885489` to `0.9928085`; maximum
template-to-template scores ranged from `0.8225479` to `0.9916477`. This is a
contaminated identity set, not a normal low-margin recognition result.

## Model and score interpretation

The deployed recognizer is the 128-dimensional OpenCV Zoo SFace 2021dec INT8BQ
model with YuNet 2023mar INT8 alignment/detection. Cosine similarity is used;
higher values indicate more similar embeddings, with a maximum of `1.0`.

OpenCV publishes benchmark same-identity cosine thresholds that vary by dataset:
`0.363` on LFW, `0.340` on CALFW, `0.275` on CPLFW, `0.277` on AgeDB-30, and
`0.212` on CFP-FP. Those benchmark thresholds are not deployment calibration.
The project must still calibrate attendance acceptance on held-out samples from
its real cameras, lighting, ages, and venues.

Primary sources:

- OpenCV DNN face recognition tutorial:
  https://docs.opencv.org/4.13.0/d0/dd4/tutorial_dnn_face.html
- OpenCV Zoo SFace model documentation:
  https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface
- SFace paper: https://arxiv.org/abs/2205.12010

## Implemented enrollment policy

- The new-face centroid and every new template are compared with active
  same-model templates belonging to other active members in the same branch.
- A score at or above `FACE_ENROLLMENT_DUPLICATE_THRESHOLD` is rejection-only:
  enrollment returns `FACE_ALREADY_ASSIGNED` before consent/template writes.
- The safe default is `0.75`. This high threshold is deliberately stricter
  about declaring a collision than OpenCV's benchmark identity thresholds, to
  reduce accidental blocks while catching the measured contamination. It does
  not authorize recognition or attendance.
- A branch/model named lock serializes the compare-and-insert transaction so
  concurrent enrollments cannot assign the same face to two child IDs.
- Existing same-child active mapping remains `ALREADY_ENROLLED`; different
  branches are not searched, preserving the no-organization-wide-search goal.

## Deployment remediation

The 15 contaminated templates for members `616`–`620` were changed from
`ACTIVE` to `REVOKED`; their five program authorizations were changed to
`WITHDRAWN`. No rows or encrypted templates were deleted. The post-remediation
active audit reports zero member groups and zero collision pairs. Each child
must now be remapped using that child's own face.

## Verification

```sh
ATTENDANCE_TEST_DB_USER='test_user' \
ATTENDANCE_TEST_DB_PASSWORD='test_password' \
php tests/attendance/FACE-ENR-02-cross-member-duplicate.php
```

The read-only deployment audit is
`tests/attendance/FACE-ENR-02-template-audit.php`. It emits member IDs and
scores only; raw frames and decrypted embeddings never leave process memory.
