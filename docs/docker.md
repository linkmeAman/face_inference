# Docker Deployment Guide for `face-inference`

This guide explains how to build, run, configure, and maintain `face-inference` in containerized environments using Docker and Docker Compose.

---

## 1. Overview & Image Architecture

The `face-inference` container image is built using a multi-stage `python:3.12-slim` base:

- **Stage 1 (Builder):** Installs `hatchling` and packages the project into a wheel using `pip wheel`.
- **Stage 2 (Runtime):** Installs the generated wheel and runtime dependencies (`cryptography`), configures a non-root system user (`faceapp`, UID `10001`), creates a persistent `/data` volume mount point, and configures healthchecks.
- **Security:** Runs as non-root user UID `10001` with minimal filesystem privileges.
- **Port:** Exposes private listener port `6122`.
- **Models:** Model weights are not baked into the image. On startup, the container entrypoint invokes `face-inference models fetch` to download and verify required model assets into `/data/models`.

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

By default, `compose.yaml` binds port `6122` to `127.0.0.1:6122:6122` on the host to adhere to the project's privacy-by-default standard.

> [!NOTE]
> Within the container, `face-inference` creates `/data/config.json` with `host: "127.0.0.1"` if none exists. If you expose the service outside the container or across a container network, ensure `config.json` has `"host": "0.0.0.0"`.

---

## 5. HTTPS Reverse Proxy (Camera Access Requirement)

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

## 6. Data Volume & Critical Key-Loss Warning

All persistent runtime data is stored in the volume mounted at `/data` (`ENV FACE_INFERENCE_HOME=/data`), including:

- `/data/config.json`: Configuration file containing `api_token` and `encryption_key`.
- `/data/faces.sqlite3`: SQLite database holding enrolled face metadata and encrypted feature embeddings.
- `/data/models/`: Downloaded model assets (YuNet, SFace, ONNX Runtime WASM).

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
