# Operational logging

Dispatched GET, POST, DELETE and OPTIONS requests produce a JSON message on the
`face-standalone` Python logger. Set `FACE_INFERENCE_LOG_LEVEL=INFO` or run
`face-inference serve --log-level INFO` to collect all records on stderr. The
flag takes precedence over the environment; the default is `WARNING`, and server
errors are emitted at `ERROR`. Levels `DEBUG`, `INFO`, `WARNING` and `ERROR` are
case-insensitive. Embedding applications can configure the logger themselves.

For Docker Compose, add the environment variable to the `face` service in
`compose.yaml`, then start the service and follow its logs:

```yaml
environment:
  - FACE_INFERENCE_LOG_LEVEL=INFO
```

```sh
docker compose up -d
docker compose logs -f face
```

Each message has only these fields:

- `request_id`: a new server-generated 32-character hexadecimal correlation ID.
- `outcome`: `success`, `client_error`, `server_error`, or `disconnected`.
- `status`: the HTTP response status, or null if no status was sent.
- `duration_ms`: elapsed monotonic milliseconds, capped at 3,600,000.

The ID is not taken from the request or returned in a response header. Records
contain no URL, request headers, body, personal or collection identifiers, token,
biometric data, or exception text. Default HTTP access logging remains disabled.
Requests rejected before dispatch, including server overload and HTTP parsing
errors, do not produce these records.
