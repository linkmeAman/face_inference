import re
from pathlib import Path

import pytest

REPO_ROOT = Path(__file__).resolve().parent.parent
SERVER_PY = REPO_ROOT / "src" / "face_inference" / "server.py"
OPENAPI_YAML = REPO_ROOT / "docs" / "openapi.yaml"


def extract_server_routes(server_code: str) -> set[tuple[str, str]]:
    """Extract dispatched API routes and methods from server.py Handler.route implementation."""
    routes: set[tuple[str, str]] = set()

    # 1. Exact path matches: self.command == '<METHOD>' and parts == ['<p1>', '<p2>', ...]
    exact_pattern = re.compile(
        r"self\.command == '([A-Z]+)'\s+and\s+parts == \[([^\]]+)\]"
    )
    for method, parts_str in exact_pattern.findall(server_code):
        parts = [p.strip().strip("'\"") for p in parts_str.split(",") if p.strip()]
        routes.add((method, "/" + "/".join(parts)))

    # 2. Check for collections route block: parts[:3] == ['api', 'v1', 'collections']
    collections_prefix_match = re.search(
        r"parts\[:3\] == \[([^\]]+)\]", server_code
    )
    if collections_prefix_match:
        prefix_parts = [
            p.strip().strip("'\"")
            for p in collections_prefix_match.group(1).split(",")
            if p.strip()
        ]
        base_path = "/" + "/".join(prefix_parts)

        # 2a. len(parts) == 3 -> root collections routes: GET and POST
        len3_match = re.search(
            r"if len\(parts\) == 3:(.*?)(?=if len\(parts\) >= 5|\Z)",
            server_code,
            re.DOTALL,
        )
        if len3_match:
            len3_block = len3_match.group(1)
            for method in re.findall(r"self\.command == '([A-Z]+)'", len3_block):
                routes.add((method, base_path))

        # 2b. len(parts) >= 5 -> subresources under collection
        subresource_match = re.search(
            r"^([ \t]*)if len\(parts\) >= 5:(.*?)(?=^\1raise FaceError|\Z)",
            server_code,
            re.DOTALL | re.MULTILINE,
        )
        if subresource_match:
            sub_block = subresource_match.group(2)

            # GET subresources with len(parts) == 5
            get_block_match = re.search(
                r"if self\.command == 'GET' and len\(parts\) == 5:(.*?)(?=actions\s*=|\Z)",
                sub_block,
                re.DOTALL,
            )
            if get_block_match:
                get_block = get_block_match.group(1)
                for resource in re.findall(r"resource == '([a-z]+)'", get_block):
                    routes.add(("GET", f"{base_path}/{{id}}/{resource}"))

            # POST subresources: actions = {...} and resource in actions
            actions_match = re.search(r"actions\s*=\s*\{([^}]+)\}", sub_block)
            if actions_match:
                actions_str = actions_match.group(1)
                action_resources = re.findall(r"'([a-z]+)':", actions_str)
                if "self.command == 'POST'" in sub_block:
                    for resource in action_resources:
                        routes.add(("POST", f"{base_path}/{{id}}/{resource}"))

            # DELETE subresources: DELETE len(parts) == 7, people, parts[6] == 'face'
            delete_match = re.search(r"if (?:\()?([^:]*self\.command == 'DELETE'[^:]*):", sub_block)
            if delete_match:
                condition = delete_match.group(1)
                resource = re.search(r"resource == '([a-z]+)'", condition)
                subresource = re.search(r"parts\[6\] == '([a-z]+)'", condition)
                if "len(parts) == 7" in condition and resource and subresource:
                    path = f"{base_path}/{{id}}/{resource.group(1)}/{{person}}"
                    path += f"/{subresource.group(1)}"
                    routes.add(("DELETE", path))

    return routes


