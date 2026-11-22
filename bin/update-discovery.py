#!/usr/bin/env python3
"""Read-only GitHub Releases discovery for an enrolled Book installation.

A candidate is *available* only after the installed Ed25519 key authenticates
its release.json and the GitHub asset inventory agrees with that manifest.
This does not download or execute deployment.zip, mutate the installation, or
replace the updater's mandatory fetch/preflight/apply verification.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import urllib.error
import urllib.request

_spec = importlib.util.spec_from_file_location('book_discovery_release', Path(__file__).with_name('release-package.py'))
release = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(release)

SEMVER = re.compile(r'(?P<base>(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))'
                    r'(?:-(?P<pre>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\Z')
ETAG = re.compile(r'[\x21-\x7e]{1,256}\Z')
MAX_RESPONSE = 4 * 1024 * 1024
MAX_CACHE = 8 * 1024 * 1024
PER_PAGE = 100
MAX_PAGES = 3
MAX_REJECTIONS = 8


class DiscoveryError(RuntimeError):
    pass


def require(ok, message):
    if not ok:
        raise DiscoveryError(message)


def unique(pairs):
    obj = {}
    for k, v in pairs:
        require(k not in obj, 'Duplicate GitHub JSON field')
        obj[k] = v
    return obj


def parse_json(data):
    return json.loads(data, object_pairs_hook=unique,
                      parse_constant=lambda _: (_ for _ in ()).throw(DiscoveryError('Nonfinite JSON value')))


def semantic(value):
    """Full SemVer ordering, including numeric vs alphabetic pre-release IDs."""
    m = SEMVER.fullmatch(value) if isinstance(value, str) else None
    require(m is not None, 'Invalid SemVer tag')
    numbers = tuple(int(p) for p in m['base'].split('.'))
    pre = m['pre']
    ids = []
    if pre is not None:
        for part in pre.split('.'):
            if part.isascii() and part.isdigit():
                require(part == '0' or not part.startswith('0'), 'Leading zero in SemVer prerelease')
                ids.append((0, int(part)))
            else:
                ids.append((1, part))
    return numbers, (1,) if pre is None else (0, tuple(ids))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        raise DiscoveryError('GitHub API redirect is not permitted')


class GitHubAPI:
    def __init__(self, token=None, opener=None):
        self.token = token
        self.opener = opener if opener is not None else urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect())

    def get_page(self, repo, page, old=None, refresh=False):
        require(release.REPO.fullmatch(repo) is not None and all(x not in ('.', '..') for x in repo.split('/')),
                'Invalid repository name')
        require(type(page) is int and 1 <= page <= MAX_PAGES, 'Invalid release page')
        headers = {'User-Agent': 'CajeerEngineBook-Update-Discovery/1',
                   'Accept': 'application/vnd.github+json',
                   'X-GitHub-Api-Version': '2022-11-28',
                   'Accept-Encoding': 'identity'}
        if self.token:
            require('\r' not in self.token and '\n' not in self.token, 'Unsafe GitHub credential')
            headers['Authorization'] = 'Bearer ' + self.token
        if not refresh and isinstance(old, dict) and isinstance(old.get('etag'), str) and ETAG.fullmatch(old['etag']):
            headers['If-None-Match'] = old['etag']
        req = urllib.request.Request(
            'https://api.github.com/repos/' + repo + f'/releases?per_page={PER_PAGE}&page={page}',
            headers=headers)
        try:
            with self.opener.open(req, timeout=20) as res:
                require(res.status == 200, 'Unexpected GitHub API status')
                require(res.headers.get('Content-Encoding', 'identity') == 'identity',
                        'Compressed GitHub API response refused')
                raw = res.read(MAX_RESPONSE + 1)
                require(len(raw) <= MAX_RESPONSE, 'GitHub API response too large')
                listing = parse_json(raw)
                require(isinstance(listing, list) and len(listing) <= PER_PAGE,
                        'Invalid GitHub release page')
                etag = res.headers.get('ETag')
                if not isinstance(etag, str) or not ETAG.fullmatch(etag):
                    etag = None
                return listing, {'etag': etag, 'items': listing}
        except urllib.error.HTTPError as e:
            if e.code == 304:
                require(isinstance(old, dict) and isinstance(old.get('items'), list),
                        'GitHub returned 304 without cached releases')
                return old['items'], old
            if e.code == 429 or (e.code == 403 and e.headers.get('X-RateLimit-Remaining') == '0'):
                retry = e.headers.get('Retry-After', '')
                require(not retry or (retry.isdigit() and len(retry) <= 8), 'Invalid GitHub rate-limit response')
                raise DiscoveryError('GitHub API rate limit exceeded' + (f'; retry after {retry}s' if retry else '')) from e
            raise DiscoveryError(f'GitHub API HTTP {e.code} (not an empty release list)') from e
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise DiscoveryError('GitHub API unavailable') from e

    def list_releases(self, repo, cache=None, refresh=False):
        pages = {} if refresh else (cache or {}).get('pages', {})
        require(isinstance(pages, dict), 'Invalid release cache')
        found = []
        updated = {}
        truncated = False
        for p in range(1, MAX_PAGES + 1):
            rows, entry = self.get_page(repo, p, pages.get(str(p)), refresh)
            require(isinstance(rows, list) and len(rows) <= PER_PAGE, 'Invalid cached GitHub page')
            found.extend(rows)
            updated[str(p)] = entry
            if len(rows) < PER_PAGE:
                break
            if p == MAX_PAGES:
                truncated = True
        return found, {'format': 1, 'repository': repo, 'pages': updated, 'truncated': truncated}


def cache_load(root, repo):
    path = Path(root) / 'github-discovery-cache.json'
    if not path.exists() and not path.is_symlink():
        return None
    require(path.is_file() and not path.is_symlink(), 'Unsafe release discovery cache')
    s = path.stat()
    require(s.st_uid == os.geteuid() and stat.S_IMODE(s.st_mode) == 0o600 and
            s.st_nlink == 1 and s.st_size <= MAX_CACHE,
            'Untrusted release discovery cache')
    cache = parse_json(path.read_bytes())
    require(isinstance(cache, dict) and cache.get('format') == 1 and
            cache.get('repository') == repo and isinstance(cache.get('pages'), dict),
            'Invalid or mismatched release discovery cache')
    return cache


def cache_save(root, cache):
    root = Path(root)
    data = json.dumps(cache, sort_keys=True, separators=(',', ':'), allow_nan=False).encode('utf-8')
    require(len(data) <= MAX_CACHE, 'Release discovery cache exceeds size limit')
    path = root / 'github-discovery-cache.json'
    require(not path.is_symlink(), 'Unsafe release discovery cache')
    fd, tmp = tempfile.mkstemp(prefix='.github-discovery-', dir=root)
    try:
        with os.fdopen(fd, 'wb') as dest:
            dest.write(data)
            dest.flush()
            os.fsync(dest.fileno())
        os.chmod(tmp, 0o600)
        os.replace(tmp, path)
        fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def release_candidate(info):
    require(isinstance(info, dict), 'Malformed release metadata')
    tag = info.get('tag_name')
    require(isinstance(tag, str) and tag.startswith('v'), 'Release has no SemVer tag')
    parsed = semantic(tag[1:])
    require(info.get('draft') is False and info.get('deleted_at') in (None, ''), 'Draft or deleted release')
    require(isinstance(info.get('published_at'), str) and info['published_at'], 'Unpublished release')
    require(info.get('prerelease') is (parsed[1][0] == 0), 'GitHub prerelease and tag disagree')
    require(isinstance(info.get('assets'), list), 'Release has no asset inventory')
    return tag[1:], parsed


def signed_candidate(info, v, repo, pub, download, manifest_header):
    """Verify only bounded release.json + signature; never retrieve source/deployment ZIPs."""
    require(release.VERSION.fullmatch(v) is not None, 'Updater cannot install prerelease versions')
    assets = info['assets']
    expected = (release.files_for(v) | {'release.json', 'release.json.sig', 'source.zip.sig', 'deployment.zip.sig'})
    require(len(assets) == len(expected), 'Incomplete or unexpected GitHub release assets')
    byname = {}
    for a in assets:
        require(isinstance(a, dict), 'Malformed GitHub asset')
        name = a.get('name')
        require(isinstance(name, str) and name in expected and name not in byname,
                'Duplicate, missing, or unexpected GitHub asset')
        require(a.get('state') == 'uploaded', 'GitHub asset has not finished uploading')
        size = a.get('size')
        require(type(size) is int and 0 < size <= (64 if name.endswith('.sig') else (1024 * 1024 if name == 'release.json' else release.asset_limit(name))),
                'Invalid asset size')
        byname[name] = a
    require(set(byname) == expected, 'Missing GitHub release assets')
    require(byname['release.json.sig']['size'] == 64, 'Wrong manifest signature size')
    with tempfile.TemporaryDirectory(prefix='book-update-discovery-') as td:
        path = Path(td)
        for name in ('release.json', 'release.json.sig'):
            download(f'https://github.com/{repo}/releases/download/v{v}/{name}',
                     path / name, byname[name]['size'], byname[name]['size'])
            digest = byname[name].get('digest')
            if digest is not None:
                with (path / name).open('rb') as stream:
                    require(digest == 'sha256:' + hashlib.file_digest(stream, 'sha256').hexdigest(),
                            'GitHub manifest digest differs from download')
        signed = manifest_header(path, pub, repo, v)
        manifest_digest = hashlib.sha256((path / 'release.json').read_bytes()).hexdigest()
    for name, expected_artifact in signed['artifacts'].items():
        a = byname[name]
        require(a['size'] == expected_artifact['size'], 'Signed artifact size differs from GitHub asset')
        if a.get('digest') is not None:
            require(a['digest'] == 'sha256:' + expected_artifact['sha256'],
                    'Signed artifact digest differs from GitHub asset')
    require(type(signed['schema_version']) is int and signed['schema_version'] >= 0,
            'Invalid signed schema version')
    return signed, manifest_digest


def discover(repo, current, highest, pub, *, channel='stable', api=None,
             cache=None, refresh=False, download=None, manifest_header=None,
             pending=False, env_changed=False):
    require(channel in ('stable', 'beta'), 'Invalid release channel')
    require(release.REPO.fullmatch(repo) is not None and all(x not in ('.', '..') for x in repo.split('/')),
            'Invalid repository')
    cur_v = semantic(current['version'])
    highest_v = semantic(highest['version'])
    require(highest_v >= cur_v, 'Invalid updater high-water version')
    require(download is not None and manifest_header is not None, 'Missing verified manifest transport')
    if api is None:
        api = GitHubAPI(os.environ.get('GITHUB_TOKEN') or os.environ.get('GH_TOKEN'))
    entries, new_cache = api.list_releases(repo, cache, refresh)
    eligible = []
    rejected = []
    for entry in entries:
        try:
            ver, parts = release_candidate(entry)
            if channel == 'stable' and parts[1][0] == 0:
                continue
            if parts <= cur_v:
                continue
            eligible.append((parts, ver, entry))
        except (DiscoveryError, ValueError, TypeError, KeyError) as e:
            if len(rejected) < MAX_REJECTIONS:
                rejected.append({'tag': str(entry.get('tag_name', ''))[:80] if isinstance(entry, dict) else '',
                                 'reason': str(e)})
    eligible.sort(key=lambda item: item[0], reverse=True)
    selected = None
    unsupported = None
    for rank, v, entry in eligible:
        if rank <= highest_v:
            rejected.append({'tag': 'v' + v, 'reason': 'Below enrolled anti-downgrade high-water version'})
            continue
        if '-' in v:
            if unsupported is None:
                unsupported = {'version': v, 'status': 'unsupported_prerelease',
                               'reason': 'Signed bundle format and updater support stable versions only'}
            continue
        try:
            signed, manifest_digest = signed_candidate(entry, v, repo, pub, download, manifest_header)
            if signed['schema_version'] < current['schema']:
                raise DiscoveryError('Release would downgrade PostgreSQL schema')
            selected = {'version': v, 'tag': 'v' + v, 'commit': signed['commit'],
                        'schema': signed['schema_version'],
                        'requires_schema_upgrade': signed['schema_version'] > current['schema'],
                        'name': entry.get('name', '')[:200] if isinstance(entry.get('name'), str) else '',
                        'changelog': entry.get('body', '')[:6000] if isinstance(entry.get('body'), str) else '',
                        'published_at': entry['published_at'],
                        'manifest_sha256': manifest_digest}
            break
        except (DiscoveryError, ValueError, KeyError, TypeError, OSError, urllib.error.URLError, subprocess.CalledProcessError) as e:
            if len(rejected) < MAX_REJECTIONS:
                rejected.append({'tag': 'v' + v, 'reason': str(e)})
    blocked = pending or env_changed
    truncated = bool(new_cache.get('truncated', False))
    status = ('blocked' if blocked else
              'available' if selected is not None else
              'incomplete' if truncated else
              'unsupported_prerelease' if unsupported is not None else
              'rejected' if eligible and rejected else 'up_to_date')
    return {'status': status, 'channel': channel, 'repository': repo,
            'installed': current['version'], 'highest': highest['version'],
            'pending_recovery': bool(pending), 'environment_changed': bool(env_changed),
            'candidate': selected, 'unsupported': unsupported,
            'rejected': rejected[:MAX_REJECTIONS], 'checked_releases': len(entries),
            'pagination_truncated': truncated}, new_cache
