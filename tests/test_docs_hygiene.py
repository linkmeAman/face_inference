"""Documentation hygiene checks.

Scans all core documentation and markdown files for machine-specific absolute
paths, private file:/// URIs, or lane-specific strings.
"""

import shutil
import subprocess
from pathlib import Path

import pytest

PROHIBITED_SUBSTRINGS = (
    "/Users/",
    "file:///",
    "face_inference-lane",
    "C:\\Users",
)

TARGET_ROOT_FILES = (
    "README.md",
    "CONTRIBUTING.md",
    "SECURITY.md",
    "PRIVACY.md",
    "CHANGELOG.md",
)


def collect_target_doc_files(repo_root: Path) -> list[Path]:
    """Collects all required markdown files to inspect for documentation hygiene."""
    resolved_root = repo_root.resolve()
    targets: list[Path] = []
    for filename in TARGET_ROOT_FILES:
        target = resolved_root / filename
        if target.is_file():
            targets.append(target)

    targets.extend(resolved_root.glob("docs/**/*.md"))
    targets.extend(resolved_root.glob("examples/**/*.md"))
    targets.extend(resolved_root.glob("packages/**/README.md"))

    return sorted(set(p.resolve() for p in targets))


def find_doc_hygiene_violations(files: list[Path], repo_root: Path) -> list[str]:
    """Scans the provided files for prohibited substrings and reports violations with file:line."""
    resolved_root = repo_root.resolve()
    violations: list[str] = []
    for file_path in files:
        resolved_file = file_path.resolve()
        try:
            rel_path = resolved_file.relative_to(resolved_root).as_posix()
        except ValueError:
            rel_path = resolved_file.as_posix()

        try:
            content = resolved_file.read_text(encoding="utf-8")
        except UnicodeDecodeError as err:
            violations.append(f"{rel_path}:1: failed to decode utf-8: {err}")
            continue

        for line_no, line in enumerate(content.splitlines(), start=1):
            for needle in PROHIBITED_SUBSTRINGS:
                if needle in line:
                    violations.append(
                        f"{rel_path}:{line_no}: found prohibited substring {needle!r}"
                    )
    return violations


def test_docs_hygiene():
    """Verify documentation files do not leak machine-specific paths or private URIs."""
    repo_root = Path(__file__).resolve().parents[1]
    targets = collect_target_doc_files(repo_root)

    assert targets, "No documentation files found to scan for hygiene"

    violations = find_doc_hygiene_violations(targets, repo_root)

    assert not violations, (
        f"Found {len(violations)} docs hygiene violation(s):\n"
        + "\n".join(violations)
    )


def test_docs_hygiene_refactor_v1(tmp_path: Path):
    """Verify documentation files on the refactor/v1 integration ref are also 100% clean."""
    repo_root = Path(__file__).resolve().parents[1]
    git_dir = repo_root / ".git"

    if not git_dir.exists() or not shutil.which("git"):
        pytest.skip("git repository not found in test environment")

    # Check whether refactor/v1 ref exists in local git
    check_ref = subprocess.run(
        ["git", "rev-parse", "--verify", "refactor/v1"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    if check_ref.returncode != 0:
        pytest.skip("refactor/v1 ref not present in local git repository")

    # Extract refactor/v1 doc trees into tmp_path without network
    archive_proc = subprocess.run(
        ["git", "archive", "refactor/v1"],
        cwd=repo_root,
        capture_output=True,
        check=True,
    )
    subprocess.run(
        ["tar", "-x", "-C", str(tmp_path)],
        input=archive_proc.stdout,
        check=True,
    )

    resolved_tmp = tmp_path.resolve()
    targets = collect_target_doc_files(resolved_tmp)
    assert targets, "No documentation files found in refactor/v1 archive"

    violations = find_doc_hygiene_violations(targets, resolved_tmp)
    assert not violations, (
        f"Found {len(violations)} docs hygiene violation(s) on refactor/v1:\n"
        + "\n".join(violations)
    )
