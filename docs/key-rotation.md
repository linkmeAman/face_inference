# Offline encryption key rotation

Stop the server and every other process using the database before starting. Save and verify a separate database backup and its matching private `config.json` first; see [backup](backup.md). Keep the old config securely: old backups are readable only with that old key's config.

Run `face-inference rotate-key --config /private/path/config.json`. By default this creates a verified, private `<db>.pre-rotation-<UTC timestamp>` snapshot without overwriting an existing file. `--no-backup` skips this snapshot; use it only when a verified backup and old config are already safe. The command never prints keys.

Rotation stores a pending key in the config, re-encrypts templates, observations and the database key check in one SQLite transaction, checkpoints the WAL, and promotes the key. If interrupted, restart any repository-opening command or repeat `rotate-key`: recovery checks the database binding and discards an unused pending key or completes promotion. If neither key opens the database, it stops without changing the config; restore the verified backup with its matching config. Do not manually remove a pending key.

After rotation, verify the application and data with the new config. The snapshot contains templates encrypted with the old key. Once no longer needed, securely delete the snapshot and retire the old key and any old backups according to your retention policy. Keep the old key as long as any retained backup needs it.

This protocol requires a local filesystem where same-directory `os.replace` is atomic, such as ext4, xfs, APFS or NTFS. Network filesystems are unverified. SQLite supplies database transaction atomicity; config power-loss durability depends on fsync support. Windows uses `os.replace` but cannot fsync a directory. External crashes that corrupt the disk are outside this guarantee; the verified snapshot is the safety net.
