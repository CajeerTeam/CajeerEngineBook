#!/usr/bin/env python3
"""Policy regressions with real Git history and explicitly synthetic evidence."""
import copy
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('production_qualification',ROOT/'tests/production-qualification.py')
q=importlib.util.module_from_spec(spec);spec.loader.exec_module(q)
V='0.9.0';C='a'*40;P='b'*40;D='c'*64


def lifecycle():
    return {'format':1,'status':'passed','release':V,'commit':C,'previous_commit':P,
            'steps':[{'name':n,'status':'passed','seconds':1} for n in q.life.STEPS],
            'rpo_limit_seconds':900,'rto_limit_seconds':3600,'rpo_seconds':60,'rto_seconds':120,
            'images':{r:{'id':'sha256:'+str(i)*64,'test_ref':'127.0.0.1:5000/'+r+'@sha256:'+str(i)*64,'candidate_ref':None} for i,r in enumerate(('old','new','pitr'),1)},
            'content_sha256':{'page':D,'media':D},'restore_point':{'id':'20261116T120000Z-'+'a'*32,'lsn':'0/1234','captured_at':1}}


def op(k,n):
    return {'requests':n,'completed':n,'dropped':0,'successes':n,'errors':0,'integrity_errors':0,
            'p95_limit_ms':q.LIMITS[k],'p99_limit_ms':1000,'p95_ms':10,'p99_ms':20,'passed':True}


def soak():
    phases=[]
    for name in ('steady','ramp','spike','stress','recovery','soak'):
        strict=name in ('steady','recovery','soak');duration,rate,ramp,total={'steady':(120,20,None,2400),'ramp':(120,40,20,3600),'spike':(60,100,None,6000),'stress':(120,100,None,12000),'recovery':(120,20,None,2400),'soak':(86400,20,None,1728000)}[name];n=total//(8 if strict else 6)
        ops={k:op(k,n) for k in q.LIMITS if strict or k not in ('save','publish')}
        phases.append({'phase':name,'passed':True,'slo_passed':True,'exploratory_overload':not strict,'operations':ops,'requests':sum(x['requests'] for x in ops.values()),'max_inflight':10,'concurrency':100,'php_resources':{'passed':True,'peak_growth_bytes':0},'offered_seconds':duration,'seconds':duration+1,'rate_limit_rps':rate,'ramp_from_rps':ramp})
    phases[-1].update(offered_seconds=86400,seconds=86401,rate_limit_rps=20,
        windows=[{'start_seconds':i*60,'seconds':60,'enforced':True,'passed':True,'operations':{k:op(k,150) for k in q.LIMITS}} for i in range(1440)],
        dependency_resources={'passed':True,'samples':[{'status':'ok'},{'status':'ok'}],'limits':{'postgres_connections':64,'redis_memory_bytes':2048*1024*1024,'redis_keys':1000000},'maxima':{'postgres_connections':10,'redis_memory_bytes':100,'redis_keys':100}},
        background_maintenance={'passed':True,'errors':[],'operations':[{'operation':k,'exit_code':0} for k in ('worker','backup','restore','restore-check')]})
    return {'release':V,'source_digest':D,'status':'passed','qualification_complete':True,'target_corpus_and_duration':True,'final_integrity_checks':'passed','qualification_limits':q.LIMITS.copy(),'phases':phases,
        'corpus':{'published_pages':100000,'workspace_count':100},'profile':{'backend':'postgresql','redis':True,'full':True,'telemetry':True,'pages':100000,'per_workspace':1000,'concurrency':100,'nodes':4,'qualification':{'soak_seconds':86400,'offered_rps':20,'stress_multiplier':5,'phase_seconds':120,'source_ips':64,'min_samples':20,'error_budget':.001,'p99_ms':1000,'rss_growth_mib':128,'sample_seconds':60,'background_maintenance':True,'pg_max_connections':64,'redis_max_memory_mib':2048,'redis_max_keys':1000000}}}