def test_route_extractor_regex_unit():
    """Unit test for route extractor regex patterns on synthetic handler snippets."""
    snippet = """
    parts = path.strip('/').split('/')
    if self.command == 'GET' and parts == ['api', 'v1', 'config']:
        return
    if parts[:3] == ['api', 'v1', 'collections']:
        if len(parts) == 3:
            if self.command == 'GET':
                return
            if self.command == 'POST':
                return
        if len(parts) >= 5:
            if self.command == 'GET' and len(parts) == 5:
                if resource == 'people':
                    return
                if resource == 'events':
                    return
            actions = {'people': 'person', 'enrollments': 'enroll'}
            if self.command == 'POST' and len(parts) == 5 and resource in actions:
                return
            if (self.command == 'DELETE' and len(parts) == 7 and
                resource == 'people' and parts[6] == 'face'):
                return
    """
    extracted = extract_server_routes(snippet)
    assert ("GET", "/api/v1/config") in extracted
    assert ("GET", "/api/v1/collections") in extracted
    assert ("POST", "/api/v1/collections") in extracted
    assert ("GET", "/api/v1/collections/{id}/people") in extracted
    assert ("GET", "/api/v1/collections/{id}/events") in extracted
    assert ("POST", "/api/v1/collections/{id}/people") in extracted
    assert ("POST", "/api/v1/collections/{id}/enrollments") in extracted
    assert ("DELETE", "/api/v1/collections/{id}/people/{person}/face") in extracted



@pytest.mark.parametrize("condition", [
    "self.command == 'DELETE' and len(parts) == 7 and resource == 'people' and parts[6] == 'face'",
    "self.command == 'DELETE' and len(parts) == 7 and parts[6] == 'face' and resource == 'people'",
])
def test_route_extractor_delete_after_nested_raise(condition):
    snippet = f"""
    if parts[:3] == ['api', 'v1', 'collections']:
        if len(parts) >= 5:
            if invalid:
                raise FaceError('INVALID')
            if {condition}:
                return
    """
    assert ("DELETE", "/api/v1/collections/{id}/people/{person}/face") in (
        extract_server_routes(snippet)
    )


def test_server_routes_extracted():
    """Verify that route extractor discovers all 10 API operations from server.py."""
    assert SERVER_PY.is_file(), f"server.py not found at {SERVER_PY}"
    server_code = SERVER_PY.read_text(encoding="utf-8")
    server_routes = extract_server_routes(server_code)

    expected_routes = {
        ("POST", "/api/v1/session-tokens"),
        ("GET", "/api/v1/config"),
        ("GET", "/api/v1/collections"),
        ("POST", "/api/v1/collections"),
        ("GET", "/api/v1/collections/{id}/people"),
        ("POST", "/api/v1/collections/{id}/people"),
        ("POST", "/api/v1/collections/{id}/enrollments"),
        ("POST", "/api/v1/collections/{id}/recognitions"),
        ("POST", "/api/v1/collections/{id}/observations"),
        ("DELETE", "/api/v1/collections/{id}/people/{person}/face"),
        ("GET", "/api/v1/collections/{id}/events"),
    }
    diff_missing = expected_routes - server_routes
    diff_extra = server_routes - expected_routes
    assert server_routes == expected_routes, (
        f"Route mismatch:\nMissing: {diff_missing}\nUnexpected: {diff_extra}"
    )


def test_openapi_file_exists():
    """Verify that docs/openapi.yaml exists."""
    assert OPENAPI_YAML.is_file(), f"Missing OpenAPI specification at {OPENAPI_YAML}"


def _normalize_path(path: str) -> str:
    """Normalize path parameter names to {id} and {person} for route comparison."""
    # Replace {id} or {collection_id} with {id}
    p = re.sub(r"\{collection(_id)?\}", "{id}", path)
    # Replace {person} or {person_id} with {person}
    p = re.sub(r"\{person(_id)?\}", "{person}", p)
    return p


def test_openapi_routes_match_server():
    """Parse openapi.yaml with yaml and assert every server route appears in spec and vice versa."""
    yaml = pytest.importorskip("yaml")
    assert OPENAPI_YAML.is_file(), f"Missing OpenAPI specification at {OPENAPI_YAML}"

    spec = yaml.safe_load(OPENAPI_YAML.read_text(encoding="utf-8"))
    assert isinstance(spec, dict), "OpenAPI spec must be a dictionary"
    assert "paths" in spec, "OpenAPI spec must have 'paths'"

    http_methods = {"get", "post", "put", "delete", "patch", "options", "head"}
    spec_routes: set[tuple[str, str]] = set()
    for path, path_item in spec["paths"].items():
        if not isinstance(path_item, dict):
            continue
        norm_path = _normalize_path(path)
        for method, op in path_item.items():
            if method.lower() in http_methods and isinstance(op, dict):
                spec_routes.add((method.upper(), norm_path))

    server_code = SERVER_PY.read_text(encoding="utf-8")
    server_routes = extract_server_routes(server_code)

    assert spec_routes == server_routes, (
        f"Route coverage mismatch between server.py and openapi.yaml:\n"
        f"In server.py but missing in spec: {server_routes - spec_routes}\n"
        f"In spec but not in server.py: {spec_routes - server_routes}"
    )


