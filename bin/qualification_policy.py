"""Shared production evidence policy. No test harness or Docker dependencies."""
import math
import re
import hashlib
import stat
import subprocess
from pathlib import Path, PurePosixPath

STEPS = ('source-history','docker-preflight','build-actual-0.8.2','build-actual-target','build-physical-postgres','seed-historical-http','enroll','upgrade','rollback','reupgrade','replica-redis-recovery','physical-backup-and-wal','destroy-source','physical-restore-and-http','cleanup')

def require(value, message):
    if not value: raise ValueError(message)

def number(v):
    return type(v) in (int, float) and math.isfinite(v) and v >= 0

def validate_lifecycle(data,version,commit,prior):
    require(data.get('status')=='passed' and data.get('format')==1 and data.get('release')==version and data.get('commit')==commit and data.get('previous_commit')==prior and prior!=commit,'Lifecycle source or status mismatch')
    require([s['name'] for s in data['steps']]==list(STEPS) and all(s['status']=='passed' and number(s['seconds']) for s in data['steps']),'Incomplete/failed lifecycle steps')
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
        require(phase['offered_seconds']==duration and number(phase['seconds']) and phase['seconds']>=duration and phase['rate_limit_rps']==rate and phase['ramp_from_rps']==ramp and phase['requests']==count,'Incorrect offered workload: '+name)
        require(number(phase['php_resources']['peak_growth_bytes']) and phase['php_resources']['peak_growth_bytes']<=128*1024*1024,'PHP RSS SLO failed')
        strict=name in ('steady','recovery','soak');ops=phase['operations'];expected=set(LIMITS) if strict else set(LIMITS)-{'save','publish'}
        require(phase['passed'] is True and phase['exploratory_overload'] is (not strict) and set(ops)==expected and phase['php_resources']['passed'] is True,'Phase failed or missing operations: '+name)
        require(sum(o['requests'] for o in ops.values())==phase['requests'] and sum(o['successes'] for o in ops.values())>0,'Phase counters inconsistent')
        require(type(phase['max_inflight']) is int and 0<phase['max_inflight']<=100 and phase['concurrency']==100,'Unexpected concurrency')
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



def source_digest_entries(entries):
    """Bind long-running qualification to *every* shipped Git file, not just PHP.

    Changes to Compose, Dockerfiles, host updater, dependencies, public assets or
    workflow security policy must invalidate old load evidence too.
    """
    digest = hashlib.sha256()
    seen = set()
    for name, body in sorted(entries, key=lambda row: row[0]):
        require(isinstance(name, str) and name and name not in seen and
                not name.startswith('/') and not any(x in ('', '.', '..') for x in name.split('/')) and
                isinstance(body, bytes), 'Invalid source fingerprint input')
        seen.add(name)
        digest.update(name.encode('utf-8') + b'\0' + hashlib.sha256(body).digest())
    require(bool(seen), 'Empty source fingerprint')
    return digest.hexdigest()


def source_digest_tree(root):
    """Fingerprint tracked working-tree bytes; untracked vendor and secrets excluded.

    Release packaging fingerprints the committed blob bytes using the identical
    source_digest_entries algorithm. A dirty checkout cannot manufacture matching
    qualification evidence for a different immutable release.
    """
    root = Path(root)
    names = subprocess.check_output(['git', 'ls-files', '--cached', '-z'], cwd=root, stderr=subprocess.DEVNULL).split(b'\0')
    require(names and names[-1] == b'', 'Invalid Git file list')
    entries = []
    for raw in names[:-1]:
        name = raw.decode('utf-8')
        file = root / name
        info = file.lstat()
        require(stat.S_ISREG(info.st_mode) and not file.is_symlink(), 'Non-regular tracked source: ' + name)
        entries.append((name, file.read_bytes()))
    return source_digest_entries(entries)


def validate_reports(summary, lifecycle, soak, candidate, version, commit, images, digest):
    require(summary.get('mode')=='full' and summary.get('status')=='lab-qualified' and summary.get('full_qualification_verified') is True and summary.get('commit')==commit and summary.get('release')==version and summary.get('source_digest')==digest, 'Full qualification not for this release source')
    require([s['name'] for s in summary['steps']]==['preflight','lifecycle','lifecycle-evidence','24h-load','24h-evidence'] and all(s['status']=='passed' for s in summary['steps']), 'Qualification incomplete')
    prior=summary['previous_commit']
    require(isinstance(prior,str) and re.fullmatch(r'[0-9a-f]{40}',prior), 'Invalid historical commit')
    lab=validate_lifecycle(lifecycle,version,commit,prior)
    load=validate_soak(soak,version,digest)
    require(summary['lifecycle']==lab and summary['load']==load, 'Qualification summary differs from reports')
    checked=validate_lifecycle(candidate,version,commit,prior)
    require(candidate['images']['new']['candidate_ref']==images['book'] and candidate['images']['pitr']['candidate_ref']==images['postgres-pitr'], 'Exact candidate lifecycle was not tested')
    return {'soak_seconds':load['offered_seconds'],'soak_requests':load['requests'],'minute_windows':load['minute_windows'],
            'rpo_seconds':lab['rpo_seconds'],'rto_seconds':lab['rto_seconds'],
            'candidate_rpo_seconds':checked['rpo_seconds'],'candidate_rto_seconds':checked['rto_seconds']}
