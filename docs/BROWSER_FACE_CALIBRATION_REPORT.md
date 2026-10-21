> Current correction (2026-10-09): operator confirmed only one real person across pilot labels. Stored labels are not independent people. The pilot wrong-label samples2/3 are reversibly excluded; member623 retained. Public synthetic evaluation is complete but does not approve matching. See [current public/one-person report](PUBLIC_FACE_DATASET_EVALUATION.md). Older label-count/impostor claims below are historical and cannot justify calibration.

# Actual face matching evidence — 2026-10-09

**Production matching blocked.** Final read-only ccc_database audit: 20 unexpired aggregate captures across six selected identity labels, zero validation captures, four retained complete batches (20 raw frame observations), five PENDING candidates, zero ACTIVE templates. These labels are not independently confirmed as six different physical people. Missing historical batches were not synthesized.

Actual private evaluation: /tmp/ccc-face-physical-audit-evaluation-20261009.json and .md (0600). Neither is approved or installed. Temporary plaintext export is removed after evaluation. Production DUPLICATE_THRESHOLD and ATTENDANCE_THRESHOLD recommendations remain **null**.

| Evidence | Actual result |
| --- | --- |
| Independent same-person calibration pairs | 0 |
| Different selected-label aggregate pairs | 98 |
| Different-label cosine min / max / mean | 0.07465607 / 0.97510947 / 0.76058408 |
| Validation same-/different-person pairs | 0 / 0 |
| Retained complete observation batches | 4 |
| Complete eligible calibration / validation query trials | 0 / 0 |
| Enrollment gallery candidates | 5 |
| Real-person unknown/identification error estimates | Unavailable |

The four full batches currently supply enrollment references, not separate query trials. Adjacent frames and same-visit repeats cannot prove real-world stability/accuracy. High different-label similarity does not establish whether labels represent different people; confirmed identity labeling is necessary. No physical-camera matching accuracy, false-positive/false-negative rate or completion time is inferred from missing evidence.

If the six existing labels are confirmed distinct consenting people, minimum count deficits are 14 further identities and 80 further aggregate captures. This is only arithmetic coverage: also obtain current complete enrollment/query batches, two independent calibration dates and two later validation dates per identity, explicitly labeled unenrolled-person trials against a nonempty eligible gallery, intended cameras/lighting and similar-looking comparisons. The three pilot identities cannot satisfy production coverage. The isolated physical pilot still has zero captures; two different people with repeated captures and a third unenrolled person are needed to begin exploratory testing.

## Collection without operator calibration screens

Ordinary attendance.php uses selected-member Enroll Face and optional Check face, the same automatic camera/model pipeline and a visible consent notice. All actions require existing Attendance access and branch authorization. The server retains encrypted observations, actual capture UUID/date, measured lighting, hashed camera and verified eligible context; it never stores predicted identities as ground truth or raw photographs. Conflicting labels from duplicate tests are excluded from evidence export. First two distinct dates are calibration and later dates validation. Same-day repeats cannot satisfy independent-visit requirements. Check face does not create an active candidate or attendance and can collect labeled unenrolled-person trials.

The established preliminary gate remains at least 20 identities/100 aggregate observations, two independent calibration dates and two later validation dates per identity, representative device/lighting coverage and independent workflow approval. This is not proof that accuracy is adequate. Explicitly selected unknown people must be tested against a nonempty eligible gallery. Acceptable duplicate false-rejection and attendance false-identification budgets require review.

## Evaluation and approval

The evaluator computes actual template aggregation/duplicate decisions and complete attendance decisions using the backend's unanimous unique-winner eligible-gallery rule. Reports contain genuine/impostor distributions, missed duplicates, false duplicate rejection, wrong duplicate identities, attendance false-positive/false-negative identification, known rejection, unknown acceptance/rejection, session/condition counts, candidate curves and Wilson 95% intervals. Repeated captures/identities are correlated; reported intervals do not remove that limitation. Selection and held-out validation sets are separate. Trivial reject-everyone operating points cannot pass release.

Production recommendations require sufficient full evidence and accepted held-out results. Protected approval additionally binds the report SHA-256, current model/preprocessing, counts, live source, exact recommendations, approved reviewer/time, independent_validation and independent_workflow_validation. Public-photo tests, synthetic vectors and aggregate-only evidence cannot enable this gate.

An exploratory data-derived pilot configuration is possible only from actual evidence in the separate allowlisted pilot database. Its report records production_approved:false, uncertainty and fit limitations. It never changes production policy. Current pilot has no real captures and matching remains disabled; the existing source data is out of pilot scope.

After genuine collection, follow the private export/evaluation commands in BROWSER_FACE_SETUP.md. Actual physical browser/person outcomes and approved risk trade-offs remain required. No ongoing operator calibration steps are needed once a genuinely validated policy is deployed; pending activation still performs version, consent and tenant-wide duplicate/conflict checks.
