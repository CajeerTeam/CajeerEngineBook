#!/usr/bin/env python3
"""Bounded concurrent HTTP gate. Run only against a disposable seeded installation.

Default: real two-node file-mode API/host isolation (no optional dependencies).
--url: actual Reader, PostgreSQL search, API and draft isolation after release-http.py.
"""
import argparse
import concurrent.futures
import http.client
import importlib.util
import json
import math
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]


def request(base, path, headers=None):
    url = urllib.parse.urlsplit(base)
    conn = (http.client.HTTPSConnection if url.scheme == 'https' else http.client.HTTPConnection)(url.hostname, url.port, timeout=15)
    try:
        conn.request('GET', path, headers=headers or {})
        response = conn.getresponse()
        body = response.read()
        return response.status, body
    finally:
        conn.close()


def benchmark(operation, count, concurrency, max_p95):
    def measure(i):
        start = time.perf_counter()
        operation(i)
        return (time.perf_counter() - start) * 1000
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
        durations = sorted(pool.map(measure, range(count)))
    elapsed = time.perf_counter() - start
    report = {'requests': count, 'concurrency': concurrency, 'errors': 0,
              'p50_ms': round(durations[math.ceil(count*.50)-1], 2),
              'p95_ms': round(durations[math.ceil(count*.95)-1], 2),
              'p99_ms': round(durations[math.ceil(count*.99)-1], 2),
              'throughput_rps': round(count/elapsed, 2)}
    print(json.dumps(report), flush=True)
    if report['p95_ms'] > max_p95:
        raise RuntimeError(f'HTTP p95 exceeded {max_p95} ms')


def external(base, args):
    # Existing release gate published this page, moved its path, and left a secret draft.
    key = 'release/1.0/renamed'
    status, body = request(base, '/api/v1/pages/'+key)
    assert status == 200 and 'Mixed release content' in json.loads(body)['body']
    def operation(i):
        # Keep API and search calls below the existing per-IP Redis limits, even at 100 clients.
        if i % 6 == 0:
            status, body = request(base, '/api/v1/search?q=Mixed&book=release&version=1.0&locale=en')
            assert status == 200 and key in [hit['key'] for hit in json.loads(body)], (status, body[:200])
        elif i % 6 == 1:
            status, body = request(base, '/api/v1/search?q=Unpublished%20secret%20release&book=release')
            assert status == 200 and not json.loads(body), (status, body[:200])
        elif i % 6 == 2:
            status, body = request(base, '/api/v1/pages/'+key)
            assert status == 200 and 'Mixed release content' in json.loads(body)['body'] and b'Unpublished secret release' not in body
        else:
            status, body = request(base, '/docs/'+key)
            assert status == 200 and b'Mixed release content' in body and b'Unpublished secret release' not in body, (status, len(body))
    benchmark(operation, args.requests, args.concurrency, args.max_p95_ms)


def local(args):
    spec = importlib.util.spec_from_file_location('domains_http', ROOT/'tests/domains-http.py')
    fixture = importlib.util.module_from_spec(spec); spec.loader.exec_module(fixture)
    with tempfile.TemporaryDirectory(prefix='book-enterprise-load-') as tmp:
        env = {k:v for k,v in os.environ.items() if not k.startswith('BOOK_')}
        env.update(BOOK_DATA_DIR=tmp+'/data', BOOK_PRIMARY_HOST=fixture.MAIN,
                   BOOK_DOMAIN_EDGE_SECRET=fixture.EDGE, BOOK_SECURE_COOKIE='1')
        state = json.loads(subprocess.check_output(['php','-r',fixture.SEED],cwd=ROOT,env=env))
        processes=[]; logs=[]; bases=[]
        try:
            for _ in range(2):
                with socket.socket() as s:
                    s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
                base=f'http://127.0.0.1:{port}'; bases.append(base)
                log=tempfile.TemporaryFile(); logs.append(log)
                p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log); processes.append(p)
                for _ in range(100):
                    try:
                        if request(base,'/health/live',{'Host':fixture.MAIN,'X-Book-Edge-Proto':'https','X-Book-Edge-Secret':fixture.EDGE})[0]==200: break
                    except OSError: time.sleep(.05)
                else: raise RuntimeError('PHP node unavailable')
            def operation(i):
                host,title,mid=(fixture.A,'Alpha',state['media'][0]) if i%2==0 else (fixture.B,'Beta',state['media'][1])
                headers={'Host':host,'X-Book-Edge-Proto':'https','X-Book-Edge-Secret':fixture.EDGE,'Cookie':'__Host-book_session='+state['token']}
                base=bases[(i//2)%2]
                if i%6 in (0,1):
                    status,body=request(base,'/api/v1/pages',headers); pages=json.loads(body)
                    assert status==200 and list(pages)==['guide/1/start'] and pages['guide/1/start']['title']==title
                elif i%6 in (2,3):
                    status,body=request(base,'/media/'+mid,headers); assert status==200 and body==('Public media '+title).encode()
                else:
                    status,_=request(base,'/api/v1/pages/secret/1/start',headers); assert status==404
            benchmark(operation,args.requests,args.concurrency,args.max_p95_ms)
        finally:
            for p in processes: p.terminate(); p.wait(timeout=10)
            for log in logs: log.close()


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url',help='Disposable deployment seeded by release-http.py; omit for local storage-only load')
    parser.add_argument('--requests',type=int,default=300)
    parser.add_argument('--concurrency',type=int,default=100)
    parser.add_argument('--max-p95-ms',type=float,default=5000)
    args=parser.parse_args()
    if not 1<=args.concurrency<=args.requests<=10000 or args.max_p95_ms<=0: parser.error('Invalid load bounds')
    external(args.url.rstrip('/'),args) if args.url else local(args)
