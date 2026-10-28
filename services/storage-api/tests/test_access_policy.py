import unittest
from dataclasses import dataclass
from app.access_policy import (
    Principal, FileObject, FileGrant, GrantRole, Permission, can_access,
)
from app.access_service import AccessService, AccessDenied, InvalidOperation


class PolicyTests(unittest.TestCase):
    def setUp(self):
        self.root = FileObject("root", 1)
        self.file = FileObject("file", 1, "root")
        self.objects = {"root": self.root, "file": self.file}

    def allowed(self, employee, permission, grants=(), objects=None, admin=False, active=True):
        return can_access(Principal(employee, active, admin), "file", permission,
                          self.objects if objects is None else objects, list(grants))

    def test_owner_editor_reader_and_no_implicit_manager(self):
        self.assertTrue(self.allowed(1, Permission.TRASH))
        self.assertTrue(self.allowed(1, Permission.SHARE))
        self.assertFalse(self.allowed(2, Permission.READ))
        reader = [FileGrant("root", 2, GrantRole.READER)]
        self.assertTrue(self.allowed(2, Permission.DOWNLOAD, reader))
        self.assertTrue(self.allowed(2, Permission.THUMBNAIL, reader))
        self.assertFalse(self.allowed(2, Permission.UPLOAD, reader))
        self.assertFalse(self.allowed(2, Permission.TRASH, reader))
        editor = [FileGrant("root", 2, GrantRole.EDITOR)]
        self.assertTrue(self.allowed(2, Permission.UPLOAD, editor))
        self.assertTrue(self.allowed(2, Permission.EDIT, editor))
        self.assertFalse(self.allowed(2, Permission.SHARE, editor))
        self.assertFalse(self.allowed(2, Permission.TRASH, editor))
        self.assertFalse(self.allowed(2, Permission.RESTORE, editor))

    def test_denied_revoked_inactive_deleted_or_cross_owner(self):
        self.assertFalse(self.allowed(2, Permission.READ))
        grant = [FileGrant("file", 2, GrantRole.READER)]
        self.assertFalse(self.allowed(2, Permission.READ, grant, active=False))
        self.assertFalse(self.allowed(1, Permission.DOWNLOAD, active=False))
        trashed = {"root": self.root, "file": FileObject("file", 1, "root", "trashed")}
        self.assertFalse(self.allowed(1, Permission.DOWNLOAD, objects=trashed))
        self.assertTrue(self.allowed(1, Permission.RESTORE, objects=trashed))
        parent_deleted = {"root": FileObject("root", 1, state="trashed"), "file": self.file}
        self.assertFalse(self.allowed(1, Permission.READ, objects=parent_deleted))
        self.assertFalse(self.allowed(1, Permission.RESTORE, objects=parent_deleted))
        cross_owner = {"root": FileObject("root", 2), "file": self.file}
        self.assertFalse(self.allowed(1, Permission.READ, objects=cross_owner))

    def test_invalid_graph_and_admin_still_requires_active(self):
        self.assertFalse(self.allowed(1, Permission.READ, objects={"file": self.file}))
        cycle = {"root": FileObject("root", 1, "file"), "file": self.file}
        self.assertFalse(self.allowed(1, Permission.READ, objects=cycle))
        self.assertTrue(self.allowed(9, Permission.AUDIT, admin=True))
        self.assertFalse(self.allowed(9, Permission.READ, admin=True, active=False))


@dataclass
class FakeRepo:
    active: bool = True

    def __post_init__(self):
        self.objects = {"file": FileObject("file", 1)}
        self.grants = []
        self.events = []
        self.operations = []

    def load_objects(self, _): return self.objects
    def load_grants(self, _): return self.grants
    def employee_is_active(self, _): return self.active
    def put_grant(self, obj, employee, role, actor):
        self.grants.append(FileGrant(obj, employee, role))
        self.operations.append(("grant", obj, actor))
    def revoke_grant(self, obj, employee):
        self.grants = [x for x in self.grants if not (x.object_id == obj and x.employee_id == employee)]
    def set_object_state(self, obj, state):
        f = self.objects[obj]
        self.objects[obj] = FileObject(f.object_id, f.owner_employee_id, f.parent_id, state)
    def append_audit(self, event): self.events.append(event)


class CommandTests(unittest.TestCase):
    def test_grant_revoke_audited_and_actor_is_server_verified(self):
        repo = FakeRepo()
        svc = AccessService(repo, Principal(1, True), "00000000-0000-4000-8000-000000000000")
        svc.grant("file", 2, GrantRole.READER)
        self.assertEqual(repo.events[-1]["actor_employee_id"], 1)
        self.assertTrue(can_access(Principal(2, True), "file", Permission.READ, repo.objects, repo.grants))
        svc.revoke("file", 2)
        self.assertFalse(can_access(Principal(2, True), "file", Permission.READ, repo.objects, repo.grants))
        self.assertEqual(repo.events[-1]["action"], "acl.revoke")

    def test_editor_cannot_share_and_owner_restrictions(self):
        repo = FakeRepo()
        repo.grants.append(FileGrant("file", 2, GrantRole.EDITOR))
        with self.assertRaises(AccessDenied):
            AccessService(repo, Principal(2, True), "id").grant("file", 3, GrantRole.READER)
        with self.assertRaises(InvalidOperation):
            AccessService(repo, Principal(1, True), "id").grant("file", 1, GrantRole.EDITOR)
        repo.active = False
        with self.assertRaises(InvalidOperation):
            AccessService(repo, Principal(1, True), "id").grant("file", 3, GrantRole.READER)

    def test_trash_restores_with_audit_without_touching_s3(self):
        repo = FakeRepo()
        svc = AccessService(repo, Principal(1, True), "id")
        svc.trash("file")
        self.assertEqual(repo.objects["file"].state, "trashed")
        with self.assertRaises(AccessDenied):
            AccessService(repo, Principal(2, True), "id").restore("file")
        svc.restore("file")
        self.assertEqual(repo.objects["file"].state, "active")
        self.assertEqual([e["action"] for e in repo.events], ["object.trash", "object.restore"])


if __name__ == "__main__":
    unittest.main()
