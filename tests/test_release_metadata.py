"""Tests and gate for release metadata consistency, supply chain, and workflows.

Validates Python and npm package metadata, semver conformity, tag consistency,
least-privilege CI workflow definitions, and supply-chain auditing documentation.
"""

import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

import pytest
import yaml

try:
    import tomllib
except ModuleNotFoundError:  # pragma: no cover
    import tomli as tomllib  # type: ignore[no-redef]

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
    try:
        pyproject_data = tomllib.loads(pyproject_path.read_text(encoding="utf-8"))
    except Exception as err:
        raise ValueError(f"Failed to parse pyproject.toml: {err}") from err

    project_section = pyproject_data.get("project", {})
    if project_section.get("name") != "face-inference":
        raise ValueError(
            f"Expected project name 'face-inference', got {project_section.get('name')!r}"
        )
    dynamic_fields = project_section.get("dynamic", [])
    if "version" not in dynamic_fields:
        raise ValueError("pyproject.toml must declare dynamic = ['version']")

    hatch_version_path = (
        pyproject_data.get("tool", {}).get("hatch", {}).get("version", {}).get("path")
    )
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


# =============================================================================
# Unit Tests
# =============================================================================

REPO_ROOT = Path(__file__).resolve().parents[1]


def test_verify_current_repo_metadata():
    """Verify that current repository files pass release metadata validation."""
    result = verify_release_metadata(REPO_ROOT)
    assert result["valid"] is True
    assert result["python_version"] == "0.1.0"
    assert result["npm_version"] == "0.1.0"
    assert result["npm_match"] is True


def test_verify_matching_tag(tmp_path: Path):
    """Verify that a matching release tag validates successfully and outputs flags."""
    out_file = tmp_path / "github_output"
    result = verify_release_metadata(REPO_ROOT, tag="v0.1.0", output_file=out_file)
    assert result["valid"] is True
    assert result["tag_version"] == "0.1.0"
    assert result["npm_match"] is True

    output_lines = out_file.read_text(encoding="utf-8").splitlines()
    assert "npm_publish=true" in output_lines
    assert "tag_version=0.1.0" in output_lines
    assert "python_version=0.1.0" in output_lines


def test_verify_tag_mismatch_fails():
    """Verify that a tag mismatch with Python package version raises ValueError."""
    with pytest.raises(
        ValueError,
        match="Tag version '0.2.0' does not match face_inference.__version__ '0.1.0'",
    ):
        verify_release_metadata(REPO_ROOT, tag="v0.2.0")


@pytest.mark.parametrize(
    "invalid_tag",
    [
        "invalid",
        "v",
        "v1",
        "v1.0",
        "1.0",
        "v01.0.0",
        "release-0.1.0",
    ],
)
def test_verify_malformed_tag_fails(invalid_tag: str):
    """Verify that malformed tags fail with clear SemVer error messages."""
    with pytest.raises(ValueError, match="does not conform to valid SemVer format"):
        verify_release_metadata(REPO_ROOT, tag=invalid_tag)


def test_npm_mismatch_conditional_publish(tmp_path: Path):
    """Verify that npm mismatch does not fail release, but sets npm_publish=false."""
    # Copy repo files to mock directory
    mock_root = tmp_path / "mock_repo"
    mock_src = mock_root / "src" / "face_inference"
    mock_pkg = mock_root / "packages" / "face-capture"
    mock_src.mkdir(parents=True)
    mock_pkg.mkdir(parents=True)

    (mock_root / "pyproject.toml").write_text(
        '[project]\nname = "face-inference"\ndynamic = ["version"]\n'
        '[tool.hatch.version]\npath = "src/face_inference/__init__.py"\n',
        encoding="utf-8",
    )
    (mock_src / "__init__.py").write_text('__version__ = "0.1.0"\n', encoding="utf-8")
    (mock_pkg / "package.json").write_text(
        json.dumps({"name": "face-capture", "version": "0.2.0"}),
        encoding="utf-8",
    )

    out_file = tmp_path / "output"
    result = verify_release_metadata(mock_root, tag="v0.1.0", output_file=out_file)
    assert result["valid"] is True
    assert result["python_version"] == "0.1.0"
    assert result["npm_version"] == "0.2.0"
    assert result["npm_match"] is False

    output_lines = out_file.read_text(encoding="utf-8").splitlines()
    assert "npm_publish=false" in output_lines


