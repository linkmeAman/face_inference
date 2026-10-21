# Local feasibility model licenses

This directory contains the user-approved OpenCV Zoo feasibility bundle.

- YuNet detector: `face_detection_yunet_2023mar_int8.onnx`, MIT license.
- SFace recognizer: `face_recognition_sface_2021dec_int8bq.onnx`, Apache-2.0
  license.
- Intel Open Model Zoo `anti-spoof-mn3`: `anti-spoof-mn3.onnx`, MIT license.
  The upstream specification reports CelebA-Spoof training and 3.81% ACER;
  those upstream results do not replace local attack-fixture calibration.

The exact sources, retrieval date, and SHA-256 values are recorded in
`MODEL-MANIFEST.json`. These files are approved only for local feasibility and
shadow mode. Automatic attendance and production use remain pending real
device calibration and legal/product approval.

The PAD model is approved only for shadow-mode technical evaluation until its
local fixture calibration and product/privacy sign-off are recorded. Do not
substitute InsightFace model packs without separate commercial-use permission.
Preserve the upstream license notices with any deployed bundle.
