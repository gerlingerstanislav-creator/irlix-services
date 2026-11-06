"""Preflight selected content-addressed image tags before allocating build jobs.

The build-service action performs the same manifest check per runner. Moving
it into a bounded concurrent preflight avoids many redundant GitHub runners
on cumulative releases. Lookup failures always require a normal build.
"""
import concurrent.futures
import json
import os
import subprocess
import time


def available(image_ref):
    """Only an actual successful GHCR manifest lookup permits image reuse."""
    try:
        result = subprocess.run(
            ["docker", "manifest", "inspect", image_ref],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=35,
            check=False,
        )
        return result.returncode == 0
    except subprocess.TimeoutExpired:
        return False


def select(matrix, checker=available):
    images = [component for component in matrix.get("include", [])
              if not component.get("skip")]
    with concurrent.futures.ThreadPoolExecutor(max_workers=min(5, len(images) or 1)) as pool:
        found = list(pool.map(lambda item: checker(item["image_ref"]), images))
    missing = [image for image, exists in zip(images, found) if not exists]
    reused = [image for image, exists in zip(images, found) if exists]
    return {
        "selected": images,
        "reused": reused,
        "missing": missing,
        "build_matrix": {"include": missing or [{"id": "not-required", "skip": True}]},
        "needs_build": bool(missing),
    }


def main():
    started = time.monotonic()
    matrix = json.loads(os.environ["SELECTED_MATRIX"])
    plan = select(matrix)
    build_matrix = json.dumps(plan["build_matrix"], separators=(",", ":"))
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
            output.write(f"build_matrix={build_matrix}\n")
            output.write(f"needs_build={str(plan['needs_build']).lower()}\n")
    elapsed = round(time.monotonic() - started, 2)
    print(f"Verified GHCR image preflight: {len(plan['selected'])} selected, "
          f"{len(plan['reused'])} reusable, {len(plan['missing'])} require build "
          f"({elapsed}s)")
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as summary:
            summary.write("### GHCR verified image preflight\n")
            summary.write(f"- Selected: {len(plan['selected'])}\n")
            summary.write(f"- Reused: {len(plan['reused'])}\n")
            summary.write(f"- Builds required: {len(plan['missing'])}\n")
            summary.write(f"- Preflight: {elapsed}s\n")


if __name__ == "__main__":
    main()
