#!/usr/bin/env python3
"""Quickstart example for the face_inference Python client.

Demonstrates:
  1. Health check (GET /health)
  2. Creating a collection (POST /api/v1/collections)
  3. Adding a person (POST /api/v1/collections/{id}/people)
  4. Listing people in a collection (GET /api/v1/collections/{id}/people)
  5. Deleting face data for a person (DELETE /api/v1/collections/{id}/people/{id}/face)

Prerequisites:
  Start the face_inference server:
    PYTHONPATH=src FACE_INFERENCE_HOME=$(mktemp -d) python3 -m face_inference

  Obtain the API token:
    PYTHONPATH=src python3 -m face_inference token

  Run this example:
    FACE_URL=http://127.0.0.1:6122 FACE_TOKEN=<token> python3 examples/python/quickstart.py
"""

from __future__ import annotations

import os
import sys
import time

try:
    from face_inference.client import FaceApiError, FaceClient, FaceConnectionError
except ImportError:
    repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
    sys.path.insert(0, os.path.join(repo_root, "src"))
    from face_inference.client import FaceApiError, FaceClient, FaceConnectionError


def main() -> int:
    base_url = os.environ.get("FACE_URL", "http://127.0.0.1:6122")
    token = os.environ.get("FACE_TOKEN", "").strip()

    if not token:
        print(
            "Error: FACE_TOKEN environment variable is required.\n"
            "Run 'python -m face_inference token' to obtain an API token,\n"
            "then run:\n"
            f"  FACE_URL={base_url} FACE_TOKEN=<token> python3 {sys.argv[0]}",
            file=sys.stderr,
        )
        return 1

    print(f"Connecting to face_inference service at {base_url}...")
    client = FaceClient(base_url=base_url, token=token)

    # 1. Health check (unauthenticated public endpoint)
    print("\n1. Checking service health (GET /health)...")
    try:
        health = client.health()
        print(f"   Status: {health.get('status')}")
        print(f"   Storage: {health.get('storage')}")
        print(f"   API Version: {health.get('api_version')}")
    except (FaceApiError, FaceConnectionError) as exc:
        print(f"   Failed to connect: {exc}", file=sys.stderr)
        return 1

    # 2. Create collection
    collection_id = f"demo-{int(time.time())}"
    collection_name = "Quickstart Demo Collection"
    print(f"\n2. Creating collection '{collection_id}' (POST /api/v1/collections)...")
    try:
        col = client.create_collection(id=collection_id, name=collection_name)
        cid = col.get("collection_id") or col.get("id") or collection_id
        cstate = col.get("state", "CREATED")
        print(f"   Collection created: id={cid}, state={cstate}")
    except FaceApiError as exc:
        print(f"   Create collection failed: {exc}", file=sys.stderr)
        return 1

    # 3. Add person
    person_id = "alice"
    person_name = "Alice Smith"
    metadata = {"role": "engineer", "department": "rnd"}
    print(f"\n3. Adding person '{person_id}' (POST /api/v1/collections/{collection_id}/people)...")
    try:
        person = client.add_person(
            collection_id=collection_id,
            id=person_id,
            name=person_name,
            metadata=metadata,
        )
        pdata = person.get("person") or {}
        pid = person.get("person_id") or pdata.get("id") or person_id
        pname = pdata.get("name") or person_name
        pstate = person.get("state") or pdata.get("state")
        print(f"   Person added: id={pid}, name={pname}, state={pstate}")
    except FaceApiError as exc:
        print(f"   Add person failed: {exc}", file=sys.stderr)
        return 1

    # 4. List people in collection
    print(f"\n4. Listing people in collection '{collection_id}'...")
    try:
        people = client.list_people(collection_id=collection_id)
        print(f"   Found {len(people)} person(s):")
        for p in people:
            print(f"   - {p.get('id')}: {p.get('name')} (state: {p.get('state')})")
    except FaceApiError as exc:
        print(f"   List people failed: {exc}", file=sys.stderr)
        return 1

    # 5. Delete face template and capture evidence for person
    print(f"\n5. Deleting face data for person '{person_id}'...")
    try:
        del_result = client.delete_face(collection_id=collection_id, person_id=person_id)
        state = del_result.get("state")
        pid = del_result.get("person_id")
        print(f"   Result: state={state}, person_id={pid}")
    except FaceApiError as exc:
        print(f"   Delete face failed: {exc}", file=sys.stderr)
        return 1

    print("\n✓ Quickstart completed successfully!")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
