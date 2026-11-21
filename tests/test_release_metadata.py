"""Tests and gate for release metadata consistency, supply chain, and workflows.

Validates Python and npm package metadata, semver conformity, tag consistency,
least-privilege CI workflow definitions, and supply-chain auditing documentation.
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

import pytest
import yaml

REPO_ROOT = Path(__file__).resolve().parents[1]
TOOLS_DIR = REPO_ROOT / "tools"
if str(TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(TOOLS_DIR))

# ruff: noqa: E402
from verify_release_metadata import (
    SEMVER_PATTERN,
    extract_python_version_from_init,
    verify_release_metadata,
)
from verify_release_metadata import (
    main as tool_main,
)

# =============================================================================
# Unit Tests
# =============================================================================


def test_extract_python_version_direct():
    """Verify extract_python_version_from_init reads version correctly."""
    init_file = REPO_ROOT / "src" / "face_inference" / "__init__.py"
    assert extract_python_version_from_init(init_file) == "0.1.0"
    assert SEMVER_PATTERN.match("0.1.0") is not None


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
    """Verify CLI entrypoint of verify_release_metadata works."""
    tool_path = REPO_ROOT / "tools" / "verify_release_metadata.py"
    cmd = [
        sys.executable,
        str(tool_path),
        "--repo-root",
        str(REPO_ROOT),
        "--tag",
        "v0.1.0",
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    assert proc.returncode == 0
    assert "Release metadata valid" in proc.stdout


def test_cli_execution_isolated_stdlib_only(tmp_path: Path):
    """Verify tools/verify_release_metadata.py runs under python -I (isolated mode, stdlib only)."""
    tool_path = REPO_ROOT / "tools" / "verify_release_metadata.py"
    out_file = tmp_path / "github_output"
    cmd = [
        sys.executable,
        "-I",
        str(tool_path),
        "--repo-root",
        str(REPO_ROOT),
        "--tag",
        "v0.1.0",
        "--github-output",
        str(out_file),
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    assert proc.returncode == 0, f"Isolated run failed: {proc.stderr}"
    assert "Release metadata valid" in proc.stdout
    assert "npm_publish=true" in out_file.read_text(encoding="utf-8")

    cmd_mismatch = [
        sys.executable,
        "-I",
        str(tool_path),
        "--repo-root",
        str(REPO_ROOT),
        "--tag",
        "v0.9.9",
    ]
    proc_mismatch = subprocess.run(
        cmd_mismatch, capture_output=True, text=True, check=False
    )
    assert proc_mismatch.returncode != 0
    assert (
        "Tag version '0.9.9' does not match face_inference.__version__ '0.1.0'"
        in proc_mismatch.stderr
    )


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

    verify_steps = jobs["verify"].get("steps", [])
    verify_step = next(
        (s for s in verify_steps if "verify_release_metadata.py" in s.get("run", "")),
        None,
    )
    assert verify_step is not None, "Missing verify_release_metadata step in verify job"
    assert "--tag" in verify_step["run"]
    assert "--github-output" in verify_step["run"]

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
    assert "--skip-editable" in py_run_step["run"]


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
    sys.exit(tool_main())
