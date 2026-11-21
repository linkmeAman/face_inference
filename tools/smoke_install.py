#!/usr/bin/env python3
"""Clean-machine install smoke test for face-inference.

Builds the distribution wheel, installs it into a pristine temporary virtual environment
without repo pollution or PYTHONPATH, verifies CLI commands and model asset workflows,
boots a local server subprocess, exercises the typed FaceClient from the installed venv,
and confirms graceful server shutdown and port release.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path


def free_port(host: str = "127.0.0.1") -> int:
    """Find and return an available local TCP port."""
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind((host, 0))
        return int(s.getsockname()[1])


def is_port_released(port: int, host: str = "127.0.0.1", timeout: float = 5.0) -> bool:
    """Check if the given port is released and available for binding."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                s.bind((host, port))
                return True
        except OSError:
            time.sleep(0.1)
    return False


def venv_bin_dir(venv_dir: Path, platform: str | None = None) -> Path:
    """Return the executable scripts/bin directory for a virtual environment."""
    plat = platform if platform is not None else sys.platform
    return venv_dir / "Scripts" if plat == "win32" else venv_dir / "bin"


def venv_python(venv_dir: Path, platform: str | None = None) -> Path:
    """Return the python executable path inside a virtual environment."""
    plat = platform if platform is not None else sys.platform
    bin_dir = venv_bin_dir(venv_dir, platform=plat)
    if plat == "win32":
        candidate = bin_dir / "python.exe"
        return candidate if candidate.is_file() else bin_dir / "python.exe"
    return bin_dir / "python"


def venv_executable(venv_dir: Path, name: str, platform: str | None = None) -> Path:
    """Resolve an executable script inside the virtual environment."""
    plat = platform if platform is not None else sys.platform
    bin_dir = venv_bin_dir(venv_dir, platform=plat)
    if plat == "win32":
        for ext in (".exe", ".cmd", ".bat", ""):
            cand = bin_dir / f"{name}{ext}"
            if cand.is_file():
                return cand
        return bin_dir / f"{name}.exe"
    return bin_dir / name


def build_wheel(repo_root: Path, out_dir: Path) -> Path:
    """Build wheel into out_dir via uv build or python -m build fallback."""
    uv_bin = shutil.which("uv")
    wheel_built = False
    if uv_bin:
        cmd = [uv_bin, "build", "--wheel", "--out-dir", str(out_dir)]
        res = subprocess.run(cmd, cwd=repo_root, capture_output=True, text=True)
        if res.returncode == 0:
            wheel_built = True

    if not wheel_built:
        cmd = [sys.executable, "-m", "build", "--wheel", "--outdir", str(out_dir)]
        res = subprocess.run(cmd, cwd=repo_root, capture_output=True, text=True)
        if res.returncode != 0:
            raise RuntimeError(
                f"Failed to build wheel with uv and python -m build:\n{res.stderr.strip()}"
            )

    wheels = sorted(out_dir.glob("*.whl"))
    if not wheels:
        raise RuntimeError(f"No .whl file found in {out_dir}")
    return wheels[0]


def create_venv_and_install(
    venv_dir: Path,
    wheel_path: Path,
    empty_cwd: Path,
    env: dict[str, str],
) -> tuple[Path, Path]:
    """Create a pristine virtual environment and install only the wheel."""
    uv_bin = shutil.which("uv")
    venv_created = False
    if uv_bin:
        cmd = [uv_bin, "venv", "--python", sys.executable, str(venv_dir)]
        res = subprocess.run(cmd, cwd=empty_cwd, env=env, capture_output=True, text=True)
        if res.returncode == 0:
            venv_created = True

    if not venv_created:
        res = subprocess.run(
            [sys.executable, "-m", "venv", str(venv_dir)],
            cwd=empty_cwd,
            env=env,
            capture_output=True,
            text=True,
        )
        if res.returncode != 0:
            raise RuntimeError(f"Virtualenv creation failed:\n{res.stderr.strip()}")

    target_py = venv_python(venv_dir)
    if not target_py.is_file():
        raise RuntimeError(f"Virtualenv python executable not found: {target_py}")

    installed = False
    if uv_bin:
        cmd = [uv_bin, "pip", "install", "--python", str(target_py), str(wheel_path)]
        res = subprocess.run(cmd, cwd=empty_cwd, env=env, capture_output=True, text=True)
        if res.returncode == 0:
            installed = True

    if not installed:
        cmd = [str(target_py), "-m", "pip", "install", str(wheel_path)]
        res = subprocess.run(cmd, cwd=empty_cwd, env=env, capture_output=True, text=True)
        if res.returncode != 0:
            raise RuntimeError(f"Wheel installation via pip failed:\n{res.stderr.strip()}")

    face_bin = venv_executable(venv_dir, "face-inference")
    return target_py, face_bin


