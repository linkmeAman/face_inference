# Production Deployment Guide

This guide covers deploying the `face-inference` service in production environments.

The server uses a standard-library Python HTTP server (`ThreadingHTTPServer`) backed by an encrypted SQLite database. By default, it binds to loopback interface `127.0.0.1:6122`.

---

## 1. TLS and Reverse Proxy (Required)

### Why TLS is Mandatory
Modern web browsers require a **Secure Context** (`https://` or `localhost` / `127.0.0.1`) to access user media devices via `navigator.mediaDevices.getUserMedia()`. If your application runs on any remote hostname or non-local IP address without HTTPS, the browser will block camera access entirely.

In production:
- Terminate TLS at a reverse proxy (such as Caddy or Nginx).
- Reverse proxy requests to the private loopback listener `127.0.0.1:6122`.
- **WebSockets are NOT needed**: The service is strictly standard HTTP/1.1 REST endpoints and static assets.
- Ensure the proxy passes the `X-Forwarded-For`, `Host`, and `X-Forwarded-Proto` headers.

> [!NOTE]
> **Trusted-proxy support**: Client IP resolution for rate limiting is handled by `face_inference.ratelimit.client_ip`. The server traverses the `X-Forwarded-For` header right-to-left, validating each proxy against `trusted_proxies` (or the comma-separated `FACE_INFERENCE_TRUSTED_PROXIES` environment override) and extracting the real untrusted client IP.

### Caddy Configuration

Caddy provides automatic HTTPS certificate provisioning and renewal:

```caddyfile
face.example.com {
    reverse_proxy 127.0.0.1:6122 {
        header_up Host {host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto https
    }
}
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name face.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name face.example.com;

    ssl_certificate /etc/letsencrypt/live/face.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/face.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    client_max_body_size 1m;

    location / {
        proxy_pass http://127.0.0.1:6122;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

---

## 2. Dedicated Service User and Directories

Run the service under a dedicated, unprivileged system user. Never run the service as `root`.

### Linux (systemd)

1. Create a dedicated system user:
   ```bash
   sudo useradd --system --no-create-home --shell /usr/sbin/nologin face-inference
   ```

2. Create the data directory and lock down file permissions:
   ```bash
   sudo mkdir -p /var/lib/face-inference
   sudo chown face-inference:face-inference /var/lib/face-inference
   sudo chmod 0700 /var/lib/face-inference
   ```

3. Create the systemd service file at `/etc/systemd/system/face-inference.service`:
   ```ini
   [Unit]
   Description=Face Inference Server
   After=network.target

   [Service]
   Type=simple
   User=face-inference
   Group=face-inference
   WorkingDirectory=/var/lib/face-inference
   Environment="FACE_INFERENCE_HOME=/var/lib/face-inference"
   ExecStart=/opt/face-inference/.venv/bin/face-inference serve
   Restart=on-failure
   RestartSec=5s

   # Hardening
   ProtectSystem=strict
   ProtectHome=true
   ReadWritePaths=/var/lib/face-inference
   PrivateTmp=true
   NoNewPrivileges=true

   [Install]
   WantedBy=multi-user.target
   ```

4. Enable and start the service:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now face-inference.service
   sudo systemctl status face-inference.service
   ```

### macOS (launchd)

1. Create a dedicated service user or run under an isolated unprivileged user.
2. Ensure directory permissions:
   ```bash
   sudo mkdir -p /var/lib/face-inference
   sudo chown _faceinfer:daemon /var/lib/face-inference
   sudo chmod 0700 /var/lib/face-inference
   ```

