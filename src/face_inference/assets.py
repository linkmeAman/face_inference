"""Model and runtime asset management: models_dir, verify, and fetch."""

import hashlib
import json
import os
import re
import tempfile
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from .config import default_home

DEFAULT_BASE_URL = "https://github.com/linkmeAman/face_inference/releases/download/models-v1/"
PACKAGED_VENDOR_DIR = Path(__file__).resolve().parent / "web" / "vendor" / "browser-face"


class VerifyResult(dict):
    """Dictionary containing verification outcome; evaluates to True on PASS."""

    def __bool__(self) -> bool:
        return self.get("status") == "PASS"


def digest(path: Path) -> str:
    """Calculate SHA-256 digest of a file in 1MB chunks."""
    result = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            result.update(block)
    return result.hexdigest()


def _check_path(path: Path) -> None:
    if path.is_symlink():
        raise RuntimeError(f"Symlink in asset path: {path}")


def _validate_manifest(manifest: dict) -> dict:
    if not isinstance(manifest, dict) or not isinstance(manifest.get("files"), dict):
        raise RuntimeError("Invalid asset manifest")
    for name, info in manifest["files"].items():
        if (not isinstance(name, str) or name in (".", "..")
                or not re.fullmatch(r"[A-Za-z0-9._-]+", name)):
            raise RuntimeError("Invalid asset manifest")
        if (not isinstance(info, dict) or not isinstance(info.get("sha256"), str)
                or not re.fullmatch(r"[0-9a-f]{64}", info["sha256"])
                or ("size" in info and (type(info["size"]) is not int or info["size"] < 0))):
            raise RuntimeError("Invalid asset manifest")
    return manifest


def _unique_pairs(pairs: list) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise RuntimeError("Invalid asset manifest")
        result[key] = value
    return result


def _read_manifest(path: Path) -> dict:
    try:
        return _validate_manifest(json.loads(path.read_text(), object_pairs_hook=_unique_pairs))
    except (json.JSONDecodeError, UnicodeDecodeError) as err:
        raise RuntimeError("Invalid asset manifest") from err


def load_manifest(models_dir: Path | str | None = None) -> dict:
    """Load and validate the local or packaged asset manifest."""
    if models_dir is not None:
        local_manifest = Path(models_dir) / "manifest.json"
        _check_path(local_manifest)
        if local_manifest.is_file():
            return _read_manifest(local_manifest)
    packaged_manifest = PACKAGED_VENDOR_DIR / "manifest.json"
    _check_path(packaged_manifest)
    if packaged_manifest.is_file():
        return _read_manifest(packaged_manifest)
    raise RuntimeError(f"Asset manifest not found: {packaged_manifest}")


def _is_dir_valid(dir_path: Path, manifest: dict | None = None) -> bool:
    """Check if all assets in the manifest exist in dir_path with matching checksums."""
    if not dir_path.is_dir():
        return False
    try:
        manifest = _validate_manifest(manifest) if manifest is not None else load_manifest(dir_path)
    except Exception:
        return False
    files = manifest.get("files", {})
    if not files:
        return False
    for filename, info in files.items():
        target = dir_path / filename
        if not target.is_file() or target.is_symlink():
            return False
        expected_size = info.get("size")
        if expected_size is not None and target.stat().st_size != expected_size:
            return False
        if digest(target) != info["sha256"]:
            return False
    return True


def models_dir(config: dict | None = None) -> Path:
    """Resolve models directory following priority:

    1. Environment variable FACE_INFERENCE_MODELS_DIR
    2. <config parent or default_home()>/models if present and valid
    3. Packaged dir web/vendor/browser-face if all files present and valid
    4. Default fallback: <config parent or default_home()>/models
    """
    if "FACE_INFERENCE_MODELS_DIR" in os.environ:
        return Path(os.environ["FACE_INFERENCE_MODELS_DIR"]).absolute()

    if config and isinstance(config, dict) and "_path" in config:
        home_models = (Path(config["_path"]).parent / "models").absolute()
    else:
        home_models = default_home() / "models"

    if _is_dir_valid(home_models):
        return home_models

    packaged = PACKAGED_VENDOR_DIR
    if _is_dir_valid(packaged):
        return packaged

    return home_models


def verify(target_dir: Path | str) -> VerifyResult:
    """Verify that all assets in the manifest exist and match their checksums."""
    target_path = Path(target_dir).absolute()
    manifest = load_manifest(target_path)
    files = manifest.get("files", {})
    failures = []

    for filename, info in files.items():
        file_path = target_path / filename
        if not file_path.is_file() or file_path.is_symlink():
            failures.append({"path": filename, "reason": "MISSING_OR_SYMLINK"})
        elif info.get("size") is not None and file_path.stat().st_size != info["size"]:
            failures.append({"path": filename, "reason": "CHECKSUM_MISMATCH"})
        elif digest(file_path) != info["sha256"]:
            failures.append({"path": filename, "reason": "CHECKSUM_MISMATCH"})

    status = "FAIL" if failures else "PASS"
    return VerifyResult(status=status, checked_assets=len(files), failures=failures)


