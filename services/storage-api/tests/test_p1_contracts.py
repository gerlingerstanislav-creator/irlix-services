import unittest
from fastapi.testclient import TestClient
from unittest.mock import patch
from app.main import app, guard, v1_guard
from app.access_policy import Permission, Principal, FileObject, FileGrant, GrantRole, can_access
from app.v1_api import _name


class P1Contracts(unittest.TestCase):
    def test_extensions_are_explicit_and_scope_limited(self):
        owner = Principal(1, active=True)
        reader = Principal(2, active=True)
        editor = Principal(3, active=True)
        tree = {"root": FileObject("root", 1), "file": FileObject("file", 1, "root")}
        grants = [FileGrant("root", 2, GrantRole.READER),
                  FileGrant("root", 3, GrantRole.EDITOR)]
        self.assertTrue(can_access(owner, "file", Permission.MOVE, tree, grants))
        self.assertFalse(can_access(reader, "file", Permission.MOVE, tree, grants))
        self.assertFalse(can_access(editor, "file", Permission.MOVE, tree, grants))
        self.assertTrue(can_access(reader, "file", Permission.COPY, tree, grants))
        self.assertFalse(can_access(reader, "file", Permission.EDIT, tree, grants))
        self.assertTrue(can_access(editor, "file", Permission.COPY, tree, grants))

    def test_v1_mutations_refuse_without_verified_actor(self):
        app.dependency_overrides[guard] = lambda: None
        app.dependency_overrides[v1_guard] = lambda: None
        try:
            with TestClient(app) as c:
                ids = [
                    "/v1/search?q=report",
                    "/v1/folders/targets",
                    "/v1/objects/00000000-0000-4000-8000-000000000000/versions",
                ]
                for url in ids:
                    self.assertEqual(c.get(url).status_code, 503, url)
                self.assertEqual(
                    c.post("/v1/objects/00000000-0000-4000-8000-000000000000/move",
                           json={"parent_id": None}).status_code, 503)
                self.assertEqual(
                    c.post("/v1/objects/00000000-0000-4000-8000-000000000000/copy",
                           json={}).status_code, 503)
        finally:
            app.dependency_overrides.clear()

    def test_illegal_name_rejected(self):
        for name in ("", ".", "..", "../test", "bad\\name", "x\n"):
            with self.assertRaises(Exception):
                _name(name)


if __name__ == "__main__":
    unittest.main()
