# Examples

Runnable examples for the `face_inference` toolkit.

---

## Index

| Example | Type / Target | Description | File |
|---|---|---|---|
| [Python Quickstart](python/quickstart.py) | Python 3 (stdlib) | Connects to server, checks health, creates collection, adds person, lists people, and deletes face data. | [`examples/python/quickstart.py`](python/quickstart.py) |
| [Web Component Embed](web/index.html) | Vanilla JS / HTML5 | Demonstrates `<face-capture>` custom element with camera feed, token configuration, and live event log. | [`examples/web/index.html`](web/index.html) |
| [React Integration](web/react.md) | React Component | Compact snippet (<25 lines) showing how to bind `ref` and listen to custom events in React. | [`examples/web/react.md`](web/react.md) |
| [Vue 3 Integration](web/vue.md) | Vue 3 SFC | Compact snippet (<25 lines) showing how to embed `<face-capture>` in Vue 3 `<script setup>`. | [`examples/web/vue.md`](web/vue.md) |

---

## Running the Python Quickstart

The Python client (`face_inference.client.FaceClient`) is zero-dependency (Python 3.10+ standard library).

### 1. Start the server

```bash
export FACE_HOME=$(mktemp -d)
PYTHONPATH=src FACE_INFERENCE_HOME=$FACE_HOME python3 -m face_inference
```

### 2. Fetch the API token

```bash
export FACE_TOKEN=$(PYTHONPATH=src FACE_INFERENCE_HOME=$FACE_HOME python3 -m face_inference token)
export FACE_URL="http://127.0.0.1:6122"
```

### 3. Run the quickstart script

```bash
python3 examples/python/quickstart.py
```

Actual execution output:

```
Connecting to face_inference service at http://127.0.0.1:6122...

1. Checking service health (GET /health)...
   Status: ok
   Storage: sqlite
   API Version: 1

2. Creating collection 'demo-1795280311' (POST /api/v1/collections)...
   Collection created: id=demo-1795280311, state=CREATED

3. Adding person 'alice' (POST /api/v1/collections/demo-1795280311/people)...
   Person added: id=alice, name=Alice Smith, state=CREATED

4. Listing people in collection 'demo-1795280311'...
   Found 1 person(s):
   - alice: Alice Smith (state: NOT_ENROLLED)

5. Deleting face data for person 'alice'...
   Result: state=DELETED, person_id=alice

✓ Quickstart completed successfully!
```

---

## Running the Web Embed

The web component (`<face-capture>`) runs client-side inference using YuNet + SFace compiled to WebAssembly.

```bash
# 1. Build the web component package
cd packages/face-capture && npm run build && cd ../..

# 2. Serve the repository
python3 -m http.server 8000 --bind 127.0.0.1
```

Open [http://127.0.0.1:8000/examples/web/index.html](http://127.0.0.1:8000/examples/web/index.html) in your browser.

> [!WARNING]
> **Security Notice**:
> The UI includes a token input labelled **"dev only — use session tokens in production"**.
> Long-lived operator API tokens should never be embedded in public client code. Scoped, short-lived session-token minting lands in upcoming task **A3**.
