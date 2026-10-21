# Face model provenance

Model weights are deployment artifacts and should be managed separately from
source control unless the repository's artifact policy explicitly permits
them. The local feasibility bundle is recorded in `MODEL-MANIFEST.json`.

Before a model is placed in this directory, record the following in the
deployment inventory and in `face_model_version`:

- exact source URL or licensed package;
- license and intended-use approval;
- detector and recognizer version;
- SHA-256 checksum;
- preprocessing and embedding dimension;
- calibration dataset and approved threshold/margin;
- approval date and retiring date.

The feasibility filenames are `face_detection_yunet_2023mar_int8.onnx` and
`face_recognition_sface_2021dec_int8bq.onnx`; the service accepts paths through
`FACE_YUNET_MODEL` and `FACE_SFACE_MODEL`.
Missing or unapproved weights leave the service in review-only mode; the
service never guesses an identity without an explicit calibrated threshold.
