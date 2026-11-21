#!/usr/bin/env python3
"""Package model and runtime assets for GitHub release models-v1.

Copies asset files into dist-assets/ and writes SHA256SUMS.
Does not upload anything.
"""

import hashlib
import json
import shutil
from pathlib import Path


def digest(path: Path) -> str:
    result = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            result.update(block)
    return result.hexdigest()


def main() -> int:
    root = Path(__file__).resolve().parents[1]
    source_dir = root / "src" / "face_inference" / "web" / "vendor" / "browser-face"
    manifest_path = source_dir / "manifest.json"
    if not manifest_path.is_file():
        raise SystemExit(f"Missing manifest: {manifest_path}")

    manifest = json.loads(manifest_path.read_text())
    files = manifest.get("files", {})

    dist_dir = root / "dist-assets"
    dist_dir.mkdir(parents=True, exist_ok=True)

    sums = []
    for filename, info in sorted(files.items()):
        source_file = source_dir / filename
        if not source_file.is_file():
            raise SystemExit(f"Missing asset file to package: {source_file}")
        target_file = dist_dir / filename
        shutil.copyfile(source_file, target_file)
        file_sha256 = digest(target_file)
        if file_sha256 != info["sha256"]:
            raise SystemExit(f"Checksum mismatch packaging {filename}")
        sums.append(f"{file_sha256}  {filename}")

    sums_file = dist_dir / "SHA256SUMS"
    sums_file.write_text("\n".join(sums) + "\n")
    print(f"Packaged {len(sums)} assets in {dist_dir} and wrote SHA256SUMS.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
