#!/usr/bin/env python3
"""Disposable corpus and real HTTP benchmark. No production URLs or credentials inherited."""
import argparse
import concurrent.futures
from contextlib import nullcontext
import http.client
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import platform
import random
import re
import socket
import signal
import subprocess
import tempfile
import threading
import time
import urllib.parse
import uuid
import xml.etree.ElementTree as ET
import load_qualification as qualification
from load_maintenance import Maintenance, Dependencies

ROOT = Path(__file__).resolve().parents[1]
FORMAT = 1
_policy_spec = importlib.util.spec_from_file_location('release_qualification_policy', ROOT/'bin/qualification_policy.py')
release_policy = importlib.util.module_from_spec(_policy_spec)
_policy_spec.loader.exec_module(release_policy)


def dump(path, data):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(data, indent=2, ensure_ascii=False, allow_nan=False)+'\n')
    tmp.replace(path)


def percentile(values, fraction):
    return round(sorted(values)[max(0, math.ceil(len(values)*fraction)-1)], 3) if values else None


def request_http(port, path, method='GET', form=None, headers=None, json_body=None, source=None):
    c = http.client.HTTPConnection('127.0.0.1', port, timeout=30, source_address=(source,0) if source else None)
    headers = dict(headers or {})
    if form is not None:
        form = urllib.parse.urlencode(form)
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    if json_body is not None:
        form=json.dumps(json_body);headers['Content-Type']='application/json'
    try:
        c.request(method, path, form, headers)
        r = c.getresponse()
        # Bound responses even if a regression emits unexpectedly huge output.
        data = r.read(32*1024*1024+1)
        if len(data)>32*1024*1024:
            raise RuntimeError('response exceeds 32 MiB')
        return r.status, dict((k.lower(),v) for k,v in r.getheaders()), data
    finally:
        c.close()


class RequestFailure(RuntimeError):
    def __init__(self, reason, status, size):
        super().__init__(reason);self.status=status;self.size=size


def require(condition, reason):
    if not condition:
        raise RuntimeError(reason)


def proc_resources(pids):
    result = {'rss_bytes': 0, 'cpu_seconds': 0.0, 'read_bytes': 0, 'write_bytes': 0, 'processes': 0}
    for pid in pids:
        try:
            stat = Path(f'/proc/{pid}/stat').read_text().rsplit(')',1)[1].split()
            result['cpu_seconds'] += (int(stat[11])+int(stat[12]))/os.sysconf('SC_CLK_TCK')
            result['rss_bytes'] += int(stat[21])*os.sysconf('SC_PAGE_SIZE')
            result['processes'] += 1
        except (OSError, ValueError, IndexError):
            continue
        try:
            io = dict(line.split(':',1) for line in Path(f'/proc/{pid}/io').read_text().splitlines())
            for key in ('read_bytes','write_bytes'):
                result[key] += int(io[key])
        except (OSError, ValueError, KeyError):
            pass
    return result


def start_node(env, log, bounded=False):
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'], cwd=ROOT, env=env, stdout=subprocess.PIPE if bounded else log, stderr=subprocess.STDOUT if bounded else log)
    if bounded:
        def drain():
            remaining=8*1024*1024
            while chunk:=p.stdout.read(65536):
                if remaining:
                    part=chunk[:remaining];log.buffer.write(part);remaining-=len(part)
                    if not remaining:log.buffer.write(b'\n[log limit reached; further output discarded]\n')
            log.flush();p.stdout.close()
        p.log_thread=threading.Thread(target=drain,daemon=True);p.log_thread.start()
    try:
        for _ in range(200):
            if p.poll() is not None: break
            try:
                if request_http(port,'/health/live')[0]==200: return p,port
            except OSError: pass
            time.sleep(.05)
        raise RuntimeError('PHP node not ready')
    except BaseException:
        stop_node(p);raise


def stop_node(proc):
    if proc.poll() is None:
        proc.terminate()
        try:proc.wait(timeout=10)
        except subprocess.TimeoutExpired:proc.kill();proc.wait(timeout=10)
    if hasattr(proc,'log_thread'):proc.log_thread.join(timeout=5)


def metric_delta(before, after):
    # Only monotonically increasing counter and histogram series, no timestamps/gauges.
    return {k:round(v-before.get(k,0),6) for k,v in after.items()
            if any(x in k.split('{')[0] for x in ('_total','_count','_sum','_bucket')) and v>=before.get(k,0)}


