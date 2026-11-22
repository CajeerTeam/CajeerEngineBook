#!/usr/bin/env python3
"""Release 1.1.2: actual Ed25519 manifest verification and GitHub REST failure modes."""
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from email.message import Message
from unittest.mock import patch
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[1]

def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m

d = module('book_discovery_test', ROOT/'bin/update-discovery.py')
u = module('book_update_test', ROOT/'bin/update.py')
REPO = 'CajeerTeam/CajeerEngineBook'


class Response:
    def __init__(self, value, etag='"v1"'):
        self.raw = io.BytesIO(json.dumps(value).encode())
        self.status = 200
        self.headers = Message()
        self.headers['ETag'] = etag
    def __enter__(self): return self
    def __exit__(self, *_): return False
    def read(self, cap): return self.raw.read(cap)


class Opener:
    def __init__(self, *outcomes):
        self.calls = []
        self.outcomes = list(outcomes)
    def open(self, req, timeout):
        self.calls.append(req)
        value = self.outcomes.pop(0)
        if isinstance(value, Exception): raise value
        return value


def error(code, headers=None):
    h = Message()
    for k, v in (headers or {}).items(): h[k] = v
    return HTTPError('https://api.github.com/', code, 'error', h, io.BytesIO(b'private data'))


class TestGithubAPI(unittest.TestCase):
    def test_live_page_etag_and_304(self):
        opener = Opener(Response([{'tag_name': 'v1.1.2'}]), error(304))
        api = d.GitHubAPI(opener=opener)
        rows, cached = api.get_page(REPO, 1)
        self.assertEqual(rows[0]['tag_name'], 'v1.1.2')
        self.assertEqual(cached['etag'], '"v1"')
        again, _ = api.get_page(REPO, 1, cached)
        self.assertEqual(rows, again)
        self.assertIn('If-none-match', opener.calls[1].headers)
        self.assertEqual(opener.calls[1].get_header('If-none-match'), '"v1"')
        self.assertEqual(opener.calls[0].full_url,
                         'https://api.github.com/repos/CajeerTeam/CajeerEngineBook/releases?per_page=100&page=1')

    def test_refresh_forces_new_page(self):
        opener = Opener(Response([]))
        d.GitHubAPI(opener=opener).get_page(REPO, 1, {'etag':'"old"','items':[]}, refresh=True)
        self.assertIsNone(opener.calls[0].get_header('If-none-match'))

    def test_304_without_previous_data_fails(self):
        with self.assertRaisesRegex(d.DiscoveryError, 'without cached'):
            d.GitHubAPI(opener=Opener(error(304))).get_page(REPO, 1)

    def test_404_is_error_not_up_to_date(self):
        with self.assertRaisesRegex(d.DiscoveryError, '404'):
            d.GitHubAPI(opener=Opener(error(404))).get_page(REPO, 1)

    def test_rate_limit_has_no_body_leakage(self):
        with self.assertRaisesRegex(d.DiscoveryError, 'retry after 21s') as result:
            d.GitHubAPI(opener=Opener(error(403, {'X-RateLimit-Remaining':'0', 'Retry-After':'21'}))).get_page(REPO, 1)
        self.assertNotIn('private', str(result.exception))

    def test_json_duplicates_nan_and_large_response_fail(self):
        class RawResponse(Response):
            def __init__(self, raw):
                self.raw = io.BytesIO(raw)
                self.status = 200
                self.headers = Message()
        for raw in (b'[{"x":1,"x":2}]', b'[{"x":NaN}]', b'{' + b' ' * (d.MAX_RESPONSE + 1)):
            with self.subTest(raw=raw[:20]):
                with self.assertRaises((d.DiscoveryError, ValueError, json.JSONDecodeError)):
                    d.GitHubAPI(opener=Opener(RawResponse(raw))).get_page(REPO, 1)

    def test_redirect_blocked_even_with_token(self):
        with self.assertRaisesRegex(d.DiscoveryError, 'redirect'):
            d.NoRedirect().redirect_request(None, None, 302, 'redirect', Message(),
                                            'https://attacker.example/steal')
        opener = Opener(Response([]))
        d.GitHubAPI(token='secret', opener=opener).get_page(REPO, 1)
        self.assertEqual(opener.calls[0].get_header('Authorization'), 'Bearer secret')

    def test_cache_is_atomic_private_and_repository_scoped(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            c = {'format':1, 'repository':REPO, 'pages':{'1':{'etag':'"test"', 'items':[]}}}
            d.cache_save(root, c)
            self.assertEqual(d.cache_load(root, REPO), c)
            self.assertEqual((root/'github-discovery-cache.json').stat().st_mode & 0o777, 0o600)
            with self.assertRaisesRegex(d.DiscoveryError, 'mismatched'):
                d.cache_load(root, 'Other/Repo')
            path = root/'github-discovery-cache.json'
            path.unlink()
            path.symlink_to(root/'absent')
            with self.assertRaisesRegex(d.DiscoveryError, 'Unsafe'):
                d.cache_save(root, c)

    def test_bounded_pagination_marks_truncated_results(self):
        page = [{'tag_name':'v1.0.0'}] * d.PER_PAGE
        opener = Opener(Response(page), Response(page), Response(page))
        rows, cache = d.GitHubAPI(opener=opener).list_releases(REPO)
        self.assertEqual(len(rows), d.PER_PAGE * d.MAX_PAGES)
        self.assertTrue(cache['truncated'])
        self.assertEqual(len(opener.calls), d.MAX_PAGES)

    def test_semver_comparison_and_prerelease_validation(self):
        self.assertLess(d.semantic('1.1.2-beta.2'), d.semantic('1.1.2-beta.10'))
        self.assertLess(d.semantic('1.1.2-beta.10'), d.semantic('1.1.2'))
        self.assertLess(d.semantic('1.1.2'), d.semantic('1.2.0'))
        with self.assertRaises(d.DiscoveryError): d.semantic('1.1.2-rc.01')
        with self.assertRaises(d.DiscoveryError): d.semantic('v1.1.2')


class Fixture:
    def __init__(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.key = self.root/'sign.pem'
        self.pub = self.root/'pub.pem'
        subprocess.run(['openssl','genpkey','-algorithm','Ed25519','-out',str(self.key)], check=True, stdout=subprocess.DEVNULL)
        subprocess.run(['openssl','pkey','-in',str(self.key),'-pubout','-out',str(self.pub)], check=True, stdout=subprocess.DEVNULL)
        self.calls = []

    def close(self): self.tmp.cleanup()

    def release(self, v='1.1.2', schema=7, corrupt_sig=False):
        names = d.release.files_for(v)
        artifacts = {name:{'size': 64 if name.endswith('.sig') else 37,
                           'sha256': hashlib.sha256(name.encode()).hexdigest()} for name in names}
        manifest = {'format':1, 'product':'CajeerEngineBook','version':v,'repository':REPO,
                    'commit':'a'*40, 'schema_version':schema,
                    'signing_key_sha256':hashlib.sha256(d.release.key_der(self.pub,True)).hexdigest(),
                    'artifacts':artifacts}
        body = (json.dumps(manifest, sort_keys=True, separators=(',',':'))+'\n').encode()
        m = self.root/'release.json'
        m.write_bytes(body)
        (self.root/'release.json.sig').unlink(missing_ok=True)
        d.release.sign(m, self.key)
        sig = (self.root/'release.json.sig').read_bytes()
        if corrupt_sig: sig = bytes([sig[0] ^ 1]) + sig[1:]
        byname = {name: {'name':name, 'size':info['size'], 'state':'uploaded',
                         'digest':'sha256:'+info['sha256']} for name,info in artifacts.items()}
        byname.update({
            'release.json':{'name':'release.json','size':len(body),'state':'uploaded',
                            'digest':'sha256:'+hashlib.sha256(body).hexdigest()},
            'release.json.sig':{'name':'release.json.sig','size':64,'state':'uploaded',
                                'digest':'sha256:'+hashlib.sha256(sig).hexdigest()},
            'source.zip.sig':{'name':'source.zip.sig','size':64,'state':'uploaded'},
            'deployment.zip.sig':{'name':'deployment.zip.sig','size':64,'state':'uploaded'},
        })
        rel={'tag_name':'v'+v,'name':'Book '+v,'body':'Changes','draft':False,'deleted_at':None,
             'published_at':'2026-11-22T00:00:00Z','prerelease':False,'assets':list(byname.values())}
        def download(url, dest, cap, expected):
            self.calls.append(url)
            name = url.rsplit('/',1)[1]
            contents = {'release.json':body,'release.json.sig':sig}[name]
            if len(contents) > cap or len(contents) != expected:
                raise d.DiscoveryError('Bad transfer length')
            dest.write_bytes(contents)
        return rel, download


class FakeAPI:
    def __init__(self, releases): self.releases = releases
    def list_releases(self, *args): return self.releases, {'format':1,'repository':REPO,'pages':{}}


class TestDiscovery(unittest.TestCase):
    def setUp(self): self.fix = Fixture()
    def tearDown(self): self.fix.close()
    def run_discovery(self, releases, downloader, **kw):
        return d.discover(REPO, {'version':'1.1.1','schema':7},
                          {'version':'1.1.1','schema':7}, self.fix.pub,
                          api=FakeAPI(releases), download=downloader,
                          manifest_header=u.manifest_header, **kw)[0]

    def test_signed_release_selected_without_package_download(self):
        rel, download = self.fix.release()
        result = self.run_discovery([rel],download)
        self.assertEqual(result['status'], 'available')
        self.assertEqual(result['candidate']['version'], '1.1.2')
        self.assertEqual(result['candidate']['schema'], 7)
        self.assertEqual(result['candidate']['manifest_sha256'], hashlib.sha256((self.fix.root/'release.json').read_bytes()).hexdigest())
        self.assertEqual({v.rsplit('/',1)[1] for v in self.fix.calls}, {'release.json','release.json.sig'})

    def test_missing_asset_or_mismatched_digest_is_rejected(self):
        for method in ('missing', 'wrong_digest', 'unready', 'extra'):
            with self.subTest(method=method):
                rel, download = self.fix.release()
                if method=='missing': rel['assets'].pop()
                if method=='wrong_digest': rel['assets'][0]['digest']='sha256:'+'f'*64
                if method=='unready': rel['assets'][0]['state']='new'
                if method=='extra': rel['assets'].append({'name':'extra.txt','size':1,'state':'uploaded'})
                result = self.run_discovery([rel],download)
                self.assertEqual(result['status'], 'rejected')
                self.assertIsNone(result['candidate'])

    def test_bad_signature_blocks_and_no_auto_fallback_to_unsigned(self):
        rel, download = self.fix.release(corrupt_sig=True)
        result = self.run_discovery([rel],download)
        self.assertEqual(result['status'],'rejected')
        self.assertIn('1.1.2',result['rejected'][0]['tag'])

    def test_schema_downgrade_blocks(self):
        rel, download = self.fix.release(schema=6)
        result = self.run_discovery([rel],download)
        self.assertEqual(result['status'],'rejected')
        self.assertIn('downgrade',result['rejected'][0]['reason'])

    def test_schema_upgrade_requires_preflight(self):
        rel, download = self.fix.release(schema=8)
        result = self.run_discovery([rel],download)
        self.assertEqual(result['status'],'available')
        self.assertTrue(result['candidate']['requires_schema_upgrade'])

    def test_beta_channel_visible_but_cannot_be_installed(self):
        rel, download = self.fix.release()
        beta = dict(rel, tag_name='v1.1.3-beta.2', prerelease=True)
        self.assertEqual(self.run_discovery([beta,rel],download,channel='stable')['candidate']['version'], '1.1.2')
        result = self.run_discovery([beta],download,channel='beta')
        self.assertEqual(result['status'],'unsupported_prerelease')
        self.assertEqual(result['unsupported']['version'],'1.1.3-beta.2')

    def test_block_pending_or_modified_environment(self):
        rel, download = self.fix.release()
        for kw in ({'pending':True}, {'env_changed':True}):
            result = self.run_discovery([rel],download,**kw)
            self.assertEqual(result['status'], 'blocked')
            self.assertEqual(result['candidate']['version'], '1.1.2')

    def test_highest_version_protects_downgrade(self):
        rel, download = self.fix.release()
        result,_ = d.discover(REPO,{'version':'1.1.1','schema':7},
                              {'version':'1.1.3','schema':7},self.fix.pub,api=FakeAPI([rel]),
                              download=download,manifest_header=u.manifest_header)
        self.assertEqual(result['status'],'rejected')
        self.assertEqual(self.fix.calls,[])

    def test_incomplete_scan_never_claims_up_to_date(self):
        class TruncatedAPI:
            def list_releases(self, *args):
                return [], {'format':1,'repository':REPO,'pages':{},'truncated':True}
        result,_ = d.discover(REPO, {'version':'1.1.1','schema':7},
                              {'version':'1.1.1','schema':7},self.fix.pub,api=TruncatedAPI(),
                              download=lambda *args: self.fail('Unexpected download'),
                              manifest_header=u.manifest_header)
        self.assertEqual(result['status'],'incomplete')
        self.assertTrue(result['pagination_truncated'])

    def test_real_update_cli_check_path_and_private_etag_cache(self):
        rel, download = self.fix.release()
        with tempfile.TemporaryDirectory() as td:
            base = Path(td)
            root = base/'state'
            m = u.Manager(root)
            m.pub.write_bytes(self.fix.pub.read_bytes())
            m.pub.chmod(0o400)
            env = base/'runtime.env'
            env.write_text('BOOK_TEST=1\n')
            env.chmod(0o600)
            receipt = {'version':'1.1.1','schema':7}
            m.save({'format':1,'config':{'project':'book', 'repository':REPO,
                                        'env':str(env),'env_sha':u.release.sha(env)},
                    'key':hashlib.sha256(u.release.key_der(m.pub,True)).hexdigest(),
                    'current':receipt,'highest':receipt,'pending':None})
            writer = io.StringIO()
            with patch.object(u, 'download', download), \
                 patch('urllib.request.build_opener', return_value=Opener(Response([rel]))), \
                 patch.object(u, 'project_lock', return_value=contextlib.nullcontext()), \
                 patch.object(u.os, 'geteuid', return_value=0), \
                 patch.object(sys, 'argv', ['update.py','--state-dir',str(root),'check']), \
                 contextlib.redirect_stdout(writer):
                self.assertEqual(u.main(), 0)
            parsed = json.loads(writer.getvalue())
            self.assertEqual(parsed['candidate']['version'],'1.1.2')
            self.assertEqual(parsed['status'],'available')
            self.assertIsNone(m.read()['pending'])
            self.assertEqual(m.read()['current'],receipt)
            self.assertEqual(d.cache_load(root, REPO)['repository'], REPO)

    def test_empty_response_is_up_to_date(self):
        result = self.run_discovery([],lambda *args: self.fail('Unexpected download'))
        self.assertEqual(result['status'],'up_to_date')
        self.assertEqual(result['checked_releases'],0)


if __name__=='__main__':
    unittest.main(verbosity=2)
