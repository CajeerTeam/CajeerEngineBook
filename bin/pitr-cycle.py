#!/usr/bin/env python3
"""Run a physical backup + paired sidecar, verify archived WAL and persist an operator receipt.
Run on the Compose host, with the production Compose project selected explicitly by the operator.
"""
import argparse
import fcntl
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time


def run(*args, input=None):
    return json.loads(subprocess.run(['docker','compose',*args],input=input,text=True,stdout=subprocess.PIPE,check=True,timeout=21600).stdout)


def durable(path, data):
    fd, name = tempfile.mkstemp(prefix='.pitr-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(data,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(name,path)
        d=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
        try: os.fsync(d)
        finally: os.close(d)
    finally:
        if os.path.exists(name): os.unlink(name)


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('directory');p.add_argument('--type',choices=['full','diff','incr'],default='full');p.add_argument('--backup-label',help='Use an already completed retained physical backup (frequent paired restore points)');p.add_argument('--retry-id',help='Retry an existing local sidecar upload, no new point');p.add_argument('--health',type=int,metavar='MAX_AGE_SECONDS');a=p.parse_args()
    root=Path(a.directory)
    if not root.is_absolute() or root.is_symlink() or not root.is_dir() or root.stat().st_mode&0o077:raise ValueError('Use an existing private receipt directory (0700).')
    if a.health is not None:
        if a.health<1:raise ValueError('Invalid age')
        r=json.loads((root/'latest.json').read_text());age=int(time.time())-r['captured_at'];ok=0<=age<=a.health and r['status']=='archived';print(json.dumps({'status':'ok' if ok else 'unavailable','age_seconds':age,'max_age_seconds':a.health,'id':r['id']}));return 0 if ok else 1
    with open(root/'cycle.lock','a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        # Keep the authenticated local sidecar ID available for retry after a WAL/archive failure.
        if (root/'pending.json').exists() and not a.retry_id:
            a.retry_id=json.loads((root/'pending.json').read_text())['id']
        if a.retry_id:
            m=run('run','--rm','--no-deps','-T','pitr-sidecar','php','bin/pitr.php','retry',a.retry_id)
        else:
            label=a.backup_label or run('exec','-T','-u','postgres','postgres','book-pitr-repo','backup','--type',a.type)['backup']
            m=run('run','--rm','--no-deps','-T','pitr-sidecar','php','bin/pitr.php','capture',label)
        durable(root/'pending.json',m)
        # Re-authenticate the remote copy before trusting its metadata.
        m=run('run','--rm','--no-deps','-T','pitr-sidecar','php','bin/pitr.php','inspect',m['id'])
        r=run('exec','-T','-u','postgres','postgres','book-pitr-repo','archive-check',input=json.dumps(m))
        if r['id']!=m['id'] or r['status']!='archived':raise ValueError('Unexpected archive verification response')
        durable(root/(r['id']+'.json'),r);durable(root/'latest.json',r);(root/'pending.json').unlink();print(json.dumps(r));return 0


if __name__=='__main__':
    os.umask(0o077)
    try: sys.exit(main())
    except (ValueError,OSError,KeyError,subprocess.SubprocessError) as e:print('PITR cycle failed: '+str(e),file=sys.stderr);sys.exit(1)
