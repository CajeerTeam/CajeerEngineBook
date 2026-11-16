#!/usr/bin/env python3
"""Production qualification coordinator. Runs real lab lifecycle and 24h SQL soak.
A smoke run never qualifies a release. No external report imports or operator DSN.
The existing CI also requires security, domain, distributed and release gates.
"""
import argparse
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
life=load('production_lifecycle',ROOT/'tests/production-lifecycle.py')
u=life.u


def require(value,message):
    if not value:raise ValueError(message)


def number(v):return type(v) in (int,float) and math.isfinite(v) and v>=0


def source_digest(root=ROOT):
    # Same committed runtime/test input set as performance-benchmark.py.
    d=hashlib.sha256()
    for p in sorted([root/'VERSION',*[p for folder in ('src','sql','tests') for p in (root/folder).rglob('*') if p.is_file() and p.suffix in ('.php','.py','.sql','.sh')]]):
        d.update(str(p.relative_to(root)).encode()+b'\0'+hashlib.sha256(p.read_bytes()).digest())
    return d.hexdigest()


def validate_lifecycle(data,version,commit,prior):
    require(data.get('status')=='passed' and data.get('format')==1 and data.get('release')==version and data.get('commit')==commit and data.get('previous_commit')==prior and prior!=commit,'Lifecycle source or status mismatch')
    require([s['name'] for s in data['steps']]==list(life.STEPS) and all(s['status']=='passed' and number(s['seconds']) for s in data['steps']),'Incomplete/failed lifecycle steps')
    require(data.get('rpo_limit_seconds')==900 and data.get('rto_limit_seconds')==3600,'Unexpected recovery SLO policy')
    require(number(data.get('rpo_seconds')) and data['rpo_seconds']<=900 and number(data.get('rto_seconds')) and 0<data['rto_seconds']<=3600,'Recovery SLO failed')
    require(set(data['images'])=={'old','new','pitr'} and all(re.fullmatch(r'sha256:[0-9a-f]{64}',x['id']) and re.fullmatch(r'127\.0\.0\.1:[0-9]+/[a-z]+@sha256:[0-9a-f]{64}',x['test_ref']) for x in data['images'].values()),'Missing tested image identity')
    require(data['images']['old']['id']!=data['images']['new']['id'],'Historical and target images must be different')
    require(set(data['content_sha256'])=={'page','media'} and all(re.fullmatch(r'[0-9a-f]{64}',v) for v in data['content_sha256'].values()),'Missing content and media integrity evidence')
    point=data['restore_point'];require(re.fullmatch(r'[0-9A-F]{1,8}/[0-9A-F]{1,8}',point['lsn']) and re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{32}',point['id']) and type(point['captured_at']) is int and point['captured_at']>0,'Missing WAL target evidence')
    return {'rpo_seconds':data['rpo_seconds'],'rto_seconds':data['rto_seconds'],'image_ids':{r:x['id'] for r,x in data['images'].items()}}


LIMITS={'api':300,'media':300,'isolation':300,'reader':300,'search':200,'search_broad':200,'save':500,'publish':500}


def operation(o,limit,minimum,strict=True):
    require(type(o['requests']) is int and o['requests']>0 and all(type(o[k]) is int and o[k]>=0 for k in ('completed','dropped','successes','errors','integrity_errors')),'Invalid request counters')
    require(o['requests']==o['completed']+o['dropped'] and o['successes']+o['errors']==o['requests'] and o['integrity_errors']==0,'Requests missing or integrity violation')
    require(o['p95_limit_ms']==limit and o['p99_limit_ms']==1000,'Latency policy changed')
    if strict:
        require(o['passed'] is True and o['successes']>=minimum and o['errors']/o['requests']<=.001 and number(o['p95_ms']) and o['p95_ms']<=limit and number(o['p99_ms']) and o['p99_ms']<=1000,'Operation SLO failed')


def validate_soak(data,version,digest):
    require(data.get('release')==version and data.get('source_digest')==digest and data.get('status')=='passed' and data.get('qualification_complete') is True and data.get('target_corpus_and_duration') is True and data.get('final_integrity_checks')=='passed','Soak is not a completed target run for this source')
    p=data['profile'];q=p['qualification'];corpus=data['corpus']
    require(p['backend']=='postgresql' and p['redis'] is True and p['full'] is True and p['telemetry'] is True and p['pages']==100000 and p['per_workspace']==1000 and p['concurrency']==100 and p['nodes']==4 and corpus['published_pages']==100000 and corpus['workspace_count']==100,'Wrong production corpus or topology')
    require(q['soak_seconds']==86400 and q['offered_rps']==20 and q['stress_multiplier']==5 and q['phase_seconds']==120 and q['source_ips']==64 and q['min_samples']>=20 and 0<=q['error_budget']<=.001 and q['p99_ms']==1000 and q['rss_growth_mib']==128 and q['sample_seconds']==60 and q['background_maintenance'] is True and q['pg_max_connections']==64 and q['redis_max_memory_mib']==2048 and q['redis_max_keys']==1000000,'Qualification profile weakened')
    require(data['qualification_limits']==LIMITS,'Qualification latency limits changed')
    phases=data['phases'];require([x['phase'] for x in phases]==['steady','ramp','spike','stress','recovery','soak'],'Incomplete phase evidence')
    schedule={'steady':(120,20,None,2400),'ramp':(120,40,20,3600),'spike':(60,100,None,6000),'stress':(120,100,None,12000),'recovery':(120,20,None,2400),'soak':(86400,20,None,1728000)}
    for phase in phases:
        name=phase['phase'];duration,rate,ramp,count=schedule[name]
        require(phase['offered_seconds']==duration and phase['seconds']>=duration and phase['rate_limit_rps']==rate and phase['ramp_from_rps']==ramp and phase['requests']==count,'Incorrect offered workload: '+name)
        require(number(phase['php_resources']['peak_growth_bytes']) and phase['php_resources']['peak_growth_bytes']<=128*1024*1024,'PHP RSS SLO failed')
        strict=name in ('steady','recovery','soak');ops=phase['operations'];expected=set(LIMITS) if strict else set(LIMITS)-{'save','publish'}
        require(phase['passed'] is True and phase['exploratory_overload'] is (not strict) and set(ops)==expected and phase['php_resources']['passed'] is True,'Phase failed or missing operations: '+name)
        require(sum(o['requests'] for o in ops.values())==phase['requests'] and sum(o['successes'] for o in ops.values())>0,'Phase counters inconsistent')
        require(phase['max_inflight']<=100 and phase['concurrency']==100,'Unexpected concurrency')
        for k,o in ops.items():operation(o,LIMITS[k],q['min_samples'],strict)
        if strict:require(phase['slo_passed'] is True,'Strict phase SLO failed: '+name)
    soak=phases[-1]
    require(soak['offered_seconds']==86400 and soak['seconds']>=86400 and soak['rate_limit_rps']==20 and soak['requests']==1728000,'24h offered load not completed')
    windows=soak['windows'];require(len(windows)==1440 and [x['start_seconds'] for x in windows]==list(range(0,86400,60)),'Missing full minute windows')
    for w in windows:
        require(w['seconds']==60 and w['enforced'] is True and w['passed'] is True and set(w['operations'])==set(LIMITS),'Soak minute window failed or incomplete')
        for k,o in w['operations'].items():operation(o,LIMITS[k],1)
    require(all(sum(o['requests'] for o in w['operations'].values())==1200 for w in windows),'Minute offered load incomplete')
    for k,o in soak['operations'].items():
        require(all(sum(w['operations'][k][field] for w in windows)==o[field] for field in ('requests','completed','dropped','successes','errors','integrity_errors')),'Window and operation totals differ')
    dep=soak['dependency_resources'];maintenance=soak['background_maintenance'];require(dep['passed'] is True and len(dep['samples'])>1 and all(s['status']=='ok' for s in dep['samples']),'Missing/failed PostgreSQL/Redis sampling')
    require(dep['limits']=={'postgres_connections':64,'redis_memory_bytes':2048*1024*1024,'redis_keys':1000000} and all(number(dep['maxima'][k]) and dep['maxima'][k]<=limit for k,limit in dep['limits'].items()),'Resource limits exceeded')
    require(maintenance['passed'] is True and not maintenance['errors'] and all(r['exit_code']==0 for r in maintenance['operations']) and {'worker','backup','restore','restore-check'} <= {r['operation'] for r in maintenance['operations']},'Concurrent maintenance evidence missing or failed')
    return {'offered_seconds':86400,'requests':soak['requests'],'minute_windows':len(windows),'operations':{k:{'p95_ms':v['p95_ms'],'p99_ms':v['p99_ms'],'errors':v['errors'],'requests':v['requests']} for k,v in soak['operations'].items()},'stress_slo_passed':phases[3]['slo_passed']}


def release_evidence(summary_path,candidate_path,images,commit,version):
    """Revalidate actual CI evidence before signing. Never accept a smoke or a summary alone."""
    summary_path=Path(summary_path);directory=summary_path.parent
    summary=u.release.json_read(summary_path)
    require(summary.get('mode')=='full' and summary.get('status')=='lab-qualified' and summary.get('commit')==commit and summary.get('release')==version and summary.get('source_digest')==source_digest(),'Full qualification not for this release source')
    require([s['name'] for s in summary['steps']]==['preflight','lifecycle','lifecycle-evidence','24h-load','24h-evidence'] and all(s['status']=='passed' for s in summary['steps']),'Qualification incomplete')
    prior,head,v=life.revisions(ROOT,summary['previous_commit']);require(head==commit and v==version,'Git history mismatch')
    lifecycle=directory/'lifecycle/lifecycle.json';soak=directory/'load/qualification/report.json'
    require(soak.is_file() and soak.stat().st_size<=256*1024*1024,'Missing/oversized soak report')
    require(summary['evidence_sha256']=={'lifecycle':u.release.sha(lifecycle),'load':u.release.sha(soak)},'Qualification artifact changed')
    lab=validate_lifecycle(u.release.json_read(lifecycle),version,commit,prior)
    load_result=validate_soak(json.loads(soak.read_bytes()),version,summary['source_digest'])
    require(summary['lifecycle']==lab and summary['load']==load_result,'Qualification summary differs from reports')
    candidate_path=Path(candidate_path);candidate=u.release.json_read(candidate_path);checked=validate_lifecycle(candidate,version,commit,prior)
    require(candidate['images']['new']['candidate_ref']==images['book'] and candidate['images']['pitr']['candidate_ref']==images['postgres-pitr'],'Exact candidate lifecycle was not tested')
    return {'status':'lab-qualified','version':version,'commit':commit,'source_digest':summary['source_digest'],
            'report_sha256':u.release.sha(summary_path),'lifecycle_sha256':u.release.sha(lifecycle),'soak_sha256':u.release.sha(soak),
            'candidate_lifecycle_sha256':u.release.sha(candidate_path),'candidate_images':images,
            'soak_seconds':load_result['offered_seconds'],'soak_requests':load_result['requests'],'minute_windows':load_result['minute_windows'],
            'rpo_seconds':lab['rpo_seconds'],'rto_seconds':lab['rto_seconds'],
            'candidate_rpo_seconds':checked['rpo_seconds'],'candidate_rto_seconds':checked['rto_seconds']}


def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--report-dir',required=True,type=Path);p.add_argument('--previous-ref');p.add_argument('--mode',choices=('smoke','full'),default='full');a=p.parse_args()
    report=a.report_dir.resolve();report.mkdir(mode=0o700,parents=True,exist_ok=False);os.umask(0o077)
    state={'format':1,'release':(ROOT/'VERSION').read_text().strip(),'mode':a.mode,'status':'failed','steps':[],'production_certified':False}
    def save():
        u.atomic(report/'qualification.json',state)
        suite=ET.Element('testsuite',name='production-qualification',tests=str(len(state['steps'])+1))
        for r in state['steps']:
            c=ET.SubElement(suite,'testcase',name=r['name'],time=str(r['seconds']))
            if r['status']!='passed':ET.SubElement(c,'failure',message='Qualification step failed')
        c=ET.SubElement(suite,'testcase',name='overall')
        if state['status'] not in ('lab-qualified','smoke-passed'):ET.SubElement(c,'failure',message='Qualification incomplete')
        ET.ElementTree(suite).write(report/'junit.xml',encoding='utf-8',xml_declaration=True)
    def step(name,fn):
        start=time.monotonic();print('QUALIFY',name,flush=True)
        try:r=fn()
        except BaseException as e:
            state['steps'].append({'name':name,'status':'failed','seconds':round(time.monotonic()-start,3),'error':type(e).__name__+': '+str(e)[:200]});save();raise
        state['steps'].append({'name':name,'status':'passed','seconds':round(time.monotonic()-start,3)});save();return r
    def run(name,args,timeout):
        # Local disposable daemon only; no production environment or remote Docker context.
        env={k:v for k,v in os.environ.items() if not k.startswith(('BOOK_','AWS_','PG','REDIS_','COMPOSE_','DOCKER_HOST','DOCKER_CONTEXT')) and k!='PYTHONOPTIMIZE'}
        env.update(DOCKER_HOST='unix:///var/run/docker.sock',COMPOSE_DISABLE_ENV_FILE='1',AWS_EC2_METADATA_DISABLED='true')
        with (report/(name+'.log')).open('w') as log:
            proc=subprocess.Popen(args,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT,start_new_session=True)
            try:rc=proc.wait(timeout=timeout)
            except BaseException:
                os.killpg(proc.pid,signal.SIGTERM)
                try:proc.wait(timeout=90)
                except subprocess.TimeoutExpired:os.killpg(proc.pid,signal.SIGKILL);proc.wait()
                raise
        require(rc==0,name+' failed; see '+name+'.log')
    def preflight():
        missing=[x for x in ('docker','git','php','openssl','python3','bash')+ (('composer','node','npm') if a.mode=='full' else ()) if not shutil.which(x)]
        require(not missing,'Missing executables: '+', '.join(missing))
        if a.mode=='full':
            require((ROOT/'vendor/autoload.php').is_file(),'Install locked Composer dependencies before full qualification')
            subprocess.run(['php','-r','foreach(["pdo_pgsql","redis","mbstring","zip","gd","sodium","pcntl"] as $e)if(!extension_loaded($e)){fwrite(STDERR,"Missing PHP extension: ".$e."\\n");exit(1);}'],check=True,timeout=20)
        life.docker('info',timeout=30)
        prior,head,version=life.revisions(ROOT,a.previous_ref);state.update(commit=head,previous_commit=prior,source_digest=source_digest());save();return prior,head,version
    def interrupted(*_):raise KeyboardInterrupt('Qualification interrupted')
    signal.signal(signal.SIGTERM,interrupted)
    save()
    try:
        prior,head,version=step('preflight',preflight)
        args=(['sudo','-n'] if os.geteuid()!=0 else [])+[sys.executable,str(ROOT/'tests/production-lifecycle.py'),'--report-dir',str(report/'lifecycle'),'--previous-ref',prior]
        step('lifecycle',lambda:run('lifecycle',args,10800))
        state['lifecycle']=step('lifecycle-evidence',lambda:validate_lifecycle(u.release.json_read(report/'lifecycle/lifecycle.json'),version,head,prior));save()
        if a.mode=='full':
            step('24h-load',lambda:run('24h-load',['bash','tests/qualification-gate.sh',str(report/'load')],108000))
            # Full 24h report can exceed release-package's 128MB JSON limit on some hosts.
            path=report/'load/qualification/report.json';require(path.is_file() and path.stat().st_size<=256*1024*1024,'Missing or oversized soak evidence')
            state['load']=step('24h-evidence',lambda:validate_soak(json.loads(path.read_bytes()),version,state['source_digest']))
            state['evidence_sha256']={'lifecycle':u.release.sha(report/'lifecycle/lifecycle.json'),'load':u.release.sha(path)}
            state['status']='lab-qualified'
        else:state['status']='smoke-passed'
        save();return 0
    except (ValueError,RuntimeError,OSError,subprocess.SubprocessError,KeyError,TypeError,KeyboardInterrupt) as e:
        state['status']='failed';save();print(str(e),file=sys.stderr);return 1

if __name__=='__main__':sys.exit(main())
