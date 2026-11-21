# Third-Party Notices

This project incorporates third-party open-source components and machine-learning models. Below is a summary of the components, their licenses, upstream sources, and applicable copyright notices.

## Component Summary

| Component | Role | License | Upstream Source | Upstream License Link |
|---|---|---|---|---|
| **YuNet** | Face Detection Model | [MIT](https://spdx.org/licenses/MIT.html) | [opencv_zoo: face_detection_yunet](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet) | [YuNet LICENSE](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_detection_yunet/LICENSE) |
| **SFace** | Face Recognition Model | [Apache-2.0](https://www.apache.org/licenses/LICENSE-2.0) | [opencv_zoo: face_recognition_sface](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface) | [SFace LICENSE](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_recognition_sface/LICENSE) |
| **onnxruntime-web** | In-Browser ML Runtime (v1.24.3) | [MIT](https://spdx.org/licenses/MIT.html) | [onnxruntime.ai](https://onnxruntime.ai) (`microsoft/onnxruntime`) | [npm package](https://registry.npmjs.org/onnxruntime-web) |
| **cryptography** | Server-side Encryption | [Apache-2.0](https://www.apache.org/licenses/LICENSE-2.0) / [BSD-3-Clause](https://spdx.org/licenses/BSD-3-Clause.html) | [pyca/cryptography](https://github.com/pyca/cryptography) | [cryptography LICENSE](https://raw.githubusercontent.com/pyca/cryptography/main/LICENSE) |

---

## Model Provenance Notice

> **Important:** The training data provenance of both models (YuNet and SFace) is not fully documented upstream. Users and deployers should perform independent due diligence regarding data provenance, demographic representation, and regulatory suitability for their specific deployment contexts.

---

## Notices and License Terms

### 1. YuNet Face Detector
- **License:** MIT License
- **Copyright:** Copyright (c) 2020 Shiqi Yu `<shiqi.yu@gmail.com>`
- **Source:** [opencv_zoo / face_detection_yunet](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet)
- **License Terms:** Licensed under the MIT License. A copy of the license is available at [YuNet LICENSE](https://raw.githubusercontent.com/opencv/opencv_zoo/main/models/face_detection_yunet/LICENSE).

### 2. SFace Face Recognizer
- **License:** Apache License, Version 2.0
- **Copyright:** OpenCV Zoo Contributors
- **Source:** [opencv_zoo / face_recognition_sface](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface)
- **Notice Requirements:** Section 4 of the Apache License, Version 2.0 requires that any distribution of copies or derivative works preserve all copyright, patent, trademark, and attribution notices from the source, and include a copy of the Apache-2.0 license and any applicable NOTICE file. Full license terms are available at [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0).

### 3. ONNX Runtime Web (`onnxruntime-web`)
- **Version:** 1.24.3
- **License:** MIT License
- **Copyright:** Copyright (c) Microsoft Corporation. All rights reserved.
- **Source:** [onnxruntime.ai](https://onnxruntime.ai) / [onnxruntime-web npm](https://registry.npmjs.org/onnxruntime-web)
- **License Terms:** Licensed under the MIT License ([SPDX MIT](https://spdx.org/licenses/MIT.html)).

### 4. cryptography
- **License:** Dual licensed under [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0) or [BSD 3-Clause License](https://spdx.org/licenses/BSD-3-Clause.html)
- **Copyright:** Copyright (c) Individual contributors.
- **Source:** [pyca/cryptography](https://github.com/pyca/cryptography)
- **License Terms:** Distributed under the terms of either Apache-2.0 ([LICENSE.APACHE](https://raw.githubusercontent.com/pyca/cryptography/main/LICENSE.APACHE)) or BSD-3-Clause ([LICENSE.BSD](https://raw.githubusercontent.com/pyca/cryptography/main/LICENSE.BSD)).
