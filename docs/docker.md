# Docker Deployment Guide for `face-inference`

This guide explains how to build, run, configure, and maintain `face-inference` in containerized environments using Docker and Docker Compose.

---

## 1. Overview & Image Architecture

The `face-inference` container image is built using a multi-stage `python:3.12-slim` base:

- **Stage 1 (Builder):** Installs `hatchling` and packages the project into a wheel using `pip wheel`.
- **Stage 2 (Runtime):** Installs the generated wheel and runtime dependencies (`cryptography`), configures a non-root system user (`faceapp`, UID `10001`), creates a persistent `/data` volume mount point, and configures healthchecks.
- **Security:** Runs as non-root user UID `10001` with minimal filesystem privileges.
- **Host Binding:** Sets `ENV FACE_INFERENCE_HOST=0.0.0.0` inside the container so the HTTP server listens on all container network interfaces.
- **Port:** Exposes private listener port `6122`.
- **Models:** Model weights and ONNX runtime WASM assets are stored under `/data/models`. On startup, `serve` automatically runs `face-inference models fetch` to download and verify required model assets into `/data/models` (or verify pre-mounted assets).

---

## 2. Building the Image

Build the container image using Docker:

```bash
docker build -t face-inference:latest .
```

---

## 3. Running with Docker

Run the container with a named volume for persistent storage and bind the port to loopback (`127.0.0.1`) by default:

```bash
docker run -d \
  --name face-inference \
  -p 127.0.0.1:6122:6122 \
  -v face-data:/data \
  face-inference:latest
```

### Checking Status and Health

Check container health status:

```bash
docker inspect --format='{{json .State.Health}}' face-inference
```

Run status check inside the container:

```bash
docker run --rm -v face-data:/data face-inference:latest check
```

Retrieve the API bearer token:

```bash
docker run --rm -v face-data:/data face-inference:latest token
```

---

## 4. Running with Docker Compose

A pre-configured `compose.yaml` is provided for running `face-inference`:

```bash
# Start service in background
docker compose up -d

# View service logs
docker compose logs -f face

# Stop service
docker compose down
```

### Network and Host Binding

By default, `compose.yaml` binds port `6122` to `127.0.0.1:6122:6122` on the host to adhere to the project's privacy-by-default standard. The container sets `ENV FACE_INFERENCE_HOST=0.0.0.0` so traffic forwarded from the host loopback is accepted by the server.

### Allowed Origins Configuration

You can configure CORS allowed origins via the environment variable `FACE_INFERENCE_ALLOWED_ORIGINS`:

```bash
FACE_INFERENCE_ALLOWED_ORIGINS="http://localhost:3000,https://app.example.com" docker compose up -d
```

---

## 5. Offline / Air-Gapped Deployment

In air-gapped or restricted network environments where the container cannot reach GitHub Releases to download model weights, you can provide the 6 required assets (`ORT-LICENSE`, `ort-wasm-simd-threaded.mjs`, `ort-wasm-simd-threaded.wasm`, `ort.wasm.min.mjs`, `sface.onnx`, `yunet.onnx`) offline using either of two methods:

### Method A: Host Directory Mount

Mount a directory from the host containing the 6 model files directly into the container's `/data/models` directory:

```bash
docker run -d \
  --name face-inference \
  -p 127.0.0.1:6122:6122 \
  -v face-data:/data \
  -v /path/to/host/models:/data/models:ro \
  face-inference:latest
```

When the container launches `serve`, the entrypoint runs `face-inference models fetch`. Because all required files are already present and match their recorded SHA-256 checksums, `fetch` succeeds idempotently without making any outbound network requests.

### Method B: Pre-Seeded Container Image Build

You can build a self-contained offline container image by copying model assets from `src/face_inference/web/vendor/browser-face` (or any local directory) into a build-time directory, and fetching them locally using `--from-dir`:

```dockerfile
# Example Offline Dockerfile
FROM face-inference:latest

USER root
COPY src/face_inference/web/vendor/browser-face /opt/models-seed
RUN mkdir -p /data/models && \
    face-inference models fetch --from-dir /opt/models-seed && \
    rm -rf /opt/models-seed && \
    chown -R 10001:10001 /data
USER 10001:10001
```

Alternatively, pass `FACE_INFERENCE_MODELS_FROM_DIR` at runtime if you mount a directory at a custom path:

```bash
docker run -d \
  -p 127.0.0.1:6122:6122 \
  -v face-data:/data \
  -v /path/to/host/models:/seed:ro \
  -e FACE_INFERENCE_MODELS_FROM_DIR=/seed \
  face-inference:latest
```

---

## 6. HTTPS Reverse Proxy (Camera Access Requirement)

Modern web browsers enforce strict security policies on media devices: **camera access (`navigator.mediaDevices.getUserMedia`) is restricted to secure contexts (`https://`) or `localhost`/`127.0.0.1`**.

If hosting `face-inference` on a remote server or domain name, you must terminate TLS in front of the container. `compose.yaml` includes a commented Caddy reverse-proxy service block:

```yaml
# caddy:
#   image: caddy:2-alpine
#   restart: unless-stopped
#   ports:
#     - "80:80"
#     - "443:443"
#   volumes:
#     - ./Caddyfile:/etc/caddy/Caddyfile:ro
#     - caddy-data:/data
#     - caddy-config:/config
#   depends_on:
#     - face
```

An example `Caddyfile`:

```caddy
face.example.com {
    reverse_proxy face:6122
}
```

---

## 7. Data Volume & Critical Key-Loss Warning

All persistent runtime data is stored in the volume mounted at `/data` (`ENV FACE_INFERENCE_HOME=/data`), including:

- `/data/config.json`: Configuration file containing `api_token` and `encryption_key`.
- `/data/faces.sqlite3`: SQLite database holding enrolled face metadata and encrypted feature embeddings.
- `/data/models/`: Model assets (YuNet, SFace, ONNX Runtime WASM).

> [!CAUTION]
> **CRITICAL KEY-LOSS WARNING:**
> All facial template embeddings in `faces.sqlite3` are encrypted using Fernet with the symmetric key stored in `/data/config.json` (`encryption_key`).
>
> If `/data/config.json` is lost, deleted, or replaced, **all existing enrolled face templates become permanently undecryptable and irrecoverable**.
> Always backup `/data/config.json` together with `/data/faces.sqlite3`. Never delete the persistent volume when upgrading containers.

### Backing Up Data Volume

To perform a backup of the volume data:

```bash
docker run --rm \
  -v face-data:/data:ro \
  -v "$(pwd)":/backup \
  alpine tar czf /backup/face-data-backup-$(date +%Y%m%d).tar.gz -C /data .
```

### Restoring Data Volume

To restore data from an existing backup archive into `face-data`:

```bash
docker run --rm \
  -v face-data:/data \
  -v "$(pwd)":/backup \
  alpine sh -c "tar xzf /backup/face-data-backup-*.tar.gz -C /data && chown -R 10001:10001 /data"
```
