#!/usr/bin/env python3
"""Deterministic runner policy tests, plus actual timed scheduler execution."""
import importlib.util
from pathlib import Path
import time
from load_qualification import Histogram, Operation, run_phase

spec=importlib.util.spec_from_file_location('perf',Path(__file__).with_name('performance-benchmark.py'))
perf=importlib.util.module_from_spec(spec);spec.loader.exec_module(perf)

def check(ok,label):
    if not ok:raise RuntimeError(label)
    print('PASS '+label)

def resources(_):return {'rss_bytes':1024,'processes':0,'cpu_seconds':0,'read_bytes':0,'write_bytes':0}

def run(fn, *, rate=20, seconds=.25, concurrency=2, overload=False, window_seconds=60, ramp_from=None):
    return run_phase('policy',[('api',fn)],seconds,rate,concurrency,[],resources,{'api':300},1000,.001,1,1024,overload=overload,window_seconds=window_seconds,ramp_from=ramp_from,request_failure=perf.RequestFailure)

h=Histogram()
for n in [1.1,2.1,3.1,4.1,1001.1]:h.add(n)
check(h.quantile(.95)==1010 and h.quantile(.5)==4,'conservative bounded histogram quantiles')
h.add(999999);check(h.quantile(1)==120001,'overflow bucket cannot hide very slow success')
r=run(lambda i:(200,10))
check(r['passed'] and r['requests']==5 and r['seconds']>=.25,'scheduled arrivals and complete duration')
check(r['operations']['api']['completed']==5 and r['max_inflight']<=2,'bounded in-flight accounting')
r=run(lambda i:(time.sleep(.07) or (200,10)),rate=100,seconds=.25,concurrency=1)
check(not r['passed'] and r['operations']['api']['dropped']>0,'overload drops count against strict SLO')
check(r['operations']['api']['completed']+r['operations']['api']['dropped']==r['requests'],'all offered arrivals accounted')
r=run(lambda i:(time.sleep(.07) or (200,10)),rate=100,seconds=.25,concurrency=1,overload=True)
check(r['passed'] and not r['slo_passed'],'exploratory overload safety is distinct from SLO')
def bad(i):raise perf.RequestFailure('response validation failed',200,10)
r=run(bad,overload=True)
check(not r['passed'] and r['operations']['api']['integrity_errors']==r['requests'],'wrong successful response always fails stress')
def limited(i):raise perf.RequestFailure('HTTP status 429, expected 200',429,0)
r=run(limited,overload=True)
check(not r['passed'] and not r['operations']['api']['integrity_errors'],'all rejected workload is not successful stress')
r=run(lambda i:(200,0),seconds=.4,rate=20,window_seconds=.2)
check(len(r['windows'])==2 and all(w['enforced'] and w['passed'] for w in r['windows']),'full time windows individually enforced')
r=run(lambda i:(200,0),seconds=.4,rate=30,ramp_from=10)
check(r['requests']==8,'linear ramp integrated arrival count')
r=run(lambda i:(200,0),seconds=.25,rate=20)
check(r['operations']['api']['p95_ms']>=r['operations']['api']['service_p95_ms'],'latency includes scheduling delay')
# A failed time window must not be hidden by an overall error budget.
check(all(w['operations']['api']['requests']==r['requests'] for w in r['windows']),'window accounting')
