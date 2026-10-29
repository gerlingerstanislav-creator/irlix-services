"""Offline regression tests for production Docker image retention safety."""
import importlib.util
from datetime import datetime, timedelta, timezone
from pathlib import Path
import unittest

FILE = Path(__file__).resolve().parent / "prune_docker_images.py"
SPEC = importlib.util.spec_from_file_location("image_retention", FILE)
RETENTION = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RETENTION)
NOW = datetime(2026, 10, 29, tzinfo=timezone.utc)
PREFIX = "ghcr.io/gerlingerstanislav-creator/irlix-"


def image(name, index, age_hours, tags=None):
    identity = "sha256:" + str(index).zfill(64)
    return {
        "Id": identity,
        "RepoTags": tags if tags is not None else [f"{PREFIX}{name}:src-{index}"],
        "Created": (NOW - timedelta(hours=age_hours)).isoformat(),
    }


class RetentionSafetyTest(unittest.TestCase):
    def test_keeps_two_latest_per_project_image_and_recent_generations(self):
        images = [image("employees", n, 12 + n * 24) for n in range(8)]
        result = RETENTION.plan(images, set(), NOW)
        self.assertEqual(set(result), {x["Id"] for x in images[2:]})
        self.assertEqual(len(result), 6)

    def test_never_deletes_image_referenced_by_running_or_stopped_container(self):
        images = [image("clients", n, 300 + n) for n in range(5)]
        protected = {images[4]["Id"]}
        result = RETENTION.plan(images, protected, NOW)
        self.assertNotIn(images[4]["Id"], result)
        self.assertEqual(len(result), 2)

    def test_preserves_unknown_foreign_untagged_or_multi_repository(self):
        images = [image("employees", n, 300 + n) for n in range(4)]
        foreign = image("postgres", 10, 300, ["postgres:16-alpine"])
        dangling = image("none", 11, 300, ["<none>:<none>"])
        shared = image("other", 12, 300, [f"{PREFIX}other:a", f"{PREFIX}clients:b"])
        mixed = image("mixed", 13, 300, [f"{PREFIX}mixed:a", "foreign/mixed:a"])
        result = RETENTION.plan(images + [foreign, dangling, shared, mixed], set(), NOW)
        self.assertEqual(result, sorted([images[2]["Id"], images[3]["Id"]]))

    def test_first_two_always_preserved_even_when_both_are_very_old(self):
        images = [image("timesheets", n, 24 * (n + 50)) for n in range(3)]
        result = RETENTION.plan(images, set(), NOW)
        self.assertEqual(result, [images[2]["Id"]])

    def test_newly_built_image_is_not_deleted_and_invalid_times_fail_closed(self):
        images = [image("web", n, hours) for n, hours in enumerate([1, 10, 30, 47, 48, 70])]
        self.assertEqual(RETENTION.plan(images, set(), NOW), sorted(i["Id"] for i in images[4:]))
        images[5]["Created"] = "invalid timestamp"
        with self.assertRaises(ValueError):
            RETENTION.plan(images, set(), NOW)

    def test_duplicate_tags_same_repo_and_empty_inventory(self):
        images = [image("finance", n, 100 + n) for n in range(4)]
        images[3]["RepoTags"] = [f"{PREFIX}finance:one", f"{PREFIX}finance:two"]
        self.assertEqual(RETENTION.plan(images, set(), NOW), [images[3]["Id"], images[2]["Id"]][::-1])
        self.assertEqual(RETENTION.plan([], set(), NOW), [])


if __name__ == "__main__":
    unittest.main()
