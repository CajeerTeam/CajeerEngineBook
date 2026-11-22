#!/usr/bin/env python3
"""Root-owned durable operation history, safe retries, redacted codes and failure fences."""
import importlib.util
import sys
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile

ROOT=Path(__file__).resolve().parents[1]
def ok(condition, why):
    if not condition: raise AssertionError(why)
    print('PASS',why)
def rejected(fn, why, cls):
    try: fn()
    except cls: ok(True,why); return
    raise AssertionError(why)

def policy_only():
    with tempfile.TemporaryDirectory(prefix='book117-unit-') as d:
        spec=importlib.util.spec_from_file_location('book117unit',ROOT/'bin/update-agent.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        class Fixture:
            history_path=Path(d)/'history.json'
            def history(self):
                return json.loads(self.history_path.read_text()) if self.history_path.exists() else []
        fixture=Fixture()
        for n in range(80):
            module.Agent.event(fixture,{'job_id':format(n,'032x'),'action':'fetch','state':'succeeded',
                'stage':'fetched','candidate':{'version':'1.1.7'},'started_at':int(__import__('time').time())-2},'COMPLETED')
        events=fixture.history()
        ok(len(events)==module.HISTORY_KEEP and events[0]['id']==format(79,'032x'),
           'Durable root-agent event writer maintains bounded newest-first history')
        ok(all(e['code']=='COMPLETED' and 'output' not in e for e in events),
           'Serialized events use only safe allowlisted fields')
        ok(stat.S_IMODE(fixture.history_path.stat().st_mode)==0o600,'History is always written with mode 0600')

def main():
    if os.geteuid()!=0: raise SystemExit('Root needed for root-only fixture')
    with tempfile.TemporaryDirectory(prefix='book117-history-') as tmp:
        base=Path(tmp);base.chmod(0o755)
        tools=base/'trusted';tools.mkdir(mode=0o700)
        code=tools/'update-agent.py';shutil.copy2(ROOT/'bin/update-agent.py',code);code.chmod(0o600)
        updater=tools/'update.py'
        updater.write_text('''import argparse,json,sys\nfrom pathlib import Path\np=argparse.ArgumentParser();p.add_argument('--state-dir');p.add_argument('action');p.add_argument('--version');p.add_argument('--commit');p.add_argument('--bundle');p.add_argument('--offline',action='store_true');a=p.parse_args()\nr=Path(a.state_dir)\nif a.action=='status':\n print(json.dumps({'pending':None,'env_changed':False,'current':{'version':'1.1.6','commit':'a'*40,'manifest':'b'*64}}));sys.exit(0)\nif ((r/'fail').exists() and a.action=='preflight') or ((r/'fail-fetch').exists() and a.action=='fetch'):\n print('SECRET_CREDENTIAL=never_display',file=sys.stderr);sys.exit(19)\nc={'version':a.version,'commit':a.commit,'manifest':'b'*64}\nprint(json.dumps(c if a.action=='fetch' else {'status':'passed','candidate':c} if a.action=='preflight' else {'current':c}))\n''');updater.chmod(0o600)
        state=base/'agent';state.mkdir(mode=0o700)
        upstate=base/'updater';upstate.mkdir(mode=0o700)
        (state/'updater-state-path').write_text(str(upstate)+'\n');(state/'updater-state-path').chmod(0o600)
        spec=importlib.util.spec_from_file_location('book117agent',code);mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
        permit=base/'permit.json';agent=mod.Agent(state,updater,permit)
        jobs=[];original=mod.subprocess.Popen
        try:
            c={'version':'1.1.7','commit':'a'*40,'manifest_sha256':'b'*64}
            (upstate/'releases'/('1.1.7-'+'a'*40)/'bundle').mkdir(parents=True)
            def run(action,candidate=None):
                candidate=c if candidate is None else candidate
                mod.subprocess.Popen=lambda *args,**kw: jobs.append(args[0])
                try: ret=agent.request({'action':action,'candidate':candidate})
                finally: mod.subprocess.Popen=original
                ok(ret['state']=='queued','Operation queued: '+action)
                agent.worker(ret['job_id'])
                return agent.request({'action':'status'})
            ok(run('fetch')['state']=='succeeded','Real signed-fetch receipt recorded')
            (upstate/'fail').touch()
            ok(run('preflight')['state']=='failed','Preflight failure recorded without activating')
            events=agent.request({'action':'history'})['events']
            ok(events[0]['code']=='UPDATE_MANAGER_REJECTED' and events[0]['state']=='failed','Failure code is structured and redacted')
            ok(not any('SECRET_CREDENTIAL' in json.dumps(e) for e in events),'Host diagnostics never disclose stderr')
            (upstate/'fail').unlink()
            ok(run('preflight')['state']=='succeeded','Safe preflight retry after failure succeeds')
            ok(len(agent.request({'action':'history'})['events'])>=9,'Queued/running/finished events persisted')
            new=mod.Agent(state,updater,permit)
            ok(new.request({'action':'history'})['events']==events[:0]+agent.request({'action':'history'})['events'],
               'History survives agent reconstruction')
            ok(stat.S_IMODE((state/'history.json').stat().st_mode)==0o600,'History is root-only 0600')
            ok(len(new.request({'action':'history'})['events'])<=mod.HISTORY_PUBLIC,'Web RPC is bounded')
            rejected(lambda: new.request({'action':'apply','candidate':c}),
                     'Apply remains forbidden without signed maintenance permit',mod.Rejected)
            other={'version':'1.1.8','commit':'c'*40,'manifest_sha256':'b'*64}
            (upstate/'fail-fetch').touch()
            ok(run('fetch',other)['state']=='failed','Failed fetch does not mark the new candidate as verified')
            (upstate/'fail-fetch').unlink()
            ok(run('fetch',other)['state']=='succeeded','Failed fetch can be retried without unsigned bypass')
            ok(agent.request({'action':'history'})['events'][0]['code']=='COMPLETED','Retry outcome remains visible in history')
            (state/'history.json').unlink();(state/'history.json').symlink_to(updater)
            rejected(lambda: new.request({'action':'history'}),'Symlinked history rejected',mod.Rejected)
            rejected(lambda: new.request({'action':'fetch','candidate':c}),
                     'Corrupt audit state fails closed for mutating operations',mod.Rejected)
        finally:
            mod.subprocess.Popen=original
    print('Update History 1.1.7 tests completed')
if __name__=='__main__':
    if '--policy-only' in sys.argv: policy_only()
    else: main()