3. Create the property list file at `/Library/LaunchDaemons/com.faceinference.server.plist`:
   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
   <plist version="1.0">
   <dict>
       <key>Label</key>
       <string>com.faceinference.server</string>
       <key>UserName</key>
       <string>_faceinfer</string>
       <key>ProgramArguments</key>
       <array>
           <string>/opt/face-inference/.venv/bin/face-inference</string>
           <string>serve</string>
       </array>
       <key>WorkingDirectory</key>
       <string>/var/lib/face-inference</string>
       <key>EnvironmentVariables</key>
       <dict>
           <key>FACE_INFERENCE_HOME</key>
           <string>/var/lib/face-inference</string>
       </dict>
       <key>RunAtLoad</key>
       <true/>
       <key>KeepAlive</key>
       <true/>
       <key>StandardOutPath</key>
       <string>/var/log/face-inference.log</string>
       <key>StandardErrorPath</key>
       <string>/var/log/face-inference.err</string>
   </dict>
   </plist>
   ```

4. Load the service:
   ```bash
   sudo launchctl load /Library/LaunchDaemons/com.faceinference.server.plist
   ```

---

## 3. Configuration and Environment Variables

Configuration is loaded from `config.json` inside `FACE_INFERENCE_HOME` (or specified by `--config`).
Without that override, an existing `./.face-inference/` directory is reused with
one legacy-location notice on stderr. Otherwise, the default is
`~/Library/Application Support/face-inference` on macOS,
`$XDG_DATA_HOME/face-inference` (fallback `~/.local/share/face-inference`) on Linux,
or `%APPDATA%\face-inference` (fallback `~\AppData\Roaming\face-inference`) on Windows.
New directories use mode `0700` on POSIX. `init` and `check` report the resolved
directory as `home` in their JSON output. Model downloads follow `<home>/models`
unless `FACE_INFERENCE_MODELS_DIR` is set; verified packaged assets remain a fallback.

### Cross-Origin Embedding (`allowed_origins`)

When embedding the `<face-capture>` custom element on a different origin (e.g., your app is hosted at `https://portal.example.com` while the face service runs at `https://face.example.com`), you must add the exact calling origin to `allowed_origins` in `config.json`:

```json
{
  "version": 1,
  "host": "127.0.0.1",
  "port": 6122,
  "allowed_origins": [
    "https://portal.example.com"
  ]
}
```

Rules for `allowed_origins` (see `docs/guide.md`):
- Must be exact HTTPS origins (`https://domain[:port]`).
- Trailing slashes, wildcard domains (`*`), HTTP schemes (except localhost), paths, or query fragments are rejected at startup.

### Environment Variables

| Variable | Description |
|---|---|
| `FACE_INFERENCE_HOME` | Override the data directory described above. Holds `config.json` and `faces.sqlite3`. |
| `FACE_STANDALONE_CONFIG` | Absolute path to `config.json` override. |
| `FACE_INFERENCE_HOST` *(available after G2)* | Override listen host (e.g. `127.0.0.1`). |
| `FACE_INFERENCE_PORT` *(available after G2)* | Override listen port (e.g. `6122`). |
| `FACE_INFERENCE_ALLOWED_ORIGINS` *(available after G2)* | Comma-delimited list of exact HTTPS origins. |

---

## 4. Backup and Recovery

> [!CAUTION]
> **CRITICAL: BACK UP `faces.sqlite3` AND `config.json` TOGETHER!**
> - `faces.sqlite3` contains the encrypted face vectors and audit journals.
> - `config.json` contains the private Fernet `encryption_key`.
> 
> If `config.json` or its `encryption_key` is lost, all records in `faces.sqlite3` become permanently unreadable and unrecoverable. Restoring a database without its matching original key will fail startup verification.

### Backup Procedure

Perform consistent, atomic SQLite snapshots alongside the config file:

```bash
BACKUP_DIR="/backup/face-inference-$(date +%Y%m%d%H%M%S)"
mkdir -p "$BACKUP_DIR"
chmod 0700 "$BACKUP_DIR"

# 1. Atomic SQLite online backup
sqlite3 /var/lib/face-inference/faces.sqlite3 ".backup '$BACKUP_DIR/faces.sqlite3'"

# 2. Copy matching configuration and encryption key
cp -p /var/lib/face-inference/config.json "$BACKUP_DIR/config.json"

chmod 0600 "$BACKUP_DIR"/*
```

---

## 5. Upgrades and Maintenance

### Upgrading the Service

1. Stop the active daemon:
   ```bash
   sudo systemctl stop face-inference
   ```

2. Run a full backup of `config.json` and `faces.sqlite3` (see above).

3. Update the package / environment:
   ```bash
   cd /opt/face-inference
   uv pip install --upgrade .
   ```

4. Verify integrity of models, runtime assets, configuration, and schema:
   ```bash
   FACE_INFERENCE_HOME=/var/lib/face-inference /opt/face-inference/.venv/bin/face-inference check
   ```

5. Restart the daemon:
   ```bash
   sudo systemctl start face-inference
   ```

6. Confirm health check responds with 200 OK:
   ```bash
   curl -f http://127.0.0.1:6122/health
   ```

### Data Retention Purge

The server automatically expires unverified observations past `retention_days` (default 30 days). To manually trigger a purge run:

```bash
FACE_INFERENCE_HOME=/var/lib/face-inference /opt/face-inference/.venv/bin/face-inference purge
```
