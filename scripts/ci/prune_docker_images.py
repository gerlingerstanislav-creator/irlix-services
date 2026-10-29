#!/usr/bin/env python3
"""Conservative production image retention. No container, volume or file deletion."""
import argparse
from collections import defaultdict
from datetime import datetime, timezone, timedelta
import json
import subprocess

PREFIX = "ghcr.io/gerlingerstanislav-creator/irlix-"
MIN_HOURS = 48
KEEP_PER_REPOSITORY = 2
MAX_OBJECTS = 2000


def run(*argv):
    return subprocess.check_output(["docker", *argv], text=True, timeout=90)


def created_at(image):
    value = image.get("Created")
    if not isinstance(value, str):
        raise ValueError("Missing image creation timestamp")
    timestamp = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if timestamp.tzinfo is None:
        raise ValueError("Image creation time has no timezone")
    return timestamp.astimezone(timezone.utc)


def plan(images, protected_ids, now):
    """Return eligible image IDs; allowlist project images and keep two per repo."""
    repositories = defaultdict(list)
    candidates = []
    for item in images:
        identity = item.get("Id")
        tags = item.get("RepoTags") or []
        if not isinstance(identity, str) or not identity.startswith("sha256:"):
            raise ValueError("Unexpected Docker image identity")
        if not isinstance(tags, list):
            raise ValueError("Invalid image tags")
        # Unknown, untagged, foreign or multi-repository images are preserved.
        if not tags or any(not isinstance(tag, str) or not tag.startswith(PREFIX)
                           or ":" not in tag[len(PREFIX):] for tag in tags):
            continue
        repo_names = {tag.rsplit(":", 1)[0] for tag in tags}
        # Shared multi-repository image: no safe single-service ownership.
        if len(repo_names) != 1:
            continue
        repository = next(iter(repo_names))
        date = created_at(item)
        repositories[repository].append((date, identity))
        if identity not in protected_ids and now - date >= timedelta(hours=MIN_HOURS) and date <= now:
            candidates.append((identity, repository))
    keep = set(protected_ids)
    for records in repositories.values():
        keep.update(identity for _, identity in sorted(records, reverse=True)[:KEEP_PER_REPOSITORY])
    return sorted(identity for identity, _ in candidates if identity not in keep)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    ids = list(dict.fromkeys(run("image", "ls", "-aq", "--no-trunc").splitlines()))
    if len(ids) > MAX_OBJECTS:
        raise RuntimeError("Image count exceeds inventory budget")
    images = []
    for offset in range(0, len(ids), 100):
        images.extend(json.loads(run("image", "inspect", *ids[offset:offset + 100])))
    if len(images) != len(ids):
        raise RuntimeError("Docker image inventory is incomplete")

    container_ids = run("container", "ls", "-aq", "--no-trunc").splitlines()
    if len(container_ids) > MAX_OBJECTS:
        raise RuntimeError("Container inventory budget exhausted")
    # Read only image IDs; do NOT access container environment/configuration.
    protected = set()
    for offset in range(0, len(container_ids), 100):
        rows = run("container", "inspect", "--format", "{{.Image}}",
                   *container_ids[offset:offset + 100])
        protected.update(rows.splitlines())

    now = datetime.now(timezone.utc)
    candidates = plan(images, protected, now)
    print(f"image_retention inspected={len(ids)} protected_by_containers={len(protected)} "
          f"eligible={len(candidates)} keep_per_repo={KEEP_PER_REPOSITORY} "
          f"min_age_hours={MIN_HOURS} dry_run={args.dry_run}", flush=True)
    if args.dry_run:
        return
    succeeded = 0
    rejected = 0
    for identity in candidates:
        # Docker refuses removal of images referenced by any container; never force.
        result = subprocess.run(["docker", "image", "rm", identity],
                                text=True, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=90, check=False)
        if result.returncode == 0:
            succeeded += 1
        else:
            rejected += 1
    print(f"image_retention removed={succeeded} preserved_or_failed={rejected}", flush=True)


if __name__ == "__main__":
    main()
