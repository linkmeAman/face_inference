# Common Problems and Solutions

A quick-reference guide for resolving common errors when installing, running, or embedding `face_inference`.

---

## Quick Reference Table

| Symptom | Plain-English Cause | Solution Link |
|---|---|---|
| `command not found: python3` or `python` | Python is not installed or not in your system PATH | [Command not found: python](#command-not-found-python) |
| `command not found: git` | Git command-line tool is not installed | [Command not found: git](#command-not-found-git) |
| `command not found: face-inference` | Package was not installed or command run without environment path | [Command not found: face-inference](#command-not-found-face-inference) |
| Python version is older than 3.10 | Installed Python version lacks required modern runtime features | [Python too old (< 3.10)](#python-too-old) |
| `pip install .` fails | Missing build tool or outdated pip packaging utility | [pip install fails](#pip-install-fails) |
| `OSError: [Errno 48] Address already in use` | Another instance or application is already using port 6122 | [Port already in use](#port-already-in-use) |
| Web browser says "Unable to connect" | The background service is not running or running on a different port | [The page will not load](#page-will-not-load) |
| `HTTP 401: A valid API token is required` | Wrong or missing token passed in request or web UI input | [Invalid token (HTTP 401)](#invalid-token-or-401) |
| `Allow camera access, then retry` | Browser camera permission denied or blocked by security policy | [Camera blocked or permission denied](#camera-blocked-or-permission-denied) |
| Camera preview shows black box | Webcam is in use by another application or hardware stream frozen | [Black camera screen](#black-camera-screen) |
| Models missing / verification fails | Onnx or WASM model weights were accidentally removed | [Models missing or verify failure](#models-missing-or-run-face-inference-models-fetch) |
| `HTTP 403: Use an allowed browser origin` or `Use the same-origin face service` | Calling web page origin is not in the server's allowlist | [Origin rejected (HTTP 403)](#origin-rejected-403) |
| `HTTP 429: Too many requests; wait a minute` | Rate limit exceeded (more than 120 requests in 60 seconds) | [Too many requests (HTTP 429)](#too-many-requests-429) |
| Forgot or misplaced administrative token | Token string was not saved when service initialized | [Forgot API token](#forgot-api-token) |
| Lost or deleted `config.json` | Master encryption key removed; existing templates unreadable | [Lost config.json](#lost-config-json) |
| `sqlite3.OperationalError: database is locked` | Another concurrent write or locked database connection | [Database is locked](#database-is-locked) |
| Need detailed terminal logging | Default logging only shows startup message | [How to see detailed logs](#how-to-see-detailed-logs) |

---

## Detailed Symptoms and Fixes

### Command not found: python
<a id="command-not-found-python"></a>

**Real terminal message:**
```text
zsh: command not found: python3
```
*(Windows PowerShell: `The term 'python' is not recognized as the name of a cmdlet`)*

**Plain-English cause:**  
Your operating system does not know where to find Python because it is either not installed or its directory was not added to your system's PATH environment variable.

**Exact fix:**  
1. Download and install Python 3.10 or newer from the official site: https://www.python.org/downloads/
2. On Windows, make sure you check the box **"Add python.exe to PATH"** at the very beginning of the installer.
3. Open a brand-new terminal window and verify:
   ```bash
   python3 --version
   ```
   *(Windows PowerShell: `python --version`)*  
   You should see a version string like `Python 3.12.x` (numbers may differ).

---

### Command not found: git
<a id="command-not-found-git"></a>

**Real terminal message:**
```text
zsh: command not found: git
```

**Plain-English cause:**  
Git is not installed on your system.

**Exact fix:**  
- **macOS:** Run `xcode-select --install` in your terminal, or download from https://git-scm.com/downloads/mac.
- **Windows:** Download and run the installer from https://git-scm.com/downloads/win.
- **Linux (Ubuntu/Debian):** Run `sudo apt update && sudo apt install -y git`.

---

### Command not found: face-inference
<a id="command-not-found-face-inference"></a>

**Real terminal message:**
```text
zsh: command not found: face-inference
```

**Plain-English cause:**  
The virtual environment where `face-inference` was installed is not in your current PATH, or `pip install .` was not yet run.

**Exact fix:**  
Run the executable directly from your project's `.venv` directory instead of typing `face-inference` alone:
- **macOS/Linux:**
  ```bash
  .venv/bin/face-inference --help
  ```
- **Windows PowerShell:**
  ```powershell
  .\.venv\Scripts\face-inference.exe --help
  ```

---

### Python too old (< 3.10)
<a id="python-too-old"></a>

**Real terminal message:**
```text
Python 3.9.7
ERROR: Package 'face-inference' requires a different Python: 3.9.7 not in '>=3.10'
```
*(Version numbers may differ.)*

**Plain-English cause:**  
`face_inference` uses modern Python features introduced in Python 3.10 (such as union types and standard library timezone improvements). Older versions of Python cannot run it.

**Exact fix:**  
1. Check your active Python version:
   ```bash
   python3 -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}')"
   ```
2. If the output starts with `3.9`, `3.8`, or lower, download a current version (Python 3.10 through 3.12 recommended) from https://www.python.org/downloads/.
3. Remove the existing `.venv` folder and recreate it with the updated Python:
   ```bash
   rm -rf .venv
   python3 -m venv .venv
   .venv/bin/python -m pip install .
   ```

---

### pip install fails
<a id="pip-install-fails"></a>

**Real terminal message:**
```text
error: subprocess-exited-with-error
Building wheel for cryptography (pyproject.toml) did not run successfully.
```

**Plain-English cause:**  
Your virtual environment's `pip` tool may be outdated, causing it to attempt compiling dependencies from source rather than downloading pre-built binary wheels.

**Exact fix:**  
1. Upgrade `pip` inside the virtual environment:
   ```bash
   .venv/bin/python -m pip install --upgrade pip
   ```
2. Re-run the installation:
   ```bash
   .venv/bin/python -m pip install .
   ```

---

### Port already in use
<a id="port-already-in-use"></a>

**Real terminal message:**
```text
OSError: [Errno 48] Address already in use
```
*(Windows: `OSError: [Errno 10048] Only one usage of each socket address (protocol/network address/port) is normally permitted`)*

**Plain-English cause:**  
Another program or a previously started `face-inference` process is already listening on port 6122.

**Exact fix:**  
You can either stop the other process or instruct `face-inference` to listen on an alternate port:
```bash
.venv/bin/face-inference serve --port 6188
```
*(Windows PowerShell: `.\.venv\Scripts\face-inference.exe serve --port 6188`)*

Then open `http://127.0.0.1:6188` in your browser.

---

### The page will not load
<a id="page-will-not-load"></a>

**Real browser message:**
```text
This site can’t be reached
127.0.0.1 refused to connect.
ERR_CONNECTION_REFUSED
```

**Plain-English cause:**  
The background service is not running, or was started on a different port than the one you entered in your browser's address bar.

**Exact fix:**  
1. Check the terminal where you started the server. It should display:
   ```text
   Face service: http://127.0.0.1:6122
   Use python -m face_inference token in your terminal to get the private API token.
   ```
2. If the terminal returned to a shell prompt or closed, start it again:
   ```bash
   .venv/bin/face-inference serve
   ```
3. Open the exact URL: `http://127.0.0.1:6122` (do not use `https://`).

---

### Invalid token (HTTP 401)
<a id="invalid-token-or-401"></a>

**Real server response:**
```json
HTTP/1.0 401 Unauthorized
{"error": 1, "error_msg": "A valid API token is required", "message": "A valid API token is required", "code": "AUTH_REQUIRED", "data": {}}
```

**Plain-English cause:**  
The token entered in the browser UI or in an API request does not match the token saved in your private `config.json` file.

**Exact fix:**  
1. In your terminal, print your real active token:
   ```bash
   .venv/bin/face-inference token
   ```
2. Copy the full 43-character token string output by that command.
3. In the web interface, paste it into the **API token** field (placeholder *Private token from your terminal*) and click **Connect**.

---

### Camera blocked or permission denied
<a id="camera-blocked-or-permission-denied"></a>

**Real UI error message:**
```text
Allow camera access, then retry
```

**Plain-English cause:**  
The browser asked for camera permission and it was dismissed or blocked, or system privacy settings prevent the browser from accessing the webcam. Note that modern browsers only allow camera access on `http://localhost`, `http://127.0.0.1`, or secure `https://` websites.

**Exact fix:**  
1. Look at the browser address bar: click the camera or padlock icon on the left of the URL.
2. Change the camera permission setting from "Block" to "Allow".
3. On macOS: open **System Settings -> Privacy & Security -> Camera**, and ensure your web browser is toggled ON.
4. Refresh the web page and click **Enroll** again.

---

### Black camera screen
<a id="black-camera-screen"></a>

**Real symptom:**  
The video box appears on screen, but stays pitch black without showing any image.

**Plain-English cause:**  
Another application (like Zoom, Teams, Meet, or FaceTime) has exclusive hardware control of the webcam, or your physical camera privacy shutter is closed.

**Exact fix:**  
1. Check your physical webcam for a closed privacy shutter or slider.
2. Completely quit other applications that might be using the camera.
3. If you have multiple cameras (such as an external webcam and a laptop built-in camera), click the **Switch camera** button in the web interface.

---

### Models missing or verify failure
<a id="models-missing-or-run-face-inference-models-fetch"></a>

**Real symptom:**  
Running `.venv/bin/face-inference models verify` reports missing files:
```json
{
  "status": "FAIL",
  "checked_assets": 6,
  "failures": [
    {"path": "sface.onnx", "reason": "MISSING_OR_SYMLINK"}
  ]
}
```

**Plain-English cause:**  
The neural network weights (`sface.onnx`, `yunet.onnx`) or WebAssembly binaries were deleted, incompletely downloaded, or modified. Normally, all 6 model assets are already pre-bundled inside the downloaded repository clone and inside the installed package under `src/face_inference/web/browser-face` and `src/face_inference/web/vendor/browser-face`, so a beginner never needs to download them separately.

**The fix that works today:**  
If files were accidentally deleted in your local clone, restore them directly from your git repository history and re-install the package:
- **macOS / Linux:**
  ```bash
  git checkout -- src/face_inference/web/vendor
  .venv/bin/python -m pip install .
  .venv/bin/face-inference models verify
  ```
- **Windows PowerShell:**
  ```powershell
  git checkout -- src/face_inference/web/vendor
  .\.venv\Scripts\python.exe -m pip install .
  .\.venv\Scripts\face-inference.exe models verify
  ```

If your local clone is corrupted beyond recovery, simply delete the folder and clone a fresh copy from GitHub:
```bash
git clone https://github.com/linkmeAman/face_inference.git
```

> [!NOTE]
> *`face-inference models fetch` is the automated remote download command; it will become active after the official `models-v1` GitHub release is published by the project maintainers.*

---

### Origin rejected (HTTP 403)
<a id="origin-rejected-403"></a>

Depending on how the request was sent, the server returns one of two distinct messages:

**1. During browser preflight checks (OPTIONS request):**  
```json
HTTP/1.0 403 Forbidden
{"error": 1, "error_msg": "Use an allowed browser origin", "message": "Use an allowed browser origin", "code": "ORIGIN_REJECTED", "data": {}}
```
*Where you see this:* In your web browser's developer tools console (press F12 -> Console / Network tab) when a web page hosted on another origin (such as `http://127.0.0.1:8000`) tries to connect to the API on port 6122. The browser automatically sends an HTTP `OPTIONS` preflight request before making the actual request.

**2. During direct API calls (GET or POST request):**  
```json
HTTP/1.0 403 Forbidden
{"error": 1, "error_msg": "Use the same-origin face service", "message": "Use the same-origin face service", "code": "ORIGIN_REJECTED", "data": {}}
```
*Where you see this:* When testing requests using `curl` or HTTP clients with a custom `Origin` header that does not match an allowed origin or the loopback server address.

**Plain-English cause:**  
You embedded the face capture component into a web page hosted on a custom port or domain (such as `http://127.0.0.1:8000`), but that address was not allowlisted in the server's `FACE_INFERENCE_ALLOWED_ORIGINS` configuration.

**Exact fix:**  
Start the server with the `FACE_INFERENCE_ALLOWED_ORIGINS` environment variable set to your web page's origin:
- **macOS / Linux:**
  ```bash
  export FACE_INFERENCE_ALLOWED_ORIGINS="http://127.0.0.1:8000"
  .venv/bin/face-inference serve
  ```
- **Windows PowerShell:**
  ```powershell
  $env:FACE_INFERENCE_ALLOWED_ORIGINS = "http://127.0.0.1:8000"
  .\.venv\Scripts\face-inference.exe serve
  ```

---

### Too many requests (HTTP 429)
<a id="too-many-requests-429"></a>

**Real server response:**
```json
HTTP/1.0 429 Too Many Requests
{"error": 1, "error_msg": "Too many requests; wait a minute", "message": "Too many requests; wait a minute", "code": "RATE_LIMIT", "data": {}}
```

**Plain-English cause:**  
The server includes an automated rate limiter that allows up to 120 requests per minute from each client IP. When exceeded, further requests are temporarily paused for safety.

**Exact fix:**  
Wait 60 seconds for the rate limit bucket to refill. If you are running high-volume automated tests, increase the rate limit using `FACE_INFERENCE_RATE_LIMIT`:
```bash
export FACE_INFERENCE_RATE_LIMIT=1000
.venv/bin/face-inference serve
```

---

### Forgot API token
<a id="forgot-api-token"></a>

**Plain-English cause:**  
You closed the terminal or did not record the token generated during startup.

**Exact fix:**  
Run the token command in your project directory at any time:
```bash
.venv/bin/face-inference token
```
*(Windows PowerShell: `.\.venv\Scripts\face-inference.exe token`)*

This reads the existing token directly from your `config.json` without modifying any data.

---

### Lost config.json
<a id="lost-config-json"></a>

**Plain-English cause:**  
The `config.json` file in your data directory was deleted or moved.

**Important warning:**  
`config.json` contains the master cryptographic key used to encrypt templates in `faces.sqlite3`. If this key is lost, **existing stored faces cannot be decrypted**. Running `face-inference token` will generate a brand new key, but previous records will remain permanently inaccessible.

**Exact fix:**  
1. Restore `config.json` from your latest backup.
2. If no backup exists, find your data directory by running:
   ```bash
   .venv/bin/face-inference check
   ```
   *(Windows PowerShell: `.\.venv\Scripts\face-inference.exe check`)*  
   Look at the `"database"` path in the output JSON.
3. Remove the old encrypted database files:
   - **macOS:** `rm -f "$HOME/Library/Application Support/face-inference/faces.sqlite3"*`
   - **Linux:** `rm -f "${XDG_DATA_HOME:-$HOME/.local/share}/face-inference/faces.sqlite3"*`
   - **Windows PowerShell:** `Remove-Item "$env:APPDATA\face-inference\faces.sqlite3*"`
4. Run `.venv/bin/face-inference token` to create a fresh configuration and start fresh enrollments.

---

### Database is locked
<a id="database-is-locked"></a>

**Real error message:**
```text
sqlite3.OperationalError: database is locked
```

**Plain-English cause:**  
Another process holds an open write transaction on `faces.sqlite3`, or the file system does not support SQLite file locking (such as certain network-mounted shares).

**Exact fix:**  
1. Ensure only one `face-inference serve` instance is running against your data directory.
2. If a background process did not shut down cleanly, find and terminate it:
   ```bash
   pkill -f "face-inference serve"
   ```
3. Keep your data directory on a local hard drive (SSD/HDD) rather than a network folder (NFS/SMB).

---

### How to see detailed logs
<a id="how-to-see-detailed-logs"></a>

**Plain-English explanation:**  
By default, the server runs quietly and only logs when an error occurs. When troubleshooting network or API issues, enabling request logging prints every call and status code in real time.

**Exact fix:**  
Start the server with `--log-level INFO`:
```bash
.venv/bin/face-inference serve --log-level INFO
```

**Real output example:**  
When requests arrive, you will see structured JSON lines:
```json
{"request_id": "e0c7ddd7c5674d008558bf1921078f2f", "outcome": "success", "status": 200, "duration_ms": 0}
```
*(Numbers and identifiers will differ.)*  
If a request fails with an error code (such as 401 or 403), the line will display the exact status and duration.
