"""Execute real background operations on a disposable qualification installation.

Workers scan the actual durable queue. The backup task creates and restores a
portable bundle while the HTTP workload runs; no live data is ever overwritten.
"""
from pathlib import Path
import subprocess
import tempfile
import threading
import time


class Maintenance:
    def __init__(self, root, env, report, interval, timeout):
        self.root = root; self.env = env; self.report = report; self.interval = interval; self.timeout = timeout
        self.stop = threading.Event(); self.rows = []; self.threads = []; self.started = time.monotonic()
        self.lock = threading.Lock(); self.errors=[]; self.counts = {'worker':0,'backup':0}
        if env.get('BOOK_BENCHMARK_DISPOSABLE') != '1' or not (Path(env['BOOK_DATA_DIR'])/'.benchmark-disposable').is_file():
            raise RuntimeError('Background maintenance requires a disposable corpus')

    def command(self, label, args, env, cycle):
        start = time.monotonic(); status = 1
        with (self.report/f'maintenance-{label}-{cycle:04d}.log').open('w') as log:
            try:
                status = subprocess.run(['php', *args], cwd=self.root, env=env, stdout=log, stderr=subprocess.STDOUT, timeout=self.timeout).returncode
            except (OSError, subprocess.TimeoutExpired):
                log.write('\nMaintenance command failed or timed out.\n')
        with self.lock:
            self.rows.append({'operation':label,'cycle':cycle,'start_seconds':round(start-self.started,3),
                              'seconds':round(time.monotonic()-start,3),'exit_code':status})
        if status:raise RuntimeError('Maintenance command failed: '+label)

    def worker(self):
        while not self.stop.is_set():
            self.counts['worker'] += 1
            try:self.command('worker',['bin/worker.php','--once'],self.env,self.counts['worker'])
            except RuntimeError:return
            if self.stop.wait(30):return

    def backup(self):
        # Delay briefly so the snapshot competes with actual HTTP traffic.
        if self.stop.wait(1):return
        while not self.stop.is_set():
            self.counts['backup'] += 1;cycle=self.counts['backup']
            try:
                with tempfile.TemporaryDirectory(prefix='book-load-maintenance-') as tmp:
                    bundle=Path(tmp)/'bundle'; restored=Path(tmp)/'restored'
                    self.command('backup',['bin/installation.php','backup',str(bundle)],self.env,cycle)
                    restore_env={k:v for k,v in self.env.items() if not k.startswith(('BOOK_PG_','BOOK_REDIS_'))}
                    restore_env['BOOK_DATA_DIR']=str(restored)
                    self.command('restore',['bin/installation.php','restore',str(bundle)],restore_env,cycle)
                    self.command('restore-check',['bin/installation.php','check'],restore_env,cycle)
            except RuntimeError:return
            if self.stop.wait(self.interval):return

    def __enter__(self):
        self.started=time.monotonic()
        def guarded(fn):
            try:fn()
            except Exception:
                with self.lock:self.errors.append(fn.__name__)
        for fn in (self.worker,self.backup):
            thread=threading.Thread(target=guarded,args=(fn,));self.threads.append(thread);thread.start()
        return self

    def __exit__(self,*_):
        self.stop.set()
        for t in self.threads:t.join()

    def result(self, offered_seconds):
        rows=sorted(self.rows,key=lambda r:r['start_seconds'])
        # At least one real backup and restore must have completed, with backup
        # started while load was offered. Completion can extend into drain.
        ok=not self.errors and all(r['exit_code']==0 for r in rows) and any(r['operation']=='worker' for r in rows) and any(r['operation']=='backup' and r['start_seconds']<offered_seconds for r in rows) and any(r['operation']=='restore-check' for r in rows)
        return {'passed':ok,'cycles':self.counts,'errors':self.errors,'operations':rows,'scope':'worker scan and portable backup/restore; no remote S3 transfer'}


class Dependencies:
    """Sample actual PostgreSQL/Redis state outside the arrival scheduler."""
    def __init__(self, root, env, max_connections, max_redis_bytes, max_redis_keys):
        self.root=root;self.env=env;self.limits=(max_connections,max_redis_bytes,max_redis_keys)
        self.stop=threading.Event();self.samples=[];self.thread=None;self.started=0

    def run(self):
        import json
        while not self.stop.is_set():
            at=time.monotonic()-self.started
            try:
                data=json.loads(subprocess.check_output(['php','tests/performance-seed.php','stats'],cwd=self.root,env=self.env,stderr=subprocess.DEVNULL,timeout=15))
                # JSON contains only fixed numeric database and Redis counters.
                self.samples.append({'elapsed_seconds':round(at,3),'status':'ok',**data})
            except Exception:
                self.samples.append({'elapsed_seconds':round(at,3),'status':'unavailable'})
            if self.stop.wait(60):return

    def __enter__(self):
        self.started=time.monotonic();self.thread=threading.Thread(target=self.run);self.thread.start();return self

    def __exit__(self,*_):
        self.stop.set();self.thread.join()

    def result(self):
        valid=[s for s in self.samples if s['status']=='ok'];limits=self.limits
        maxima={'postgres_connections':max((int(s['postgres']['connections']) for s in valid),default=0),
                'postgres_lock_waiters':max((int(s['postgres']['lock_waiters']) for s in valid),default=0),
                'redis_memory_bytes':max((int(s.get('redis',{}).get('resources',{}).get('used_memory',0)) for s in valid),default=0),
                'redis_keys':max((int(s.get('redis',{}).get('resources',{}).get('keys',0)) for s in valid),default=0)}
        exceeded=[k for k,v,limit in [('postgres_connections',maxima['postgres_connections'],limits[0]),('redis_memory_bytes',maxima['redis_memory_bytes'],limits[1]),('redis_keys',maxima['redis_keys'],limits[2])] if limit and v>limit]
        return {'passed':bool(valid) and len(valid)==len(self.samples) and not exceeded,'maxima':maxima,'limits':dict(zip(('postgres_connections','redis_memory_bytes','redis_keys'),limits)),'exceeded':exceeded,'samples':self.samples}