def run_phase(name, operations, count, concurrency, rate, pids, limits):
    # Closed-loop clients; optional global launch pacing. Record failures, not just successful latency.
    begin=time.perf_counter(); initial=proc_resources(pids); peak=[initial['rss_bytes']]; stop=threading.Event()
    def sample():
        while not stop.wait(.1): peak[0]=max(peak[0],proc_resources(pids)['rss_bytes'])
    sampler=threading.Thread(target=sample,daemon=True);sampler.start()
    def task(i):
        if rate:
            delay=begin+i/rate-time.perf_counter()
            if delay>0:time.sleep(delay)
        op,fn=operations[i%len(operations)]
        start=time.perf_counter(); status=0; size=0; error=None
        try:
            status,size=fn(i)
        except Exception as exc:
            if isinstance(exc,RequestFailure):status=exc.status;size=exc.size
            # No response body, URLs, or credentials in evidence.
            error=type(exc).__name__+': '+str(exc)[:120]
        return op,(time.perf_counter()-start)*1000,status,size,error
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as pool:
            rows=[row for batch in pool.map(lambda worker:[task(i) for i in range(worker,count,concurrency)],range(concurrency)) for row in batch]
    finally:
        stop.set();sampler.join()
    elapsed=time.perf_counter()-begin; end=proc_resources(pids);peak[0]=max(peak[0],end['rss_bytes'])
    groups={}
    for op in sorted({r[0] for r in rows}):
        subset=[r for r in rows if r[0]==op];good=[r[1] for r in subset if not r[4]];bad=[r for r in subset if r[4]]
        statuses={str(x):sum(r[2]==x for r in subset) for x in sorted({r[2] for r in subset})}
        p95=percentile(good,.95); threshold=limits.get(op)
        groups[op]={'requests':len(subset),'successes':len(good),'errors':len(bad),'status_counts':statuses,
                    'p50_ms':percentile(good,.5),'p95_ms':p95,'p99_ms':percentile(good,.99),
                    'max_ms':round(max(good),3) if good else None,'response_bytes':sum(r[3] for r in subset),
                    'error_examples':list(dict.fromkeys(r[4] for r in bad))[:5], 'p95_limit_ms':threshold,
                    'passed':not bad and p95 is not None and (threshold is None or p95<=threshold)}
    return {'phase':name,'requests':count,'concurrency':concurrency,'rate_limit_rps':rate,'seconds':round(elapsed,3),
            'throughput_rps':round(count/elapsed,3),'successful_rps':round(sum(r[4] is None for r in rows)/elapsed,3),
            'php_resources':{'peak_rss_bytes':peak[0],'cpu_seconds':round(end['cpu_seconds']-initial['cpu_seconds'],3),
                             'read_bytes':max(0,end['read_bytes']-initial['read_bytes']),'write_bytes':max(0,end['write_bytes']-initial['write_bytes']),'processes':end['processes']},
            'operations':groups,'passed':all(v['passed'] for v in groups.values())}


