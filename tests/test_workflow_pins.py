"""Guard against workflow action references that GitHub cannot resolve."""

import re
from pathlib import Path

WORKFLOWS = Path(__file__).resolve().parents[1] / ".github" / "workflows"


def test_setup_uv_is_pinned_to_an_exact_release_tag():
    """astral-sh/setup-uv publishes no floating major tags after v7.

    `astral-sh/setup-uv@v10` fails every job at "Set up job" with "unable to find version v10",
    so it must be pinned to a full `vX.Y.Z` tag (Dependabot keeps it current).
    """
    refs = []
    for workflow in sorted(WORKFLOWS.glob("*.yml")):
        for number, line in enumerate(workflow.read_text().splitlines(), start=1):
            match = re.search(r"uses:\s*astral-sh/setup-uv@(\S+)", line)
            if match:
                refs.append((f"{workflow.name}:{number}", match.group(1)))
    assert refs, "expected at least one setup-uv usage"
    bad = [(where, ref) for where, ref in refs if not re.fullmatch(r"v\d+\.\d+\.\d+", ref)]
    assert not bad, f"setup-uv must use an exact vX.Y.Z tag: {bad}"
