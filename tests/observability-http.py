#!/usr/bin/env python3
"""Real multi-process HTTP requests, bounded labels, authenticated scrape, outage."""
import concurrent.futures
import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
ROOT=Path(__file__).resolve().parents[1]

def req(port,path,method='GET',headers=None):
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
    try:
        c.request(method,path,headers=headers or {});r=c.getresponse();return r.status,dict(r.getheaders()),r.read()
    finally:c.close()

def check(name,condition):
    assert condition,name
    print('PASS '+name,flush=True)

def start(env,log):
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
    for _ in range(100):
        try:
            if p.poll() is not None:break
            req(port,'/health/live');return p,port
        except OSError:time.sleep(.03)
    p.terminate();p.wait(timeout=10);raise RuntimeError('HTTP server failed to start')

with tempfile.TemporaryDirectory(prefix='book-observe-') as tmp:
    root=Path(tmp);data=root/'data';data.mkdir();token='a'*64;secret=root/'token';secret.write_text(token+'\n');secret.chmod(0o600)
    env={k:v for k,v in os.environ.items() if not k.startswith('BOOK_')}
    env.update(BOOK_DATA_DIR=str(data),BOOK_OBSERVABILITY='1',BOOK_SECURE_COOKIE='0')
    # File mode fixtures are real application data, not synthetic metric injection.
    subprocess.run(['php','-r',"require 'tests/oidc-bootstrap.php'; $s=new CajeerBook\\IdentityStore(getenv('BOOK_DATA_DIR'));$s->initialize('owner','Owner','owner@example.org','password-owner-123'); CajeerBook\\BookStore::writeDatabase(getenv('BOOK_DATA_DIR').'/books.json',['schema'=>1,'pages'=>[]]);"],cwd=ROOT,env=env,check=True)
    children=[]
    from contextlib import ExitStack
    with ExitStack() as stack:
        logs=[stack.enter_context((root/f'http-{i}.log').open('w+')) for i in range(3)]
        try:
            for i in range(2):children.append(start(env,logs[i]))
            exporter=dict(env,BOOK_OBSERVABILITY_EXPORTER='1',BOOK_METRICS_TOKEN_FILE=str(secret),BOOK_MONITOR_WORKERS='1',BOOK_MONITOR_BACKUP='1')
            children.append(start(exporter,logs[2]));a,b,metrics=[c[1] for c in children];auth={'Authorization':'Bearer '+token}
            check('metrics disabled on normal app',req(a,'/metrics')[0]==404)
            check('exporter liveness independent of failed dependencies',req(metrics,'/health/live')[0]==200 and json.loads(req(metrics,'/health/live')[2])=={'status':'ok'})
            check('exporter liveness accepts HEAD but not POST',req(metrics,'/health/live','HEAD')[0]==200 and req(metrics,'/health/live','POST')[0]==404)
            check('exporter denies anonymous and wrong token',req(metrics,'/metrics')[0]==404 and req(metrics,'/metrics',headers={'Authorization':'Bearer wrong'})[0]==404)
            check('exporter cannot serve app routes',req(metrics,'/login',headers=auth)[0]==404 and req(metrics,'/metrics','POST',auth)[0]==405)
            def work(i):return req(a if i%2 else b,'/api/v1/pages?secret=do-not-log',headers={'Cookie':'sensitive-cookie=do-not-log','Authorization':'Bearer do-not-log'})
            with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:results=list(pool.map(work,range(80)))
            check('80 actual API requests on two processes',all(r[0]==200 for r in results))
            ids=[r[1]['X-Request-ID'] for r in results];check('unique server-generated request IDs',len(set(ids))==80)
            code,_,body=req(metrics,'/metrics',headers=auth);text=body.decode()
            check('aggregated counters include both PHP processes',code==200 and 'book_http_requests_total{route="api",method="GET",status="2xx"} 80\n' in text)
            check('Prometheus cumulative histogram with zero buckets and +Inf', '# TYPE book_http_request_duration_seconds histogram' in text and 'book_http_request_duration_seconds_bucket{route="api",le="+Inf"} 80\n' in text and 'book_http_request_duration_seconds_count{route="api"} 80\n' in text)
            # Trigger actual worker tick and backup state publication by real recovery command in separate tests.
            subprocess.run(['php','-r',"require 'tests/oidc-bootstrap.php';$r=getenv('BOOK_DATA_DIR');(new CajeerBook\\AutomationWorker($r,new CajeerBook\\IdentityStore($r)))->tick();"],env=env,cwd=ROOT,check=True)
            code,_,body=req(metrics,'/diagnostics',headers=auth);d=json.loads(body);check('component failures are isolated; worker heartbeat recovers',code==503 and d['checks']['worker']=='ok' and d['checks']['backup']=='unavailable' and d['checks']['storage']=='ok')
            lines=[];rows=[]
            for log in logs:log.flush();log.seek(0);lines.extend(log.read().splitlines())
            for line in lines:
                i=line.find('{"timestamp":')
                if i>=0:
                    try:rows.append(json.loads(line[i:]))
                    except ValueError:pass
            check('structured logs correlate request IDs and omit credentials',set(ids)<=set(r.get('request_id') for r in rows) and all('do-not-log' not in json.dumps(r) and token not in json.dumps(r) for r in rows))
            # Deliberate invalid metric file must not cause an application failure.
            (data/'observability'/'metrics-0.json').write_text('broken')
            check('corrupt telemetry does not take application offline',req(a,'/api/v1/pages')[0]==200)
            code,_,body=req(metrics,'/metrics',headers=auth);check('scrape reports telemetry failure and other dependencies',code==200 and b'book_dependency_up{component="telemetry"} 0' in body and b'book_dependency_up{component="storage"} 1' in body)
        finally:
            for p,_ in children:p.terminate()
            for p,_ in children:p.wait(timeout=10)
