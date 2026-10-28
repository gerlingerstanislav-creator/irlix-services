import asyncio
from datetime import datetime, timezone
from io import BytesIO
from unittest import TestCase
from unittest.mock import patch

from fastapi.testclient import TestClient
from fastapi import HTTPException
from app.main import app, guard, file_key, prefix, name


class FakeS3:
    def __init__(self):
        self.records = {}

    def put_object(self, Bucket, Key, Body, **kwargs):
        self.records[Key] = bytes(Body)

    def get_object(self, Bucket, Key):
        if Key not in self.records:
            raise AssertionError("Unexpected get on missing key")
        payload = self.records[Key]
        return {"Body": BytesIO(payload), "ContentLength": len(payload)}

    def list_objects_v2(self, Bucket, Prefix, Delimiter, MaxKeys, **kwargs):
        found = set()
        files = []
        for key, content in self.records.items():
            if not key.startswith(Prefix):
                continue
            tail = key[len(Prefix):]
            if "/" in tail:
                found.add(Prefix + tail.split("/", 1)[0] + "/")
            else:
                files.append({"Key": key, "Size": len(content),
                              "LastModified": datetime.now(timezone.utc)})
        return {"CommonPrefixes": [{"Prefix": key} for key in sorted(found)],
                "Contents": files, "IsTruncated": False}


class StoragePilotApiTests(TestCase):
    def setUp(self):
        self.store = FakeS3()
        self.stub = patch("app.main.s3", return_value=self.store)
        self.stub.start()
        app.dependency_overrides[guard] = lambda: None
        self.client = TestClient(app)

    def tearDown(self):
        self.client.close()
        app.dependency_overrides.clear()
        self.stub.stop()

    def test_upload_list_preview_and_download_in_isolated_namespace(self):
        result = self.client.post("/files", data={"folder": ""},
                                  files={"file": ("contract.pdf", b"%PDF synthetic", "application/pdf")})
        self.assertEqual(result.status_code, 200)
        key = result.json()["key"]
        self.assertTrue(key.startswith("pilot-ui/"))
        entries = self.client.get("/items").json()
        self.assertEqual([x["name"] for x in entries["files"]], ["contract.pdf"])
        response = self.client.get("/files", params={"key": key, "preview": "true"})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.content, b"%PDF synthetic")
        self.assertEqual(response.headers["content-type"], "application/pdf")
        self.assertTrue(response.headers["content-disposition"].startswith("inline"))
        download = self.client.get("/files", params={"key": key})
        self.assertEqual(download.status_code, 200)
        self.assertTrue(download.headers["content-disposition"].startswith("attachment"))

    def test_empty_folder_visible_after_creation(self):
        response = self.client.post("/folders", data={"folder": "", "folder_name": "Отчёты"})
        self.assertEqual(response.status_code, 200)
        listed = self.client.get("/items").json()
        self.assertEqual(listed["folders"], [{"name": "Отчёты", "path": "Отчёты/"}])
        self.assertEqual(self.client.get("/items?folder=Отчёты/").json()["files"], [])

    def test_rejects_traversal_invalid_names_and_oversized_upload(self):
        for value in ("../", "documents/../", "a//", "a\\b/"):
            self.assertEqual(self.client.get("/items", params={"folder": value}).status_code, 422)
        self.assertEqual(self.client.post("/folders", data={"folder_name": "../secret"}).status_code, 422)
        self.assertEqual(self.client.post("/files",
            files={"file": ("big.pdf", b"x" * (10 * 1024 * 1024 + 1))}).status_code, 413)
        self.assertFalse(self.store.records)

    def test_never_read_outside_pilot_or_allow_arbitrary_object_ids(self):
        for key in ("legacy-cv/secret.pdf", "pilot-ui/../../private.pdf",
                    "pilot-ui/guest.pdf", "pilot-ui/.irlix-folder"):
            response = self.client.get("/files", params={"key": key})
            self.assertIn(response.status_code, (404, 422), "invalid object ID must be rejected")
        self.assertEqual(self.client.delete("/files").status_code, 405)

    def test_active_formats_are_forced_download(self):
        result = self.client.post("/files", files={"file": ("test.html", b"<script>evil</script>")})
        response = self.client.get("/files", params={"key": result.json()["key"], "preview": "true"})
        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.headers["content-disposition"].startswith("attachment"))
        self.assertEqual(response.headers["content-type"], "application/octet-stream")
        self.assertEqual(response.headers["x-content-type-options"], "nosniff")

    def test_anonymous_endpoint_denied_even_on_direct_origin(self):
        app.dependency_overrides.clear()
        self.assertEqual(self.client.get("/items").status_code, 401)
        self.assertEqual(self.client.post("/folders", data={"folder_name": "oops"}).status_code, 401)
        self.assertFalse(self.store.records)



class StorageSessionGateTests(TestCase):
    def test_cookie_is_revalidated_and_csrf_is_enforced(self):
        from starlette.requests import Request

        class FakeResponse:
            status_code = 204

        class FakeClient:
            async def __aenter__(self):
                return self
            async def __aexit__(self, *args):
                return None
            async def get(self, *args, **kwargs):
                self.headers = kwargs["headers"]
                return FakeResponse()

        fake = FakeClient()

        def request(method, origin, header):
            headers = [(b"cookie", b"irlix_storage_pilot=synthetic-cookie")]
            if origin:
                headers.append((b"origin", origin.encode()))
            if header:
                headers.append((b"x-irlix-storage-request", header.encode()))
            return Request({"type": "http", "method": method, "path": "/files",
                            "headers": headers, "query_string": b""})

        with patch("app.main.httpx.AsyncClient", return_value=fake):
            asyncio.run(guard(request("GET", None, None)))
            self.assertEqual(fake.headers, {"Cookie": "irlix_storage_pilot=synthetic-cookie"})
            with self.assertRaises(HTTPException) as denied:
                asyncio.run(guard(request("POST", "https://evil.example", "1")))
            self.assertEqual(denied.exception.status_code, 403)
            with self.assertRaises(HTTPException) as denied:
                asyncio.run(guard(request("POST", "http://services.lan:8110", None)))
            self.assertEqual(denied.exception.status_code, 403)
            asyncio.run(guard(request("POST", "http://services.lan:8110", "1")))


class StoragePathTests(TestCase):
    def test_validation(self):
        self.assertEqual(prefix("Счета/2026/"), "Счета/2026/")
        for value in ("../", "a//", "a\\b/", "a/../", "a/" * 9):
            with self.assertRaises(HTTPException):
                prefix(value)
        with self.assertRaises(HTTPException):
            name("x/y")
        with self.assertRaises(HTTPException):
            file_key("other/asset")
