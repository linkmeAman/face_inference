# Multi-stage Dockerfile for face-inference

# Stage 1: Build the wheel
FROM python:3.12-slim AS builder

WORKDIR /build

RUN pip install --no-cache-dir hatchling

COPY . /build/

RUN pip wheel --no-build-isolation --no-deps --wheel-dir /dist .

# Stage 2: Final runtime container
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    FACE_INFERENCE_HOME=/data \
    FACE_INFERENCE_HOST=0.0.0.0

# Create non-root system group and user with uid 10001
RUN groupadd -g 10001 faceapp && \
    useradd -u 10001 -g 10001 -s /usr/sbin/nologin -d /data faceapp && \
    mkdir -p /data/models && \
    chown -R 10001:10001 /data

# Copy and install the built wheel from builder stage
COPY --from=builder /dist/*.whl /tmp/
RUN pip install --no-cache-dir /tmp/*.whl && \
    rm -rf /tmp/*.whl

# Entrypoint script to handle model fetching before serving
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    'fetch_models() {' \
    '  if face-inference models verify >/dev/null 2>&1; then return 0; fi' \
    '  if [ -n "$FACE_INFERENCE_MODELS_FROM_DIR" ]; then' \
    '    face-inference models fetch --from-dir "$FACE_INFERENCE_MODELS_FROM_DIR"' \
    '  else' \
    '    face-inference models fetch' \
    '  fi' \
    '}' \
    'if [ "$#" -eq 0 ]; then' \
    '  fetch_models' \
    '  exec face-inference serve' \
    'fi' \
    'case "$1" in' \
    '  init|check|token|purge|export|models)' \
    '    exec face-inference "$@"' \
    '    ;;' \
    '  serve)' \
    '    shift' \
    '    fetch_models' \
    '    exec face-inference serve "$@"' \
    '    ;;' \
    '  -*)' \
    '    exec face-inference "$@"' \
    '    ;;' \
    '  *)' \
    '    exec "$@"' \
    '    ;;' \
    'esac' > /usr/local/bin/docker-entrypoint.sh && \
    chmod 0755 /usr/local/bin/docker-entrypoint.sh

USER 10001:10001
WORKDIR /data
VOLUME /data
EXPOSE 6122

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:6122/health', timeout=4)"

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["serve"]
