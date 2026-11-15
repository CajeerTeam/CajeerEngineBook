#!/usr/bin/env python3
"""CI client: credentials exclusively in environment; no redirect or secret logging."""
import argparse
import base64
import json
import hashlib
import http.client
import re
import tempfile
import zipfile
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


def request(payload, destination=None):
    url = os.environ.get('BOOK_CI_URL', '')
    token = os.environ.get('BOOK_CI_TOKEN', '')
    parsed = urllib.parse.urlsplit(url)
    local = (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')
             and os.environ.get('BOOK_CI_ALLOW_HTTP_LOCAL') == '1')
    if (not (parsed.scheme == 'https' or local) or not parsed.hostname or parsed.username is not None
            or parsed.password is not None or parsed.query or parsed.fragment or not parsed.path.endswith('/api/v1/ci')):
        raise ValueError('BOOK_CI_URL must be the HTTPS CI endpoint (loopback HTTP only with explicit test opt-in).')
    if not re.fullmatch(r'cbci_[a-f0-9]{32}\.[a-f0-9]{64}', token):
        raise ValueError('A valid BOOK_CI_TOKEN is required.')
    raw = json.dumps(payload, separators=(',', ':')).encode()
    if len(raw) > 12 * 1024 * 1024:
        raise ValueError('CI request exceeds 12 MiB.')
    req = urllib.request.Request(url, data=raw, headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    try:
        handlers = [NoRedirect()]
        if local:
            handlers.append(urllib.request.ProxyHandler({}))
        with urllib.request.build_opener(*handlers).open(req, timeout=120) as r:
            if destination is not None:
                return save_artifact(r, destination, payload)
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
        error = str(error).replace(token, '[redacted]')[:1000]
        raise ValueError(f'CI HTTP {exc.code}: {error}') from None


def save_artifact(response, destination, payload):
    """Verify transfer and release identity before atomically installing a new file."""
    target = Path(destination)
    if target.suffix != '.zip' or target.exists() or target.is_symlink():
        raise ValueError('Export destination must be a new .zip file.')
    expected = response.headers.get('X-Book-Artifact-SHA256', '')
    length = response.headers.get('Content-Length', '')
    limit = 900 * 1024 * 1024
    if (response.headers.get_content_type() != 'application/zip' or not re.fullmatch(r'[a-f0-9]{64}', expected)
            or not re.fullmatch(r'[0-9]{1,10}', length) or not 0 < int(length) <= limit):
        raise ValueError('Invalid release artifact response.')
    sha = hashlib.sha256()
    count = 0
    temp = None
    try:
        with tempfile.NamedTemporaryFile(dir=target.parent, prefix='.book-release-', delete=False) as out:
            temp = Path(out.name)
            while chunk := response.read(65536):
                count += len(chunk)
                if count > int(length):
                    raise ValueError('Release artifact exceeds declared length.')
                sha.update(chunk)
                out.write(chunk)
            out.flush()
            os.fsync(out.fileno())
        if count != int(length) or sha.hexdigest() != expected:
            raise ValueError('Release artifact integrity mismatch.')
        with zipfile.ZipFile(temp) as archive:
            names = set()
            expanded = 0
            for entry in archive.infolist():
                name = entry.filename
                expanded += entry.file_size
                if (not name or name.startswith('/') or '\\' in name or ':' in name
                        or any(part in ('', '.', '..') for part in name.split('/'))
                        or name in names or entry.is_dir() or (entry.external_attr >> 16) & 0o170000 == 0o120000
                        or expanded > limit or len(names) >= 20000):
                    raise ValueError('Unsafe release ZIP entry.')
                names.add(name)
            if not {'index.html', 'search.html', 'release.json'} <= names:
                raise ValueError('Incomplete release ZIP.')
            info = archive.getinfo('release.json')
            if info.file_size > 16384:
                raise ValueError('Invalid release metadata.')
            release = json.loads(archive.read(info))
            if (not isinstance(release, dict) or release.get('build') != payload['id']
                    or release.get('digest') != payload['digest'] or release.get('visibility') != 'public'):
                raise ValueError('Release artifact identity mismatch.')
        os.link(temp, target)  # never overwrite, including a concurrent writer
        return {'file': str(target), 'sha256': expected, 'bytes': count, 'release': release}
    except (zipfile.BadZipFile, KeyError) as exc:
        raise ValueError('Invalid release ZIP.') from exc
    finally:
        if temp is not None:
            temp.unlink(missing_ok=True)


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
    export = sub.add_parser('export'); export.add_argument('id'); export.add_argument('digest'); export.add_argument('output')
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
        elif args.command == 'export':
            if not re.fullmatch(r'[a-f0-9]{32}', args.id) or not re.fullmatch(r'[a-f0-9]{64}', args.digest):
                raise ValueError('Invalid release ID or digest.')
            target = Path(args.output)
            if target.suffix != '.zip' or target.exists() or target.is_symlink() or not target.parent.is_dir():
                raise ValueError('Export destination must be a new .zip file in an existing directory.')
            result = request({'action': 'export', 'id': args.id, 'digest': args.digest}, args.output)
        elif args.command == 'import':
            result = request({'action': 'import', 'expected': args.expected, 'allow_update': args.allow_update, 'files': read_files(args.directory)})
        else:
            payload = {'action': args.command}
            payload.update({k: v for k, v in vars(args).items() if k != 'command'})
            result = request(payload)
        print(json.dumps(result, ensure_ascii=False, indent=2))
    except (ValueError, OSError, urllib.error.URLError, http.client.HTTPException) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
