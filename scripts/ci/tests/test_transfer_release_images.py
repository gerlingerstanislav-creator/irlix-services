"""Offline regression tests for the production GHCR -> SSH batch loader.

Only synthetic image refs and mocked ssh/docker binaries are used. No network,
production host, or credentials are required.
"""
import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


SCRIPT = Path(__file__).resolve().parents[1] / "transfer-release-images.sh"


class TransferReleaseImagesTests(unittest.TestCase):
    def test_shell_syntax(self):
        subprocess.run(["bash", "-n", str(SCRIPT)], check=True)

    def run_loader(self, fail_load=False):
        refs = [
            "ghcr.io/test/a:src-a",
            "ghcr.io/test/b:src-b",
            "ghcr.io/test/c:src-c",
            "ghcr.io/test/d:src-d",
        ]
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            bin_dir = root / "bin"
            bin_dir.mkdir()
            (root / "refs").write_text("\n".join(refs) + "\n")
            # One tag already exists remotely; only three must transfer.
            (root / "remote-tags").write_text(refs[0] + "\n")
            (root / "trace").write_text("")
            for name, source in {
                "python3": r'''
                    #!/usr/bin/env bash
                    if [[ "$*" == "scripts/ci/service_plan.py image-refs" ]]; then
                      cat "$TEST_REFS"
                    else
                      exec /usr/bin/python3 "$@"
                    fi
                ''',
                "docker": r'''
                    #!/usr/bin/env bash
                    case "$1" in
                      login) exit 0 ;;
                      pull) printf 'pull %s\n' "$2" >> "$TEST_TRACE" ;;
                      save)
                        shift
                        printf 'save %s\n' "$*" >> "$TEST_TRACE"
                        printf '%s\n' "$@"
                        ;;
                      image) printf 'remove %s\n' "$3" >> "$TEST_TRACE" ;;
                      *) echo "unexpected fake docker invocation: $*" >&2; exit 1 ;;
                    esac
                ''',
                "ssh": r'''
                    #!/usr/bin/env bash
                    args="$*"
                    if [[ "$args" == *"docker image inspect"* ]]; then
                      image=$(printf '%s\n' "$args" | sed -E "s/.*docker image inspect '([^']+)'.*/\1/")
                      grep -Fqx "$image" "$TEST_REMOTE_TAGS"
                    elif [[ "$args" == *"gzip -dc |"* ]]; then
                      if [[ "$TEST_FAIL_LOAD" == 1 ]]; then exit 7; fi
                      gzip -dc >> "$TEST_REMOTE_TAGS"
                      printf 'load\n' >> "$TEST_TRACE"
                    else
                      # Initial remote free-space preflight is safe in this stub.
                      exit 0
                    fi
                ''',
            }.items():
                target = bin_dir / name
                target.write_text(textwrap.dedent(source).lstrip())
                target.chmod(0o755)

            env = os.environ.copy()
            env.update({
                "PATH": f"{bin_dir}:{env.get('PATH', '')}",
                "HOME": str(root),
                "DEPLOY_HOST": "example.invalid",
                "DEPLOY_PORT": "22",
                "DEPLOY_USER": "ci",
                "GHCR_USER": "ci",
                "GHCR_TOKEN": "synthetic_only",
                "IRLIX_TRANSFER_BATCH_SIZE": "2",
                "GITHUB_STEP_SUMMARY": str(root / "summary"),
                "TEST_REFS": str(root / "refs"),
                "TEST_REMOTE_TAGS": str(root / "remote-tags"),
                "TEST_TRACE": str(root / "trace"),
                "TEST_FAIL_LOAD": "1" if fail_load else "0",
            })
            result = subprocess.run(
                ["bash", str(SCRIPT)], cwd=SCRIPT.parents[2], env=env,
                text=True, capture_output=True, timeout=20,
            )
            return result, (root / "trace").read_text(), (root / "remote-tags").read_text(), (
                (root / "summary").read_text() if (root / "summary").exists() else ""
            )

    def test_three_missing_images_share_two_ssh_batches(self):
        result, trace, remote, summary = self.run_loader()
        self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
        self.assertEqual(trace.count("save "), 2, trace)
        self.assertEqual(trace.count("load"), 2, trace)
        self.assertEqual(trace.count("pull "), 3, trace)
        self.assertEqual(trace.count("remove "), 3, trace)
        self.assertIn("ghcr.io/test/a:src-a", remote)
        self.assertIn("ghcr.io/test/d:src-d", remote)
        self.assertIn("- Already on server: 1", summary)
        self.assertIn("- Transferred: 3", summary)
        self.assertIn("- SSH batches: 2", summary)

    def test_failed_remote_load_is_not_reported_as_success(self):
        result, trace, _, summary = self.run_loader(fail_load=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("Transferred and verified", result.stdout)
        self.assertNotIn("Production image delivery", summary)


if __name__ == "__main__":
    unittest.main()
