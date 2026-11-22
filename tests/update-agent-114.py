#!/usr/bin/env python3
"""Fault-injected host-agent execution + real Unix peer-credential test (disposable root sandbox)."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / 'bin' / 'update-agent.py'


def check(ok, message):
    if not ok: raise AssertionError(message)
    print('PASS', message)


def wait(agent, expected, timeout=12):
    until = time.monotonic() + timeout
    while time.monotonic() < until:
        r = agent.request({'action':'status'})
        if r['state'] in ('succeeded', 'failed'):
            check(r['state'] == expected, 'Host worker finished with expected state: '+expected+' ('+str(r)+')')
            return r
        time.sleep(.1)
    raise AssertionError('Worker did not complete: '+str(r))


def peer(socket_path, uid, gid, payload):
    script = '''import json,socket,sys
s=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM);s.settimeout(4);s.connect(sys.argv[1]);s.sendall((sys.argv[2]+"\\n").encode());print(s.recv(16384).decode().strip());s.close()
'''
    def downgrade():
        os.setgroups([]);os.setgid(gid);os.setuid(uid)
    output=subprocess.run([sys.executable,'-c',script,str(socket_path),json.dumps(payload)],
                          capture_output=True,text=True,timeout=10,preexec_fn=downgrade if uid != 0 else None)
    check(output.returncode == 0,'Unix socket answered an authorized/unauthorized peer: '+output.stderr)
    return json.loads(output.stdout)


def main():
    if os.geteuid() != 0:
        raise SystemExit('Requires root to verify SO_PEERCRED and protected host tools; run via sudo on isolated CI runner')
    with tempfile.TemporaryDirectory(prefix='book-agent-114-') as tmp:
        base=Path(tmp);base.chmod(0o755);tools=base/'tools';tools.mkdir(mode=0o700)
        code=tools/'update-agent.py';shutil.copy2(SRC,code);code.chmod(0o600)
        updater=tools/'update.py'
        updater.write_text('''import argparse,json,sys
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--state-dir');p.add_argument('action');p.add_argument('--version');p.add_argument('--commit');p.add_argument('--bundle');p.add_argument('--offline',action='store_true');a=p.parse_args()
root=Path(a.state_dir);digest='a'*64
if a.action=='status':
 print(json.dumps({'pending':{'phase':'migration'} if (root/'pending').exists() else None, 'env_changed':False, 'current':{'version':'1.1.4','commit':'e'*40,'manifest':'a'*64}}));sys.exit(0)
if (root/'fail-preflight').exists() and a.action=='preflight': print('simulated error',file=sys.stderr);sys.exit(1)
if (root/'bad-receipt').exists():digest='b'*64
receipt={'version':a.version,'commit':a.commit,'manifest':digest}
if a.action=='fetch': result=receipt
elif a.action=='preflight': result={'status':'passed','candidate':receipt}
else: result={'current':receipt}
print(json.dumps(result))
''')
        updater.chmod(0o600)
        agent_state=base/'agent';agent_state.mkdir(mode=0o700)
        updater_state=base/'updater';updater_state.mkdir(mode=0o700)
        (agent_state/'updater-state-path').write_text(str(updater_state)+'\n')
        (agent_state/'updater-state-path').chmod(0o600)
        permit=base/'permit.json'
        spec=importlib.util.spec_from_file_location('update_agent_114',code)
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        agent=module.Agent(agent_state,updater,permit)
        recovery = agent.request({'action':'recovery-status'})
        check(recovery['state']=='ready' and recovery['current_version']=='1.1.4',
              'Owner panel can read sanitized recovery state without host paths')
        (updater_state/'pending').touch()
        recovery = agent.request({'action':'recovery-status'})
        check(recovery['state']=='pending' and recovery['manual_recovery_required'],
              'Pending journal is reported without enabling unsafe web rollback')
        (updater_state/'pending').unlink()
        c={'version':'1.1.4','commit':'e'*40,'manifest_sha256':'a'*64}
        other={'version':'1.1.5','commit':'f'*40,'manifest_sha256':'a'*64}
        (updater_state/'releases'/('1.1.4-'+'e'*40)/'bundle').mkdir(parents=True)
        (updater_state/'releases'/('1.1.5-'+'f'*40)/'bundle').mkdir(parents=True)
        check(agent.request({'action':'status'})['state']=='idle','Empty host journal is idle')
        for malformed in [{'action':'shell','cmd':'rm -rf /'},{'action':'fetch','candidate':dict(c, extra='x')},
                          {'action':'fetch','candidate':dict(c,commit='x'*40)}]:
            try:agent.request(malformed);raise AssertionError('Untrusted request accepted')
            except module.Rejected:pass
        check(True,'Unknown operations, extra fields and invalid commit refused')
        for operation in ('preflight','apply'):
            try: agent.request({'action':operation,'candidate':c});raise AssertionError('Out-of-order update accepted')
            except module.Rejected:pass
        check(True,'Preflight/apply denied before signed fetch')
        response=agent.request({'action':'fetch','candidate':c})
        check(response['state']=='queued','Fetch started as independent host worker')
        try:agent.request({'action':'preflight','candidate':c});raise AssertionError('Parallel update permitted')
        except module.Rejected:pass
        check(True,'Parallel operation refused while worker is active')
        check(wait(agent,'succeeded')['stage']=='fetched','Signed receipt advances host journal to fetched')
        try:agent.request({'action':'preflight','candidate':dict(c,manifest_sha256='b'*64)});raise AssertionError('Wrong digest accepted')
        except module.Rejected:pass
        check(True,'Different signed manifest refused after fetch')
        (updater_state/'fail-preflight').touch()
        agent.request({'action':'preflight','candidate':c})
        check(wait(agent,'failed')['stage']=='fetched','Failed preflight retains earlier safe stage')
        (updater_state/'fail-preflight').unlink()
        agent.request({'action':'preflight','candidate':c})
        check(wait(agent,'succeeded')['stage']=='preflight','Successful preflight recorded durably')
        try:agent.request({'action':'apply','candidate':c});raise AssertionError('No permit accepted')
        except module.Rejected:pass
        check(True,'Activation requires root-owned maintenance permit')
        def write_permit(data):
            permit.write_text(json.dumps(data));permit.chmod(0o600)
        write_permit({'candidate':other,'fenced':True,'expires_at':int(time.time())+120})
        try:agent.request({'action':'apply','candidate':c});raise AssertionError('Wrong permit accepted')
        except module.Rejected:pass
        check(True,'Mismatched permit refused')
        write_permit({'candidate':c,'fenced':True,'expires_at':int(time.time())-1})
        try:agent.request({'action':'apply','candidate':c});raise AssertionError('Expired permit accepted')
        except module.Rejected:pass
        check(True,'Expired approval refused')
        permit.unlink()
        try:agent.issue_permit(False);raise AssertionError('Permit issued without explicit maintenance acknowledgement')
        except module.Rejected:pass
        check(True,'Root operator must explicitly acknowledge external maintenance fence')
        check(agent.issue_permit(True,120)['version']==c['version'],'Root-only approval issues bounded, candidate-pinned permit')
        try:agent.issue_permit(True,120);raise AssertionError('Existing permit overwritten')
        except module.Rejected:pass
        check(True,'Root-only permit cannot be overwritten or replayed')
        agent.request({'action':'apply','candidate':c})
        check(wait(agent,'succeeded')['stage']=='applied','Offline apply reached applied stage')
        check(not permit.exists(),'Maintenance approval is consumed exactly once')
        try:agent.request({'action':'apply','candidate':c});raise AssertionError('Replay succeeded')
        except module.Rejected:pass
        check(True,'Activation replay refused')
        (updater_state/'bad-receipt').touch()
        agent.request({'action':'fetch','candidate':other})
        check(wait(agent,'failed')['stage']=='none','Mismatching signed receipt fails closed')
        (updater_state/'bad-receipt').unlink()
        # Real socket path, Linux SO_PEERCRED, separate process as non-root UID.
        sockdir=base/'socket';sockdir.mkdir(mode=0o755)
        sock=sockdir/'update.sock'
        server=subprocess.Popen([sys.executable,str(code),'serve','--state-dir',str(agent_state),
            '--updater',str(updater),'--socket',str(sock),'--allowed-uid','65534',
            '--allowed-gid','65534','--permit-file',str(permit)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        try:
            for _ in range(50):
                if sock.exists():break
                if server.poll() is not None:break
                time.sleep(.1)
            check(sock.exists(),'Protected Unix socket listener starts')
            check(stat.S_IMODE(sock.stat().st_mode)==0o660,'Socket is not publicly connectable')
            result=peer(sock,65534,65534,{'action':'status'})
            check(result['ok'] and result['data']['state']=='failed','Allowed unprivileged app UID can read sanitized status')
            # The actual PHP client must also connect as an unprivileged app UID.
            client = sockdir / 'UpdateAgentClient.php'
            shutil.copy2(ROOT/'src'/'UpdateAgentClient.php', client)
            client.chmod(0o644)
            php_code = 'require $argv[1]; $client=new \\CajeerBook\\UpdateAgentClient($argv[2]); echo json_encode($client->call("status"));'
            def php_uid():
                os.setgroups([]);os.setgid(65534);os.setuid(65534)
            php = subprocess.run(['php','-r',php_code,str(client),str(sock)],capture_output=True,text=True,
                                  timeout=8,preexec_fn=php_uid)
            check(php.returncode==0 and json.loads(php.stdout)['state']=='failed',
                  'Actual unprivileged PHP client communicates with host Unix broker: '+php.stderr)

            bad=peer(sock,0,0,{'action':'status'})
            check(not bad['ok'],'Root or unrelated peer UID refused')
            bad=peer(sock,65534,65534,{'action':'run','binary':'bash'})
            check(not bad['ok'],'Arbitrary command injection refused at socket boundary')
            (updater_state/'pending').touch()
            try:agent.reconcile();raise AssertionError('Pending migration was discarded')
            except module.Rejected:pass
            check(True,'Root reconciliation refuses pending migration state')
            (updater_state/'pending').unlink()
            check(agent.reconcile()['stage']=='none','Root reconciliation unlocks only verified clean updater state')
        finally:
            server.terminate()
            try:server.communicate(timeout=3)
            except subprocess.TimeoutExpired:server.kill();server.communicate()
    print('Host Update Agent 1.1.4 checks completed')


if __name__=='__main__':main()
