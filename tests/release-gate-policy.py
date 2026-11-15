#!/usr/bin/env python3
"""Exercise real application fail-closed behavior and gate evidence without services."""
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
import urllib.error
import xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('gate', ROOT/'tests/release-gate.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)


def check(name, condition):
    assert condition, name
    print('PASS '+name, flush=True)


def http(env):
    with socket.socket() as s:
        s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
        def req(path):
            try:
                r=urllib.request.urlopen(f'http://127.0.0.1:{port}'+path,timeout=3)
            except urllib.error.HTTPError as e: r=e
            with r:return r.status,r.read()
        try:
            for _ in range(100):
                try:
                    if req('/health/live')[0]==200:break
                except OSError:time.sleep(.05)
            else:raise RuntimeError('HTTP server unavailable')
            check('liveness remains available',req('/health/live')[0]==200)
            for path in ('/api/v1/pages','/login','/health/ready'):
                status,body=req(path);check('invalid deployment blocks '+path,status==503 and (json.loads(body)['status']=='unavailable' if path=='/health/ready' else b'Service unavailable' in body))
        finally:p.terminate();p.wait(timeout=10)


with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)
    os.environ.update(BOOK_PG_DSN='production-secret',AWS_SECRET_ACCESS_KEY='secret',COMPOSE_PROJECT_NAME='production',PYTHONOPTIMIZE='1')
    env=gate.isolated_env()
    check('production settings not inherited',not any(k in env for k in ('BOOK_PG_DSN','AWS_SECRET_ACCESS_KEY','COMPOSE_PROJECT_NAME','PYTHONOPTIMIZE')) and env['COMPOSE_DISABLE_ENV_FILE']=='1')
    report=path/'report';report.mkdir();g=gate.Gate(report,'test',env)
    check('successful subprocess recorded',g.run('pass',[sys.executable,'-c','print("ok")']))
    check('failed subprocess recorded',not g.run('fail',[sys.executable,'-c','raise SystemExit(7)']))
    check('timeout fails gate',not g.run('timeout',[sys.executable,'-c','import time; time.sleep(5)'],.1))
    g.finish(True)
    data=json.loads((report/'report.json').read_text());xml=ET.parse(report/'junit.xml').getroot()
    check('failed evidence cannot be marked passed',data['status']=='failed' and [x['exit_code'] for x in data['steps']]==[0,7,124] and xml.attrib['failures']=='2')
    http(dict(env,BOOK_DATA_DIR=str(path/'data'),BOOK_OIDC_REQUIRED='1'))
    http(dict(env,BOOK_DATA_DIR=str(path/'data2'),BOOK_REDIS_HOST='redis'))
