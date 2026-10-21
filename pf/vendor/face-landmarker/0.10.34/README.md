# Pinned Face Landmarker assets

These files came from the official `@mediapipe/tasks-vision` 0.10.34 npm archive and Google's Face Landmarker float16 task bundle. The exact downloaded bytes are pinned by `ASSET-MANIFEST.json`. The npm module is served as `vision_bundle.js` so this Nginx site returns a JavaScript MIME type; its bytes are unchanged. The browser worker loads JS, WASM, and model from this same-origin directory. Captured frames are sent only to the existing first-party enrollment API.

The npm package declares Apache-2.0. Google's [Face Landmarker model page](https://developers.google.com/edge/mediapipe/solutions/vision/face_landmarker#models) links the bundle and its BlazeFace, FaceMesh V2, and Blendshape model cards. Each card states Apache-2.0. `LICENSE-APACHE-2.0` contains the license text.

The source model URL contains `latest`, so the source alone is not an immutable version. The SHA-256 in the manifest pins this copy. An artifact-specific license/provenance review and a browser network capture are release gates. A code scan cannot prove the bundled WASM makes no network request. The user authorized a device pilot with the flag enabled; those checks remain required before release validation is complete.