def test_missing_npm_package_tolerated(tmp_path: Path):
    """Verify missing packages/face-capture/package.json results in npm_match=False."""
    mock_root = tmp_path / "mock_repo"
    mock_src = mock_root / "src" / "face_inference"
    mock_src.mkdir(parents=True)

    (mock_root / "pyproject.toml").write_text(
        '[project]\nname = "face-inference"\ndynamic = ["version"]\n'
        '[tool.hatch.version]\npath = "src/face_inference/__init__.py"\n',
        encoding="utf-8",
    )
    (mock_src / "__init__.py").write_text('__version__ = "0.1.0"\n', encoding="utf-8")

    result = verify_release_metadata(mock_root, tag="v0.1.0")
    assert result["valid"] is True
    assert result["npm_version"] is None
    assert result["npm_match"] is False


def test_corrupt_npm_package_json(tmp_path: Path):
    """Verify that corrupt package.json fails release metadata verification."""
    mock_root = tmp_path / "mock_repo"
    mock_src = mock_root / "src" / "face_inference"
    mock_pkg = mock_root / "packages" / "face-capture"
    mock_src.mkdir(parents=True)
    mock_pkg.mkdir(parents=True)

    (mock_root / "pyproject.toml").write_text(
        '[project]\nname = "face-inference"\ndynamic = ["version"]\n'
        '[tool.hatch.version]\npath = "src/face_inference/__init__.py"\n',
        encoding="utf-8",
    )
    (mock_src / "__init__.py").write_text('__version__ = "0.1.0"\n', encoding="utf-8")
    (mock_pkg / "package.json").write_text("{not: valid: json}", encoding="utf-8")

    with pytest.raises(ValueError, match="Failed to parse"):
        verify_release_metadata(mock_root)


def test_invalid_npm_version_format(tmp_path: Path):
    """Verify that invalid SemVer in package.json fails validation."""
    mock_root = tmp_path / "mock_repo"
    mock_src = mock_root / "src" / "face_inference"
    mock_pkg = mock_root / "packages" / "face-capture"
    mock_src.mkdir(parents=True)
    mock_pkg.mkdir(parents=True)

    (mock_root / "pyproject.toml").write_text(
        '[project]\nname = "face-inference"\ndynamic = ["version"]\n'
        '[tool.hatch.version]\npath = "src/face_inference/__init__.py"\n',
        encoding="utf-8",
    )
    (mock_src / "__init__.py").write_text('__version__ = "0.1.0"\n', encoding="utf-8")
    (mock_pkg / "package.json").write_text(
        json.dumps({"name": "face-capture", "version": "1.0"}),
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="Invalid SemVer in npm package.json"):
        verify_release_metadata(mock_root)


def test_invalid_python_version_format(tmp_path: Path):
    """Verify that invalid SemVer in __init__.py fails validation."""
    mock_root = tmp_path / "mock_repo"
    mock_src = mock_root / "src" / "face_inference"
    mock_src.mkdir(parents=True)

    (mock_root / "pyproject.toml").write_text(
        '[project]\nname = "face-inference"\ndynamic = ["version"]\n'
        '[tool.hatch.version]\npath = "src/face_inference/__init__.py"\n',
        encoding="utf-8",
    )
    (mock_src / "__init__.py").write_text('__version__ = "not-semver"\n', encoding="utf-8")

    with pytest.raises(ValueError, match="Invalid SemVer in __version__"):
        verify_release_metadata(mock_root)


def test_missing_pyproject(tmp_path: Path):
    """Verify that missing pyproject.toml raises FileNotFoundError."""
    mock_root = tmp_path / "mock_repo"
    mock_root.mkdir()
    with pytest.raises(FileNotFoundError, match="pyproject.toml not found"):
        verify_release_metadata(mock_root)


def test_invalid_pyproject_name(tmp_path: Path):
    """Verify that incorrect project name in pyproject.toml raises ValueError."""
    mock_root = tmp_path / "mock_repo"
    mock_root.mkdir()
    (mock_root / "pyproject.toml").write_text(
        '[project]\nname = "wrong-name"\ndynamic = ["version"]\n',
        encoding="utf-8",
    )
    with pytest.raises(ValueError, match="Expected project name 'face-inference'"):
        verify_release_metadata(mock_root)


