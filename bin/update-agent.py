#!/usr/bin/env python3
"""Owner-controlled, root-hosted update execution broker for an enrolled Docker installation.

Install this reviewed script and update.py/release-package.py in a root-owned,
non-writable host tools directory outside any deployed release tree.
Only a dedicated, non-root application UID can access the Unix socket.
No arbitrary paths, command-line flags, shell, or executable text are accepted.
"""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import socketserver
import stat
import struct
import subprocess
import sys
import tempfile
import threading
import time

VERSION = re.compile(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z')
SHA = re.compile(r'[a-f0-9]{64}\Z')
COMMIT = re.compile(r'[a-f0-9]{40}\Z')
MAX_REQUEST = 2048
MAX_RESPONSE = 16384
PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
ALLOWED = {'fetch', 'preflight', 'apply', 'status'}


class Rejected(Exception):
    pass


def require(ok, message):
    if not ok:
        raise Rejected(message)


def private_dir(path, create=False):
    path = Path(path)
    require(path.is_absolute() and not path.is_symlink() and path.resolve() == path, 'Unsafe agent directory')
    if create:
        path.mkdir(mode=0o700, parents=False, exist_ok=True)
    s = path.stat()
    require(s.st_uid == 0 and stat.S_ISDIR(s.st_mode) and stat.S_IMODE(s.st_mode) == 0o700, 'Agent directory must be root-owned 0700')
    for ancestor in path.parents:
        s = ancestor.stat()
        require(s.st_uid == 0 and (not s.st_mode & 0o022 or (s.st_mode & stat.S_ISVTX)), 'Writable or untrusted agent directory ancestor')
    return path


def trusted_file(path, mode=None):
    path = Path(path)
    require(path.is_absolute() and path.resolve() == path and path.is_file() and not path.is_symlink(), 'Untrusted host file')
    s = path.stat()
    require(s.st_uid == 0 and stat.S_ISREG(s.st_mode) and not s.st_mode & 0o022, 'Host file must be owned by root and not writable by others')
    if mode is not None:
        require(stat.S_IMODE(s.st_mode) in mode, 'Host file permissions not private')
    for p in path.parents:
        s = p.stat()
        require(s.st_uid == 0 and (not s.st_mode & 0o022 or (s.st_mode & stat.S_ISVTX)), 'Untrusted host file ancestor')
    return path


def write_json(path, obj):
    payload = (json.dumps(obj, sort_keys=True, separators=(',', ':'), allow_nan=False) + '\n').encode()
    require(len(payload) <= MAX_RESPONSE, 'Job record exceeds limit')
    fd, tmp = tempfile.mkstemp(prefix='.operation-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            os.fchmod(f.fileno(), 0o600)
            f.write(payload); f.flush(); os.fsync(f.fileno())
        os.replace(tmp, path)
        d = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(d)
        finally: os.close(d)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)


class Agent:
    def __init__(self, state_dir, updater, permit_file=None):
        self.dir = private_dir(state_dir, True)
        self.updater = trusted_file(updater)
        self.agent = trusted_file(Path(__file__).resolve())
        require(self.updater.parent == self.agent.parent, 'Host updater and agent must be installed together')
        self.permit_file = Path(permit_file) if permit_file else None
        if self.permit_file is not None:
            require(self.permit_file.is_absolute(), 'Maintenance permit must be absolute')
        self.record = self.dir / 'operation.json'
        self.lock_path = self.dir / 'agent.lock'

    @contextlib.contextmanager
    def lock(self):
        fd = os.open(self.lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            yield

    def load(self):
        if not self.record.exists():
            return {'state': 'idle', 'stage': 'none', 'candidate': None}
        require(not self.record.is_symlink() and self.record.stat().st_uid == 0 and stat.S_IMODE(self.record.stat().st_mode) == 0o600, 'Unsafe agent journal')
        r = json.loads(self.record.read_text())
        require(isinstance(r, dict) and r.get('state') in ('queued', 'running', 'succeeded', 'failed') and r.get('stage') in ('none', 'fetched', 'preflight', 'applied'), 'Invalid agent journal')
        return r

    @staticmethod
    def candidate(req):
        c = req.get('candidate')
        require(isinstance(c, dict) and set(c) == {'version', 'commit', 'manifest_sha256'}, 'Invalid update candidate')
        require(all(isinstance(c[k], str) for k in c), 'Invalid update candidate types')
        require(VERSION.fullmatch(c['version']) and COMMIT.fullmatch(c['commit']) and SHA.fullmatch(c['manifest_sha256']), 'Invalid signed release identity')
        return c

    def permit(self, candidate, consume=False):
        require(self.permit_file is not None, 'Maintenance permit is not configured')
        p = trusted_file(self.permit_file, {0o400, 0o600})
        require(p.stat().st_size <= 2048, 'Invalid maintenance permit')
        data = json.loads(p.read_text())
        require(isinstance(data, dict) and data.get('fenced') is True and data.get('candidate') == candidate, 'Maintenance fence approval does not match release')
        expiry = data.get('expires_at')
        require(type(expiry) is int and time.time() < expiry <= time.time() + 900, 'Maintenance approval expired or exceeds 15 minutes')
        if consume:
            p.unlink()

    def request(self, req):
        require(isinstance(req, dict) and set(req) <= {'action', 'candidate'} and req.get('action') in ALLOWED, 'Unsupported agent command')
        action = req['action']
        with self.lock():
            old = self.load()
            if action == 'status':
                public = {k: old.get(k) for k in ('state','stage','action','candidate','started_at','finished_at','heartbeat_at','result','job_id') if k in old}
                if old['state'] in ('queued', 'running') and time.time() - old.get('heartbeat_at', old.get('started_at', 0)) > 45:
                    public['state'] = 'interrupted'
                    public['result'] = 'Operator recovery required; journal remains locked'
                return public
            c = self.candidate(req)
            require(old['state'] not in ('queued', 'running'), 'Another update operation is active')
            require(not (old.get('action') == 'apply' and old.get('state') == 'failed'), 'Failed activation requires manual recovery')
            if action == 'preflight':
                require(old['stage'] == 'fetched' and old.get('candidate') == c, 'Fetch the exact signed candidate first')
            if action == 'apply':
                require(old['stage'] == 'preflight' and old.get('candidate') == c, 'Successful preflight of the exact candidate is required')
                self.permit(c)
            if action == 'fetch':
                require(old['stage'] != 'applied' or old.get('candidate') != c, 'Version already applied')
            job = {
                'state': 'queued', 'stage': old['stage'] if old.get('candidate') == c else 'none',
                'candidate': c, 'action': action, 'job_id': os.urandom(16).hex(),
                'started_at': int(time.time()), 'heartbeat_at': int(time.time()),
            }
            write_json(self.record, job)
            try:
                # Detached worker outlives the PHP request; the durable journal survives broker restart.
                subprocess.Popen([sys.executable, str(self.agent), 'worker', '--state-dir', str(self.dir),
                                  '--updater', str(self.updater), '--job-id', job['job_id'],
                                  '--permit-file', str(self.permit_file) if self.permit_file else ''],
                                 env={'PATH': PATH, 'LANG': 'C', 'HOME': '/root'},
                                 stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                 close_fds=True, start_new_session=True, cwd='/')
            except OSError:
                job['state'] = 'failed'; job['result'] = 'Worker launch failed'; job['finished_at'] = int(time.time())
                write_json(self.record, job)
                raise Rejected('Worker launch failed')
            return {'state': 'queued', 'stage': job['stage'], 'action': action, 'job_id': job['job_id']}

    def issue_permit(self, confirm_fenced=False, seconds=600):
        """Root-only explicit approval, after the operator has actually fenced ingress.

        Never accessible through RPC. The host agent never asserts that it can
        fence external writers; this remains a documented operational action.
        """
        require(confirm_fenced is True and 1 <= seconds <= 900, 'Operator must confirm external maintenance fence')
        require(self.permit_file is not None, 'Maintenance permit path is not configured')
        with self.lock():
            record = self.load()
            require(record['state'] == 'succeeded' and record['stage'] == 'preflight' and record['action'] == 'preflight',
                    'A completed preflight is required before issuing host approval')
            c = record.get('candidate')
            require(isinstance(c, dict), 'Missing signed candidate')
            path = self.permit_file
            require(path.parent.is_dir() and path.parent.stat().st_uid == 0 and not path.parent.stat().st_mode & 0o022,
                    'Maintenance permit directory must be root-controlled')
            require(not path.exists() and not path.is_symlink(), 'Maintenance permit already exists')
            permit = {'candidate': c, 'fenced': True, 'expires_at': int(time.time()) + seconds}
            fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'w') as f:
                json.dump(permit, f, sort_keys=True)
                f.write('\n'); f.flush(); os.fsync(f.fileno())
            return {'approved_until':permit['expires_at'], 'version':c['version']}

    def enrolled_dir(self):
        path_file = trusted_file(self.dir / 'updater-state-path', {0o400, 0o600})
        path = path_file.read_text().strip()
        require(path.startswith('/') and '\n' not in path and len(path) <= 1024, 'Invalid enrolled updater path')
        return private_dir(path)

    def reconcile(self):
        """Root-operator-only repair after manually resolving interrupted updater state.

        The web RPC cannot invoke this method. Never discard an unfinished SQL
        migration or unknown activation; update.py recover must be completed first.
        """
        with self.lock():
            old = self.load()
            if old['state'] in ('queued', 'running'):
                require(time.time() - old.get('heartbeat_at', old.get('started_at', 0)) > 120,
                        'Worker may still be active; do not reconcile')
            args = [sys.executable, str(self.updater), '--state-dir', str(self.enrolled_dir()), 'status']
            try:
                p = subprocess.run(args, capture_output=True, timeout=45, env={'PATH': PATH, 'LANG':'C','HOME':'/root'},
                                   stdin=subprocess.DEVNULL, cwd='/', check=False)
                require(p.returncode == 0 and len(p.stdout) <= 262144, 'Host updater status is unavailable')
                state = json.loads(p.stdout)
                require(isinstance(state, dict) and state.get('pending') is None and state.get('env_changed') is False,
                        'Finish operator recovery and restore pinned environment before reconciliation')
                current = state.get('current')
                require(isinstance(current, dict) and VERSION.fullmatch(current.get('version',''))
                        and COMMIT.fullmatch(current.get('commit','')) and SHA.fullmatch(current.get('manifest','')),
                        'Updater has no verified current release')
                c = old.get('candidate')
                applied = c is not None and c == {'version':current['version'], 'commit':current['commit'],
                                                  'manifest_sha256':current['manifest']}
                record = {'state':'succeeded', 'stage':'applied' if applied else 'none',
                          'candidate': c if applied else None, 'action':'reconcile',
                          'result':'Operator reconciled verified host updater state',
                          'finished_at':int(time.time())}
                write_json(self.record, record)
                return {'stage':record['stage'],'status':'reconciled'}
            except (OSError, ValueError, subprocess.TimeoutExpired, json.JSONDecodeError):
                raise Rejected('Reconciliation failed; inspect host updater state')

    def worker(self, job_id):
        with self.lock():
            job = self.load()
            require(job.get('job_id') == job_id and job['state'] == 'queued', 'Job no longer queued')
            job['state'] = 'running'; job['heartbeat_at'] = int(time.time()); write_json(self.record, job)
        finished = threading.Event()
        def heartbeat():
            while not finished.wait(8):
                try:
                    with self.lock():
                        now = self.load()
                        if now.get('job_id') != job_id or now['state'] != 'running': return
                        now['heartbeat_at'] = int(time.time()); write_json(self.record, now)
                except (OSError, ValueError, Rejected):
                    return
        thread = threading.Thread(target=heartbeat, daemon=True); thread.start()
        result = 'failed'; stage = job['stage']; success = False
        try:
            c = job['candidate']; action = job['action']
            updir = self.enrolled_dir()
            args = [sys.executable, str(self.updater), '--state-dir', str(updir), action,
                    '--version', c['version'], '--commit', c['commit']]
            if action in ('preflight', 'apply'):
                bundle = updir / 'releases' / (c['version'] + '-' + c['commit']) / 'bundle'
                require(bundle.is_dir() and not bundle.is_symlink(), 'Previously verified bundle is missing')
                args += ['--bundle', str(bundle)]
            if action == 'apply':
                self.permit(c, consume=True)
                args.append('--offline')
            output = self.dir / ('output-' + job_id + '.json')
            error = self.dir / ('error-' + job_id + '.log')
            # Output and diagnostics remain root-only; never return raw updater stderr to PHP.
            fd = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            ef = os.open(error, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'wb') as out, os.fdopen(ef, 'wb') as err:
                proc = subprocess.run(args, stdout=out, stderr=err, timeout={'fetch':2400,'preflight':2400,'apply':10800}[action],
                                      stdin=subprocess.DEVNULL, cwd='/', env={'PATH':PATH,'LANG':'C','HOME':'/root'}, check=False)
            require(proc.returncode == 0 and output.stat().st_size <= 262144, 'Update manager rejected operation; review host logs')
            report = json.loads(output.read_text())
            receipt = report if action == 'fetch' else report.get('candidate') if action == 'preflight' else report.get('current')
            require(isinstance(receipt, dict) and receipt.get('version') == c['version'] and receipt.get('commit') == c['commit']
                    and receipt.get('manifest') == c['manifest_sha256'], 'Updater signed receipt differs from pinned release')
            if action == 'preflight': require(report.get('status') == 'passed', 'Preflight did not pass')
            stage = {'fetch':'fetched','preflight':'preflight','apply':'applied'}[action]
            success = True
            result = 'Signed release verified' if action == 'fetch' else 'Preflight passed' if action == 'preflight' else 'Update activated'
        except (Rejected, OSError, ValueError, KeyError, subprocess.TimeoutExpired, json.JSONDecodeError) as e:
            # Error class only: no traceback or secrets leave the root-owned directory.
            result = 'Host update failed (' + type(e).__name__ + '); inspect host logs'
        finally:
            finished.set(); thread.join(timeout=2)
            with self.lock():
                latest = self.load()
                if latest.get('job_id') == job_id:
                    latest.update({'state':'succeeded' if success else 'failed',
                                   'stage':stage,'result':result,'finished_at':int(time.time()),'heartbeat_at':int(time.time())})
                    write_json(self.record, latest)


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(3)
        try:
            cred = self.request.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12)
            _, uid, _ = struct.unpack('3i', cred)
            require(uid == self.server.allowed_uid, 'Peer UID is not permitted')
            data = bytearray()
            while len(data) <= MAX_REQUEST:
                chunk = self.request.recv(min(512, MAX_REQUEST + 1 - len(data)))
                if not chunk: break
                data.extend(chunk)
                if b'\n' in chunk: break
            require(len(data) <= MAX_REQUEST and data.endswith(b'\n') and data.count(b'\n') == 1, 'Invalid request framing')
            response = {'ok':True,'data':self.server.agent.request(json.loads(data))}
        except (Rejected, ValueError, TypeError, KeyError, OSError, json.JSONDecodeError):
            response = {'ok':False,'error':'Operation rejected; check agent configuration and operator logs'}
        self.request.sendall((json.dumps(response, separators=(',', ':'))+'\n').encode())


