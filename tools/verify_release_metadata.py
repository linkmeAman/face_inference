#!/usr/bin/env python3
"""Release metadata verification CLI and library.

Verifies consistency across:
- Python package metadata in pyproject.toml
- Python version string in src/face_inference/__init__.py
- npm package metadata in packages/face-capture/package.json (if present)
- Git release tag (e.g. v0.1.0 or refs/tags/v0.1.0)
- GitHub Actions output flags (npm_publish=true|false)

This tool depends STRICTLY on the Python standard library (no 3rd-party dependencies).
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path
from typing import Any

try:
    import tomllib
except ModuleNotFoundError:  # pragma: no cover
    try:
        import tomli as tomllib  # type: ignore[no-redef]
    except ModuleNotFoundError:
        tomllib = None  # type: ignore[assignment]

SEMVER_PATTERN = re.compile(
    r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)"
    r"(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)"
    r"(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?"
    r"(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$"
)


def extract_python_version_from_init(init_path: Path) -> str:
    """Extract __version__ string from a Python __init__.py file."""
    if not init_path.is_file():
        raise FileNotFoundError(f"Python init file not found: {init_path}")
    content = init_path.read_text(encoding="utf-8")
    match = re.search(r'^__version__\s*=\s*["\']([^"\']+)["\']', content, re.MULTILINE)
    if not match:
        raise ValueError(f"No __version__ definition found in {init_path}")
    version = match.group(1).strip()
    if not SEMVER_PATTERN.match(version):
        raise ValueError(f"Invalid SemVer in __version__ ({version!r}) at {init_path}")
    return version


def verify_release_metadata(
    repo_root: Path,
    tag: str | None = None,
    output_file: Path | str | None = None,
) -> dict[str, Any]:
    """Verify package metadata, tag consistency, and output build flags.

    Checks:
    1. Python package metadata in pyproject.toml and src/face_inference/__init__.py.
    2. npm package metadata in packages/face-capture/package.json if present.
    3. Tag consistency against Python package version (when tag is present).
    4. Conditional npm publication flag based on whether tag matches npm version.
    5. Outputs build variables to GitHub Actions output file if specified.
    """
    resolved_root = repo_root.resolve()

    # 1. Validate pyproject.toml
    pyproject_path = resolved_root / "pyproject.toml"
    if not pyproject_path.is_file():
        raise FileNotFoundError(f"pyproject.toml not found at {pyproject_path}")

    if tomllib is not None:
        try:
            pyproject_data = tomllib.loads(pyproject_path.read_text(encoding="utf-8"))
        except Exception as err:
            raise ValueError(f"Failed to parse pyproject.toml: {err}") from err

        project_section = pyproject_data.get("project", {})
        project_name = project_section.get("name")
        dynamic_fields = project_section.get("dynamic", [])
        hatch_version_path = (
            pyproject_data.get("tool", {}).get("hatch", {}).get("version", {}).get("path")
        )
    else:
        text = pyproject_path.read_text(encoding="utf-8")
        name_match = re.search(r'^\s*name\s*=\s*["\']([^"\']+)["\']', text, re.MULTILINE)
        project_name = name_match.group(1) if name_match else None
        dynamic_match = re.search(
            r'^\s*dynamic\s*=\s*\[(.*?)\]', text, re.DOTALL | re.MULTILINE
        )
        dynamic_fields = (
            [
                s.strip().strip('"\'')
                for s in dynamic_match.group(1).split(",")
                if s.strip().strip('"\'')
            ]
            if dynamic_match
            else []
        )
        hatch_match = re.search(
            r'\[tool\.hatch\.version\]\s*path\s*=\s*["\']([^"\']+)["\']',
            text,
            re.DOTALL,
        )
        hatch_version_path = hatch_match.group(1) if hatch_match else None

    if project_name != "face-inference":
        raise ValueError(
            f"Expected project name 'face-inference', got {project_name!r}"
        )
    if "version" not in dynamic_fields:
        raise ValueError("pyproject.toml must declare dynamic = ['version']")

    if hatch_version_path != "src/face_inference/__init__.py":
        raise ValueError(
            f"Expected [tool.hatch.version].path = 'src/face_inference/__init__.py', "
            f"got {hatch_version_path!r}"
        )

    # 2. Validate Python __init__.py version
    init_path = resolved_root / "src" / "face_inference" / "__init__.py"
    py_ver = extract_python_version_from_init(init_path)

    # 3. Validate npm package.json if present
    pkg_file = resolved_root / "packages" / "face-capture" / "package.json"
    npm_ver: str | None = None
    if pkg_file.is_file():
        try:
            pkg_data = json.loads(pkg_file.read_text(encoding="utf-8"))
        except Exception as err:
            raise ValueError(f"Failed to parse {pkg_file}: {err}") from err

        if pkg_data.get("name") != "face-capture":
            raise ValueError(
                f"Expected npm package name 'face-capture', got {pkg_data.get('name')!r}"
            )
        raw_npm_ver = str(pkg_data.get("version", "")).strip()
        if not raw_npm_ver or not SEMVER_PATTERN.match(raw_npm_ver):
            raise ValueError(f"Invalid SemVer in npm package.json: {raw_npm_ver!r}")
        npm_ver = raw_npm_ver

    # 4. Check tag consistency when tag is provided
    npm_match = False
    clean_tag_ver: str | None = None
    if tag:
        clean_tag = tag.strip()
        if clean_tag.startswith("refs/tags/"):
            clean_tag = clean_tag[len("refs/tags/") :]
        if clean_tag.startswith("v"):
            clean_tag_ver = clean_tag[1:]
        else:
            clean_tag_ver = clean_tag

        if not SEMVER_PATTERN.match(clean_tag_ver):
            raise ValueError(
                f"Release tag {tag!r} does not conform to valid SemVer format ('vX.Y.Z')"
            )

        if py_ver != clean_tag_ver:
            raise ValueError(
                f"Tag version {clean_tag_ver!r} does not match "
                f"face_inference.__version__ {py_ver!r}"
            )

        npm_match = (npm_ver == clean_tag_ver) if npm_ver is not None else False
    else:
        npm_match = (npm_ver == py_ver) if npm_ver is not None else False

    # 5. Write to output file if provided (e.g. GITHUB_OUTPUT)
    if output_file:
        out_path = Path(output_file)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        with out_path.open("a", encoding="utf-8") as f:
            f.write(f"npm_publish={str(npm_match).lower()}\n")
            if clean_tag_ver:
                f.write(f"tag_version={clean_tag_ver}\n")
            f.write(f"python_version={py_ver}\n")
            if npm_ver:
                f.write(f"npm_version={npm_ver}\n")

    return {
        "python_version": py_ver,
        "npm_version": npm_ver,
        "npm_match": npm_match,
        "tag": tag,
        "tag_version": clean_tag_ver,
        "valid": True,
    }


def main(argv: list[str] | None = None) -> int:
    """CLI entrypoint for verifying release metadata."""
    parser = argparse.ArgumentParser(
        description="Verify release metadata consistency across repo files and tags."
    )
    parser.add_argument(
        "--repo-root",
        type=Path,
        default=Path("."),
        help="Path to repository root (default: current directory).",
    )
    parser.add_argument(
        "--tag",
        default=os.environ.get("GITHUB_REF_NAME", ""),
        help="Release tag to verify (default: $GITHUB_REF_NAME or empty).",
    )
    parser.add_argument(
        "--github-output",
        "--output-file",
        dest="github_output",
        default=os.environ.get("GITHUB_OUTPUT"),
        help="Path to GitHub Actions output file ($GITHUB_OUTPUT).",
    )
    args = parser.parse_args(argv)

    tag = args.tag.strip() if args.tag and args.tag.strip() else None
    output_file = (
        args.github_output.strip()
        if args.github_output and args.github_output.strip()
        else None
    )

    try:
        res = verify_release_metadata(
            repo_root=args.repo_root,
            tag=tag,
            output_file=output_file,
        )
    except (ValueError, FileNotFoundError) as err:
        sys.stderr.write(f"Release metadata verification failed: {err}\n")
        return 1
    except Exception as err:
        sys.stderr.write(f"Unexpected verification error: {err}\n")
        return 1

    sys.stdout.write(f"Release metadata valid: {res}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
