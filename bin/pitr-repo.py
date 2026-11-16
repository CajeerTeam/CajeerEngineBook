#!/usr/bin/env python3
"""pgBackRest operator commands; repository config is supplied by the PostgreSQL operator.
Only named, authenticated-sidecar targets are supported by the Book recovery path.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

LABEL = re.compile(r'\d{8}-\d{6}F(?:_\d{8}-\d{6}[DI])?\Z', re.ASCII)
ID = re.compile(r'\d{8}T\d{6}Z-[a-f0-9]{32}\Z', re.ASCII)
WAL = re.compile(r'[0-9A-F]{24}\Z')
LSN = re.compile(r'[0-9A-F]{1,8}/[0-9A-F]{1,8}\Z')


def meta(data):
    if not isinstance(data, dict) or data.get('format') != 1:
        raise ValueError('Invalid sidecar metadata')
    patterns = {'id': ID, 'backup': LABEL, 'wal': WAL, 'lsn': LSN,
                'system_id': re.compile(r'[0-9]{1,20}\Z'), 'namespace': re.compile(r'[a-f0-9]{32}\Z'),
                'binding': re.compile(r'[a-f0-9]{32}\Z'), 'database': re.compile(r'[a-zA-Z0-9_]{1,63}\Z')}
    for k, pattern in patterns.items():
        if not isinstance(data.get(k), str) or not pattern.fullmatch(data[k]):
            raise ValueError('Invalid sidecar field: '+k)
    if data.get('point') != 'book_'+data['id'][-32:] or type(data.get('timeline')) is not int or data['timeline'] != int(data['wal'][:8], 16) or data['timeline'] < 1:
        raise ValueError('Invalid restore point/timeline')
    if type(data.get('captured_at')) is not int or data['captured_at'] < 1:
        raise ValueError('Invalid capture time')
    return data


def lsn(value):
    if not isinstance(value, str) or not LSN.fullmatch(value):
        raise ValueError('Invalid LSN')
    hi, lo = value.split('/')
    return (int(hi,16)<<32)+int(lo,16)


def encrypted(info):
    if not isinstance(info,list) or len(info)!=1 or info[0].get("cipher")!="aes-256-cbc":
        raise ValueError("Encrypted pgBackRest repository (aes-256-cbc) required")
    return info


def select_backup(info, m):
    """Reject unrelated repositories, expired/error backups and targets before backup stop."""
    if not isinstance(info, list) or len(info) != 1 or info[0].get('status', {}).get('code') != 0:
        raise ValueError('Repository stanza is not ready')
    encrypted(info)
    stanza = info[0]
    matches = [b for b in stanza.get('backup', []) if b.get('label') == m['backup'] and not b.get('error', False)]
    if len(matches) != 1:
        raise ValueError('Backup set missing or failed')
    b = matches[0]
    # Match the backup database incarnation, not merely the latest stanza database.
    db = [d for d in stanza.get('db', []) if d.get('id') == b.get('database', {}).get('id') and d.get('repo-key') == b.get('database', {}).get('repo-key')]
    if len(db) != 1 or str(db[0].get('system-id')) != m['system_id']:
        raise ValueError('Backup set is from a different PostgreSQL cluster')
    stop = b.get('archive', {}).get('stop')
    if not isinstance(stop, str) or not WAL.fullmatch(stop) or stop[:8] != m['wal'][:8] or stop > m['wal']:
        raise ValueError('Backup and point must be on the same timeline, in chronological order')
    # pgBackRest versions exposing LSNs permit an exact stop-LSN check.
    stop_lsn = b.get('lsn', {}).get('stop')
    if stop_lsn is not None and lsn(stop_lsn) >= lsn(m['lsn']):
        raise ValueError('Restore point must follow backup completion')
    if type(b.get('timestamp', {}).get('stop')) is not int or b['timestamp']['stop'] > m['captured_at']:
        raise ValueError('Restore point predates completed backup')
    return b


def new_directory(path):
    p = Path(path)
    if not p.is_absolute() or '..' in p.parts or p.exists() or p.is_symlink() or not p.parent.is_dir() or p.parent.resolve() != p.parent:
        raise ValueError('Restore requires an absolute, new directory under a real existing parent')
    # No existing PGDATA, no delta/force restores, no symlink targets.
    p.mkdir(mode=0o700)
    return p


class Repo:
    def __init__(self, config, stanza):
        if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', stanza):
            raise ValueError('Invalid stanza')
        if not Path(config).is_absolute() or not Path(config).is_file():
            raise ValueError('Explicit pgBackRest configuration required')
        self.base = ['pgbackrest', '--config='+config, '--stanza='+stanza, '--repo=1']

    def run(self, *args, timeout=21600, capture=False):
        return subprocess.run([*self.base, *args], check=True, timeout=timeout, text=True,
                              stdout=subprocess.PIPE if capture else sys.stderr).stdout

    def info(self):
        return json.loads(self.run('--output=json', 'info', timeout=60, capture=True))

    def backup(self, kind):
        before = {b['label'] for s in encrypted(self.info()) for b in s.get('backup', [])}
        self.run('--type='+kind, '--no-expire-auto', '--archive-check', 'backup')
        info = self.info()
        if len(info)!=1 or info[0].get('status',{}).get('code')!=0:
            raise ValueError('Backup repository not ready')
        added = [b['label'] for b in info[0].get('backup',[]) if b['label'] not in before and not b.get('error',False)]
        if len(added)!=1 or not LABEL.fullmatch(added[0]):
            raise ValueError('Cannot unambiguously identify new backup set; do not run concurrent backups')
        return {'backup': added[0]}

    def archive_check(self, m, wait, pgdata=None):
        select_backup(self.info(), m)
        deadline = time.monotonic()+wait
        # Read the actual WAL from the repository, not merely pg_stat_archiver counters.
        with tempfile.TemporaryDirectory(prefix='book-wal-') as tmp:
            path = Path(tmp)/m['wal']
            while True:
                try:
                    self.run(*(['--pg1-path='+str(pgdata)] if pgdata is not None else []), '--no-archive-async', 'archive-get', m['wal'], str(path), timeout=max(1,min(120,int(deadline-time.monotonic()))))
                    if path.is_file() and path.stat().st_size > 0:
                        return {'status':'archived', 'id':m['id'], 'point':m['point'], 'backup':m['backup'], 'wal':m['wal'], 'captured_at':m['captured_at'], 'archive_checked_at':int(time.time())}
                except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                    pass
                if path.exists(): path.unlink()
                if time.monotonic()>=deadline: raise RuntimeError('Restore point WAL is not retrievable from repository')
                time.sleep(min(2,max(0,deadline-time.monotonic())))

    def restore(self, m, target):
        select_backup(self.info(), m)
        p = new_directory(target)
        # Config is trusted operator input. No delta, force, or existing target.
        # Disable archiving so a drill cannot write a divergent timeline into the source repository.
        self.run('--pg1-path='+str(p), '--set='+m['backup'], '--type=name', '--target='+m['point'],
                 '--target-timeline='+str(m['timeline']), '--target-action=promote', '--archive-mode=off',
                 '--no-delta', '--no-force', '--no-link-all', 'restore')
        # archive-get validates the local pg_control; the source may no longer exist.
        self.archive_check(m, 300, p)
        return {'status':'restored_files', 'pgdata':str(p), 'id':m['id'], 'point':m['point']}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--config',default='/etc/pgbackrest/pgbackrest.conf');p.add_argument('--stanza',default='book')
    sub=p.add_subparsers(dest='cmd',required=True)
    b=sub.add_parser('backup');b.add_argument('--type',choices=['full','diff','incr'],default='full')
    a=sub.add_parser('archive-check');a.add_argument('--wait',type=int,default=300)
    r=sub.add_parser('restore');r.add_argument('target');r.add_argument('--isolated',action='store_true',required=True)
    args=p.parse_args();repo=Repo(args.config,args.stanza)
    if args.cmd=='backup': out=repo.backup(args.type)
    else:
        # Metadata must come from php bin/pitr.php inspect ID (authenticated remote sidecar).
        raw=sys.stdin.buffer.read(16385)
        if len(raw)>16384: raise ValueError('Metadata too large')
        m=meta(json.loads(raw))
        if args.cmd=='archive-check':
            if not 1<=args.wait<=3600: raise ValueError('Invalid WAL wait')
            out=repo.archive_check(m,args.wait)
        else: out=repo.restore(m,args.target)
    print(json.dumps(out,sort_keys=True))


if __name__=='__main__':
    try: main()
    except (ValueError, RuntimeError, OSError, subprocess.SubprocessError) as e:
        print('PITR repository operation failed: '+str(e),file=sys.stderr);sys.exit(1)
