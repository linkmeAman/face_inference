"""Transactional schema migrations for SQLite repository."""

from __future__ import annotations

import sqlite3

CURRENT_SCHEMA_VERSION = 2

MIGRATIONS: dict[int, tuple[str, ...]] = {
    # version -> statements that upgrade the schema from that version to the next
    1: (
        "CREATE INDEX IF NOT EXISTS events_collection_id ON events(collection_id, id)",
        "CREATE INDEX IF NOT EXISTS captures_expires_at ON captures(expires_at)",
        "CREATE INDEX IF NOT EXISTS people_expires_at ON people(expires_at)",
    ),
}


def migrate(db: sqlite3.Connection) -> None:
    """Migrate database schema atomically to CURRENT_SCHEMA_VERSION.

    Rejects unknown future versions without modifying data.
    Rolls back schema and version together if any statement fails.
    """
    cursor = db.execute(
        "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='metadata'"
    )
    if cursor.fetchone()[0] == 0:
        return

    row = db.execute(
        "SELECT value FROM metadata WHERE key='schema_version'"
    ).fetchone()
    if not row:
        return

    try:
        current_v = int(row[0])
    except (ValueError, TypeError) as err:
        raise RuntimeError("Unsupported face database schema version") from err

    if current_v > CURRENT_SCHEMA_VERSION or current_v < 1:
        raise RuntimeError("Unsupported face database schema version")

    while current_v < CURRENT_SCHEMA_VERSION:
        target_v = current_v + 1
        statements = MIGRATIONS.get(current_v)
        if statements is None:
            raise RuntimeError("Unsupported face database schema version")

        db.execute("BEGIN IMMEDIATE")
        try:
            for stmt in statements:
                db.execute(stmt)
            db.execute(
                "UPDATE metadata SET value=? WHERE key='schema_version'",
                (str(target_v),),
            )
            db.commit()
        except BaseException:
            db.rollback()
            raise
        current_v = target_v
