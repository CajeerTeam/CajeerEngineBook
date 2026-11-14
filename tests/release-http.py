#!/usr/bin/env python3
"""Cross-module HTTP release gate against actual PHP/CommonMark (or Docker Apache).

Set BOOK_TEST_URL and BOOK_TEST_PASSWORD to use a disposable external installation.
The default creates a fresh temporary store and PHP server. Never use a live site.
"""
import argparse
import contextlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = os.environ.get('BOOK_TEST_PASSWORD', 'release-test-only-password')
PAGE = 'release/1.0/mixed'
NEW = 'release/1.0/renamed'


def check(condition, name):
    if not condition:
        raise AssertionError(name)
    print('PASS', name, flush=True)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base, auth=False):
        self.base = base
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), urllib.request.ProxyHandler({}))
        self.headers = {}
        if auth:
            _, _, form = self.request('/login')
            csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', form).group(1).decode()
            self.request('/login', 'POST', {'csrf':csrf,'username':'admin','password':PASSWORD}, expected=303)

    def request(self, path, method='GET', data=None, headers=None, expected=200):
        h = {**self.headers, **(headers or {})}
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data).encode()
            h['Content-Type'] = 'application/x-www-form-urlencoded'
        req = urllib.request.Request(self.base + path, data=data, headers=h, method=method)
        try:
            response = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as e:
            response = e
        with response:
            body = response.read()
            code = response.status
            headers = response.headers
        if expected is not None and code != expected:
            raise AssertionError(f'{method} {path}: expected {expected}, got {code}: {body[:500]!r}')
        return code, headers, body


def wait_ready(base):
    client = Client(base)
    for _ in range(90):
        try:
            code, _, body = client.request('/health/ready', expected=None)
            if code == 200 and json.loads(body)['status'] == 'ok':
                return
        except (OSError, ValueError, KeyError):
            pass
        time.sleep(1)
    raise RuntimeError('Application failed readiness (real PHP dependencies and Mermaid assets required)')


def fence(kind, value):
    return f'~~~book-{kind}\n{json.dumps(value, ensure_ascii=False)}\n~~~\n'


