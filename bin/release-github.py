#!/usr/bin/env python3
"""Fail-closed GitHub Release admission/publication checks for signed Book builds.

The local bundle is separately verified with release-package.py (including Cosign);
this guard validates the *actual GitHub Release* and its remote asset inventory.
No release is created, edited or deleted by this program.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, HTTPRedirectHandler, build_opener

REPO = re.compile(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z')
VERSION = re.compile(r'(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z')
SHA256 = re.compile(r'[0-9a-f]{64}\Z')
MAX_RESPONSE = 4 * 1024 * 1024


class ReleaseError(RuntimeError):
    pass


def require(ok, message):
    if not ok:
        raise ReleaseError(message)


def unique(pairs):
    obj = {}
    for key, value in pairs:
        require(key not in obj, 'Duplicate key in GitHub JSON')
        obj[key] = value
    return obj


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        # Never forward a GH_TOKEN/GITHUB_TOKEN bearer credential to another host.
        raise ReleaseError('GitHub API redirect refused')


def api_json(repo, path, token):
    """Only query GitHub REST API paths constructed from validated input."""
    url = 'https://api.github.com/repos/' + repo + path
    request = Request(url, headers={
        'Authorization': 'Bearer ' + token,
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        'User-Agent': 'CajeerEngineBook-Release-Publisher/1',
    })
    try:
        with build_opener(NoRedirect()).open(request, timeout=25) as response:
            require(response.status == 200, 'Unexpected GitHub API HTTP response')
            raw = response.read(MAX_RESPONSE + 1)
            require(len(raw) <= MAX_RESPONSE, 'GitHub API response exceeds limit')
            return json.loads(raw, object_pairs_hook=unique)
    except HTTPError as e:
        if e.code == 404:
            return None
        # Do not expose response bodies (tokens and internal details can leak).
        raise ReleaseError(f'GitHub API failed with HTTP {e.code}') from e
    except (URLError, TimeoutError, OSError) as e:
        raise ReleaseError('GitHub API connectivity failure') from e


def release_info(repo, version, token):
    # A 404 for /releases/tags is not proof of absence if access to the repo
    # itself has been revoked. Check repo visibility with the same token.
    info = api_json(repo, '/releases/tags/v' + version, token)
    if info is None:
        repository = api_json(repo, '', token)
        require(isinstance(repository, dict) and
                str(repository.get('full_name', '')).lower() == repo.lower(),
                'Cannot authenticate repository while checking release absence')
    return info


def expected_files(directory):
    directory = Path(directory)
    require(directory.is_dir() and not directory.is_symlink(), 'Missing verified local bundle')
    expected = {}
    for f in directory.iterdir():
        require(f.is_file() and not f.is_symlink(), 'Unsafe local bundle entry')
        with f.open('rb') as stream:
            expected[f.name] = (f.stat().st_size, hashlib.file_digest(stream, 'sha256').hexdigest())
        require(expected[f.name][0] > 0, 'Empty release artifact')
    require('release.json' in expected and 'release.json.sig' in expected and
            'source.zip' in expected and 'deployment.zip' in expected,
            'Missing required local release assets')
    return expected


def check_release(info, version, expected, state):
    require(isinstance(info, dict), 'Release does not exist')
    require(info.get('tag_name') == 'v' + version, 'Wrong remote release tag')
    require(info.get('draft') is (state == 'draft') and info.get('prerelease') is False,
            'Wrong remote release lifecycle state')
    require(info.get('deleted_at') in (None, ''), 'Release was deleted')
    assets = info.get('assets')
    require(isinstance(assets, list) and len(assets) == len(expected),
            'Missing or extra remote assets')
    remote = {}
    for a in assets:
        require(isinstance(a, dict), 'Invalid remote asset')
        name = a.get('name')
        require(isinstance(name, str) and name in expected and name not in remote,
                'Unexpected or duplicate remote asset')
        require(a.get('state') == 'uploaded', 'Remote asset is not fully uploaded')
        size, digest = expected[name]
        require(type(a.get('size')) is int and a['size'] == size,
                'Remote asset size mismatch: ' + name)
        remote_digest = a.get('digest')
        if remote_digest is not None:
            require(remote_digest == 'sha256:' + digest,
                    'Remote asset SHA-256 mismatch: ' + name)
        remote[name] = True
    require(set(remote) == set(expected), 'Incomplete remote release')


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('action', choices=('absent', 'verify'))
    p.add_argument('--repository', required=True)
    p.add_argument('--version', required=True)
    p.add_argument('--directory', type=Path)
    p.add_argument('--state', choices=('draft', 'published'))
    args = p.parse_args(argv)
    try:
        require(REPO.fullmatch(args.repository) and VERSION.fullmatch(args.version),
                'Invalid repository or release version')
        token = os.environ.get('GH_TOKEN') or os.environ.get('GITHUB_TOKEN')
        require(bool(token), 'Missing authenticated GitHub token')
        info = release_info(args.repository, args.version, token)
        if args.action == 'absent':
            require(info is None, 'GitHub Release already exists (draft or published)')
        else:
            require(args.directory is not None and args.state is not None,
                    'Remote verification requires --directory and --state')
            check_release(info, args.version, expected_files(args.directory), args.state)
        print('GitHub release ' + ('absent' if info is None else args.state + ' inventory verified'))
        return 0
    except (ReleaseError, ValueError, KeyError, OSError, json.JSONDecodeError) as e:
        print('GitHub release rejected: ' + str(e), file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
