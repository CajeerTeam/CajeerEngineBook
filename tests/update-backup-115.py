#!/usr/bin/env python3
"""Executable backup/restore acceptance tests without live Docker or PostgreSQL."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('cajeer_update_115', ROOT/'bin/update.py')
u = importlib.util.module_from_spec(spec); spec.loader.exec_module(u)
IDENTIFIER = '20261122T120000Z-' + 'a'*16


class BackupIntegrity(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='book-backup115-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root/'backups').mkdir(mode=0o700)
        self.ledger = u.BackupLedger(self.root)
        self.directory = self.root/'backups'/IDENTIFIER
        (self.directory/'portable').mkdir(parents=True)
        (self.directory/'portable'/'manifest.json').write_text('{"files":{}}')
        (self.directory/'portable'/'identity.json').write_text('{"schema":4}')
        (self.directory/'database.dump').write_bytes(b'postgresql-custom-format-fixture')

    def test_root_owned_receipt_and_hashes_are_verified(self):
        digest = self.ledger.capture(IDENTIFIER, True)
        result = self.ledger.verify(IDENTIFIER, digest)
        self.assertEqual(result['files'], 3)
        self.assertTrue(result['postgresql'])
        self.assertEqual(len(digest), 64)
        receipt = self.root/'backup-receipts'/(IDENTIFIER+'.json')
        self.assertEqual(receipt.stat().st_mode & 0o777, 0o600)
        self.assertFalse(receipt.is_relative_to(self.directory))

    def test_content_and_manifest_tampering_fail_closed(self):
        digest = self.ledger.capture(IDENTIFIER, True)
        target = self.directory/'database.dump'
        target.write_bytes(b'X' * target.stat().st_size)
        with self.assertRaisesRegex(ValueError, 'modified'): self.ledger.verify(IDENTIFIER, digest)
        target.write_bytes(b'postgresql-custom-format-fixture')
        (self.directory/'portable'/'identity.json').unlink()
        with self.assertRaisesRegex(ValueError, 'modified'): self.ledger.verify(IDENTIFIER, digest)

    def test_unexpected_symlink_and_extra_file_are_rejected(self):
        digest = self.ledger.capture(IDENTIFIER, True)
        (self.directory/'portable'/'hidden').write_bytes(b'not attested')
        with self.assertRaisesRegex(ValueError, 'modified'): self.ledger.verify(IDENTIFIER, digest)
        (self.directory/'portable'/'hidden').unlink()
        (self.directory/'portable'/'hidden').symlink_to('/etc/passwd')
        with self.assertRaisesRegex(ValueError, 'type'): self.ledger.verify(IDENTIFIER, digest)

    def test_receipt_change_is_rejected_against_journal(self):
        digest = self.ledger.capture(IDENTIFIER, True)
        receipt = self.root/'backup-receipts'/(IDENTIFIER+'.json')
        data = json.loads(receipt.read_text()); data['created_at'] += 1
        receipt.write_text(json.dumps(data)); receipt.chmod(0o600)
        with self.assertRaisesRegex(ValueError, 'durable update journal'): self.ledger.verify(IDENTIFIER, digest)

    def test_missing_dump_and_oversized_tree_fail_before_attestation(self):
        (self.directory/'database.dump').unlink()
        with self.assertRaisesRegex(ValueError, 'dump mismatch'): self.ledger.capture(IDENTIFIER, True)
        with mock.patch.object(u, 'MAX_BACKUP_BYTES', 2):
            with self.assertRaisesRegex(ValueError, 'size limit'): self.ledger.capture(IDENTIFIER, False)

    def test_recovery_denies_damaged_backup_before_touching_containers(self):
        manager = u.Manager(self.root/'updater')
        folder = manager.root/'backups'/IDENTIFIER
        (folder/'portable').mkdir(parents=True)
        (folder/'portable'/'manifest.json').write_text('{}')
        digest = manager.backup_ledger.capture(IDENTIFIER, False)
        (folder/'portable'/'manifest.json').write_text('changed')
        tx = {'phase':'activating', 'backup_complete':True,
              'backup':IDENTIFIER, 'backup_receipt':digest}
        with mock.patch.object(manager, 'load', side_effect=AssertionError('unsafe Docker access')):
            with self.assertRaisesRegex(ValueError, 'modified'):
                manager.recover({'pending':tx}, True)

    def test_recovery_denies_missing_receipt_after_completed_backup(self):
        manager = u.Manager(self.root/'updater')
        tx = {'phase':'migration','backup_complete':True,'backup':IDENTIFIER}
        with self.assertRaisesRegex(ValueError, 'no trusted receipt'):
            manager.recover({'pending':tx}, False)

    def test_postgres_dump_is_fully_scanned_and_portable_restore_audited(self):
        fake_tree = self.root/'tree'; fake_tree.mkdir()
        (fake_tree/'compose.yaml').touch()
        (self.root/'env').touch()
        compose = u.Compose(fake_tree, {'project':'test','env':str(self.root/'env'),'overlays':[]})
        commands = []
        def one(*args, mount=None, **kwargs):
            commands.append(('one',args))
            if args[0]=='php' and 'backup' in args:
                (mount/'portable').mkdir()
                (mount/'portable'/'manifest.json').write_text('{}')
            return b''
        def call(*args, **kwargs):
            commands.append(('call',args))
            if kwargs.get('stdout') is not None: kwargs['stdout'].write(b'fixture pgdump')
            return b''
        compose.one = one; compose.call = call
        with mock.patch.object(u.os,'chown'):
            compose.backup(self.directory.parent/'new-backup', True)
        self.assertTrue(any('php bin/installation.php check' in str(a) for kind,a in commands if kind=='one'))
        self.assertTrue(any('--list' in args for kind,args in commands if kind=='call'))
        self.assertTrue(any('--file=/dev/null' in args for kind,args in commands if kind=='call'))


if __name__ == '__main__': unittest.main(verbosity=2)
