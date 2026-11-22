# Start Here: Beginner's Guide to face_inference

A complete, beginner-friendly walkthrough to install, run, enroll a face, recognize it, back up your data, and embed face recognition on your own web page in under 10 minutes.

---

## 0. What This Is and What You Need

`face_inference` is a fast, privacy-focused toolkit that lets you enroll and recognize faces directly in your web browser. Face detection and feature extraction run entirely inside your browser using WebAssembly, so camera video and raw facial photographs never leave your computer. In the next 10 minutes, you will install the service, open the local dashboard, enroll a face with your webcam, and verify recognition.

### What you need
- A computer (macOS, Linux, or Windows) with a working webcam.
- **Python 3.10 or newer**: Check by opening a terminal and running:
  ```bash
  python3 --version
  ```
  *(Windows PowerShell: `python --version`)*  
  If the output is older than 3.10 or command is not found, install modern Python from https://www.python.org/downloads/.
- **Git**: Check by running:
  ```bash
  git --version
  ```
  If Git is missing, download it from https://git-scm.com/downloads.

> [!CAUTION]
> ### Before You Use This on Real People
> - **Always get consent:** You must inform people and obtain their unambiguous consent before capturing, computing, or storing facial measurements.
> - **Biometric data is sensitive:** Facial templates are sensitive personal information subject to biometric privacy laws (such as GDPR, CCPA, and BIPA).
> - **Not a security lock:** This software is **NOT** a physical access control, door lock, or anti-spoofing system. It does **not** provide liveness detection and cannot distinguish a real human face from a printed photograph or phone screen replay.
> - **Review the privacy policy:** See [../PRIVACY.md](../PRIVACY.md) for details on encrypted storage, data retention, and permanent deletion.
> - *Notice: This document provides technical instructions and does not constitute legal advice.*

