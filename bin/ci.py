#!/usr/bin/env python3
"""CI client: credentials exclusively in environment; no redirect or secret logging."""
import argparse
import base64
import json
import os
from pathlib import Path
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def request(payload):
    url = os.environ.get('BOOK_CI_URL', '')
    token = os.environ.get('BOOK_CI_TOKEN', '')
    parsed = urllib.parse.urlsplit(url)
    local = (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')
             and os.environ.get('BOOK_CI_ALLOW_HTTP_LOCAL') == '1')
    if (not (parsed.scheme == 'https' or local) or not parsed.hostname or parsed.username is not None
            or parsed.password is not None or parsed.query or parsed.fragment or not parsed.path.endswith('/api/v1/ci')):
        raise ValueError('BOOK_CI_URL must be the HTTPS CI endpoint (loopback HTTP only with explicit test opt-in).')
    if not token:
        raise ValueError('BOOK_CI_TOKEN is required.')
    raw = json.dumps(payload, separators=(',', ':')).encode()
    if len(raw) > 12 * 1024 * 1024:
        raise ValueError('CI request exceeds 12 MiB.')
    req = urllib.request.Request(url, data=raw, headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    try:
        with urllib.request.build_opener(NoRedirect()).open(req, timeout=120) as r:
            result = r.read(2 * 1024 * 1024 + 1)
            if len(result) > 2 * 1024 * 1024:
                raise ValueError('CI response exceeds limit.')
            return json.loads(result)
    except urllib.error.HTTPError as exc:
        # Server errors may contain paths; never print request credentials or external HTML.
        raw = exc.read(4096)
        try:
            error = json.loads(raw).get('error', 'Request failed')
        except (ValueError, AttributeError):
            error = 'Request failed'
        raise ValueError(f'CI HTTP {exc.code}: {error}') from None


def read_files(root):
    root = Path(root)
    if not root.is_dir() or root.is_symlink():
        raise ValueError('Import must be a real documentation directory.')
    files = {}
    total = 0
    for directory, dirs, names in os.walk(root, followlinks=False):
        for name in dirs + names:
            if (Path(directory) / name).is_symlink():
                raise ValueError('Symlinks are not supported in CI import.')
        dirs.sort()
        for name in sorted(names):
            path = Path(directory) / name
            if not path.is_file():
                raise ValueError('Only regular files are supported.')
            total += path.stat().st_size
            if total > 8 * 1024 * 1024 or len(files) >= 200:
                raise ValueError('CI directory exceeds 8 MiB or 200 files.')
            files[path.relative_to(root).as_posix()] = base64.b64encode(path.read_bytes()).decode('ascii')
    if not files:
        raise ValueError('Empty documentation directory.')
    return files


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('state')
    sync = sub.add_parser('wait-sync'); sync.add_argument('--commit', required=True); sync.add_argument('--timeout', type=int, default=180)
    upload = sub.add_parser('import'); upload.add_argument('directory'); upload.add_argument('--expected', required=True); upload.add_argument('--allow-update', action='store_true')
    build = sub.add_parser('build'); build.add_argument('--request', required=True); build.add_argument('--expected', required=True); build.add_argument('--commit', default='')
    status = sub.add_parser('status'); status.add_argument('id')
    publish = sub.add_parser('publish'); publish.add_argument('id'); publish.add_argument('digest')
    args = parser.parse_args()
    try:
        if args.command == 'wait-sync':
            if not 1 <= args.timeout <= 900:
                raise ValueError('Timeout must be 1–900 seconds.')
            deadline = time.monotonic() + args.timeout
            while True:
                result = request({'action': 'state'})
                if result['commit'] == args.commit:
                    break
                if time.monotonic() >= deadline:
                    raise ValueError('Git commit not synchronized; inspect Automation queue and conflicts.')
                time.sleep(3)
        elif args.command == 'import':
            result = request({'action': 'import', 'expected': args.expected, 'allow_update': args.allow_update, 'files': read_files(args.directory)})
        else:
            payload = {'action': args.command}
            payload.update({k: v for k, v in vars(args).items() if k != 'command'})
            result = request(payload)
        print(json.dumps(result, ensure_ascii=False, indent=2))
    except (ValueError, OSError, urllib.error.URLError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
