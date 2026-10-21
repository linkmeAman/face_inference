# Face calibration artifacts

Place the generated approved calibration policy here as:

```text
services/face-inference/calibration/calibration-approved.json
```

Set the private runtime configuration to:

```text
FACE_CALIBRATION_FILE=/var/www/admin.creativeculturalcentre.in/services/face-inference/calibration/calibration-approved.json
```

Do not put raw images, embeddings, child names, member IDs, or unapproved
thresholds in this directory. The JSON/JSONL files are ignored by Git.

The file must be generated from approved real-device genuine/impostor score
records with `services/face-inference/tools/calibrate.py`. Until it exists and
passes model/license/checksum, zero-false-accept, and latency checks, the
inference service remains review-only.

## Presentation-attack calibration

Collect score-only PAD records across at least two venues, three device types,
and four lighting conditions. The attack set must include printed photos,
phone and tablet screens, video replay, masks, synthetic camera injection,
repeated frames, and replayed API attempts. Each record contains only `label` (`live` or `attack`), `score`,
`latency_ms`, `fixture`, `venue`, `device`, and `lighting`; do not retain raw
frames or embeddings.

After the technical, privacy, and administrative approvers sign the model
approval manifest, generate the runtime policy with:

```text
python3 tools/calibrate_pad.py approval-manifest.json pad-scores.json pad-calibration-approved.json
```

Set `FACE_PAD_CALIBRATION_FILE` to that artifact. Missing coverage, overlapping
live/attack scores, any held-out attack miss, or a failed latency target keeps
PAD unapproved and every otherwise acceptable match in manual review.

PAD artifacts are also bound to preprocessing version
`anti-spoof-mn3-square-1.5-v1`. Older artifacts without that exact value fail
with `PAD_PREPROCESSING_VERSION_MISMATCH`; do not add the field by hand. Re-run
the approved score-only collection on the corrected preprocessing and generate
a new artifact with `tools/calibrate_pad.py`.
