import json
import tempfile
import threading
import time
import unittest
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from face_inference.client import (
    FaceApiError,
    FaceClient,
    FaceClientError,
    FaceConnectionError,
)
from face_inference.config import ROOT, contract, load_config
from face_inference.server import FaceHTTPServer
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


class StubHandler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def send_json(self, status: int, payload: dict):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        self.server.requests_log.append(
            {
                "method": "GET",
                "path": self.path,
                "headers": dict(self.headers),
            }
        )
        if self.path == "/delay":
            time.sleep(0.3)
            self.send_json(200, {"error": 0, "data": {"status": "delayed"}})
            return
        if self.path == "/health":
            self.send_json(
                200,
                {
                    "error": 0,
                    "code": "",
                    "error_msg": "",
                    "message": "",
                    "data": {"status": "ok", "api_version": "1", "storage": "sqlite"},
                },
            )
            return
        if self.path == "/api/v1/config":
            auth = self.headers.get("Authorization", "")
            if auth != "Bearer test-secret-token-xyz":
                self.send_json(
                    401,
                    {
                        "error": 1,
                        "code": "AUTH_REQUIRED",
                        "error_msg": "Unauthorized token: test-secret-token-xyz",
                        "data": {},
                    },
                )
                return
            self.send_json(
                200,
                {
                    "error": 0,
                    "code": "",
                    "error_msg": "",
                    "message": "",
                    "data": {
                        "mode": "EVALUATION",
                        "contract": {"model_version": "sface-v1", "dimension": 128},
                        "approved": True,
                        "duplicate_threshold": 0.85,
                        "recognition_threshold": 0.85,
                        "liveness_validated": False,
                        "maximum_people": 1000,
                    },
                },
            )
            return
        if self.path == "/api/v1/collections":
            self.send_json(
                200,
                {
                    "error": 0,
                    "data": [
                        {"id": "col1", "name": "Collection 1", "created_at": 1700000000.0}
                    ],
                },
            )
            return
        if self.path == "/api/v1/collections/col1/people":
            self.send_json(
                200,
                {"error": 0, "data": [{"id": "p1", "name": "Person 1", "state": "ENROLLED"}]},
            )
            return
        if self.path == "/api/v1/collections/col1/events":
            self.send_json(
                200,
                {
                    "error": 0,
                    "data": [
                        {
                            "id": 1,
                            "person_id": None,
                            "event": "enroll",
                            "state": "PENDING",
                            "created_at": 1700000000.0,
                        }
                    ],
                },
            )
            return
        if self.path == "/transient-get":
            self.server.transient_get_count += 1
            if self.server.transient_get_count < 2:
                self.send_json(
                    503,
                    {"error": 1, "code": "STORAGE_BUSY", "error_msg": "Storage busy", "data": {}},
                )
                return
            self.send_json(200, {"error": 0, "data": {"recovered": True}})
            return
        if self.path == "/echo-token-error":
            self.send_json(
                400,
                {
                    "error": 1,
                    "code": "BAD_AUTH",
                    "error_msg": f"Rejected token {self.headers.get('Authorization', '')}",
                    "data": {},
                },
            )
            return
        self.send_json(
            404,
            {"error": 1, "code": "NOT_FOUND", "error_msg": "Route not found", "data": {}},
        )

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length) if length > 0 else b""
        body = json.loads(raw.decode("utf-8")) if raw else {}
        self.server.requests_log.append(
            {
                "method": "POST",
                "path": self.path,
                "headers": dict(self.headers),
                "body": body,
            }
        )
        if self.path == "/fail-permanent":
            self.send_json(
                400,
                {"error": 1, "code": "INVALID_JSON", "error_msg": "Bad input", "data": {}},
            )
            return
        if self.path == "/transient-post":
            self.server.transient_post_count += 1
            if self.server.transient_post_count < 3:
                self.send_json(
                    500,
                    {
                        "error": 1,
                        "code": "INTERNAL",
                        "error_msg": "Temporary glitch",
                        "data": {},
                    },
                )
                return
            self.send_json(
                200,
                {"error": 0, "data": {"saved": True, "key": body.get("idempotency_key")}},
            )
            return
        if self.path == "/always-500":
            self.send_json(
                500,
                {"error": 1, "code": "FAIL", "error_msg": "Server down", "data": {}},
            )
            return
        if self.path == "/api/v1/collections":
            self.send_json(
                200,
                {"error": 0, "data": {"state": "CREATED", "collection_id": body.get("id")}},
            )
            return
        if self.path == "/api/v1/collections/col1/people":
            self.send_json(
                200,
                {
                    "error": 0,
                    "data": {
                        "id": body.get("id"),
                        "name": body.get("name"),
                        "state": "NOT_ENROLLED",
                    },
                },
            )
            return
        if self.path == "/api/v1/collections/col1/enrollments":
            self.send_json(
                200,
                {
                    "error": 0,
                    "data": {"state": "PENDING", "person_id": body.get("person_id")},
                },
            )
            return
        if self.path == "/api/v1/collections/col1/recognitions":
            self.send_json(
                200,
                {"error": 0, "data": {"state": "SUGGESTED", "person_id": "p1", "verified": False}},
            )
            return
        if self.path == "/api/v1/collections/col1/observations":
            self.send_json(200, {"error": 0, "data": {"recorded": True}})
            return
        self.send_response(404)
        self.end_headers()

    def do_DELETE(self):
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length) if length > 0 else b""
        body = json.loads(raw.decode("utf-8")) if raw else {}
        self.server.requests_log.append(
            {
                "method": "DELETE",
                "path": self.path,
                "headers": dict(self.headers),
                "body": body,
            }
        )
        if self.path == "/api/v1/collections/col1/people/p1/face":
            self.send_json(200, {"error": 0, "data": {"state": "DELETED", "person_id": "p1"}})
            return
        self.send_response(404)
        self.end_headers()


class ClientTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.stub_server = ThreadingHTTPServer(("127.0.0.1", 0), StubHandler)
        cls.stub_server.requests_log = []
        cls.stub_server.transient_get_count = 0
        cls.stub_server.transient_post_count = 0
        cls.stub_thread = threading.Thread(target=cls.stub_server.serve_forever, daemon=True)
        cls.stub_thread.start()
        cls.base_url = f"http://127.0.0.1:{cls.stub_server.server_port}"
        cls.token = "test-secret-token-xyz"

    @classmethod
    def tearDownClass(cls):
        cls.stub_server.shutdown()
        cls.stub_server.server_close()
        cls.stub_thread.join(timeout=3)

    def setUp(self):
        self.stub_server.requests_log.clear()
        self.stub_server.transient_get_count = 0
        self.stub_server.transient_post_count = 0
        self.client = FaceClient(self.base_url, self.token, timeout=2.0, retry_backoff=0.01)

    def test_reject_non_http_base_urls(self):
        for bad_url in (
            "ftp://example.com",
            "file:///etc/passwd",
            "ws://localhost:6122",
            "javascript:alert(1)",
            "relative/path",
            "",
            "http://",
        ):
            with self.subTest(bad_url=bad_url):
                with self.assertRaises(ValueError):
                    FaceClient(bad_url, self.token)

    def test_token_redaction(self):
        client = FaceClient(self.base_url, self.token)
        self.assertNotIn(self.token, repr(client))
        self.assertNotIn(self.token, str(client))
        self.assertIn("***", repr(client))

        # Check token never appears in exception repr / str even if server echoes it
        with self.assertRaises(FaceApiError) as cm:
            client.get("/echo-token-error")
        self.assertNotIn(self.token, repr(cm.exception))
        self.assertNotIn(self.token, str(cm.exception))
        self.assertNotIn(self.token, cm.exception.message)
        self.assertIn("***", cm.exception.message)

    def test_health_endpoint(self):
        result = self.client.health()
        self.assertEqual(result.get("status"), "ok")
        self.assertEqual(result.get("api_version"), "1")
        self.assertEqual(result.get("storage"), "sqlite")

    def test_config_endpoint(self):
        result = self.client.get_config()
        self.assertEqual(result.get("mode"), "EVALUATION")
        self.assertEqual(result.get("contract", {}).get("model_version"), "sface-v1")
        self.assertEqual(self.client.config(), result)

    def test_collections_crud(self):
        cols = self.client.list_collections()
        self.assertEqual(len(cols), 1)
        self.assertEqual(cols[0]["id"], "col1")

        created = self.client.create_collection("col2", "Collection 2")
        self.assertEqual(created["state"], "CREATED")
        self.assertEqual(created["collection_id"], "col2")
        last_req = self.stub_server.requests_log[-1]
        self.assertIn("idempotency_key", last_req["body"])

    def test_people_crud(self):
        people = self.client.list_people("col1")
        self.assertEqual(len(people), 1)
        self.assertEqual(people[0]["id"], "p1")

        person = self.client.add_person("col1", "p2", "Person 2", metadata={"dept": "eng"})
        self.assertEqual(person["id"], "p2")
        self.assertEqual(person["name"], "Person 2")
        last_req = self.stub_server.requests_log[-1]
        self.assertEqual(last_req["body"]["metadata"], {"dept": "eng"})
        self.assertIn("idempotency_key", last_req["body"])

    def test_enroll_recognize_observe(self):
        vector = [1.0] + [0.0] * 127
        session = str(uuid.uuid4())

        enroll_res = self.client.enroll(
            collection_id="col1",
            person_id="p1",
            observations=[vector] * 5,
            template=vector,
            capture_session=session,
            model_version="sface-v1",
            preprocessing_version="v1",
        )
        self.assertEqual(enroll_res["state"], "PENDING")
        self.assertEqual(enroll_res["person_id"], "p1")

        rec_res = self.client.recognize(
            collection_id="col1",
            observations=[vector] * 5,
            capture_session=session,
            model_version="sface-v1",
            preprocessing_version="v1",
        )
        self.assertEqual(rec_res["state"], "SUGGESTED")
        self.assertEqual(rec_res["person_id"], "p1")
        self.assertFalse(rec_res["verified"])

        obs_res = self.client.record_observation(
            collection_id="col1",
            person_id="p1",
            observations=[vector] * 5,
            template=vector,
            capture_session=session,
            model_version="sface-v1",
            preprocessing_version="v1",
        )
        self.assertTrue(obs_res.get("recorded"))

    def test_delete_face_and_events(self):
        del_res = self.client.delete_face("col1", "p1")
        self.assertEqual(del_res["state"], "DELETED")
        self.assertEqual(del_res["person_id"], "p1")
        last_req = self.stub_server.requests_log[-1]
        self.assertEqual(last_req["method"], "DELETE")
        self.assertIn("idempotency_key", last_req["body"])

        events = self.client.list_events("col1")
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event"], "enroll")
        self.assertEqual(events[0]["state"], "PENDING")

    def test_error_mapping_client_error(self):
        with self.assertRaises(FaceApiError) as cm:
            self.client.send("/fail-permanent", {"foo": "bar"})
        self.assertEqual(cm.exception.status, 400)
        self.assertEqual(cm.exception.code, "INVALID_JSON")
        self.assertEqual(cm.exception.message, "Bad input")
        fail_reqs = [r for r in self.stub_server.requests_log if r["path"] == "/fail-permanent"]
        self.assertEqual(len(fail_reqs), 1)

    def test_retry_on_transient_error_recovers(self):
        res_get = self.client.get("/transient-get")
        self.assertTrue(res_get.get("recovered"))
        self.assertEqual(self.stub_server.transient_get_count, 2)

        res_post = self.client.send("/transient-post", {"item": 123})
        self.assertTrue(res_post.get("saved"))
        self.assertEqual(self.stub_server.transient_post_count, 3)
        post_reqs = [r for r in self.stub_server.requests_log if r["path"] == "/transient-post"]
        keys = {r["body"]["idempotency_key"] for r in post_reqs}
        self.assertEqual(len(keys), 1)

    def test_exhausted_retries_and_retry_method(self):
        with self.assertRaises(FaceApiError) as cm:
            self.client.send("/always-500", {"x": 1})
        self.assertEqual(cm.exception.status, 500)
        self.assertIsNotNone(self.client.pending)

        with self.assertRaises(FaceClientError):
            self.client.send("/other", {"y": 2})

        with self.assertRaises(FaceApiError):
            self.client.retry()

    def test_timeout_raises_connection_error(self):
        fast_client = FaceClient(self.base_url, self.token, timeout=0.1, retry_backoff=0.01)
        with self.assertRaises(FaceConnectionError):
            fast_client.get("/delay")

    def test_network_connection_error(self):
        dead_client = FaceClient("http://127.0.0.1:1", self.token, timeout=0.5, retry_backoff=0.01)
        with self.assertRaises(FaceConnectionError):
            dead_client.health()

    def test_integration_with_actual_face_http_server(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            config = load_config(Path(tmp_dir) / "config.json")
            service = FaceService(
                SQLiteRepository(config["database"], config["encryption_key"]),
                config,
                contract(),
            )
            server = FaceHTTPServer(("127.0.0.1", 0), service, ROOT)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                real_client = FaceClient(
                    f"http://127.0.0.1:{server.server_port}",
                    config["api_token"],
                    timeout=5.0,
                    retry_backoff=0.01,
                )
                health = real_client.health()
                self.assertEqual(health["status"], "ok")

                cfg = real_client.get_config()
                self.assertEqual(cfg["mode"], "EVALUATION")

                real_client.create_collection("team-alpha", "Alpha Team")
                cols = real_client.list_collections()
                self.assertTrue(any(c["id"] == "team-alpha" for c in cols))

                real_client.add_person("team-alpha", "emp1", "Alice")
                people = real_client.list_people("team-alpha")
                self.assertTrue(any(p["id"] == "emp1" for p in people))

                model = contract()
                vector = [1.0] + [0.0] * 127
                enrolled = real_client.enroll(
                    collection_id="team-alpha",
                    person_id="emp1",
                    observations=[vector] * 5,
                    template=vector,
                    capture_session=str(uuid.uuid4()),
                    model_version=model["model_version"],
                    preprocessing_version=model["preprocessing_version"],
                )
                self.assertEqual(enrolled["state"], "PENDING")

                rec = real_client.recognize(
                    collection_id="team-alpha",
                    observations=[vector] * 5,
                    capture_session=str(uuid.uuid4()),
                    model_version=model["model_version"],
                    preprocessing_version=model["preprocessing_version"],
                )
                self.assertEqual(rec["state"], "SUGGESTED")
                self.assertEqual(rec["person_id"], "emp1")

                deleted = real_client.delete_face("team-alpha", "emp1")
                self.assertEqual(deleted["state"], "DELETED")
            finally:
                server.shutdown()
                server.server_close()
                thread.join(timeout=3)


if __name__ == "__main__":
    unittest.main()