def run_smoke_test(keep: bool = False) -> int:
    """Execute all smoke test steps in sequence."""
    repo_root = Path(__file__).resolve().parents[1]
    repo_vendor_dir = repo_root / "src" / "face_inference" / "web" / "vendor" / "browser-face"

    temp_base = Path(tempfile.mkdtemp(prefix="face_smoke_install_"))
    wheel_dir = temp_base / "dist"
    venv_dir = temp_base / "venv"
    empty_cwd = temp_base / "cwd"
    home_dir = temp_base / "home"
    custom_models_dir = temp_base / "custom_models"
    server_log = temp_base / "server.log"

    wheel_dir.mkdir(parents=True, exist_ok=True)
    empty_cwd.mkdir(parents=True, exist_ok=True)
    home_dir.mkdir(parents=True, exist_ok=True)
    custom_models_dir.mkdir(parents=True, exist_ok=True)

    clean_env = os.environ.copy()
    clean_env.pop("PYTHONPATH", None)
    clean_env.pop("FACE_INFERENCE_HOME", None)
    clean_env.pop("FACE_INFERENCE_MODELS_DIR", None)

    server_proc: subprocess.Popen | None = None
    server_port: int | None = None

    try:
        # Step 1: Build wheel
        try:
            wheel_path = build_wheel(repo_root, wheel_dir)
            print(f"[PASS] Step 1: Built wheel {wheel_path.name}")
        except Exception as err:
            print(f"[FAIL] Step 1: Failed to build wheel: {err}", file=sys.stderr)
            return 1

        # Step 2: Create pristine venv and install wheel
        try:
            target_py, face_bin = create_venv_and_install(
                venv_dir, wheel_path, empty_cwd, clean_env
            )
            cli_base = (
                [str(face_bin)]
                if face_bin.is_file()
                else [str(target_py), "-m", "face_inference.cli"]
            )
            print(f"[PASS] Step 2: Created clean venv and installed {wheel_path.name}")
        except Exception as err:
            print(f"[FAIL] Step 2: Failed to create venv or install wheel: {err}", file=sys.stderr)
            return 1

        # Step 3: Run face-inference --help with FACE_INFERENCE_HOME in temp dir
        home_env = clean_env.copy()
        home_env["FACE_INFERENCE_HOME"] = str(home_dir)
        try:
            cmd = cli_base + ["--help"]
            res = subprocess.run(
                cmd,
                cwd=empty_cwd,
                env=home_env,
                capture_output=True,
                text=True,
            )
            if res.returncode != 0 or "face-inference" not in res.stdout:
                raise RuntimeError(f"code {res.returncode}:\n{res.stderr.strip()}")
            print("[PASS] Step 3: Ran face-inference --help with temp FACE_INFERENCE_HOME")
        except Exception as err:
            print(f"[FAIL] Step 3: face-inference --help failed: {err}", file=sys.stderr)
            return 1

        # Step 4: Verify models and check reporting mode
        try:
            res_verify = subprocess.run(
                cli_base + ["models", "verify"],
                cwd=empty_cwd,
                env=home_env,
                capture_output=True,
                text=True,
            )
            if res_verify.returncode != 0:
                raise RuntimeError(
                    f"models verify exited {res_verify.returncode}:\n{res_verify.stderr.strip()}"
                )
            verify_data = json.loads(res_verify.stdout)
            if verify_data.get("status") != "PASS":
                raise RuntimeError(f"models verify failed: {verify_data}")

            res_check = subprocess.run(
                cli_base + ["check"],
                cwd=empty_cwd,
                env=home_env,
                capture_output=True,
                text=True,
            )
            if res_check.returncode != 0:
                raise RuntimeError(
                    f"check exited {res_check.returncode}:\n{res_check.stderr.strip()}"
                )
            check_data = json.loads(res_check.stdout)
            mode = check_data.get("mode", "UNKNOWN")
            checked_count = verify_data.get("checked_assets", 0)
            print(
                f"[PASS] Step 4: Models state PASS ({checked_count} bundled assets verified, "
                f"mode: {mode})"
            )
        except Exception as err:
            print(f"[FAIL] Step 4: models verify or check failed: {err}", file=sys.stderr)
            return 1

        # Step 5: Fetch models into separate FACE_INFERENCE_MODELS_DIR and verify
        custom_env = home_env.copy()
        custom_env["FACE_INFERENCE_MODELS_DIR"] = str(custom_models_dir)
        try:
            res_fetch = subprocess.run(
                cli_base + ["models", "fetch", "--from-dir", str(repo_vendor_dir)],
                cwd=empty_cwd,
                env=custom_env,
                capture_output=True,
                text=True,
            )
            if res_fetch.returncode != 0:
                raise RuntimeError(
                    f"models fetch exited {res_fetch.returncode}:\n{res_fetch.stderr.strip()}"
                )

            res_verify_custom = subprocess.run(
                cli_base + ["models", "verify"],
                cwd=empty_cwd,
                env=custom_env,
                capture_output=True,
                text=True,
            )
            if res_verify_custom.returncode != 0:
                raise RuntimeError(
                    f"custom models verify exited {res_verify_custom.returncode}:\n"
                    f"{res_verify_custom.stderr.strip()}"
                )
            custom_data = json.loads(res_verify_custom.stdout)
            if custom_data.get("status") != "PASS":
                raise RuntimeError(f"custom models verify failed: {custom_data}")
            print(
                f"[PASS] Step 5: Fetched models into FACE_INFERENCE_MODELS_DIR and verified "
                f"({custom_data.get('checked_assets', 0)} assets)"
            )
        except Exception as err:
            print(f"[FAIL] Step 5: models fetch or verify failed: {err}", file=sys.stderr)
            return 1

        # Step 6: Start server subprocess and run client tests from venv python
        try:
            res_tok = subprocess.run(
                cli_base + ["token"],
                cwd=empty_cwd,
                env=home_env,
                capture_output=True,
                text=True,
            )
            if res_tok.returncode != 0:
                raise RuntimeError(f"token retrieval failed:\n{res_tok.stderr.strip()}")
            api_token = res_tok.stdout.strip()
            if not api_token:
                raise RuntimeError("API token is empty")

            server_port = free_port()
            log_file = open(server_log, "w", encoding="utf-8")
            server_proc = subprocess.Popen(
                cli_base + ["serve", "--port", str(server_port)],
                cwd=empty_cwd,
                env=home_env,
                stdout=log_file,
                stderr=subprocess.STDOUT,
            )

            # Wait for /health 200 (max 15 s)
            deadline = time.time() + 15.0
            base_url = f"http://127.0.0.1:{server_port}"
            healthy = False
            while time.time() < deadline:
                if server_proc.poll() is not None:
                    log_file.close()
                    log_text = server_log.read_text(encoding="utf-8", errors="replace")
                    raise RuntimeError(
                        f"Server exited prematurely with code {server_proc.returncode}:\n{log_text}"
                    )
                try:
                    req = urllib.request.Request(f"{base_url}/health")
                    with urllib.request.urlopen(req, timeout=1.0) as resp:
                        if resp.status == 200:
                            healthy = True
                            break
                except (urllib.error.URLError, OSError):
                    time.sleep(0.2)

            if not healthy:
                log_file.close()
                log_text = server_log.read_text(encoding="utf-8", errors="replace")
                raise RuntimeError(f"Server /health did not return 200 within 15s:\n{log_text}")

            client_code = """
import json
import sys
import urllib.request
from face_inference.client import FaceClient

base_url = sys.argv[1]
token = sys.argv[2]

client = FaceClient(base_url, token)

# 1. Create collection
col_id = "smoke_test_collection"
client.create_collection(col_id, "Smoke Test Collection")

# 2. Add person
person_id = "user_smoke_1"
client.add_person(col_id, person_id, "Smoke User")

# 3. List people
people = client.list_people(col_id)
if not any(p.get("id") == person_id for p in people):
    raise AssertionError(f"Person {person_id} not found in collection people: {people}")

# 4. Mint session token via POST /api/v1/session-tokens
# (Send exact allowed keys: collection_id, scope, person_id)
session_req = urllib.request.Request(
    f"{base_url}/api/v1/session-tokens",
    data=json.dumps({"collection_id": col_id, "scope": "enroll", "person_id": person_id}).encode(),
    headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
    method="POST",
)
with urllib.request.urlopen(session_req, timeout=5.0) as resp:
    if resp.status != 200:
        raise AssertionError(f"Session token request returned status {resp.status}")
    session_data = json.loads(resp.read().decode())
    session_token = session_data.get("data", {}).get("token")
    if not session_token:
        raise AssertionError(f"No token in session response: {session_data}")

# 5. Call GET /, GET /vendor/browser-face/yunet.onnx, GET /browser-face/capture.mjs
# and GET /vendor/browser-face/ort-wasm-simd-threaded.wasm (for wasm content-type)
checks = [
    ("/", "text/html"),
    ("/browser-face/capture.mjs", "javascript"),
    ("/vendor/browser-face/yunet.onnx", "application/octet-stream"),
    ("/vendor/browser-face/ort-wasm-simd-threaded.wasm", "application/wasm"),
]

for path, expected_content_type in checks:
    url = f"{base_url}{path}"
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, timeout=5.0) as resp:
        if resp.status != 200:
            raise AssertionError(f"GET {path} returned status {resp.status}")
        actual_type = resp.headers.get("Content-Type", "")
        if expected_content_type not in actual_type:
            raise AssertionError(
                f"GET {path} content-type {actual_type!r} "
                f"does not contain {expected_content_type!r}"
            )

print("CLIENT_PASS")
"""
            res_client = subprocess.run(
                [str(target_py), "-c", client_code, base_url, api_token],
                cwd=empty_cwd,
                env=home_env,
                capture_output=True,
                text=True,
            )
            if res_client.returncode != 0 or "CLIENT_PASS" not in res_client.stdout:
                log_file.close()
                log_text = server_log.read_text(encoding="utf-8", errors="replace")
                raise RuntimeError(
                    f"Venv client failed (code {res_client.returncode}):\n"
                    f"stdout: {res_client.stdout}\nstderr: {res_client.stderr}\n"
                    f"Server Log:\n{log_text}"
                )

            log_file.close()
            print(
                "[PASS] Step 6: Server started, health ok, FaceClient actions & GET routes verified"
            )
        except Exception as err:
            print(f"[FAIL] Step 6: Server or client verification failed: {err}", file=sys.stderr)
            return 1

        # Step 7: Stop server and verify port released
        try:
            if server_proc is not None:
                server_proc.terminate()
                try:
                    server_proc.wait(timeout=5.0)
                except subprocess.TimeoutExpired:
                    server_proc.kill()
                    server_proc.wait(timeout=5.0)

            if server_port is not None and not is_port_released(server_port, timeout=5.0):
                raise RuntimeError(f"Port {server_port} was not released after server shutdown")
            print(f"[PASS] Step 7: Server stopped and port {server_port} successfully released")
        except Exception as err:
            print(f"[FAIL] Step 7: Failed to stop server or release port: {err}", file=sys.stderr)
            return 1

        print("ALL SMOKE INSTALL STEPS PASSED")
        return 0

    finally:
        # Guarantee server process termination
        if server_proc is not None and server_proc.poll() is None:
            try:
                server_proc.terminate()
                server_proc.wait(timeout=2.0)
            except Exception:
                try:
                    server_proc.kill()
                except Exception:
                    pass

        if keep:
            print(f"Preserved temporary directory: {temp_base}")
        else:
            shutil.rmtree(temp_base, ignore_errors=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--keep",
        action="store_true",
        help="Keep temporary directory for debugging rather than cleaning up",
    )
    args = parser.parse_args()
    return run_smoke_test(keep=args.keep)


if __name__ == "__main__":
    sys.exit(main())
