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
    return policy.source_digest_tree(root)


policy=load('qualification_policy',ROOT/'bin/qualification_policy.py')
validate_lifecycle=policy.validate_lifecycle
validate_soak=policy.validate_soak
LIMITS=policy.LIMITS


def release_evidence(summary_path,candidate_path,images,commit,version):
    """Revalidate actual CI evidence before signing. Never accept a smoke or a summary alone."""
    summary_path=Path(summary_path);directory=summary_path.parent
    summary=u.release.json_read(summary_path)
    require(summary.get('mode')=='full' and summary.get('status')=='lab-qualified' and summary.get('full_qualification_verified') is True and summary.get('commit')==commit and summary.get('release')==version and summary.get('source_digest')==source_digest(),'Full qualification not for this release source')
    require([s['name'] for s in summary['steps']]==['preflight','lifecycle','lifecycle-evidence','24h-load','24h-evidence'] and all(s['status']=='passed' for s in summary['steps']),'Qualification incomplete')
    prior,head,v=life.revisions(ROOT,summary['previous_commit']);require(head==commit and v==version,'Git history mismatch')
    lifecycle=directory/'lifecycle/lifecycle.json';soak=directory/'load/qualification/report.json'
    require(soak.is_file() and soak.stat().st_size<=256*1024*1024,'Missing/oversized soak report')
    require(summary['evidence_sha256']=={'lifecycle':u.release.sha(lifecycle),'load':u.release.sha(soak)},'Qualification artifact changed')
    lab=validate_lifecycle(u.release.json_read(lifecycle),version,commit,prior)
    load_result=validate_soak(u.release.json_read(soak,u.release.MAX_SOAK),version,summary['source_digest'])
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
    state={'format':1,'release':(ROOT/'VERSION').read_text().strip(),'mode':a.mode,'status':'failed','steps':[],'production_certified':False,'full_qualification_verified':False}
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
            state['load']=step('24h-evidence',lambda:validate_soak(u.release.json_read(path,u.release.MAX_SOAK),version,state['source_digest']))
            state['evidence_sha256']={'lifecycle':u.release.sha(report/'lifecycle/lifecycle.json'),'load':u.release.sha(path)}
            state['full_qualification_verified']=True
            state['status']='lab-qualified'
        else:state['status']='smoke-passed'
        save();return 0
    except (ValueError,RuntimeError,OSError,subprocess.SubprocessError,KeyError,TypeError,KeyboardInterrupt) as e:
        state['status']='failed';save();print(str(e),file=sys.stderr);return 1

if __name__=='__main__':sys.exit(main())