class Qualification(unittest.TestCase):
    def test_real_git_history(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);repo=root/'source';repo.mkdir();git=lambda *a:q.life.git(repo,*a)
            git('init','-q');(repo/'VERSION').write_text('0.8.2\n');(repo/'application.php').write_text('old real bytes');git('add','.');git('-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm','historical');prior=git('rev-parse','HEAD')
            (repo/'VERSION').write_text(V+'\n');(repo/'application.php').write_text('new real bytes');git('add','.');git('-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm','target');head=git('rev-parse','HEAD')
            self.assertEqual(q.life.revisions(repo), (prior,head,V));self.assertEqual(q.life.revisions(repo,prior),(prior,head,V))
            q.life.archive(repo,prior,root/'old');q.life.archive(repo,head,root/'new');self.assertEqual((root/'old/application.php').read_text(),'old real bytes');self.assertEqual((root/'new/application.php').read_text(),'new real bytes')
            with self.assertRaises(RuntimeError):q.life.revisions(repo,head)
            (repo/'application.php').write_text('dirty')
            with self.assertRaises(RuntimeError):q.life.revisions(repo,prior)

    def test_lifecycle_valid(self):self.assertEqual(q.validate_lifecycle(lifecycle(),V,C,P)['rto_seconds'],120)
    def test_lifecycle_missing_step(self):
        x=lifecycle();x['steps'].pop();self.reject_lifecycle(x)
    def test_lifecycle_failed_step(self):
        x=lifecycle();x['steps'][5]['status']='failed';self.reject_lifecycle(x)
    def test_lifecycle_wrong_source(self):
        x=lifecycle();x['previous_commit']=C;self.reject_lifecycle(x)
    def test_lifecycle_same_image(self):
        x=lifecycle();x['images']['new']['id']=x['images']['old']['id'];self.reject_lifecycle(x)
    def test_lifecycle_rpo_rto(self):
        for field,value in [('rpo_seconds',901),('rto_seconds',3601),('rto_seconds',0),('rpo_seconds',float('nan'))]:
            x=lifecycle();x[field]=value;self.reject_lifecycle(x)
    def test_lifecycle_missing_wal(self):
        x=lifecycle();x['restore_point']['lsn']='none';self.reject_lifecycle(x)
    def reject_lifecycle(self,x):
        with self.assertRaises(ValueError):q.validate_lifecycle(x,V,C,P)
    def reject_soak(self,x):
        with self.assertRaises(ValueError):q.validate_soak(x,V,D)
    def test_soak_valid(self):self.assertEqual(q.validate_soak(soak(),V,D)['minute_windows'],1440)
    def test_soak_missing_window(self):
        x=soak();x['phases'][-1]['windows'].pop();self.reject_soak(x)
    def test_soak_window_latency(self):
        x=soak();x['phases'][-1]['windows'][700]['operations']['search']['p95_ms']=201;self.reject_soak(x)
    def test_soak_error(self):
        x=soak();o=x['phases'][-1]['windows'][700]['operations']['api'];o['successes']-=1;o['errors']+=1;self.reject_soak(x)
    def test_soak_integrity_during_stress(self):
        x=soak();x['phases'][3]['operations']['api']['integrity_errors']=1;self.reject_soak(x)
    def test_soak_wrong_source(self):
        x=soak();x['source_digest']='d'*64;self.reject_soak(x)
    def test_soak_weak_corpus(self):
        x=soak();x['corpus']['published_pages']=99999;self.reject_soak(x)
    def test_soak_missing_maintenance(self):
        x=soak();x['phases'][-1]['background_maintenance']['operations'].pop();self.reject_soak(x)
    def test_soak_resource_limit(self):
        x=soak();x['phases'][-1]['dependency_resources']['maxima']['postgres_connections']=65;self.reject_soak(x)
    def test_soak_incomplete(self):
        x=soak();x['qualification_complete']=False;self.reject_soak(x)
    def test_soak_exploratory_failure_is_visible(self):
        x=soak();x['phases'][3]['slo_passed']=False;self.assertFalse(q.validate_soak(x,V,D)['stress_slo_passed'])

if __name__=='__main__':unittest.main(verbosity=2)
