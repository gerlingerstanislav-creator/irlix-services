#!/usr/bin/env python3
"""Publishable image checks shared by development, main and manual builds."""
import json
import os
import subprocess
import sys


def main():
    c = json.loads(os.environ["COMPONENT_JSON"])
    if sys.argv[1] == "prepare":
        values = {"id": c["id"], "image_ref": c["image_ref"], "context": c["context"],
                  "file": f"{c['context']}/{c['dockerfile']}", "target": c.get("target", ""),
                  "build_args": "\n".join(f"{k}={v}" for k, v in c.get("build_args", {}).items())}
        with open(os.environ["GITHUB_OUTPUT"], "a") as f:
            for k, v in values.items():
                f.write(f"{k}<<IRLIX_OUTPUT\n{v}\nIRLIX_OUTPUT\n")
        return
    assert sys.argv[1] == "check"
    image = c["image_ref"]
    check = c.get("image_check")
    if check == "migration":
        subprocess.run(["docker", "run", "--rm", "-e", "DB_CONNECTION=sqlite", "-e", "DB_DATABASE=/data/migration.sqlite",
                        image, "php", "artisan", "migrate:status"], check=True)
    elif check == "cv":
        for args in (["python", "-m", "compileall", "-q", "app"], ["python", "-m", "app.render_smoke"]):
            subprocess.run(["docker", "run", "--rm", image, *args], check=True)
    elif check:
        raise ValueError(f"Unknown image check: {check}")


if __name__ == "__main__":
    main()
