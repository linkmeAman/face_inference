# Fresh face enrollment and candidate selection — 2026-10-03

## What changed

Fresh guided enrollment uses `guided-five-select-v1`. The operator captures two
fresh snapshots at each existing target: centre, slight left, slight right,
slight up and slight down. After the existing 750 ms observed departure, the
child returns for three centred verification frames. The preview explains one
pose at a time. Client pose labels are never treated as server evidence.

Python measures the poses from submitted pixels, applies the existing strict
quality, reference, consistency and holdout rules, and checks frame hashes for
reuse. It selects one real Burst A embedding for each pose, based on its
consistency with the eligible captures and then sharpness. It selects one real
Burst B frame nearest that burst's centroid. A quality-invalid candidate may
be excluded when its pose partner passes. Any quality-valid candidate that
mismatches the reference blocks the entire attempt. PHP checks the selected
indices and embedding provenance before saving exactly six encrypted templates.
Pose logs include measured yaw and pitch and whether each eligible observation
was selected, without image bytes or embeddings.
The existing duplicate check still receives five Burst A and three Burst B
embeddings; attendance recognition still receives at most six templates and
still requires three independent scan frames. No model, similarity threshold,
pose window, schema, rate limit or attendance writer changed.

The original `guided-five-v1` (5+3) and legacy three-template paths remain
available. Python advertises the new capability separately; PHP exposes it to
the browser only when its provider supports the new protocol. The new browser
request sends each of the 13 images once. Each image retains the existing
1 MB cap; PHP limits their aggregate raw size to 10 MB so the encoded request
fits the inference service's 15 MB body cap. The extra work affects enrollment
time, not the six-template scan size.

## Old enrollment reset

This was explicitly requested before new capture. Four branch-2 subjects were
in scope. A scoped snapshot of changed rows was saved at
`/var/backups/ccc-face/pre-reset-20261003.json` (1,624,901 bytes, SHA-256
`a8da41c6b3e12b3100ba6351858fa66d93780de4cee550ecadb87c5fa7e97a7d`).
The directory is mode `0700`, the file `0600`. Restoration of every saved row
into isolated MySQL temporary tables passed before the live reset.

The live reset blanked all 12 old template embeddings and all 10 references
that retained encrypted portrait or embedding data. It removed all active
templates/references and four authorizations, cancelled one open capture
session and one expired scan. Post-reset checks returned zero payloads, active
templates, active/pending references, authorizations, open sessions and active
scans. Four enrollment attempts and 158 capture-evidence rows remained for
audit. Manual attendance and deductions were not changed. The reset transaction
committed; its first utility invocation then encountered a MySQL result cleanup
error. A separate post-reset verification passed, and the utility was fixed.

The backup retains the old encrypted data until **fresh enrollment and a
separate attendance-camera scan have both been verified**. Do not delete it
automatically. Erasure above describes the live application database, not
historical storage pages or this deliberately retained backup. The maintenance
utility is [scripts/face_enrollment_reset_20261003.php](../../scripts/face_enrollment_reset_20261003.php).

## Verification and recovery

Run `python3 tests/attendance/run-camera-phase-tests.py --phase p2` and the
full Python unittest discovery in `services/face-inference/tests/`. The new
tests cover 10+3 capture, the existing departure check, one-time image upload,
quality candidate exclusion, reference mismatch, repeated pixels, exact
six-template provenance, and persistence in the disposable test database.

For the live service, check `systemctl is-active face-inference php8.3-fpm nginx mysql`,
`curl -fsS http://127.0.0.1:6120/readyz`, and the reset postconditions with
`php scripts/face_enrollment_reset_20261003.php verify /var/backups/ccc-face/pre-reset-20261003.json`.
Run the guarded utility only from CLI. If code must be rolled back, revert its
commit and restart face inference; old enrollment remains erased. Before any
fresh enrollment, the old gallery can be recovered with
`php scripts/face_enrollment_reset_20261003.php restore-gallery /var/backups/ccc-face/pre-reset-20261003.json`.
That command refuses recovery if new template/reference/authorization rows or
new live biometric payloads exist; it restores gallery and authorization rows
but deliberately leaves expired scans and old capture sessions cancelled.

The final acceptance check needs real children: capture the new guided sequence,
then scan them in a separate session on the attendance camera, including other
children and siblings or lookalikes where available. Compare the selected
gallery with a baseline using the first valid frame per pose, and record
score-only outcomes and capture time. Stop rollout on a wrong-child selection,
failed readiness check or broken manual attendance flow. Four subjects are
insufficient to establish a reliable wrong-match rate; this is a supervised
pilot until broader real-person evaluation is available.

## Installation check

Code was committed on `master` as `56873a971`. The camera phase runner passed
51 behavior cases with zero expected failures; Python unittest discovery passed
124 tests. After a face-inference restart and PHP-FPM reload, `/readyz` reported
the model, identity calibration and PAD calibration ready. Face inference,
PHP-FPM, Nginx and MySQL were all active, the site returned HTTP 200, and the
live reset verification still returned zero usable old biometrics with the
original audit counts. No real-child enrollment or independent attendance scan
was performed by the implementation agent.