def test_cli_execution():
    """Verify CLI entrypoint of test_release_metadata works."""
    cmd = [
        sys.executable,
        str(Path(__file__).resolve()),
        "--repo-root",
        str(REPO_ROOT),
        "--tag",
        "v0.1.0",
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    assert proc.returncode == 0
    assert "Release metadata valid" in proc.stdout


# =============================================================================
# Workflow Verification Tests
# =============================================================================

WORKFLOWS_DIR = REPO_ROOT / ".github" / "workflows"


def test_release_workflow_structure():
    """Verify release.yml parses and adheres to security and release policies."""
    release_yml = WORKFLOWS_DIR / "release.yml"
    assert release_yml.is_file(), "release.yml missing"
    data = yaml.safe_load(release_yml.read_text(encoding="utf-8"))

    # Least privilege permissions
    assert data.get("permissions") == {"contents": "read"}

    # Verified action versions
    jobs = data.get("jobs", {})
    assert "verify" in jobs
    assert "pypi" in jobs
    assert "npm" in jobs
    assert "github-release" in jobs

    npm_job = jobs["npm"]
    assert npm_job.get("needs") == "verify"
    assert npm_job.get("if") == "needs.verify.outputs.npm_publish == 'true'"
    assert npm_job.get("permissions") == {"contents": "read", "id-token": "write"}

    # Verify npm publish includes provenance
    npm_steps = npm_job.get("steps", [])
    publish_step = next(
        (s for s in npm_steps if "npm publish" in s.get("run", "")),
        None,
    )
    assert publish_step is not None, "Missing npm publish step"
    assert "--provenance" in publish_step["run"], "npm publish must use --provenance"


def test_supply_chain_workflow_structure():
    """Verify supply-chain.yml parses and conforms to least-privilege standards."""
    sc_yml = WORKFLOWS_DIR / "supply-chain.yml"
    assert sc_yml.is_file(), "supply-chain.yml missing"
    data = yaml.safe_load(sc_yml.read_text(encoding="utf-8"))

    assert data.get("permissions") == {"contents": "read"}
    jobs = data.get("jobs", {})
    assert "python-audit" in jobs
    assert "npm-audit" in jobs

    # Pinned tool versions
    py_steps = jobs["python-audit"].get("steps", [])
    py_run_step = next((s for s in py_steps if "pip-audit" in s.get("run", "")), None)
    assert py_run_step is not None
    assert "pip-audit==2.10.1" in py_run_step["run"]


def test_synthetic_workflow_structure():
    """Verify synthetic.yml parses and runs evaluate_synthetic check."""
    syn_yml = WORKFLOWS_DIR / "synthetic.yml"
    assert syn_yml.is_file(), "synthetic.yml missing"
    data = yaml.safe_load(syn_yml.read_text(encoding="utf-8"))

    assert data.get("permissions") == {"contents": "read"}
    jobs = data.get("jobs", {})
    assert "synthetic" in jobs
    syn_steps = jobs["synthetic"].get("steps", [])
    run_step = next(
        (s for s in syn_steps if "evaluate_synthetic.py --check" in s.get("run", "")),
        None,
    )
    assert run_step is not None


def test_supply_chain_documentation_coverage():
    """Verify docs/supply-chain.md exists and covers scope, tools, and limitations."""
    doc_path = REPO_ROOT / "docs" / "supply-chain.md"
    assert doc_path.is_file(), "docs/supply-chain.md missing"
    content = doc_path.read_text(encoding="utf-8")

    assert "Scope" in content
    assert "Limitations" in content
    assert "Invocation" in content
    assert "pip-audit==2.10.1" in content
    assert "npm audit" in content


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Verify release metadata consistency")
    parser.add_argument("--repo-root", default=".")
    parser.add_argument("--tag", default=os.environ.get("GITHUB_REF_NAME", ""))
    parser.add_argument("--output-file", default=os.environ.get("GITHUB_OUTPUT"))
    args = parser.parse_args()

    res = verify_release_metadata(
        Path(args.repo_root),
        tag=args.tag if args.tag else None,
        output_file=args.output_file if args.output_file else None,
    )
    print(f"Release metadata valid: {res}")
