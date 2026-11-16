#!/usr/bin/env python3
"""Additional adversarial RC tests using real ZIPs, Ed25519 signatures and PHP runtime.
No production credentials, network dependency, or external audit claim.
"""
import importlib.util
import io
from pathlib import Path
import subprocess
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[1]

def load(name, file):
    spec = importlib.util.spec_from_file_location(name, ROOT/file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

u = load('update_retest', 'bin/update.py')
signing = load('signing_retest', 'tests/release-signing.py')


def archive(names, compression=zipfile.ZIP_DEFLATED):
    out = io.BytesIO()
    with zipfile.ZipFile(out, 'w') as z:
        for name, body in names:
            info = zipfile.ZipInfo(name)
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            z.writestr(info, body, compress_type=compression)
    return out.getvalue()


class Retest(unittest.TestCase):
    def inspect(self, data):
        with zipfile.ZipFile(io.BytesIO(data)) as z:
            return u.zip_members(z)

    def test_valid_archive_and_case_collisions(self):
        self.assertEqual(len(self.inspect(archive([('src/a', b'x'), ('src/b', b'y')]))), 2)
        for names in [('a', 'a/b'), ('a/b', 'a'), ('src/a', 'SRC/b'), ('src/a', 'src/A')]:
            with self.subTest(names=names), self.assertRaises(ValueError):
                self.inspect(archive([(n, b'x') for n in names]))

    def test_nul_and_del_names(self):
        # Patch both the local and central directory name; zipfile normally truncates NUL.
        data = archive([('badXname', b'x')]).replace(b'badXname', b'bad\x00name')
        with self.assertRaisesRegex(ValueError, 'Ambiguous ZIP'):
            self.inspect(data)
        with self.assertRaises(ValueError):
            self.inspect(archive([('bad\x7fname', b'x')]))

    def test_expansion_budget_and_compression(self):
        data = archive([('a', b'x'*800), ('b', b'y'*800)])
        with mock.patch.object(u.release, 'MAX_UNPACK', 1024), self.assertRaisesRegex(ValueError, 'expands'):
            self.inspect(data)
        with self.assertRaises(ValueError):
            self.inspect(archive([('a', b'x')], zipfile.ZIP_BZIP2))

    def test_signed_bundle_authentication_before_zip_parsing(self):
        fixture = signing.Signing('test_real_package_and_reproducibility')
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        fixture.assemble()
        u.verify_bundle(fixture.out, fixture.pub, fixture.repo, fixture.version, fixture.commit)
        path = fixture.out/'source.zip'
        original = path.read_bytes()
        path.write_bytes(b'X'*len(original))
        # If ZIP parsing happened first, BadZipFile would escape instead of artifact rejection.
        with self.assertRaisesRegex(ValueError, 'Artifact mismatch: source.zip'):
            u.verify_bundle(fixture.out, fixture.pub, fixture.repo, fixture.version, fixture.commit)
        path.write_bytes(original)
        signature = fixture.out/'source.zip.sig'
        sig = signature.read_bytes()
        signature.write_bytes(bytes([sig[0]^1])+sig[1:])
        with self.assertRaises(subprocess.CalledProcessError):
            u.verify_bundle(fixture.out, fixture.pub, fixture.repo, fixture.version, fixture.commit)

    def test_outbound_header_duplicate_before_socket(self):
        php = r'''require 'tests/oidc-bootstrap.php';
        try { CajeerBook\ApiPlaygroundTransport::integration('https://127.0.0.1:1/','GET',['X-Test'=>'a','x-test'=>'b'],'',1024,1,false); }
        catch (InvalidArgumentException $e) { if ($e->getMessage()==='Invalid outbound API header.') exit(0); }
        catch (Throwable $e) { fwrite(STDERR,$e->getMessage()); } exit(1);'''
        subprocess.run(['php', '-r', php], cwd=ROOT, check=True, timeout=5)

    def test_special_ip_policy(self):
        php = r'''require 'tests/oidc-bootstrap.php';
        foreach (['127.0.0.1','169.254.169.254','100.64.0.1','192.88.99.1','224.0.0.1','198.18.0.1'] as $ip)
            if (CajeerBook\ApiPlaygroundTransport::publicIp($ip)) exit(1);
        exit(CajeerBook\ApiPlaygroundTransport::publicIp('8.8.8.8')?0:1);'''
        subprocess.run(['php', '-r', php], cwd=ROOT, check=True, timeout=5)


if __name__ == '__main__':
    unittest.main(verbosity=2)
