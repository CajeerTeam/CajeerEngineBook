#!/usr/bin/env python3
"""Real GitHub publication admission policy and fail-closed unit regressions."""
from pathlib import Path
import importlib.util
import json
import os
import tempfile
import unittest
from unittest.mock import patch
from urllib.error import HTTPError, URLError
from io import BytesIO
from urllib.request import Request

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('release_github', ROOT/'bin/release-github.py')
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class TestGithubRelease(unittest.TestCase):
    REPO = 'CajeerTeam/CajeerEngineBook'
    VERSION = '1.1.1'

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for name in ('release.json','release.json.sig','source.zip','deployment.zip'):
            (self.root/name).write_bytes((name + '-content').encode())
        self.expected = mod.expected_files(self.root)
        self.info = {
            'tag_name': 'v1.1.1', 'draft': True, 'prerelease': False,
            'assets': [dict(name=name, state='uploaded', size=size, digest='sha256:'+digest)
                       for name, (size,digest) in self.expected.items()],
        }

    def test_authenticated_release_absent(self):
        def api(repo, path, token):
            return None if path.startswith('/releases/') else {'full_name': self.REPO}
        with patch.object(mod, 'api_json', side_effect=api):
            self.assertIsNone(mod.release_info(self.REPO,self.VERSION,'test-token'))

    def test_hidden_repo_is_not_absent(self):
        with patch.object(mod,'api_json', return_value=None):
            with self.assertRaisesRegex(mod.ReleaseError,'Cannot authenticate repository'):
                mod.release_info(self.REPO,self.VERSION,'test-token')

    def test_existing_draft_and_published_release_block_new_publish(self):
        for draft in (True,False):
            with self.subTest(draft=draft),patch.object(mod,'api_json',return_value={'draft':draft}):
                self.assertIsNotNone(mod.release_info(self.REPO,self.VERSION,'test-token'))

    def test_api_http_errors_are_not_treated_as_release_absence(self):
        class FailOpener:
            def __enter__(self): raise HTTPError('https://api.github.com',403,'Forbidden',{},BytesIO(b'credentials'))
            def __exit__(self,*_): pass
        with patch.object(mod,'build_opener') as opener:
            opener.return_value.open.return_value = FailOpener()
            with self.assertRaisesRegex(mod.ReleaseError,'HTTP 403') as ctx:
                mod.api_json(self.REPO,'/releases/tags/v1.1.1','secret-token')
        self.assertNotIn('secret-token',str(ctx.exception))
        with patch.object(mod,'build_opener') as opener:
            opener.return_value.open.side_effect = URLError('blocked')
            with self.assertRaisesRegex(mod.ReleaseError,'connectivity failure'):
                mod.api_json(self.REPO,'/releases/tags/v1.1.1','secret-token')

    def test_redirect_never_leaks_api_token(self):
        handler = mod.NoRedirect()
        with self.assertRaisesRegex(mod.ReleaseError, 'redirect refused'):
            handler.redirect_request(Request('https://api.github.com/', headers={'Authorization':'Bearer secret'}),
                                     None, 302, 'Found', {}, 'https://example.org/collect')

    def test_bounded_json_duplicate_keys(self):
        class FakeResponse:
            status=200
            def __enter__(self): return self
            def __exit__(self,*_): pass
            def read(self,n): return b'{"a":1,"a":2}'
        with patch.object(mod,'build_opener') as opener:
            opener.return_value.open.return_value = FakeResponse()
            with self.assertRaisesRegex(mod.ReleaseError,'Duplicate key'):
                mod.api_json(self.REPO,'/releases/tags/v1.1.1','secret-token')

    def test_valid_verified_draft_and_published_asset_inventory(self):
        mod.check_release(self.info,self.VERSION,self.expected,'draft')
        published = dict(self.info,draft=False)
        mod.check_release(published,self.VERSION,self.expected,'published')

    def test_fail_closed_remote_inventory(self):
        bad = []
        def altered(**kw): return dict(self.info,**kw)
        bad.extend([
            altered(tag_name='v1.1.0'),
            altered(draft=False),
            altered(prerelease=True),
            altered(assets=self.info['assets'][:-1]),
            altered(assets=self.info['assets'] + self.info['assets'][:1]),
            altered(assets=[dict(self.info['assets'][0], state='new')] + self.info['assets'][1:]),
            altered(assets=[dict(self.info['assets'][0], size=10_000)] + self.info['assets'][1:]),
            altered(assets=[dict(self.info['assets'][0], digest='sha256:' + '0'*64)] + self.info['assets'][1:]),
            altered(assets=[dict(self.info['assets'][0], name='unexpected.exe')] + self.info['assets'][1:]),
        ])
        for wrong in bad:
            with self.subTest(wrong=wrong),self.assertRaises(mod.ReleaseError):
                mod.check_release(wrong,self.VERSION,self.expected,'draft')

    def test_local_bundle_rejects_symlink(self):
        (self.root/'rogue').symlink_to(self.root/'source.zip')
        with self.assertRaisesRegex(mod.ReleaseError,'Unsafe'):
            mod.expected_files(self.root)

    def test_remote_digest_missing_allowed_only_with_byte_readback(self):
        # Older GH APIs may return null digest. The actual download+Ed25519
        # verification in release-publish.sh is mandatory in this case too.
        assets=[dict(a, digest=None) for a in self.info['assets']]
        mod.check_release(dict(self.info,assets=assets),self.VERSION,self.expected,'draft')
        publish=(ROOT/'bin/release-publish.sh').read_text()
        self.assertEqual(publish.count('gh release download "$tag"'),2)
        self.assertGreaterEqual(publish.count('bin/release-package.py verify'),3)
        self.assertIn('--state published',publish)
        self.assertIn('bin/release-github.py absent',publish)
        self.assertIn('--oci',publish)

    def test_release_gate_cannot_drop_publisher_regression(self):
        spec = importlib.util.spec_from_file_location('book_gate',ROOT/'tests/release-gate.py')
        gate = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(gate)
        self.assertIn('python3 tests/release-111-github.py',gate.regression_commands())
        with tempfile.TemporaryDirectory() as t:
            fake = Path(t)
            (fake/'tests').symlink_to(ROOT/'tests',target_is_directory=True)
            data = json.loads((ROOT/'composer.json').read_text())
            data['scripts']['test'].remove('python3 tests/release-111-github.py')
            (fake/'composer.json').write_text(json.dumps(data))
            with self.assertRaisesRegex(RuntimeError,'Missing mandatory release regressions'):
                gate.regression_commands(fake)

    def test_cli_fail_closed_without_token(self):
        with patch.dict(os.environ,{'GH_TOKEN':'','GITHUB_TOKEN':''}):
            self.assertEqual(mod.main(['absent','--repository',self.REPO,'--version',self.VERSION]),1)
        with patch.dict(os.environ,{'GH_TOKEN':'test-token'}):
            with patch.object(mod,'release_info', return_value=self.info):
                self.assertEqual(mod.main(['absent','--repository',self.REPO,'--version',self.VERSION]),1)


if __name__=='__main__':
    unittest.main(verbosity=2)