def run(base, persisted=False):
    wait_ready(base)
    public = Client(base)
    if persisted:
        _, _, b = public.request('/api/v1/pages/' + NEW)
        check('Mixed release content' in json.loads(b)['body'], 'published page persists after restart')
        _, h, _ = public.request('/docs/' + PAGE, expected=308)
        check(h['Location'] == '/docs/' + NEW, 'redirect persists after restart')
        return
    admin = Client(base, True)
    _, _, b = public.request('/health/live')
    check(json.loads(b)['status'] == 'ok', 'liveness')
    public.request('/studio', expected=401)
    _, _, b = admin.request('/studio')
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', b).group(1).decode()
    admin.request('/studio/editor/render', 'POST', {'mode': 'visual', 'body': 'test'}, expected=403)
    check(True, 'auth and CSRF')
    boundary = 'BookRelease' + uuid.uuid4().hex
    parts = []
    for name, value in [('csrf', csrf), ('action', 'upload'), ('folder', 'release')]:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    payload = b'Release integration attachment\n'
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="release.txt"\r\nContent-Type: text/plain\r\n\r\n'.encode() + payload + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    _, _, b = admin.request('/studio/media', 'POST', b''.join(parts), {'Content-Type': f'multipart/form-data; boundary={boundary}', 'X-Book-Media': '1'})
    item = json.loads(b)['item']; media = '/media/' + item['id']
    public.request(media, expected=404)
    _, _, b = admin.request('/studio/media/file/' + item['id'])
    check(b == payload, 'actual uploaded attachment private and intact')
    steps = fence('steps', {'v': 1, 'kind': 'steps', 'items': [{'title': 'Start', 'body': f'[Download]({media}?download=1)'}]})
    tabs = fence('tabs', {'v': 1, 'kind': 'tabs', 'sync': 'os', 'items': [{'key': 'linux', 'title': 'Linux', 'body': steps}, {'key': 'win', 'title': 'Windows', 'body': 'Use Windows'}]})
    body = '# Mixed release content\n\n| A | B |\n| --- | --- |\n| **one** | two |\n\n- [x] Done\n\n' + tabs + '\n' + fence('callout', {'v': 1, 'type': 'tip', 'title': 'Tip', 'body': '**Ready**'}) + '\n```mermaid\nflowchart LR\nA-->B\n```\n\n<script>alert(1)</script>\n'
    form = {'csrf': csrf, 'book': 'release', 'version': '1.0', 'path': 'mixed', 'title': 'Release Mixed', 'section': 'QA', 'order': 10, 'body': body, 'revision': 0}
    _, _, b = admin.request('/studio/editor/render', 'POST', {**form, 'mode': 'visual'})
    model = json.loads(b)
    check(''.join(block['source'] for block in model['blocks']) == body, 'real AST preserves all mixed Markdown')
    check('book-steps' in model['html'] and '/studio/media/file/' + item['id'] + '?download=1' in model['html'] and '<script>' not in model['html'], 'nested private preview and safe HTML')
    headers = {'X-Book-Studio': 'editor'}
    _, _, b = admin.request('/studio', 'POST', {**form, 'action': 'save'}, headers)
    check(json.loads(b)['revision'] == 1, 'draft committed')
    public.request('/docs/' + PAGE, expected=404)
    public.request(media, expected=404)
    _, _, b = admin.request('/studio', 'POST', {**form, 'action': 'publish', 'revision': 1}, headers)
    check(json.loads(b)['revision'] == 2, 'publication committed')
    _, h, b = public.request('/docs/' + PAGE)
    check(h['Cache-Control'] == 'no-store' and b'data-book-mermaid' in b and b'book-steps' in b and b'<script>alert' not in b, 'real public HTML and no stale cache')
    _, h, b = public.request(media + '?download=1')
    check(b == payload and h['Content-Disposition'].startswith('attachment'), 'published attachment downloadable')
    _, _, b = admin.request('/studio', 'POST', {**form, 'action': 'save', 'revision': 2, 'body': 'Unpublished secret release'}, headers)
    check(json.loads(b)['revision'] == 3, 'new draft committed separately')
    admin.request('/studio', 'POST', {**form, 'action': 'save', 'revision': 2}, headers, expected=409)
    _, _, b = public.request('/api/v1/pages/' + PAGE)
    check(json.loads(b)['body'] == body, 'stale write rejected and public snapshot unchanged')
    _, _, b = admin.request('/studio/tree?scope=release%2F1.0')
    token = re.search(rb'name="token" value="([a-f0-9]{64})"', b).group(1).decode()
    admin.request('/studio/tree', 'POST', {'csrf': csrf, 'scope': 'release/1.0', 'token': token, 'action': 'move', 'source': PAGE, 'target': '', 'position': 'root', 'slug': 'renamed'}, expected=303)
    public.request('/docs/' + PAGE)
    public.request('/docs/' + NEW, expected=404)
    _, _, b = admin.request('/studio/tree?scope=release%2F1.0')
    token = re.search(rb'name="token" value="([a-f0-9]{64})"', b).group(1).decode()
    admin.request('/studio/tree', 'POST', {'csrf': csrf, 'scope': 'release/1.0', 'token': token, 'action': 'publish'}, expected=303)
    _, h, _ = public.request('/docs/' + PAGE + '?q=1', expected=308)
    check(h['Location'] == '/docs/' + NEW + '?q=1', 'tree publish redirects old path')
    _, _, b = public.request('/api/v1/pages/' + NEW)
    check(json.loads(b)['body'] == body, 'tree publish never publishes draft body')
    _, _, b = admin.request('/studio/media/backup')
    check(len(b) > len(payload) and b'books.json' in b[:512], 'full backup endpoint returns TAR')
    _, h, _ = public.request('/diagram-frame')
    check("sandbox allow-scripts" in h['Content-Security-Policy'] and 'allow-same-origin' not in h['Content-Security-Policy'], 'diagram iframe isolated')
    print('RELEASE HTTP GATE PASSED', flush=True)


@contextlib.contextmanager
def local_server():
    with tempfile.TemporaryDirectory() as data, socket.socket() as sock:
        sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]; sock.close()
        hashed = subprocess.check_output(['php', '-r', 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'], input=PASSWORD.encode()).decode()
        env = dict(os.environ, BOOK_DATA_DIR=data, BOOK_ADMIN_HASH=hashed, BOOK_SECURE_COOKIE='0')
        with tempfile.TemporaryFile() as log:
            proc = subprocess.Popen(['php', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=24M', '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'], cwd=ROOT, env=env, stdout=log, stderr=log)
            try:
                yield f'http://127.0.0.1:{port}'
            except Exception:
                log.seek(0); print(log.read().decode(errors='replace')); raise
            finally:
                proc.terminate(); proc.wait(timeout=10)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--check-persisted', action='store_true')
    args = parser.parse_args()
    base = os.environ.get('BOOK_TEST_URL')
    if base:
        run(base.rstrip('/'), args.check_persisted)
    elif args.check_persisted:
        parser.error('--check-persisted requires BOOK_TEST_URL')
    else:
        with local_server() as base:
            run(base)
