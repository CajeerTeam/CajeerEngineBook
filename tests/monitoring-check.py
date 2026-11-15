"""Run inside the monitoring network. Never print credentials in test logs."""
import base64
import json
import pathlib
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

def get(url,headers=None):
    with urllib.request.urlopen(urllib.request.Request(url,headers=headers or {}),timeout=15) as r:return r.read()

def value(expr):
    d=json.loads(get('http://prometheus:9090/api/v1/query?'+urllib.parse.urlencode({'query':expr})))
    result=d['data']['result']
    return float(result[0]['value'][1]) if len(result)==1 else None

def wait(label,fn,seconds=120):
    end=time.monotonic()+seconds
    while time.monotonic()<end:
        try:
            if fn():print('PASS '+label,flush=True);return
        except (OSError,ValueError,KeyError):pass
        time.sleep(3)
    raise RuntimeError(label+' timed out')

mode=sys.argv[1]
if mode=='initial':
    token=pathlib.Path('/fixture-data/metrics-token').read_text().strip()
    wait('real Apache bearer-authenticated scrape',lambda:b'book_build_info{version="0.5.3"} 1' in get('http://diagnostics/metrics',{'Authorization':'Bearer '+token}))
    for headers in ({},{'Authorization':'Bearer invalid'}):
        try:get('http://diagnostics/metrics',headers);raise RuntimeError('unauthorized scrape accepted')
        except urllib.error.HTTPError as e:assert e.code==404
    wait('Prometheus scrapes application and component metrics',lambda:value('up{job="book"}')==1 and value('book_dependency_up{component="postgres"}')==1 and value('book_dependency_up{component="redis"}')==1 and value('book_dependency_up{component="backup"}')==1 and (value('sum(book_http_requests_total{route="search",status="2xx"})') or 0)>0)
    password=pathlib.Path('/fixture-data/grafana-password').read_text().strip()
    auth={'Authorization':'Basic '+base64.b64encode(('admin:'+password).encode()).decode()}
    wait('Grafana provisioned the actual Book dashboard',lambda:json.loads(get('http://grafana:3000/api/dashboards/uid/book-operations',auth))['dashboard']['uid']=='book-operations')
    def notification():
        p=pathlib.Path('/alerts/events.jsonl')
        if not p.exists():return False
        for line in p.read_text().splitlines():
            for a in json.loads(line).get('alerts',[]):
                if a['status']=='firing' and a['labels'].get('alertname')=='BookWorkerStale':return True
        return False
    wait('real Prometheus rule fires and Alertmanager delivers worker outage webhook',notification,300)
elif mode=='worker':
    wait('real worker tick and DNS recheck clear stale health',lambda:value('book_dependency_up{component="worker"}')==1 and value('book_dependency_up{component="domain_check"}')==1)
elif mode=='redis-down':
    wait('Redis outage is visible without hiding PostgreSQL health',lambda:value('book_dependency_up{component="redis"}')==0 and value('book_dependency_up{component="postgres"}')==1)
elif mode=='redis-up':
    wait('Redis readiness recovers after restart',lambda:value('book_dependency_up{component="redis"}')==1)
else:raise RuntimeError('Unknown monitoring test mode')
