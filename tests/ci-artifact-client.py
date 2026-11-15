#!/usr/bin/env python3
"""Exercise the real CLI's HTTP transfer and atomic file installation with a local wire fixture."""
import hashlib
import http.server
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import zipfile

ROOT = Path(__file__).resolve().parents[1]
ID, DIGEST = 'a' * 32, 'b' * 64
TOKEN = 'cbci_' + 'c' * 32 + '.' + 'd' * 64


def make_zip(build=ID, extra=None):
    out = io.BytesIO()
    with zipfile.ZipFile(out, 'w') as z:
        z.writestr('index.html', '<h1>Release</h1>')
        z.writestr('search.html', '<h1>Search</h1>')
        z.writestr('release.json', json.dumps({'build': build, 'digest': DIGEST, 'visibility': 'public'}))
        if extra:
            z.writestr(extra, 'unsafe')
    return out.getvalue()


class Handler(http.server.BaseHTTPRequestHandler):
    mode = 'ok'
    hits = 0

    def do_POST(self):
        type(self).hits += 1
        assert self.headers['Authorization'] == 'Bearer ' + TOKEN
        assert json.loads(self.rfile.read(int(self.headers['Content-Length']))) == {'action': 'export', 'id': ID, 'digest': DIGEST}
        mode = type(self).mode
        if mode == 'redirect':
            self.send_response(307); self.send_header('Location', '/api/v1/ci'); self.end_headers(); return
        data = make_zip('0' * 32 if mode == 'identity' else ID, '../unsafe' if mode == 'unsafe' else None)
        self.send_response(200)
        self.send_header('Content-Type', 'application/zip')
        self.send_header('Content-Length', str(len(data) + (1 if mode == 'short' else 0)))
        self.send_header('X-Book-Artifact-SHA256', '0' * 64 if mode == 'hash' else hashlib.sha256(data).hexdigest())
        self.end_headers(); self.wfile.write(data)

    def log_message(self, *args):
        pass


with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp)
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
    env = dict(os.environ, BOOK_CI_URL=f'http://127.0.0.1:{server.server_port}/api/v1/ci', BOOK_CI_TOKEN=TOKEN, BOOK_CI_ALLOW_HTTP_LOCAL='1')
    try:
        target = root / 'release.zip'
        cmd = [sys.executable, str(ROOT/'bin/ci.py'), 'export', ID, DIGEST, str(target)]
        result = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=10)
        assert result.returncode == 0, result.stderr
        original = target.read_bytes()
        assert json.loads(result.stdout)['sha256'] == hashlib.sha256(original).hexdigest()
        assert subprocess.run(cmd, env=env, capture_output=True, timeout=10).returncode == 1 and target.read_bytes() == original
        target.unlink()
        print('PASS CLI verified download and no overwrite')
        for mode in ['hash', 'short', 'identity', 'unsafe', 'redirect']:
            Handler.mode = mode
            hits = Handler.hits
            result = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=10)
            assert result.returncode == 1 and not target.exists() and not list(root.iterdir()), (mode, result.stdout, result.stderr)
            assert Handler.hits == hits + 1 and TOKEN not in result.stderr
            print('PASS CLI rejects', mode, 'without leaving partial files')
    finally:
        server.shutdown(); server.server_close(); thread.join()