- **You should see:** Real version strings like `Python 3.12.x` and `git version 2.x.x` printed in your terminal.
- **If you do not see that:** see [docs/common-problems.md#python-too-old](common-problems.md#python-too-old) or [docs/common-problems.md#command-not-found-git](common-problems.md#command-not-found-git).

---

## 1. Download

Clone the repository to your computer and navigate into the project directory:

- **macOS / Linux:**
  ```bash
  git clone https://github.com/linkmeAman/face_inference.git
  cd face_inference
  ```

- **Windows PowerShell:**
  ```powershell
  git clone https://github.com/linkmeAman/face_inference.git
  cd face_inference
  ```

*(Windows commands follow the same steps; they are tested in automated checks but were not run by hand by the author.)*

> [!NOTE]
> The `main` branch contains the verified release codebase, while the `refactor/v1` branch contains the latest modular architecture integrations.

- **You should see:**
  ```text
  Cloning into 'face_inference'...
  remote: Enumerating objects: ...
  Receiving objects: 100% ...
  done.
  ```
- **If you do not see that:** see [docs/common-problems.md#command-not-found-git](common-problems.md#command-not-found-git).

---

## 2. Install

> [!NOTE]
> **After the first release:** Once the package is officially published to PyPI, you will be able to install it using `pip install face-inference` or run it instantly with `uvx face-inference`. Until that release is published, installing from your local git clone is the primary path.
> 
> **Docker Alternative:** If you prefer running through Docker, run `docker compose up -d`. Labelled honestly: *Not yet verified on a live Docker by the maintainers*. See [docs/docker.md](docker.md).

Create an isolated virtual environment (`.venv`) and install the package:

- **macOS / Linux:**
  ```bash
  python3 -m venv .venv
  .venv/bin/python -m pip install .
  ```

- **Windows PowerShell:**
  ```powershell
  python -m venv .venv
  .\.venv\Scripts\python.exe -m pip install .
  ```

> [!TIP]
> Calling `.venv/bin/face-inference` directly executes using the virtual environment's dedicated Python binary and packages without requiring you to run `source` or change shell environment settings.

- **You should see:**
  ```text
  Successfully built face-inference
  Installing collected packages: tzdata, pycparser, cffi, cryptography, face-inference
  Successfully installed cffi-2.1.1 cryptography-50.0.2 face-inference-0.1.0 pycparser-3.12 tzdata-2026.6
  ```
- **If you do not see that:** see [docs/common-problems.md#pip-install-fails](common-problems.md#pip-install-fails).

---

## 3. First Start and the Access Token

An access token is a private 43-character cryptographic secret that authenticates all administrative operations, configuration changes, and web UI sessions.

Verify the pre-bundled model weights and print your private administrative token:

- **macOS / Linux:**
  ```bash
  .venv/bin/face-inference models verify
  .venv/bin/face-inference token
  ```

- **Windows PowerShell:**
  ```powershell
  .\.venv\Scripts\face-inference.exe models verify
  .\.venv\Scripts\face-inference.exe token
  ```

Where is it stored? The token and your master encryption key are stored in `config.json` inside your private data folder (restricted to file mode `0600`). Never share your token or commit it to public version control; anyone who possesses it has full control over your stored face templates.

- **You should see:**
  ```json
  {
    "status": "PASS",
    "checked_assets": 6,
    "failures": []
  }
  ```
  Followed by a random 43-character token string:
  ```text
  gygnp-ez1I3b4wZkJdEoZI2s9sb55Fp7iUBx0hPf6zU
  ```
- **If you do not see that:** see [docs/common-problems.md#models-missing-or-run-face-inference-models-fetch](common-problems.md#models-missing-or-run-face-inference-models-fetch).

---

## 4. Open the Page in Your Browser

Start the server process in your terminal:

- **macOS / Linux:**
  ```bash
  .venv/bin/face-inference serve
  ```

- **Windows PowerShell:**
  ```powershell
  .\.venv\Scripts\face-inference.exe serve
  ```

Now connect from your browser:
1. Open your web browser and navigate to: **http://127.0.0.1:6122**
2. In the connection bar at the top, paste the 43-character token you printed in Step 3 into the field labeled **Enter your private API token**.
3. Click the **Connect** button.

- **You should see:** Terminal output:
  ```text
  Face service: http://127.0.0.1:6122
  Use python -m face_inference token in your terminal to get the private API token.
  ```
  In your browser, the connection indicator turns green, showing status `Connected`, and the Collection selector unlocks.
- **If you do not see that:** see [docs/common-problems.md#invalid-token-or-401](common-problems.md#invalid-token-or-401) or [docs/common-problems.md#page-will-not-load](common-problems.md#page-will-not-load).

---

## 5. Make a Collection and Add a Person

A collection is a named folder or group of people (such as a team, department, or event roster) whose faces you want to organize and match together.

1. Click **Create a collection** to expand the creation panel.
2. Enter:
   - **Collection ID:** `team-one`
   - **Name:** `Team One`
3. Click the **Create collection** button.
4. Under the **People** section, add your first person:
   - **Person ID:** `person-001`
   - **Name:** `Alice Smith`
5. Click **Add / update person**.
6. Check the mandatory consent box:
   `[x] I have consent to capture and store the selected person’s face for this purpose.`  
   *What this means:* You verify that the person being enrolled was informed and explicitly agreed to have their biometric measurements recorded in this collection.

- **You should see:** `team-one` selected in the Collection dropdown, and a new row in the People table with name `Alice Smith`, ID `person-001`, status `Not enrolled`, and an enabled **Enroll** button.
- **If you do not see that:** see [docs/common-problems.md#database-is-locked](common-problems.md#database-is-locked).

---

## 6. Enroll

Click the **Enroll** button next to Alice Smith in the People table:

1. **What the camera does:** Your browser will display a permission prompt asking to access your camera. Click **Allow**. A video preview panel opens displaying your live webcam feed with a circular alignment guide and a progress bar.
2. **What to do with your face:** Look directly at the webcam, keep your face centered inside the circle, ensure your room is reasonably lit, and hold still for 2–3 seconds while frames are collected.
3. **What each message on screen means:**
   - `Starting camera`: Webcam hardware is powering on.
   - `Position a face inside the circle.`: The model is scanning the frame for a single detected face.
   - `Capturing...`: Face detected with good quality and lighting; collecting biometric frames.
   - `Hold steady`: Slight motion blur detected; keep your head still.
   - `Move closer`: Face bounding box is too small in the camera view.
   - `Improve lighting and try again.`: Scene is underexposed or overexposed.
   - `Only one face at a time`: More than one person is visible in frame.
   - `Face enrolled.`: Success! The mathematical template has been encrypted and saved.

- **You should see:** The camera panel closes and the table updates to display Face status `Enrolled` next to Alice Smith with a green confirmation message: `Face enrolled.`
- **If you do not see that:** see [docs/common-problems.md#camera-blocked-or-permission-denied](common-problems.md#camera-blocked-or-permission-denied) or [docs/common-problems.md#black-camera-screen](common-problems.md#black-camera-screen).

---

## 7. Recognize

Now test recognizing the enrolled face:

1. Click the **Start recognition** button at the top of the People panel.
2. Look at the camera.
3. **What the messages mean:**
   - `Ready to scan`: Camera is active and waiting for a subject to appear.
   - `Recognizing...`: A face is detected and its feature vector is being computed.
   - `Evaluation suggestion: Alice Smith (unverified)`: The system matched the face to Alice Smith in evaluation mode.
   - `Matched: Alice Smith`: Confirmed match when operating in approved mode.
   - `Face not recognized`: No enrolled face in the collection matched above the recognition confidence threshold.

> [!NOTE]
> **Why are results suggestions in evaluation mode?**  
> The service starts in evaluation mode by default so operators can test accuracy, observe confidence scores, and verify thresholds before connecting automated actions (like doors or check-ins).

- **You should see:** The screen highlights the match with: `Evaluation suggestion: Alice Smith (unverified)` or `Matched: Alice Smith`.
- **If you do not see that:** see [docs/common-problems.md#camera-blocked-or-permission-denied](common-problems.md#camera-blocked-or-permission-denied).

---

## 8. Stop and Restart

1. **How to stop the service:** Switch to the terminal window where `face-inference serve` is running and press `Ctrl+C`.
2. **Restart:** Run `.venv/bin/face-inference serve` (PowerShell: `.\.venv\Scripts\face-inference.exe serve`).
3. **Where did the data go?** All collections, enrolled face templates, and configuration are persisted in SQLite on your disk. When you reload the page and connect, Alice Smith will still be listed as `Enrolled`.

- **You should see:** The server terminates cleanly on `Ctrl+C` and restarts immediately without losing any enrolled faces.
- **If you do not see that:** see [docs/common-problems.md#port-already-in-use](common-problems.md#port-already-in-use).

---

## 9. Where Is My Data and How to Back It Up

By default, data is stored in your user profile:
- **macOS / Linux:** `~/.face-inference/` (or the folder specified by `FACE_INFERENCE_HOME`)
- **Windows:** `%LOCALAPPDATA%\face-inference`

Inside this directory are two critical files:
1. `faces.sqlite3`: The database containing encrypted biometric templates and audit logs.
2. `config.json`: Contains your API token and the master `encryption_key`.

### Creating a backup snapshot
Run the built-in backup command:

- **macOS / Linux:**
  ```bash
  .venv/bin/face-inference backup --output my-backup.sqlite3
  ```

- **Windows PowerShell:**
  ```powershell
  .\.venv\Scripts\face-inference.exe backup --output my-backup.sqlite3
  ```

> [!CAUTION]
> **Lose config.json = lose all enrolled faces.**  
> Face templates in `faces.sqlite3` are strongly encrypted with the key in `config.json`. A backup of the SQLite database without its matching `config.json` is completely unreadable ciphertext and cannot be recovered. Always back up both files and store them securely.

- **You should see:** A clean copy `my-backup.sqlite3` created on disk with restricted read permissions (`0600`).
- **If you do not see that:** see [docs/common-problems.md#lost-config-json](common-problems.md#lost-config-json).

---

## 10. Start Automatically or Run on Another Computer

- **Background service on Linux / macOS:** Set up systemd or launchd by following the production deployment guide in [docs/deployment.md](deployment.md).
- **Run in Docker:** Containerize the service and isolate data with Docker volumes by following [docs/docker.md](docker.md).

- **You should see:** Architectural guidance and unit service files in [docs/deployment.md](deployment.md).
- **If you do not see that:** see [docs/common-problems.md#page-will-not-load](common-problems.md#page-will-not-load).

---

## 11. Put It on Your Own Web Page in 5 Minutes

You can embed face recognition on any web page using the `<face-capture>` custom HTML element and a temporary session token.

### Step-by-step embed walkthrough:

1. **Build the component bundle** (if running from a source checkout):
   ```bash
   node packages/face-capture/scripts/sync.mjs
   ```

2. **Allow your web page's origin on the server:**
   Set `FACE_INFERENCE_ALLOWED_ORIGINS` to the address of your web server (e.g. `http://127.0.0.1:8000`), then start the service:
   ```bash
   export FACE_INFERENCE_ALLOWED_ORIGINS="http://127.0.0.1:8000"
   .venv/bin/face-inference serve --port 6122
   ```

3. **Mint a short-lived session token:**
   In another terminal, retrieve your admin token and request a restricted 5-minute session token for `team-one`:
   ```bash
   ADMIN_TOKEN=$(.venv/bin/face-inference token)
   curl -s -X POST http://127.0.0.1:6122/api/v1/session-tokens \
     -H "Authorization: Bearer $ADMIN_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"collection_id":"team-one","scope":"recognize","ttl":300}'
   ```
   Copy the `token` string returned in the `data` object.

4. **Create `embed.html`:**
   Paste this complete HTML file into a new file:

   ```html
   <!DOCTYPE html>
   <html lang="en">
   <head>
     <meta charset="utf-8">
     <title>Face Recognition Embed</title>
     <!-- Import the web component module -->
     <script type="module" src="packages/face-capture/dist/face-capture.js"></script>
     <style>
       body { font-family: sans-serif; display: flex; flex-direction: column; align-items: center; padding: 40px; }
       h1 { font-size: 20px; }
       #result-box { margin-top: 16px; font-weight: bold; }
     </style>
   </head>
   <body>
     <h1>Face Recognition Embed</h1>
     
     <!-- Embed the custom element with your session token -->
     <face-capture
       api-url="http://127.0.0.1:6122"
       token="PASTE_YOUR_SESSION_TOKEN_HERE"
       collection="team-one"
       mode="recognize">
     </face-capture>

     <div id="result-box">Waiting for face scan...</div>

     <script>
       const fc = document.querySelector('face-capture');
       const resultBox = document.getElementById('result-box');

       fc.addEventListener('face-result', (event) => {
         const result = event.detail;
         resultBox.textContent = result.state === 'MATCHED'
           ? 'Matched: ' + result.person_name
           : result.state === 'SUGGESTED'
             ? 'Suggestion: ' + result.person_name
             : 'Result: ' + result.state;
       });

       fc.addEventListener('face-error', (event) => {
         resultBox.textContent = 'Error: ' + event.detail.message;
       });
     </script>
   </body>
   </html>
   ```

5. **Serve and test the page:**
   Start a simple web server in your project root:
   ```bash
   python3 -m http.server 8000
   ```
   Open **http://127.0.0.1:8000/embed.html** in your browser.

- **You should see:** The `<face-capture>` element mounts with camera preview controls and communicates with your local service using the session token.
- **If you do not see that:** see [docs/common-problems.md#origin-rejected-403](common-problems.md#origin-rejected-403).

---

## 12. What Next

Explore detailed architectural specifications, configuration references, and security policies:
- **Configuration & Integration Guide:** [docs/guide.md](guide.md)
- **REST API OpenAPI Specification:** [docs/openapi.yaml](openapi.yaml)
- **Privacy Policy & Subject Rights:** [../PRIVACY.md](../PRIVACY.md)
- **Security Policy & Threat Model:** [../SECURITY.md](../SECURITY.md)
- **Production Deployment:** [docs/deployment.md](deployment.md)
- **Advanced Troubleshooting:** [docs/troubleshooting.md](troubleshooting.md)
- **Glossary of Terms:** [docs/glossary.md](glossary.md)
