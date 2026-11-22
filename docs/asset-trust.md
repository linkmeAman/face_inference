# Model asset trust

The browser worker fetches the configured YuNet and SFace models and checks their SHA-256 digests against pins shipped with the worker before creating either inference session. The pins correspond to the prepared model hashes in `src/face_inference/web/vendor/browser-face/manifest.json`, not the source-model hashes in `src/face_inference/web/browser-face/contract.json`. The npm build checks the shipped pins against that manifest and includes the pin module alongside the worker.

This detects substituted model bytes from an asset server or CDN, including a configured `model-base-url`. Local assets work the same way. A failed download, unavailable browser SHA-256 verification, unknown model, or digest mismatch stops capture with an integrity error; no model URL is shown. Cross-origin servers must allow the browser to fetch the models. Web Crypto requires a secure context in supported browsers.

The ONNX Runtime WASM binary is verified the same way: the worker fetches it from the asset base, checks its SHA-256 against a pin shipped with the worker (also tied to `manifest.json` by a test and by the npm build), and hands the verified bytes to ONNX Runtime. ONNX Runtime's JavaScript glue is never loaded from the asset origin: the worker selects the glue file that ships with the package (a path relative to the worker), so an asset origin can supply data (models and the verified WASM) but never executable JavaScript.

This was verified in real Chrome with a hostile asset origin: with the glue file booby-trapped to call home, the code before this change requested and executed it, and the current code never requests it and never runs it; a tampered WASM stops capture with the integrity error.

This does not protect against compromised shipped JavaScript or package contents.
