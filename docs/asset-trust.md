# Model asset trust

The browser worker fetches the configured YuNet and SFace models and checks their SHA-256 digests against pins shipped with the worker before creating either inference session. The pins correspond to the prepared model hashes in `src/face_inference/web/vendor/browser-face/manifest.json`, not the source-model hashes in `src/face_inference/web/browser-face/contract.json`. The npm build checks the shipped pins against that manifest and includes the pin module alongside the worker.

This detects substituted model bytes from an asset server or CDN, including a configured `model-base-url`. Local assets work the same way. A failed download, unavailable browser SHA-256 verification, unknown model, or digest mismatch stops capture with an integrity error; no model URL is shown. Cross-origin servers must allow the browser to fetch the models. Web Crypto requires a secure context in supported browsers.

This does not protect against compromised shipped JavaScript or package contents. The ONNX Runtime WASM binary is not verified by this model check.
