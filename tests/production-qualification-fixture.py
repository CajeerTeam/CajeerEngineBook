"""Synthetic shape for tests of signing/validation only; never production evidence."""
def qualification_fixture(version,commit,images):
    return {'status':'lab-qualified','version':version,'commit':commit,'candidate_images':images,
            **{k:'a'*64 for k in ('source_digest','report_sha256','lifecycle_sha256','soak_sha256','candidate_lifecycle_sha256')},
            'soak_seconds':86400,'soak_requests':1728000,'minute_windows':1440,
            'rpo_seconds':1,'rto_seconds':2,'candidate_rpo_seconds':1,'candidate_rto_seconds':2}


import importlib.util
from pathlib import Path
import copy

_spec=importlib.util.spec_from_file_location("fixture_policy",Path(__file__).resolve().parents[1]/"bin/qualification_policy.py")
policy=importlib.util.module_from_spec(_spec);_spec.loader.exec_module(policy)

def lifecycle(V,C,P,D):
    return {'format':1,'status':'passed','release':V,'commit':C,'previous_commit':P,
            'steps':[{'name':n,'status':'passed','seconds':1} for n in policy.STEPS],
            'rpo_limit_seconds':900,'rto_limit_seconds':3600,'rpo_seconds':60,'rto_seconds':120,
            'images':{r:{'id':'sha256:'+str(i)*64,'test_ref':'127.0.0.1:5000/'+r+'@sha256:'+str(i)*64,'candidate_ref':None} for i,r in enumerate(('old','new','pitr'),1)},
            'content_sha256':{'page':D,'media':D},'restore_point':{'id':'20261116T120000Z-'+'a'*32,'lsn':'0/1234','captured_at':1}}


def op(k,n):
    return {'requests':n,'completed':n,'dropped':0,'successes':n,'errors':0,'integrity_errors':0,
            'p95_limit_ms':policy.LIMITS[k],'p99_limit_ms':1000,'p95_ms':10,'p99_ms':20,'passed':True}


def soak(V,D):
    phases=[]
    for name in ('steady','ramp','spike','stress','recovery','soak'):
        strict=name in ('steady','recovery','soak');duration,rate,ramp,total={'steady':(120,20,None,2400),'ramp':(120,40,20,3600),'spike':(60,100,None,6000),'stress':(120,100,None,12000),'recovery':(120,20,None,2400),'soak':(86400,20,None,1728000)}[name];n=total//(8 if strict else 6)
        ops={k:op(k,n) for k in policy.LIMITS if strict or k not in ('save','publish')}
        phases.append({'phase':name,'passed':True,'slo_passed':True,'exploratory_overload':not strict,'operations':ops,'requests':sum(x['requests'] for x in ops.values()),'max_inflight':10,'concurrency':100,'php_resources':{'passed':True,'peak_growth_bytes':0},'offered_seconds':duration,'seconds':duration+1,'rate_limit_rps':rate,'ramp_from_rps':ramp})
    phases[-1].update(offered_seconds=86400,seconds=86401,rate_limit_rps=20,
        windows=[{'start_seconds':i*60,'seconds':60,'enforced':True,'passed':True,'operations':{k:op(k,150) for k in policy.LIMITS}} for i in range(1440)],
        dependency_resources={'passed':True,'samples':[{'status':'ok'},{'status':'ok'}],'limits':{'postgres_connections':64,'redis_memory_bytes':2048*1024*1024,'redis_keys':1000000},'maxima':{'postgres_connections':10,'redis_memory_bytes':100,'redis_keys':100}},
        background_maintenance={'passed':True,'errors':[],'operations':[{'operation':k,'exit_code':0} for k in ('worker','backup','restore','restore-check')]})
    return {'release':V,'source_digest':D,'status':'passed','qualification_complete':True,'target_corpus_and_duration':True,'final_integrity_checks':'passed','qualification_limits':policy.LIMITS.copy(),'phases':phases,
        'corpus':{'published_pages':100000,'workspace_count':100},'profile':{'backend':'postgresql','redis':True,'full':True,'telemetry':True,'pages':100000,'per_workspace':1000,'concurrency':100,'nodes':4,'qualification':{'soak_seconds':86400,'offered_rps':20,'stress_multiplier':5,'phase_seconds':120,'source_ips':64,'min_samples':20,'error_budget':.001,'p99_ms':1000,'rss_growth_mib':128,'sample_seconds':60,'background_maintenance':True,'pg_max_connections':64,'redis_max_memory_mib':2048,'redis_max_keys':1000000}}}


def write_fixture(release, directory, root, version, commit, images):
    """Synthetic signed test-only reports; never used by release-build.sh."""
    if not release.stable(version):
        return qualification_fixture(version,commit,images)
    digest=release.source_fingerprint((n,b) for n,_,b in release.source_files(root,commit))
    prior='b'*40 if commit!='b'*40 else 'c'*40
    lab=lifecycle(version,commit,prior,digest)
    candidate=copy.deepcopy(lab)
    candidate['images']['new']['candidate_ref']=images['book']
    candidate['images']['pitr']['candidate_ref']=images['postgres-pitr']
    load=soak(version,digest)
    release.dump(directory/'qualification-lifecycle.json',lab)
    release.dump(directory/'qualification-candidate.json',candidate)
    release.dump(directory/'qualification-soak.json',load)
    summary={'mode':'full','status':'lab-qualified','release':version,'commit':commit,'previous_commit':prior,'source_digest':digest,
        'steps':[{'name':n,'status':'passed'} for n in ('preflight','lifecycle','lifecycle-evidence','24h-load','24h-evidence')],
        'lifecycle':policy.validate_lifecycle(lab,version,commit,prior),'load':policy.validate_soak(load,version,digest),
        'evidence_sha256':{'lifecycle':release.sha(directory/'qualification-lifecycle.json'),'load':release.sha(directory/'qualification-soak.json')}}
    release.dump(directory/'qualification.json',summary)
    return {'status':'lab-qualified','version':version,'commit':commit,'candidate_images':images,'source_digest':digest,
        **{field:release.sha(directory/name) for name,field in release.QUALIFICATION_FILES.items()},
        **policy.validate_reports(summary,lab,load,candidate,version,commit,images,digest)}
