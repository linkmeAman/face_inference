# Local face capture demo

This demo uses the existing standalone app to enroll a consenting person and try recognition. Video and raw images stay in the browser; the service stores encrypted face embeddings. Results are **unverified evaluation suggestions**, not identity proof. The system has no liveness detection. See the [assessment](demo-assessment.md) before using results for decisions.

## Requirements and setup

You need a working webcam, a current browser, Python 3.10 or newer, and a local checkout of this repository. Run these commands from the repository root on macOS or Linux:

```sh
python3 -m venv .venv-demo
.venv-demo/bin/python -m pip install .
export FACE_INFERENCE_HOME="$(mktemp -d "${TMPDIR:-/tmp}/face-inference-demo.XXXXXX")"
printf 'Demo data directory: %s\n' "$FACE_INFERENCE_HOME"
.venv-demo/bin/face-inference models verify
.venv-demo/bin/face-inference token
.venv-demo/bin/face-inference serve
```

On Windows PowerShell, from the repository root:

```powershell
python -m venv .venv-demo
.\.venv-demo\Scripts\python.exe -m pip install .
$env:FACE_INFERENCE_HOME = Join-Path $env:TEMP ("face-inference-demo-" + [guid]::NewGuid())
New-Item -ItemType Directory -Path $env:FACE_INFERENCE_HOME
Write-Host "Demo data directory: $env:FACE_INFERENCE_HOME"
.\.venv-demo\Scripts\face-inference.exe models verify
.\.venv-demo\Scripts\face-inference.exe token
.\.venv-demo\Scripts\face-inference.exe serve
```

If port 6122 is already in use, stop the command and run `face-inference serve --port 6123` using the virtual-environment executable above; open `http://127.0.0.1:6123` in the steps below. Never paste the token into a service you have not started yourself.

Keep the printed directory path for cleanup. The token is a full administrative credential: do not share it or put it in a committed file. The commands use the same isolated directory for the token, key and database. `models verify` should show `PASS`; leave the server running. These PowerShell commands have not been manually verified.

## Enroll and recognize

1. Open [http://127.0.0.1:6122](http://127.0.0.1:6122), paste the token into **API token**, and select **Connect**. The page should show an evaluation-mode notice.
2. Under **Create a collection**, create ID `demo` and name `Demo`. Add a person with an ID and display name.
3. Explain the purpose and obtain that person's consent. Check the consent box, click **Enroll** beside the person, allow camera access, and follow the on-screen positioning instructions. Successful evaluation enrollment reports **Face saved for evaluation. Approval is pending.** and the person shows `PENDING`.
4. Select **Start recognition**, then position the same consenting person in view. A match is displayed as **Evaluation suggestion: [name] (unverified)**. A failure to match is possible; a suggestion is not proof of identity.
5. Optionally try an unenrolled consenting person. An unknown face can still be incorrectly suggested as a match, so this is a demonstration, not an accuracy measurement. Stop the camera when finished.

## Troubleshooting and cleanup

- **No page or connection:** confirm the server is still running on port 6122 and use the token printed in the same terminal and data directory. See [common problems](common-problems.md).
- **Camera denied or missing:** allow browser and operating-system camera permission, then retry; close other apps using the camera. Use the local HTTP address above, not `file://`.
- **Enrollment blocked:** select the collection, add the person, and confirm consent before clicking **Enroll**. Follow the on-screen guidance for lighting, positioning and one face at a time.
- **No recognition suggestion:** ensure enrollment reached `PENDING`, select the same collection, and retry under different lighting or positioning. A non-match does not necessarily indicate a software failure.
- Stop the server with Ctrl+C. In your file manager, delete **only the printed demo data directory** after confirming its path. This permanently removes its database, token and encryption key; do not delete a directory containing data you want to keep. Closing the browser alone does not delete enrolled templates. See [deletion](deletion.md) for deletion limitations and backups.

For an embedded component rather than the standalone app, see [the web example](../examples/web/README.md).
