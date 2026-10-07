import copy
import importlib.util
import os
from pathlib import Path
import unittest
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[1] / "service_plan.py"
spec = importlib.util.spec_from_file_location("service_plan", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class PlannerTests(unittest.TestCase):
    def setUp(self):
        self.data = p.registry()
        self.config = {"services": {}}
        for c in self.data["components"]:
            if c["kind"] == "backend":
                build = {"context": str(p.ROOT / c["paths"][0])}
            else:
                build = {"context": str(p.ROOT), "dockerfile": c["paths"][0] + "Dockerfile"}
            for name in [c["service"], *c.get("aliases", [])]:
                self.config["services"][name] = {"build": build, "image": f"ghcr.io/gerlingerstanislav-creator/{c['image']}:local"}
        self.config["services"].update({"keycloak": {"image": "keycloak:pinned"}, "cv-llm": {"image": "llama:pinned"}, "postgres": {"image": "postgres:pinned"}})

    def plan(self, paths, current=None, previous=None, full=False):
        return p.make_plan(self.data, current or self.config, previous or self.config, paths, full=full,
                           hash_image=lambda c, spec, ref: "src-" + "a" * 40)

    def ids(self, plan):
        return {c["id"] for c in plan["images"]}

    def test_frontend_only_does_not_build_restart_or_migrate_backend(self):
        plan = self.plan(["apps/clients/src/App.vue"])
        self.assertEqual(self.ids(plan), {"clients-web"})
        self.assertEqual(plan["services"], ["clients-web"])
        self.assertFalse(plan["schema"])
        self.assertFalse(plan["auth"])

    def test_shared_ui_only_rebuilds_registered_frontend_consumers(self):
        plan = self.plan(["packages/ui/src/serviceCatalog.js"])
        self.assertEqual(self.ids(plan), {c["id"] for c in self.data["components"] if c["kind"] == "frontend"})
        self.assertFalse(plan["schema"])
        self.assertFalse(plan["cv_check"])

    def test_browser_auth_change_does_not_bootstrap_keycloak(self):
        plan = self.plan(["packages/auth/src/index.js"])
        self.assertNotIn("design-system", self.ids(plan))
        self.assertFalse(plan["auth"])

    def test_backend_aliases_share_one_build(self):
        plan = self.plan(["services/employees/routes/api.php"])
        self.assertEqual(self.ids(plan), {"employees"})
        self.assertEqual(plan["services"], ["employees", "employees-events"])
        self.assertEqual(plan["migrations"], ["employees"])

    def test_diagnostics_do_not_trigger_release(self):
        plan = self.plan(["scripts/server-capacity.sh", ".github/workflows/capacity-report.yml"])
        self.assertFalse(plan["deploy"])
        self.assertEqual(plan["images"], [])

    def test_runtime_env_change_only_updates_affected_service(self):
        current = copy.deepcopy(self.config)
        current["services"]["migration"]["environment"] = {"APP_KEY": "example"}
        current["services"]["migration-worker"]["environment"] = {"APP_KEY": "example"}
        plan = self.plan([".env.example", "docker-compose.migration.yml"], current=current)
        self.assertEqual(self.ids(plan), set())
        self.assertEqual(plan["services"], ["migration", "migration-worker"])
        self.assertFalse(plan["cv_check"])

    def test_compose_build_definition_triggers_only_its_image(self):
        current = copy.deepcopy(self.config)
        current["services"]["cv-converter"]["build"]["args"] = {"VERSION": "2"}
        plan = self.plan(["docker-compose.cv.yml"], current=current)
        self.assertEqual(self.ids(plan), {"cv-converter"})
        self.assertTrue(plan["cv_check"])

    def test_model_change_requires_readiness_without_image_rebuild(self):
        current = copy.deepcopy(self.config)
        current["services"]["cv-llm"]["command"] = ["model-v2"]
        plan = self.plan(["docker-compose.cv.yml"], current=current)
        self.assertEqual(plan["images"], [])
        self.assertEqual(plan["services"], ["cv-llm"])
        self.assertTrue(plan["cv_check"])

    def test_nginx_is_configuration_only(self):
        plan = self.plan(["infra/nginx/irlix-services.conf"])
        self.assertTrue(plan["deploy"])
        self.assertTrue(plan["routing"])
        self.assertEqual(plan["images"], [])

    def test_docs_never_trigger_build(self):
        plan = self.plan(["services/clients/README.md", "docs/DEPLOYMENT.md"])
        self.assertFalse(plan["deploy"])

    def test_explicit_full_selects_all_images_and_workers(self):
        plan = self.plan([], full=True)
        self.assertEqual(len(plan["images"]), len(self.data["components"]))
        self.assertIn("migration-worker", plan["services"])
        self.assertTrue(plan["auth"])

    def test_new_component_automatically_joins_existing_pipeline(self):
        c = {"id": "new-service", "kind": "backend", "service": "new-service", "image": "irlix-new", "tag_env": "NEW_IMAGE_TAG", "paths": ["services/new-service/"], "health_path": "/api/new/health"}
        self.data["components"].append(c)
        current = copy.deepcopy(self.config)
        current["services"]["new-service"] = {"image": "ghcr.io/gerlingerstanislav-creator/irlix-new:local", "build": {"context": str(p.ROOT / "services/new-service")}}
        plan = self.plan(["infra/ci/services.json", "docker-compose.images.yml"], current=current)
        self.assertEqual(self.ids(plan), {"new-service"})
        self.assertEqual(plan["services"], ["new-service"])

    def test_unregistered_compose_service_is_rejected(self):
        self.config["services"]["unknown"] = {"build": {"context": str(p.ROOT / "services/unknown")}}
        with self.assertRaisesRegex(AssertionError, "Register new build services"):
            p.validate(self.data, self.config)

    def test_shared_image_checks_change_invalidates_all_components(self):
        plan = self.plan(["scripts/ci/image_check.py"])
        self.assertEqual(len(plan["images"]), len(self.data["components"]))

    def test_fingerprint_depends_on_build_options_and_source_but_not_commit_sha(self):
        c = next(c for c in self.data["components"] if c["id"] == "clients")
        spec = self.config["services"]["clients"]
        first = p.fingerprint(c, spec)
        second = p.fingerprint(c, spec, "HEAD")
        self.assertEqual(first, second)
        changed = copy.deepcopy(spec)
        changed["build"]["args"] = {"OPTION": "different"}
        self.assertNotEqual(first, p.fingerprint(c, changed))

    @patch.dict(os.environ, {
        "GITHUB_REF_NAME": "main",
        "GITHUB_REPOSITORY": "example/repo",
        "GITHUB_SHA": "current",
        "GH_TOKEN": "token",
    }, clear=False)
    def test_main_baseline_uses_latest_successful_deploy_even_if_workflow_failed_later(self):
        runs = {
            "workflow_runs": [
                {"head_sha": "current", "jobs_url": "jobs-current"},
                {"head_sha": "deployed-but-red", "jobs_url": "jobs-red"},
                {"head_sha": "older-green", "jobs_url": "jobs-green"},
            ]
        }

        def fake_json(url):
            if "/runs?" in url:
                self.assertNotIn("status=success", url)
                self.assertIn("/actions/runs?", url)
                return runs
            if url == "jobs-red":
                return {"jobs": [{"name": "Pull and deploy affected components", "conclusion": "success"},
                                  {"name": "Bootstrap authentication and verify stand", "conclusion": "failure"}]}
            if url == "jobs-green":
                return {"jobs": [{"name": "Pull and deploy affected components", "conclusion": "success"}]}
            return {"jobs": []}

        with patch.object(p, "github_json", side_effect=fake_json), patch.object(p, "is_ancestor", return_value=True):
            self.assertEqual(p.previous_success(), "deployed-but-red")

    @patch.dict(os.environ, {
        "GITHUB_REF_NAME": "main",
        "GITHUB_REPOSITORY": "example/repo",
        "GITHUB_SHA": "current",
        "GH_TOKEN": "token",
    }, clear=False)
    def test_main_baseline_skips_failed_deploy(self):
        runs = {
            "workflow_runs": [
                {"head_sha": "failed-deploy", "jobs_url": "jobs-failed"},
                {"head_sha": "last-deployed", "jobs_url": "jobs-success"},
            ]
        }

        def fake_json(url):
            if "/runs?" in url:
                return runs
            if url == "jobs-failed":
                return {"jobs": [{"name": "Pull and deploy affected components", "conclusion": "failure"}]}
            if url == "jobs-success":
                return {"jobs": [{"name": "Pull and deploy affected components", "conclusion": "success"}]}
            return {"jobs": []}

        with patch.object(p, "github_json", side_effect=fake_json), patch.object(p, "is_ancestor", return_value=True):
            self.assertEqual(p.previous_success(), "last-deployed")


    @patch.dict(os.environ, {"GITHUB_REF_NAME": "main", "GITHUB_REPOSITORY": "example/repo", "GITHUB_SHA": "current", "GH_TOKEN": "token"}, clear=False)
    def test_main_baseline_excludes_notifications_and_fails_closed(self):
        runs = {"workflow_runs": [
            {"head_sha": "notification", "path": ".github/workflows/notify.yml", "conclusion": "success", "jobs_url": "jobs-notify"},
            {"head_sha": "previous-success", "path": ".github/workflows/ci.yml", "conclusion": "success", "jobs_url": "jobs-missing"},
        ]}
        def fake_json(url):
            if "/actions/runs?" in url: return runs
            self.assertNotEqual(url, "jobs-notify")
            return {"jobs": []}
        with patch.object(p, "github_json", side_effect=fake_json), patch.object(p, "is_ancestor", return_value=True):
            with self.assertRaisesRegex(RuntimeError, "implicit full"):
                p.previous_success()


if __name__ == "__main__":
    unittest.main()
