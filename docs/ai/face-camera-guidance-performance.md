# Camera guidance and recognition timing

## What the operator sees

Enrollment keeps the existing reference, five slight poses (two photos each),
750 ms genuine departure, three centred verification photos, and six selected
templates. During guided capture, the face diagram and arrow show the requested
direction in the mirrored preview. The single instruction below the camera says
what to do now. A capture block takes priority over the pose prompt and explains
the correction. Once valid observations have resumed for 300 ms, the paused
label clears even if the requested pose still needs adjustment. Accepted photos
and progress remain in place. The instruction sits below the video so it cannot
cover the chin or the guide.

The reference comparison remains a hard check. A matching reference, measured
pose, frame quality, consistency, holdout, and duplicate checks are still
required. No acceptance threshold or model was changed here. The browser's
pose arrow is guidance only; the server measures the submitted pixels.

## Collecting evidence without storing photos

For a real guided attempt, the browser exposes up to 100 recent samples in
`window.faceEnrollmentDiagnostics`. Each sample contains the pose step, measured
yaw/pitch, reference similarity, reference match state, and reason code. It has
no child identifier or image. The preview API adds `reference_score` for this
diagnosis; existing response fields remain unchanged. Never export a browser
network archive for this purpose because preview requests contain JPEG frames.

For each scan, `window.faceCameraTimings` holds up to 25 anonymous samples:
`preview_ms`, `nonce_ms`, `capture_ms`, `request_ms`, `inference_ms`, `total_ms`,
`from_face`, and `decision`. `total_ms` starts when the preview first reported a
valid face; when that event was unavailable, it starts at nonce acquisition and
`from_face` is false. `request_ms` includes network, PHP, and Python time, while
`inference_ms` is the existing server-reported inference time. These browser
samples are cleared when a new scan starts and are not written to the database.

After controlled scans on the intended device, calculate the face-to-result p95:

```js
const ms = window.faceCameraTimings.filter(x => x.from_face).map(x => x.total_ms).sort((a, b) => a - b);
ms.length ? ms[Math.ceil(ms.length * .95) - 1] : null;
```

Collect at least 20 completed scans with the intended roster on the two-core
host, without a synthetic load burst against live traffic. Record p50/p95 for
each stage and whether the result was accepted, reviewed, or rejected. The
previously agreed face-to-result target is p95 at most 2 seconds. The current
changes remove one 350 ms delay after a proven departure and avoid an unused
JPEG-to-Blob conversion during recognition. They do not establish that target
until real-device measurements exist.

## Release checks and conditional reference work

Run `python3 tests/attendance/run-camera-phase-tests.py --phase p2`, the two
new browser behavior tests, and the full Python unittest suite. With real
people on the intended camera, complete all poses and verification; scan the
enrolled person; present a different and a similar-looking person; repeat the
same person without a second selection; test cancellation and detector loss.
The existing 750 ms departure, three distinct recognition frames, nonce,
idempotency, roster-only selection, and teacher's final Punch Attendance step
must still hold.

If real captures show the same person repeatedly failing the reference check
only on requested slight poses, keep the current rule until a centre-anchored
alternative has been tested against different and similar-looking people.
Centre and verification photos must continue to match the reference; any
proposed pose rule needs zero observed wrong-person acceptances and the same
quality, consistency, holdout, and duplicate gates. Do not change a numerical
threshold or enable a bypass based on the screenshot alone. If that gate or the
2-second p95 target fails, report the recorded cause before releasing further.

The repository was already on local `master` with three unrelated modified
documents and `:memory:.ses` untracked when this work began. Those files must
remain untouched and must not be included in the camera change commit.
