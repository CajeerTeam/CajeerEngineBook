#!/usr/bin/env python3
"""Real subprocess/qualification security regressions for production update release."""
import importlib.util
from pathlib import Path
import os
import re
import subprocess
import sys
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[1]

def load(name, file):
    spec = importlib.util.spec_from_file_location(name, ROOT / file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

agent = load('book_120_agent', 'bin/update-agent.py')
policy = load('book_120_policy', 'bin/qualification_policy.py')
qualify = load('book_120_qualification', 'tests/production-qualification.py')


class ProductionUpdate(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='book120-update-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.output = self.root / 'out.json'
        self.error = self.root / 'err.log'

    def run_child(self, code, timeout=10):
        return agent.run_bounded_updater([sys.executable, '-c', code], self.output, self.error, timeout)

    def test_signed_update_receipt_is_captured(self):
        self.assertEqual(self.run_child('import json; print(json.dumps({"status":"passed"}))'), 0)
        self.assertEqual(self.output.read_text().strip(), '{"status": "passed"}')
        self.assertEqual(self.error.read_bytes(), b'')
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o600)

    def test_bad_exit_code_propagates(self):
        self.assertEqual(self.run_child('import sys; sys.stderr.write("broken\\n");sys.exit(19)'), 19)
        self.assertIn('broken', self.error.read_text())

    def test_large_stdout_rejected_before_disk_exhaustion(self):
        with self.assertRaisesRegex(agent.Rejected, 'output exceeds safe limit'):
            self.run_child('import os; os.write(1, b"X" * 600000)')
        self.assertLessEqual(self.output.stat().st_size, agent.MAX_UPDATER_STDOUT)

    def test_large_stderr_rejected_before_disk_exhaustion(self):
        with self.assertRaisesRegex(agent.Rejected, 'output exceeds safe limit'):
            self.run_child('import os; os.write(2, b"Y" * 1500000)')
        self.assertLessEqual(self.error.stat().st_size, agent.MAX_UPDATER_STDERR)

    def test_silent_hanging_updater_timed_out(self):
        start = time.monotonic()
        with self.assertRaises(subprocess.TimeoutExpired):
            self.run_child('import time; time.sleep(30)', timeout=.25)
        self.assertLess(time.monotonic()-start, 6)

    def test_timeout_kills_descendant_retaining_stdout(self):
        child_pid = self.root / 'child.pid'
        code = ('import subprocess,sys; '
                'p=subprocess.Popen([sys.executable,"-c","import time; time.sleep(30)"]); '
                f'open({str(child_pid)!r},"w").write(str(p.pid))')
        with self.assertRaises(subprocess.TimeoutExpired):
            self.run_child(code, timeout=2.0)
        pid = int(child_pid.read_text())
        proc_stat = Path(f'/proc/{pid}/stat')
        # Reaping may be delayed by PID 1; zombie is dead and cannot run code.
        self.assertTrue(not proc_stat.exists() or proc_stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z')

    def test_timeout_escalates_to_kill_for_sigterm_resistant_descendant(self):
        child_pid = self.root / 'stubborn.pid'
        descendant = 'import signal,time; signal.signal(signal.SIGTERM,signal.SIG_IGN); time.sleep(30)'
        code = ('import subprocess,sys; '
                f'p=subprocess.Popen([sys.executable,"-c",{descendant!r}]); '
                f'open({str(child_pid)!r},"w").write(str(p.pid))')
        with self.assertRaises(subprocess.TimeoutExpired):
            self.run_child(code, timeout=2.0)
        pid = int(child_pid.read_text())
        proc_stat = Path(f'/proc/{pid}/stat')
        self.assertTrue(not proc_stat.exists() or proc_stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z')

    def test_concurrent_pipe_activity_does_not_deadlock(self):
        code = 'import os; [ (os.write(1,b"x"*2048),os.write(2,b"y"*2048)) for _ in range(50) ]'
        self.assertEqual(self.run_child(code), 0)
        self.assertEqual(self.output.stat().st_size, 102400)
        self.assertEqual(self.error.stat().st_size, 102400)

    def test_old_diagnostics_are_pruned_not_history(self):
        stub = object.__new__(agent.Agent)
        stub.dir = self.root
        for i in range(11):
            p = stub.dir / ('error-' + f'{i:032x}' + '.log')
            p.write_text('private diagnostic')
            os.utime(p, ns=(1_000_000_000+i, 1_000_000_000+i))
        (stub.dir / 'history.json').write_text('[]')
        stub.prune_diagnostics()
        self.assertEqual(len(list(self.root.glob('error-*.log'))), 8)
        self.assertTrue((stub.dir/'history.json').is_file())

    def test_full_source_fingerprint_uses_committed_file_set(self):
        source = self.root/'checkout'; source.mkdir()
        subprocess.run(['git','init','-q',str(source)],check=True)
        (source/'VERSION').write_text('1.2.0\n')
        (source/'compose.yaml').write_text('services: {}')
        (source/'bin').mkdir(); (source/'bin/update.py').write_text('code')
        subprocess.run(['git','add','.'],cwd=source,check=True)
        result = policy.source_digest_tree(source)
        self.assertEqual(result,policy.source_digest_entries([
            ('VERSION',b'1.2.0\n'),('compose.yaml',b'services: {}'),('bin/update.py',b'code')]))
        (source/'not-tracked.txt').write_text('new')
        self.assertEqual(result,policy.source_digest_tree(source))
        (source/'compose.yaml').write_text('services: {changed: true}')
        self.assertNotEqual(result,policy.source_digest_tree(source))

    def test_production_release_gates_are_executable_and_mandatory(self):
        workflow = (ROOT/'.github/workflows/ci.yml').read_text()
        sections = {name: re.split(r'\n  (?=[a-z][a-z-]*:)', workflow.split('  '+name+':', 1)[1], maxsplit=1)[0]
                    for name in ('test', 'security-scan', 'signed-release')}
        for name, body in sections.items():
            self.assertIn('tests/production-update-120.py', body, name)
            self.assertIn('tests/production-qualification-policy.py', body, name)
        release_gate = load('book_120_release_gate', 'tests/release-gate.py')
        self.assertIn('tests/production-update-120.py', release_gate.CRITICAL_REGRESSIONS)
        self.assertIn('tests/production-qualification-policy.py', release_gate.CRITICAL_REGRESSIONS)

    def test_qualification_source_and_certification_are_enforced(self):
        self.assertFalse((ROOT/'VERSION').read_text().strip() == '1.1.7')
        source = (ROOT/'bin/qualification_policy.py').read_text()
        self.assertIn("summary.get('full_qualification_verified') is True",source)
        coordinator = (ROOT/'tests/production-qualification.py').read_text()
        self.assertIn("state['full_qualification_verified']=True",coordinator)
        self.assertEqual(qualify.source_digest.__module__, qualify.__name__)


if __name__ == '__main__':
    unittest.main(verbosity=2)
