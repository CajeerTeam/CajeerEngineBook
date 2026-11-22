#!/usr/bin/env python3
"""Release 1.1.0 regression-list and fail-closed evidence policy tests."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('book_release_gate', ROOT/'tests/release-gate.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class Release110(unittest.TestCase):
    def test_real_composer_suite_includes_every_critical_path(self):
        commands = gate.regression_commands()
        self.assertGreaterEqual(len(commands), 110)
        self.assertIn('@php tests/recovery-110.php', commands)
        self.assertIn('@php tests/editor-ai-114.php', commands)

    def fixture(self, directory):
        root = Path(directory)
        (root/'tests').symlink_to(ROOT/'tests', target_is_directory=True)
        (root/'composer.json').write_text((ROOT/'composer.json').read_text())
        return root

    def mutate(self, root, fn):
        path = root/'composer.json'
        payload = json.loads(path.read_text())
        fn(payload['scripts']['test'])
        path.write_text(json.dumps(payload))

    def test_missing_recovery_must_fail_entire_suite(self):
        with tempfile.TemporaryDirectory() as d:
            root = self.fixture(d)
            self.mutate(root, lambda commands: commands.remove('@php tests/recovery-110.php'))
            with self.assertRaisesRegex(RuntimeError, 'Missing mandatory release regressions'):
                gate.regression_commands(root)

    def test_missing_authorization_must_fail_entire_suite(self):
        with tempfile.TemporaryDirectory() as d:
            root = self.fixture(d)
            self.mutate(root, lambda commands: commands.remove('@php tests/permissions.php'))
            with self.assertRaisesRegex(RuntimeError, 'Missing mandatory release regressions'):
                gate.regression_commands(root)

    def test_duplicate_or_unavailable_test_must_fail_entire_suite(self):
        with tempfile.TemporaryDirectory() as d:
            root = self.fixture(d)
            self.mutate(root, lambda commands: commands.append('@php tests/recovery-110.php'))
            with self.assertRaisesRegex(RuntimeError, 'Duplicate Composer regression'):
                gate.regression_commands(root)
            self.mutate(root, lambda commands: commands.append('@php tests/missing-110.php'))
            with self.assertRaises(RuntimeError):
                gate.regression_commands(root)

    def test_security_runner_bootstraps_dependencies_and_retests_recovery(self):
        yml = (ROOT/'.github/workflows/ci.yml').read_text()
        job = yml.split('  security-scan:',1)[1].split('  production-qualification:',1)[0]
        self.assertIn('composer install --no-interaction', job)
        self.assertLess(job.index('composer install'), job.index('Independent regression execution'))
        self.assertIn('php tests/recovery-110.php', job)
        self.assertIn('tests/security-scan.sh', job)
        self.assertIn('if: always()', job)

    def test_version_schema_and_critical_sources_exist(self):
        self.assertEqual((ROOT/'VERSION').read_text().strip(),'1.1.0')
        self.assertIn('public const VERSION = 7;', (ROOT/'src/DatabaseMigrations.php').read_text())
        for source in ('src/RecoveryManager.php','src/InstallationBackup.php',
                       'src/AnalyticsBackup.php','tests/performance-benchmark.py',
                       'tests/security-scan.sh','tests/production-qualification.py'):
            self.assertTrue((ROOT/source).is_file(), source)


if __name__ == '__main__':
    unittest.main(verbosity=2)