def compare(report, old, regression):
    require(old.get('format')==FORMAT and old.get('status')=='passed','baseline is not a successful compatible report')
    require(old.get('profile')==report['profile'],'baseline workload/profile mismatch')
    require(old.get('environment')==report['environment'],'baseline environment mismatch')
    for key in ('published_pages','workspace_count','per_workspace','translations','body_bytes','history_samples','seed'):
        require(old.get('corpus',{}).get(key)==report.get('corpus',{}).get(key),'baseline corpus mismatch')
    result=[]
    for phase,prior in zip(report['phases'],old['phases'],strict=True):
        require(phase['phase']==prior['phase'] and set(phase['operations'])==set(prior['operations']),'baseline operation mismatch')
        if phase.get('exploratory_overload'):
            require(prior.get('exploratory_overload') and phase['passed'] and prior['passed'],'overload safety regression');continue
        for op,now in phase['operations'].items():
            previous=prior['operations'][op]['p95_ms'];current=now['p95_ms']
            passed=previous is not None and previous>0 and current is not None and current<=previous*(1+regression/100)
            result.append({'phase':phase['phase'],'operation':op,'baseline_p95_ms':previous,'current_p95_ms':current,'passed':passed})
            if report['profile'].get('qualification'):
                p99_old=prior['operations'][op]['p99_ms'];p99_now=now['p99_ms'];result.append({'phase':phase['phase'],'operation':op,'baseline_p99_ms':p99_old,'current_p99_ms':p99_now,'passed':p99_old is not None and p99_now is not None and p99_now<=p99_old*(1+regression/100)})
    return result


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--pages',type=int,default=1000);p.add_argument('--per-workspace',type=int,default=500)
    p.add_argument('--requests',type=int,default=120);p.add_argument('--concurrency',type=int,default=8);p.add_argument('--nodes',type=int,default=2)
    p.add_argument('--seed',type=int,default=42);p.add_argument('--postgres',action='store_true');p.add_argument('--redis',action='store_true')
    p.add_argument('--full',action='store_true',help='Real Markdown Reader, search, Studio save and publish (requires Composer)')
    p.add_argument('--maintenance',action='store_true',help='Time real backup, restore, worker scan and SQL reindex after load');p.add_argument('--telemetry',action='store_true');p.add_argument('--rate',type=float,default=0)
    p.add_argument('--report-dir',type=Path,required=True);p.add_argument('--baseline',type=Path);p.add_argument('--max-regression-percent',type=float,default=20)
    p.add_argument('--max-p95-ms',type=float,default=5000);p.add_argument('--reader-p95-ms',type=float);p.add_argument('--search-p95-ms',type=float);p.add_argument('--write-p95-ms',type=float)
    p.add_argument('--qualify',action='store_true',help='Timed open-loop steady/ramp/spike/stress/recovery/soak')
    p.add_argument('--phase-seconds',type=int,default=30);p.add_argument('--soak-seconds',type=int,default=86400)
    p.add_argument('--offered-rps',type=float,default=20);p.add_argument('--stress-multiplier',type=float,default=5)
    p.add_argument('--p99-ms',type=float,default=1000);p.add_argument('--error-budget',type=float,default=.001)
    p.add_argument('--min-samples',type=int,default=20);p.add_argument('--rss-growth-mib',type=int,default=128)
    p.add_argument('--source-ips',type=int,default=1,help='Actual distinct loopback socket sources, no forwarded headers')
    p.add_argument('--sample-seconds',type=int,default=30)
    p.add_argument('--background-maintenance',action='store_true',help='Run actual worker scans and backup/restore during soak')
    p.add_argument('--maintenance-interval',type=int,default=3600)
    p.add_argument('--maintenance-timeout',type=int,default=3600)
    p.add_argument('--pg-max-connections',type=int,default=0)
    p.add_argument('--redis-max-memory-mib',type=int,default=0)
    p.add_argument('--redis-max-keys',type=int,default=0)
    a=p.parse_args()
    if not (0<=a.pg_max_connections<=100000 and 0<=a.redis_max_memory_mib<=1048576 and 0<=a.redis_max_keys<=1000000000):p.error('Invalid dependency resource limits')
    if (a.pg_max_connections or a.redis_max_memory_mib or a.redis_max_keys) and (not a.qualify or not a.postgres):p.error('Dependency limits require PostgreSQL qualification')
    if (a.redis_max_memory_mib or a.redis_max_keys) and not a.redis:p.error('Redis resource limits require --redis')
    if a.background_maintenance and (not a.qualify or not a.full or not a.telemetry or a.soak_seconds<10 or not 10<=a.maintenance_interval<=86400 or not 10<=a.maintenance_timeout<=7200):p.error('Background maintenance requires --qualify --full --telemetry, soak >=10s and bounded intervals')
    if a.qualify:
        def interrupted(signum,frame):raise KeyboardInterrupt('qualification interrupted')
        signal.signal(signal.SIGTERM,interrupted)
        if a.maintenance:p.error('Use --background-maintenance for qualification; --maintenance is the legacy post-load benchmark')
        if a.rate:p.error('Use --offered-rps with --qualify')
        if not (2<=a.phase_seconds<=3600 and 2<=a.soak_seconds<=86400 and 1<=a.offered_rps<=1000 and 2<=a.stress_multiplier<=20 and a.offered_rps*a.stress_multiplier<=1000 and 1<=a.source_ips<=254 and 1<=a.sample_seconds<=60 and 1<=a.min_samples<=10000 and 1<=a.rss_growth_mib<=4096 and 0<=a.error_budget<=.01 and math.isfinite(a.p99_ms) and a.p99_ms>0):p.error('Invalid qualification limits')
        if a.p99_ms>qualification.MAX_MS:p.error('p99 exceeds histogram range')
        if a.redis and a.source_ips<math.ceil(a.offered_rps/3):p.error('Use enough actual loopback source IPs to respect API rate limit at steady load')
    elif a.source_ips!=1:p.error('--source-ips requires --qualify')
    if not (1<=a.per_workspace<=1000 and 1<=a.pages<=a.per_workspace*100 and 6<=a.requests<=100000 and 1<=a.concurrency<=min(a.requests,500) and 1<=a.nodes<=min(16,a.requests) and 0<=a.seed<=2147483647):p.error('Invalid corpus or load bounds; max 100 workspaces and 1000 pages/scope')
    for value in (a.rate,a.max_regression_percent,a.max_p95_ms,a.reader_p95_ms,a.search_p95_ms,a.write_p95_ms):
        if value is not None and (not math.isfinite(value) or value<0):p.error('Invalid numeric limit')
    if a.max_p95_ms<=0 or any(x==0 for x in (a.reader_p95_ms,a.search_p95_ms,a.write_p95_ms)):p.error('p95 limits must be positive')
    if a.maintenance and not a.full:p.error('--maintenance requires --full')
    if a.postgres and not a.full:p.error('PostgreSQL requires --full; no emulated search')
    if a.redis and (not a.postgres or (not a.qualify and a.requests>120)):p.error('Redis requires PostgreSQL and <=120 requests per phase to stay within real shared API quota')
    if a.full and not (ROOT/'vendor/autoload.php').is_file():p.error('Install Composer dependencies before --full')
    dest=a.report_dir.resolve();dest.mkdir(mode=0o700,parents=True,exist_ok=False)
    profile={'pages':a.pages,'per_workspace':a.per_workspace,'requests_per_phase':a.requests,'concurrency':a.concurrency,'nodes':a.nodes,'seed':a.seed,'backend':'postgresql' if a.postgres else 'file','redis':a.redis,'full':a.full,'telemetry':a.telemetry,'rate':a.rate,'engine':'php-cli-server','maintenance':a.maintenance}
    if a.qualify:profile['qualification']={k:getattr(a,k) for k in ('phase_seconds','soak_seconds','offered_rps','stress_multiplier','p99_ms','error_budget','min_samples','rss_growth_mib','source_ips','sample_seconds','background_maintenance','maintenance_interval','maintenance_timeout','pg_max_connections','redis_max_memory_mib','redis_max_keys')}
    if a.qualify:profile['qualification']['latency_limits']={'api':min(a.max_p95_ms,300),'media':min(a.max_p95_ms,300),'isolation':min(a.max_p95_ms,300),'reader':a.reader_p95_ms or 300,'search':a.search_p95_ms or 200,'publish':a.write_p95_ms or 500,'save':a.write_p95_ms or 500}
    environment={'system':platform.system(),'machine':platform.machine(),'cpu_count':os.cpu_count(),'php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),'python':platform.python_version(),'kernel':platform.release(),'php_extensions':subprocess.check_output(['php','-r','echo json_encode(get_loaded_extensions());'],text=True).strip()}
    for path,key in [('/sys/fs/cgroup/cpu.max','cpu_quota'),('/sys/fs/cgroup/memory.max','memory_quota')]:
        try:environment[key]=Path(path).read_text().strip()
        except OSError:pass
    try:environment['cpu_model']=next(x.split(':',1)[1].strip() for x in Path('/proc/cpuinfo').read_text().splitlines() if x.startswith('model name'))
    except (OSError,StopIteration):pass
    try:
        digest=release_policy.source_digest_tree(ROOT)
    except (OSError, ValueError, subprocess.CalledProcessError):
        # Source ZIPs without .git may still run *diagnostic* benchmarks. They
        # cannot mint a 24-hour signed-release qualification source identity.
        if a.qualify:
            p.error('A clean Git checkout is required for signed qualification')
        diagnostic = [(file.relative_to(ROOT).as_posix(), file.read_bytes())
                      for file in [ROOT/'VERSION', *[
                          item for folder in ('src','sql','tests')
                          for item in (ROOT/folder).rglob('*')
                          if item.is_file() and item.suffix in ('.php','.py','.sql','.sh')]]]
        digest=release_policy.source_digest_entries(diagnostic)
    report={'source_digest':digest,'format':FORMAT,'release':(ROOT/'VERSION').read_text().strip(),'profile':profile,'environment':environment,'status':'failed','phases':[]}
    children=[];logs=[];env=None;seeded=False
    with tempfile.TemporaryDirectory(prefix='book-performance-') as tmp:
        root=Path(tmp)/'data';root.mkdir(mode=0o700);(root/'.benchmark-disposable').touch()
        env={k:v for k,v in os.environ.items() if not k.startswith(('BOOK_','PG','REDIS','PHP_CLI_SERVER_WORKERS'))}
        env.update(BOOK_DATA_DIR=str(root),BOOK_BENCHMARK_DISPOSABLE='1',BOOK_SECURE_COOKIE='0',BOOK_OBSERVABILITY='1' if a.telemetry else '0')
        def helper(action,*args):
            return subprocess.check_output(['php','tests/performance-seed.php',action,*map(str,args)],cwd=ROOT,env=env,text=True,timeout=7200)
        try:
            if a.postgres:
                for name in ('DSN','USER','PASSWORD'):
                    key='BOOK_BENCHMARK_PG_'+name
                    require(os.environ.get(key),'Missing '+key);env['BOOK_PG_'+name]=os.environ[key]
                env['BOOK_PG_NAMESPACE']=uuid.uuid4().hex
                seeded=True
                report['environment']['postgresql']=json.loads(helper('prepare'))
                if a.redis:
                    for name in ('HOST','PORT','PASSWORD','USER','TLS','CA'):
                        key='BOOK_BENCHMARK_REDIS_'+name
                        if key in os.environ:env['BOOK_REDIS_'+name]=os.environ[key]
                    require(env.get('BOOK_REDIS_HOST'),'Missing benchmark Redis host')
            with (dest/'seed.log').open('w') as log:
                manifest=json.loads(subprocess.check_output(['php','tests/performance-seed.php','seed',str(a.pages),str(a.per_workspace),str(a.seed)],cwd=ROOT,env=env,stderr=log,text=True,timeout=7200))
            token=manifest.pop('token');csrf=manifest.pop('csrf');scopes=manifest.pop('workspaces');report['corpus']=manifest
            require(manifest['published_pages']==a.pages,'corpus count mismatch')
            if a.postgres:
                started=time.perf_counter();helper('analyze');report['corpus']['analyze_seconds']=round(time.perf_counter()-started,3)
            for i in range(a.nodes):
                log=(dest/f'node-{i}.log').open('w');logs.append(log);children.append(start_node(env,log,a.qualify))
            ports=[port for _,port in children];pids=[x.pid for x,_ in children]
            def get(i,path,expected=200,validate=lambda b,h:True,headers=None,method='GET',form=None,json_body=None):
                status,h,b=request_http(ports[i%len(ports)],path,method,form,headers,json_body,source=f'127.0.0.{1+i%a.source_ips}' if a.qualify else None)
                if status!=expected:raise RequestFailure(f'HTTP status {status}, expected {expected}',status,len(b))
                try: valid=validate(b,h)
                except Exception:valid=False
                if not valid:raise RequestFailure('response validation failed',status,len(b))
                return status,len(b)
            rng=random.Random(a.seed)
            choices=[(rng.randrange(len(scopes)),rng.randrange(1000000)) for _ in range(a.requests)]
            def target(i,warm=False):
                if a.qualify:
                    selector=random.Random((a.seed<<32)+i);w=selector.randrange(len(scopes));n=selector.randrange(scopes[w]['pages'])
                else:w,n=choices[i%len(choices)]
                w=i%min(2,len(scopes)) if warm else w;scope=scopes[w];n=0 if warm else n%scope['pages'];version=1+(n//100)%2
                return w,n,scope,f'guide/{version}/topic-{n:04d}',f'benchw{w:03d}p{n:04d}'
            def api(i,warm):
                w,n,s,key,marker=target(i,warm)
                return get(i,s['prefix']+'/api/v1/pages/'+key,validate=lambda b,h: marker in json.loads(b)['body'] and b'Unpublished benchmark revision' not in b)
            def media(i,warm):
                w,n,s,key,marker=target(i,warm)
                return get(i,s['prefix']+'/media/'+s['media'],validate=lambda b,h:b==f'Benchmark attachment {w}'.encode())
            def reader(i,warm):
                w,n,s,key,marker=target(i,warm)
                return get(i,s['prefix']+'/docs/'+key,validate=lambda b,h:marker.encode() in b and b'Unpublished benchmark revision' not in b)
            def search(i,warm):
                w,n,s,key,marker=target(i,warm)
                return get(i,s['prefix']+'/api/v1/search?'+urllib.parse.urlencode({'q':'"'+marker+'"','book':'guide','limit':'10'}),validate=lambda b,h: [r['key'] for r in json.loads(b)]==[key] and h.get('x-search-engine')==('postgresql' if a.postgres else 'portable') and h.get('x-total-count')=='1')
            def search_broad(i,warm):
                w,n,s,key,marker=target(i,warm)
                expected=sum(1 for j in range(s['pages']) if (j//100)%2==0 and j%10!=1)
                def verify(b,h):
                    hits=json.loads(b)
                    return h.get('x-total-count')==str(expected) and len(hits)==min(10,expected) and all(r['key'].startswith('guide/1/') and r['locale']=='en' and f'benchw{w:03d}p' in r['title'] for r in hits) and all(hits[j]['score']>=hits[j+1]['score'] for j in range(len(hits)-1))
                return get(i,s['prefix']+'/api/v1/search?'+urllib.parse.urlencode({'q':'Configuration','book':'guide','version':'1','locale':'en','limit':'10'}),validate=verify)
            def negative(i):
                s=scopes[i%len(scopes)]
                path=s['prefix']+'/api/v1/pages/guide/1/draft' if s['controls'] else '/api/v1/pages/guard/1/draft'
                return get(i,path,404,validate=lambda b,h:b'hiddenbenchmark' not in b)
            # Assert counts and privacy against actual HTTP, not only fixture manifest.
            for i,s in enumerate(scopes):
                get(i,s['prefix']+'/api/v1/pages',validate=lambda b,h,n=s['pages']:len(json.loads(b))==n)
            get(0,'/api/v1/pages/private/1/hidden',404);get(0,'/api/v1/pages/guard/1/draft',404)
            if len(scopes)>1:get(0,scopes[1]['prefix']+'/media/'+scopes[0]['media'],404)
            if a.full:
                get(0,'/api/v1/search?q=%22hiddenbenchmark%22',validate=lambda b,h:json.loads(b)==[])
                if scopes[0]['controls']:
                    get(0,scopes[0]['prefix']+'/api/v1/pages/secret/1/private',404)
                    get(0,scopes[0]['prefix']+'/api/v1/search?q=%22hiddenbenchmark%22',validate=lambda b,h:json.loads(b)==[])
            limits={op:a.max_p95_ms for op in ('api','media','reader','search','search_broad','isolation','save','publish')}
            for op,value in [('reader',a.reader_p95_ms),('search',a.search_p95_ms),('search_broad',a.search_p95_ms),('save',a.write_p95_ms),('publish',a.write_p95_ms)]:
                if value is not None:limits[op]=value
            before=json.loads(helper('metrics')) if a.telemetry else {}
            if a.postgres:report['dependencies_before_load']=json.loads(helper('stats'))
            for phase,warm in ([] if a.qualify else [('varied-keys',False),('repeated-keys',True)]):
                ops=[('api',lambda i,warm=warm:api(i,warm)),('media',lambda i,warm=warm:media(i,warm)),('isolation',negative)]
                if a.full:ops += [('reader',lambda i,warm=warm:reader(i,warm)),('search',lambda i,warm=warm:search(i,warm)),('search_broad',lambda i,warm=warm:search_broad(i,warm))]
                if warm:
                    # Explicit warmup outside measurements; no cache flush or claim of OS/DB cold cache.
                    for warm_id in range(min(2,len(scopes))):
                        for _,fn in ops:fn(warm_id)
                phase_report=run_phase(phase,ops,a.requests,a.concurrency,a.rate,pids,limits);report['phases'].append(phase_report);dump(dest/'report.json',report)
                print(json.dumps(phase_report),flush=True)
            if a.qualify:
                # Warmup and preconditions are excluded from measured phases.
                ops=[('api',lambda i:api(i,False)),('media',lambda i:media(i,False)),('isolation',negative)]
                if a.full:ops += [('reader',lambda i:reader(i,False))]*5+[('search',lambda i:search(i,False)),('search_broad',lambda i:search_broad(i,False))]
                for i,(_,fn) in enumerate(ops):fn(i)
                if a.full:
                    # Dedicated legacy page, not part of immutable Reader corpus. One in-flight writer.
                    writer_lock=threading.Lock();write_revision=0;last_marker=None
                    def mixed_write(i,publish):
                        nonlocal write_revision,last_marker
                        with writer_lock:
                            marker=f'qualification-write-{i}';form={'csrf':csrf,'action':'publish' if publish else 'save','book':'qualification','version':'1','path':'live','title':'Qualification write','section':'Load','order':'0','body':marker,'revision':str(write_revision)}
                            def verify(b,h):
                                nonlocal write_revision,last_marker
                                r=json.loads(b);require(r['revision']==write_revision+1 and r['published']==(publish or last_marker is not None),'write revision mismatch');write_revision=r['revision']
                                if publish:last_marker=marker
                                return True
                            return get(i,'/studio',method='POST',form=form,headers={'Cookie':'book_session='+token,'X-Book-Studio':'editor'},validate=verify)
                    # Stress can reject writes; an ambiguous response must fail qualification rather than replay.
                    ops=ops+[('save',lambda i:mixed_write(i,False)),('publish',lambda i:mixed_write(i,True))]
                else:ops=ops*3
                qlimits={op:limits[op] for op,_ in ops}
                # Production-like SLO defaults for qualification, not the legacy 5s smoke threshold.
                qlimits.update(api=min(a.max_p95_ms,300),media=min(a.max_p95_ms,300),isolation=min(a.max_p95_ms,300))
                for op,val in [('reader',a.reader_p95_ms or 300),('search',a.search_p95_ms or 200),('search_broad',a.search_p95_ms or 200),('publish',a.write_p95_ms or 500),('save',a.write_p95_ms or 500)]:
                    if op in qlimits:qlimits[op]=val
                report['qualification_limits']=qlimits
                offset=0
                phases=[('steady',a.phase_seconds,a.offered_rps,None,False),('ramp',a.phase_seconds,a.offered_rps*2,a.offered_rps,True),('spike',max(2,a.phase_seconds//2),a.offered_rps*a.stress_multiplier,None,True),('stress',a.phase_seconds,a.offered_rps*a.stress_multiplier,None,True),('recovery',a.phase_seconds,a.offered_rps,None,False),('soak',a.soak_seconds,a.offered_rps,None,False)]
                # Keep overload read-only. A mutation whose response is lost must never be replayed.
                for name,duration,rate,ramp,overload in phases:
                    if a.redis and name=='recovery':
                        # Do not reset live rate-limit counters; wait for the actual 60s window.
                        report['recovery_cooldown_seconds']=60;time.sleep(60)
                    phase_ops=[x for x in ops if x[0] not in ('publish','save')] if overload else ops
                    # Spread low-frequency writes through the deterministic mix, and require actual samples.
                    def checkpoint(sample):
                        report['in_progress']={'phase':name,'sample':sample};dump(dest/'report.json',report)
                    maintenance=Maintenance(ROOT,env,dest,a.maintenance_interval,a.maintenance_timeout) if a.background_maintenance and name=='soak' else None
                    dependencies=Dependencies(ROOT,env,a.pg_max_connections,a.redis_max_memory_mib*1024*1024,a.redis_max_keys) if a.postgres and name=='soak' else None
                    with (maintenance if maintenance else nullcontext()), (dependencies if dependencies else nullcontext()):
                        phase=qualification.run_phase(name,phase_ops,duration,rate,a.concurrency,pids,proc_resources,qlimits,a.p99_ms,a.error_budget,a.min_samples,a.rss_growth_mib*1024*1024,ramp_from=ramp,overload=overload,start_index=offset,sample_seconds=a.sample_seconds,request_failure=RequestFailure,progress=checkpoint)
                    if dependencies:
                        phase['dependency_resources']=dependencies.result();phase['passed']=phase['passed'] and phase['dependency_resources']['passed']
                    if maintenance:
                        phase['background_maintenance']=maintenance.result(duration);phase['passed']=phase['passed'] and phase['background_maintenance']['passed']
                    report.pop('in_progress',None)
                    offset+=phase['requests'];report['phases'].append(phase);dump(dest/'report.json',report)
                    print(json.dumps({k:v for k,v in phase.items() if k!='php_resources'}),flush=True)
                    if not phase['passed']:print('Qualification phase failed: '+name,flush=True)
                    # Recheck private controls and live nodes after every phase, regardless of latency failure.
                    # Probe on a separate real loopback address: do not confuse a saturated client quota with a privacy defect.
                    status,_,body=request_http(ports[offset%len(ports)],'/api/v1/pages/private/1/hidden',source='127.0.0.255');require(status==404 and b'hiddenbenchmark' not in body,'post-phase private page leaked or probe failed')
                    if len(scopes)>1:get(offset,scopes[1]['prefix']+'/media/'+scopes[0]['media'],404,validate=lambda b,h:b'Benchmark attachment' not in b)
                    if a.full and last_marker is not None:
                        status,_,body=request_http(ports[offset%len(ports)],'/api/v1/pages/qualification/1/live',source='127.0.0.255');require(status==200 and json.loads(body)['body']==last_marker,'published revision did not survive phase')
                for i,s in enumerate(scopes):
                    status,_,body=request_http(ports[i%len(ports)],s['prefix']+'/api/v1/pages',source='127.0.0.255');require(status==200 and len(json.loads(body))==s['pages'],'post-load published count changed')
                if a.full:
                    for check,cmd in [('installation',['php','bin/installation.php','check']),*([('audit',['php','bin/audit.php','check']),('search',['php','bin/search.php','check'])] if a.postgres else [])]:
                        with (dest/f'final-{check}.log').open('w') as log:
                            subprocess.run(cmd,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT,check=True,timeout=7200)
                    report['final_integrity_checks']='passed'
                report['qualification_complete']=True
                report['target_corpus_and_duration']=bool(a.postgres and a.redis and a.full and a.pages==100000 and a.soak_seconds==86400 and a.nodes>=2 and a.concurrency>=100 and a.min_samples>=20 and a.error_budget<=.001 and a.p99_ms<=1000)
                report['production_certified']=False # CLI-server tests are not a production infrastructure certification
            if a.full and not a.qualify:
                # Sequential real authorized writes to one existing page; never manufacture concurrent revisions.
                s=scopes[0];revision=2;headers={'Cookie':'book_session='+token,'X-Book-Studio':'editor'}
                def write(i,publish):
                    nonlocal revision
                    form={'csrf':csrf,'action':'publish' if publish else 'save','book':'guide','version':'1','path':'topic-0000','title':'Benchmark write','section':'Benchmark','order':'0','body':f'# Benchmark write\n\nbenchwrite{i}','revision':str(revision)}
                    def verify(b,h):
                        nonlocal revision
                        r=json.loads(b);require(r['revision']==revision+1,'unexpected revision');revision=r['revision'];return True
                    return get(i,s['prefix']+'/studio',validate=verify,headers=headers,method='POST',form=form)
                report['phases'].append(run_phase('authorized-writes',[('save',lambda i:write(i,False)),('publish',lambda i:write(i,True))],min(a.requests,10),1,0,pids,limits))
                get(0,s['prefix']+'/api/v1/pages/guide/1/topic-0000',validate=lambda b,h:'benchwrite' in json.loads(b)['body'])
            if a.telemetry:
                report['telemetry_delta']=metric_delta(before,json.loads(helper('metrics')))
                if a.background_maintenance:require(report['telemetry_delta'].get('worker_scope_failures_total',0)==0,'background worker scope failure')
            if a.postgres:
                report['dependencies_after_load']=json.loads(helper('stats'))
                report['sql_profile']=json.loads(helper('profile'));require(report['sql_profile']['published_documents']==a.pages+(1 if a.qualify and a.full and last_marker is not None else 0),'SQL indexed public document count mismatch')
            if a.maintenance:
                helper('ci-fixture')
                status,_,raw=request_http(ports[0],'/studio/ci','POST',{'action':'issue','csrf':csrf,'scope':'benchmark-ci/1','label':'benchmark pipeline','days':'1','publish':'1'},{'Cookie':'book_session='+token})
                match=re.search(rb'cbci_[a-f0-9]{32}\.[a-f0-9]{64}',raw)
                require(status==200 and match is not None,'CI token issuance failed')
                ci_headers={'Authorization':'Bearer '+match.group().decode()}
                status,_,raw=request_http(ports[0],'/api/v1/ci','POST',headers=ci_headers,json_body={'action':'state'})
                require(status==200,'CI state failed');fingerprint=json.loads(raw)['fingerprint'];build={}
                def ci_build(i):
                    def verify(b,h):
                        build.update(json.loads(b));return build.get('pages')==100 and build.get('status')=='ready'
                    return get(i,'/api/v1/ci',headers=ci_headers,method='POST',json_body={'action':'build','request':'benchmark-1','expected':fingerprint},validate=verify)
                def ci_publish(i):
                    return get(i,'/api/v1/ci',headers=ci_headers,method='POST',json_body={'action':'publish','id':build['id'],'digest':build['digest']},validate=lambda b,h:json.loads(b)['status']=='published')
                report['phases'].append(run_phase('ci-batch',[('ci_build',ci_build),('ci_publish',ci_publish)],2,1,0,pids,{}))
                get(0,'/api/v1/pages/benchmark-ci/1/topic-099',validate=lambda b,h:'CI benchmark publication 99' in json.loads(b)['body'])
                for proc,_ in children:stop_node(proc)
                children.clear()
                maintenance={}
                def timed(name,cmd,run_env=env):
                    start=time.perf_counter()
                    with (dest/(name+'.log')).open('w') as log:
                        subprocess.run(cmd,cwd=ROOT,env=run_env,stdout=log,stderr=subprocess.STDOUT,check=True,timeout=7200)
                    maintenance[name]={'seconds':round(time.perf_counter()-start,3)}
                timed('worker-scan',['php','bin/worker.php','--once'])
                if a.postgres:timed('reindex',['php','bin/search.php','reindex','--offline'])
                backup=Path(tmp)/'backup';restored=Path(tmp)/'restored'
                timed('backup',['php','bin/installation.php','backup',str(backup)])
                maintenance['backup']['bytes']=sum(f.stat().st_size for f in backup.rglob('*') if f.is_file())
                restore_env={k:v for k,v in env.items() if not k.startswith(('BOOK_PG_','BOOK_REDIS_'))};restore_env['BOOK_DATA_DIR']=str(restored)
                timed('restore',['php','bin/installation.php','restore',str(backup)],restore_env)
                timed('restored-check',['php','bin/installation.php','check'],restore_env)
                report['maintenance']=maintenance
            if a.baseline:report['comparison']=compare(report,json.loads(a.baseline.read_text()),a.max_regression_percent)
            report['status']='passed' if all(x['passed'] for x in report['phases']) and all(x['passed'] for x in report.get('comparison',[])) else 'failed'
        except Exception as exc:
            report['failure']=type(exc).__name__+': '+str(exc)[:200]
            print(report['failure'],flush=True)
        finally:
            for proc,_ in children:
                try:stop_node(proc)
                except Exception:report['status']='failed';report['process_cleanup_failed']=True
            for log in logs:log.close()
            if seeded:
                try:helper('cleanup')
                except Exception:report['status']='failed';report['cleanup_failed']=True
            dump(dest/'report.json',report)
            suite=ET.Element('testsuite',name='performance')
            for phase in report['phases']:
                if phase.get('exploratory_overload'):
                    case=ET.SubElement(suite,'testcase',name=phase['phase']+'/safety')
                    ET.SubElement(case,'system-out').text=json.dumps({'slo_passed':phase['slo_passed'],'operations':phase['operations']})
                    if not phase['passed']:ET.SubElement(case,'failure',message='Overload safety failed')
                    continue
                for op,data in phase['operations'].items():
                    case=ET.SubElement(suite,'testcase',name=phase['phase']+'/'+op)
                    if not data['passed']:ET.SubElement(case,'failure',message='HTTP or latency gate failed')
                if 'slo_passed' in phase:
                    case=ET.SubElement(suite,'testcase',name=phase['phase']+'/windows-and-resources')
                    if not phase['passed']:ET.SubElement(case,'failure',message='Time window or resource gate failed')
            case=ET.SubElement(suite,'testcase',name='overall')
            if report['status']!='passed':ET.SubElement(case,'failure',message='Benchmark failed')
            suite.set('tests',str(len(suite.findall('testcase'))));suite.set('failures',str(len(suite.findall('testcase/failure'))))
            ET.ElementTree(suite).write(dest/'junit.xml',encoding='utf-8',xml_declaration=True)
    print(f"Benchmark {report['status']}: {dest/'report.json'}",flush=True)
    return 0 if report['status']=='passed' else 1


if __name__=='__main__':raise SystemExit(main())