def fetch(
    target_dir: Path | str,
    base_url: str | None = None,
    from_dir: Path | str | None = None,
    *,
    _allow_http: bool = False,
) -> None:
    """Fetch model and runtime assets into target_dir.

    Downloads via HTTPS or copies from from_dir, verifying checksums and atomic rename.
    Never overwrites an existing file with a wrong hash.
    """
    dest_path = Path(target_dir).absolute()
    _check_path(dest_path / "manifest.json")

    if from_dir is not None:
        source_path = Path(from_dir).absolute()
        src_manifest_path = source_path / "manifest.json"
        _check_path(src_manifest_path)
        manifest = (load_manifest(source_path) if src_manifest_path.is_file()
                    else load_manifest(dest_path))
    else:
        manifest = load_manifest(dest_path)

    files = manifest.get("files", {})
    dest_path.mkdir(parents=True, exist_ok=True)
    resolved_base_url = base_url or manifest.get("base_url", DEFAULT_BASE_URL)

    # Pre-check: never overwrite an existing file whose hash is wrong
    for filename, info in files.items():
        existing_file = dest_path / filename
        if existing_file.exists() or existing_file.is_symlink():
            if existing_file.is_symlink() or not existing_file.is_file():
                raise RuntimeError(
                    f"Existing asset is not a regular file; refusing to overwrite: {existing_file}"
                )
            if digest(existing_file) != info["sha256"]:
                raise RuntimeError(
                    f"Existing asset has wrong checksum; refusing to overwrite: {existing_file}"
                )

    for filename, info in files.items():
        target = dest_path / filename
        if target.exists() and digest(target) == info["sha256"]:
            continue  # Idempotent: already present and verified

        temp_file = None
        try:
            with tempfile.NamedTemporaryFile(
                dir=dest_path, prefix=".model-fetch-", delete=False
            ) as stream:
                temp_file = Path(stream.name)
                sha = hashlib.sha256()
                length = 0

                if from_dir is not None:
                    source_file = source_path / filename
                    if not source_file.is_file() or source_file.is_symlink():
                        raise RuntimeError(f"Source asset missing or invalid: {source_file}")
                    with source_file.open("rb") as src_stream:
                        for chunk in iter(lambda: src_stream.read(1024 * 1024), b""):
                            length += len(chunk)
                            sha.update(chunk)
                            stream.write(chunk)
                else:
                    url = resolved_base_url.rstrip("/") + "/" + filename
                    is_https = url.startswith("https://")
                    is_local_http = _allow_http and (
                        url.startswith("http://127.0.0.1:")
                        or url.startswith("http://localhost:")
                    )
                    if not (is_https or is_local_http):
                        raise RuntimeError(
                            f"Only HTTPS artifact sources are supported: {url}"
                        )
                    req = Request(url, headers={"User-Agent": "face-inference-model-fetch/1"})
                    try:
                        with urlopen(req, timeout=60) as resp:
                            resp_url = resp.geturl()
                            resp_is_https = resp_url.startswith("https://")
                            resp_is_local_http = _allow_http and (
                                resp_url.startswith("http://127.0.0.1:")
                                or resp_url.startswith("http://localhost:")
                            )
                            if not (resp_is_https or resp_is_local_http):
                                raise RuntimeError(
                                    f"Refusing redirect to non-HTTPS artifact: {resp_url}"
                                )
                            for chunk in iter(lambda: resp.read(1024 * 1024), b""):
                                length += len(chunk)
                                if length > 512 * 1024 * 1024:
                                    raise RuntimeError("Artifact exceeds download size bound")
                                sha.update(chunk)
                                stream.write(chunk)
                    except (HTTPError, URLError, OSError) as err:
                        raise RuntimeError(f"Network failure downloading {url}: {err}") from None

            if sha.hexdigest() != info["sha256"]:
                raise RuntimeError(
                    f"Downloaded artifact checksum mismatch for {filename}: "
                    f"expected {info['sha256']}, got {sha.hexdigest()}"
                )
            if info.get("size") is not None and length != info["size"]:
                raise RuntimeError(
                    f"Downloaded artifact size mismatch for {filename}: "
                    f"expected {info['size']}, got {length}"
                )

            os.replace(temp_file, target)
            temp_file = None
        finally:
            if temp_file is not None and temp_file.exists():
                temp_file.unlink()

    # Save manifest into models_dir as well
    manifest_file = dest_path / "manifest.json"
    _check_path(manifest_file)
    if not manifest_file.exists():
        manifest_file.write_text(json.dumps(manifest, indent=2) + "\n")