class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    allow_reuse_address = False


def serve(args):
    require(os.geteuid() == 0 and 1 <= args.allowed_uid <= 65534 and 1 <= args.allowed_gid <= 65534, 'Root and dedicated non-root UID/GID are required')
    agent = Agent(args.state_dir, args.updater, args.permit_file)
    sock = Path(args.socket)
    require(sock.is_absolute() and sock.parent.resolve() == sock.parent and not sock.exists() and not sock.is_symlink(), 'Socket path must be unused and absolute')
    require(sock.parent.is_dir() and sock.parent.stat().st_uid == 0 and not sock.parent.stat().st_mode & 0o022, 'Socket directory must be controlled by root')
    old_umask = os.umask(0o177)
    try:
        with Server(str(sock), Handler) as server:
            server.agent = agent; server.allowed_uid = args.allowed_uid
            os.chown(sock, 0, args.allowed_gid)
            os.chmod(sock, 0o660)
            try: server.serve_forever(poll_interval=.5)
            finally: sock.unlink(missing_ok=True)
    finally: os.umask(old_umask)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest='mode', required=True)
    s = sub.add_parser('serve'); s.add_argument('--state-dir', required=True); s.add_argument('--updater', required=True)
    s.add_argument('--socket', required=True); s.add_argument('--allowed-uid', required=True, type=int)
    s.add_argument('--allowed-gid', required=True, type=int); s.add_argument('--permit-file')
    w = sub.add_parser('worker'); w.add_argument('--state-dir', required=True); w.add_argument('--updater', required=True)
    w.add_argument('--job-id', required=True); w.add_argument('--permit-file')
    r = sub.add_parser('reconcile'); r.add_argument('--state-dir',required=True); r.add_argument('--updater',required=True)
    permit = sub.add_parser('approve-apply'); permit.add_argument('--state-dir',required=True); permit.add_argument('--updater',required=True)
    permit.add_argument('--permit-file', required=True); permit.add_argument('--confirm-fenced',action='store_true',required=True)
    permit.add_argument('--valid-seconds', type=int, default=600)
    a = p.parse_args()
    try:
        require(os.geteuid() == 0, 'Root host privileges required')
        if a.mode == 'serve': serve(a)
        elif a.mode == 'approve-apply':
            print(json.dumps(Agent(a.state_dir,a.updater,a.permit_file).issue_permit(a.confirm_fenced,a.valid_seconds)))
        elif a.mode == 'reconcile':
            print(json.dumps(Agent(a.state_dir,a.updater).reconcile()))
        else:
            require(re.fullmatch(r'[a-f0-9]{32}', a.job_id) is not None, 'Invalid job id')
            Agent(a.state_dir, a.updater, a.permit_file).worker(a.job_id)
    except (Rejected, OSError, ValueError, KeyError) as e:
        print('Update agent rejected: ' + str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
