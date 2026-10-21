# Disposable face-attendance test environment

This environment is isolated from the application database. It creates only
`ccc_database_test` and `ccc_central_test` in a named MySQL test volume and
keeps all attendance writes in the disposable test database. It is shadow-only:
live writes and punch-out are forced off in the example configuration.

## Run

Docker and the PHP/Python runtimes must be available on the host:

```sh
cp tests/face-test-env/.env.example tests/face-test-env/.env
tests/face-test-env/run.sh up
tests/face-test-env/run.sh test
tests/face-test-env/run.sh down
```

`test` runs the full inference suite plus the PHP scan/enrollment/duplicate
contracts with `--required`. A missing or inaccessible environment is a hard
failure, not a false pass.

Use `FACE_TEST_ENV_FILE=/path/to/private.env` for different local credentials.
The test scripts only accept database names beginning with the approved
`ccc_database_test` and `ccc_central_test` namespaces. The `destroy` command
requires `FACE_TEST_ENV_ALLOW_DESTROY=1` and removes only this environment's
named volume.

This environment validates application behavior, idempotency, retries,
consent/date/membership rules, replay defenses, and PAD decision classes. It
cannot replace real camera/device testing: physical PAD fixtures and
administrative approval are still required before live writes.
