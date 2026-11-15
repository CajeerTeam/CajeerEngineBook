#!/usr/bin/env python3
"""Runner regression checks; synthetic rows are never published as benchmark evidence."""
import importlib.util
from pathlib import Path
import tempfile
import json
import subprocess
import sys
spec=importlib.util.spec_from_file_location('perf',Path(__file__).with_name('performance-benchmark.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def check(condition,label):
    if not condition:raise RuntimeError(label)
    print('PASS '+label)
check(m.percentile([5,1,3,2,4],.95)==5 and m.percentile([], .95) is None,'nearest-rank percentile and no success samples')
def fail(_):raise m.RequestFailure('HTTP status 429, expected 200',429,10)
r=m.run_phase('test',[('ok',lambda i:(200,3)),('limited',fail)],6,2,0,[],{'ok':10000})
check(not r['passed'] and r['operations']['limited']['status_counts']=={'429':3} and r['operations']['limited']['p95_ms'] is None,'HTTP errors cannot disappear into latency or status zero')
check(r['operations']['ok']['successes']==3 and r['operations']['limited']['errors']==3,'operation accounting')
report={'format':1,'status':'passed','profile':{'backend':'file'},'environment':{'cpu':1},'corpus':{'published_pages':10},'phases':[{'phase':'test','operations':{'api':{'p95_ms':20}}}]}
old=json.loads(json.dumps(report));old['phases'][0]['operations']['api']['p95_ms']=10
check(not m.compare(report,old,20)[0]['passed'],'regression beyond threshold fails')
check(m.compare(report,old,100)[0]['passed'],'exact accepted threshold')
for key,value in [('profile',{}),('environment',{}),('status','failed'),('corpus',{'published_pages':11})]:
    bad=dict(old,**{key:value})
    try:m.compare(report,bad,20)
    except RuntimeError:pass
    else:raise RuntimeError('accepted incompatible baseline '+key)
    check(True,'reject baseline mismatch '+key)
with tempfile.TemporaryDirectory() as tmp:
    p=Path(tmp)/'report.json';m.dump(p,report);check(json.loads(p.read_text())==report and not p.with_suffix('.tmp').exists(),'atomic report')
    run=subprocess.run([sys.executable,str(Path(__file__).with_name('performance-benchmark.py')),'--pages','100001','--per-workspace','1000','--report-dir',tmp+'/invalid'],capture_output=True)
    check(run.returncode!=0 and not Path(tmp+'/invalid').exists(),'reject impossible corpus before setup')
check(m.metric_delta({'x_total':2,'gauge':1},{'x_total':3,'gauge':2})=={'x_total':1},'counter deltas exclude gauges')