def test_openapi_operations_have_source_citations():
    """Verify that every operation has an x-source field citing an existing file and line."""
    yaml = pytest.importorskip("yaml")
    assert OPENAPI_YAML.is_file(), f"Missing OpenAPI specification at {OPENAPI_YAML}"

    spec = yaml.safe_load(OPENAPI_YAML.read_text(encoding="utf-8"))
    for path, path_item in spec.get("paths", {}).items():
        if not isinstance(path_item, dict):
            continue
        for method, op in path_item.items():
            if method.lower() in {"get", "post", "delete"} and isinstance(op, dict):
                assert "x-source" in op, f"Missing x-source on {method.upper()} {path}"
                source = op["x-source"]
                assert ":" in source, f"Invalid x-source format (expected file:line): {source}"
                file_rel, line_str = source.split(":", 1)
                target_file = REPO_ROOT / file_rel
                assert target_file.is_file(), f"Referenced source does not exist: {target_file}"
                line_num = int(line_str)
                lines = target_file.read_text(encoding="utf-8").splitlines()
                assert 1 <= line_num <= len(lines), (
                    f"Line {line_num} out of bounds in {target_file}"
                )


def test_openapi_security_scheme():
    """Verify that OpenAPI spec configures HTTP Bearer authentication."""
    yaml = pytest.importorskip("yaml")
    assert OPENAPI_YAML.is_file(), f"Missing OpenAPI specification at {OPENAPI_YAML}"

    spec = yaml.safe_load(OPENAPI_YAML.read_text(encoding="utf-8"))
    components = spec.get("components", {})
    sec_schemes = components.get("securitySchemes", {})
    assert "BearerAuth" in sec_schemes, "Missing BearerAuth in components/securitySchemes"
    bearer = sec_schemes["BearerAuth"]
    assert bearer.get("type") == "http", "BearerAuth type must be 'http'"
    assert bearer.get("scheme") == "bearer", "BearerAuth scheme must be 'bearer'"


def test_openapi_health_and_static_not_in_paths():
    """Verify /health and static asset paths are only in description, not in paths."""
    yaml = pytest.importorskip("yaml")
    assert OPENAPI_YAML.is_file(), f"Missing OpenAPI specification at {OPENAPI_YAML}"

    spec = yaml.safe_load(OPENAPI_YAML.read_text(encoding="utf-8"))
    paths = spec.get("paths", {})
    assert "/health" not in paths, "/health must not be an operation under paths"
    for p in paths:
        assert not p.endswith(".mjs"), f"Static file {p} must not be in paths"
        assert not p.endswith(".css"), f"Static file {p} must not be in paths"
        assert not p.startswith("/browser-face"), f"Static directory {p} must not be in paths"

    description = spec.get("info", {}).get("description", "")
    assert "/health" in description, "info.description must mention /health"
    assert "browser-face" in description or "static" in description, (
        "info.description must mention static asset paths"
    )


def test_openapi_error_envelope_and_status_codes():
    """Verify error envelope schema matches server respond() structure and 401/403/413/429 cases."""
    yaml = pytest.importorskip("yaml")
    assert OPENAPI_YAML.is_file(), f"Missing OpenAPI specification at {OPENAPI_YAML}"

    spec = yaml.safe_load(OPENAPI_YAML.read_text(encoding="utf-8"))
    schemas = spec.get("components", {}).get("schemas", {})
    assert "ErrorEnvelope" in schemas, "components/schemas must include ErrorEnvelope"

    error_schema = schemas["ErrorEnvelope"]
    props = error_schema.get("properties", {})
    for expected_field in ("error", "error_msg", "message", "code", "data"):
        assert expected_field in props, f"ErrorEnvelope missing required property: {expected_field}"

    # Check 401, 403, 413, 429 status codes are documented in responses or components
    spec_yaml_str = OPENAPI_YAML.read_text(encoding="utf-8")
    for status_code in ("401", "403", "413", "429"):
        code_patterns = (
            f"'{status_code}'",
            f'"{status_code}"',
            f"\n        {status_code}:",
            f"\n      {status_code}:",
        )
        assert any(pat in spec_yaml_str for pat in code_patterns), (
            f"Expected status code {status_code} to be documented in OpenAPI spec"
        )
