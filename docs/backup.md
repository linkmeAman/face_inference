# Backup and restore

Backups are consistent while the server runs: they use the SQLite online backup API, including committed WAL data. Stop the server and all database writers before restoring.

The snapshot contains encrypted face templates. The encryption key lives in `config.json`, not in the snapshot. A snapshot without its matching `config.json` is unrecoverable. Back up `config.json` too and store it separately from the snapshot: a stolen snapshot alone is useless, but a stolen pair is not. The configuration also contains the API token and other secrets.

```sh
face-inference backup --config /secure/original/config.json --output /secure/backups/faces.sqlite3
```

The output must not already exist. The command checks database integrity and its encryption-key binding, creates a private file, and removes an incomplete output on failure. Keep the original database and configuration until you have verified the backup. Symbolic links in ancestor directories are allowed, but file paths must not themselves be symbolic links.

To recover, copy the matching original `config.json` to a secure location, preserving its secrets. Its `database` value must be an absolute path in an existing private directory. Restore refuses to overwrite an existing database or SQLite sidecar; move the old database and its sidecars aside first.

```sh
face-inference restore --config /secure/recovery/config.json --input /secure/backups/faces.sqlite3
face-inference check --config /secure/recovery/config.json
face-inference purge --config /secure/recovery/config.json
```

Restore checks integrity and the original key before publishing the new private database. The destination filesystem must support hard links. If it does not, restore into a directory on a supported filesystem, then move the file afterwards with the server still stopped. If restore fails, leave the original files untouched, correct the reported problem and retry. A restored snapshot can contain records that expired since it was taken, so run the purge command after restoring. Check that the restored service works before retiring original data.
