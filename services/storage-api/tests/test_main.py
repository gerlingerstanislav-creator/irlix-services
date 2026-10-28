import asyncio
from datetime import datetime, timezone
from io import BytesIO
from PIL import Image
from botocore.exceptions import ClientError
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
            raise ClientError({"Error": {"Code": "NoSuchKey"}}, "GetObject")
        payload = self.records[Key]
        return {"Body": BytesIO(payload), "ContentLength": len(payload)}

    def head_object(self, Bucket, Key):
        if Key not in self.records:
            raise ClientError({"Error": {"Code": "NoSuchKey"}}, "HeadObject")
        return {"ContentLength": len(self.records[Key])}

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

    def test_double_underscore_filename_is_preserved_in_content_disposition(self):
        result = self.client.post("/files", files={
            "file": ("report__final__2026.pdf", b"%PDF synthetic", "application/pdf")})
        self.assertEqual(result.status_code, 200)
        response = self.client.get("/files", params={"key": result.json()["key"]})
        self.assertEqual(response.status_code, 200)
        self.assertIn("report__final__2026.pdf", response.headers["content-disposition"])

    def test_empty_folder_visible_after_creation(self):
        response = self.client.post("/folders", data={"folder": "", "folder_name": "Отчёты"})
        self.assertEqual(response.status_code, 200)
        listed = self.client.get("/items").json()
        self.assertEqual(listed["folders"], [{"name": "Отчёты", "path": "Отчёты/"}])
        self.assertEqual(self.client.get("/items?folder=Отчёты/").json()["files"], [])

    def test_rename_preserves_physical_identity_and_updates_download_name(self):
        file_result = self.client.post("/files", files={
            "file": ("old.pdf", b"%PDF synthetic", "application/pdf")}).json()
        key = file_result["key"]
        response = self.client.post("/rename", data={
            "kind": "file", "key": key, "new_name": "new.pdf"})
        self.assertEqual(response.status_code, 200, response.text)
        self.assertIn(key, self.store.records)
        self.assertEqual(self.client.get("/items").json()["files"][0]["name"], "new.pdf")
        download = self.client.get("/files", params={"key": key})
        self.assertEqual(download.content, b"%PDF synthetic")
        self.assertIn("new.pdf", download.headers["content-disposition"])
        self.assertEqual(self.client.post("/rename", data={
            "kind": "file", "key": key, "new_name": "wrong.exe"}).status_code, 422)
        self.assertEqual(self.client.post("/rename", data={
            "kind": "file", "key": "other/secret.pdf", "new_name": "a.pdf"}).status_code, 404)

    def test_folder_rename_keeps_existing_path_and_updates_breadcrumbs(self):
        self.assertEqual(self.client.post("/folders", data={"folder_name": "Original"}).status_code, 200)
        response = self.client.post("/rename", data={
            "kind": "folder", "path": "Original/", "new_name": "Новое название"})
        self.assertEqual(response.status_code, 200, response.text)
        listed = self.client.get("/items").json()
        self.assertEqual(listed["folders"], [{"name": "Новое название", "path": "Original/"}])
        inside = self.client.get("/items", params={"folder": "Original/"}).json()
        self.assertEqual(inside["breadcrumbs"], [{"name": "Новое название", "path": "Original/"}])
        self.assertIn("pilot-ui/Original/.irlix-folder", self.store.records)

    def test_rename_rejects_collisions_and_missing_targets(self):
        self.client.post("/folders", data={"folder_name": "One"})
        self.client.post("/folders", data={"folder_name": "Two"})
        clash = self.client.post("/rename", data={
            "kind": "folder", "path": "One/", "new_name": "Two"})
        self.assertEqual(clash.status_code, 409)
        self.assertEqual(self.client.post("/rename", data={
            "kind": "folder", "path": "missing/", "new_name": "New"}).status_code, 404)
        self.assertEqual(self.client.post("/rename", data={
            "kind": "folder", "path": "One/", "new_name": "../bad"}).status_code, 422)
        self.assertEqual(self.client.post("/rename", data={
            "kind": "folder", "path": "", "new_name": "Root"}).status_code, 422)

    def test_thumbnail_is_resized_and_untrusted_data_is_not_exposed(self):
        source = BytesIO()
        Image.new("RGB", (900, 600), (42, 100, 170)).save(source, format="PNG")
        upload = self.client.post("/files", files={
            "file": ("synthetic.png", source.getvalue(), "image/png")}).json()
        result = self.client.get("/thumbnails", params={"key": upload["key"]})
        self.assertEqual(result.status_code, 200)
        self.assertEqual(result.headers["content-type"], "image/jpeg")
        self.assertEqual(result.headers["cache-control"], "private, no-store")
        with Image.open(BytesIO(result.content)) as image:
            self.assertLessEqual(image.width, 320)
            self.assertLessEqual(image.height, 240)
        self.assertEqual(self.client.get("/thumbnails", params={
            "key": "pilot-ui/not-legit"}).status_code, 404)
        other = self.client.post("/files", files={"file": ("doc.pdf", b"pdf")}).json()
        self.assertEqual(self.client.get("/thumbnails", params={"key": other["key"]}).status_code, 415)
        fake = self.client.post("/files", files={"file": ("fake.png", b"not a png")}).json()
        self.assertEqual(self.client.get("/thumbnails", params={"key": fake["key"]}).status_code, 415)

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
                asyncio.run(guard(request("POST", "http://services.lan", None)))
            self.assertEqual(denied.exception.status_code, 403)
            asyncio.run(guard(request("POST", "http://services.lan", "1")))


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
