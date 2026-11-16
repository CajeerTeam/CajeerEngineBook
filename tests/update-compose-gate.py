#!/usr/bin/env python3
"""Disposable real Docker/SQL/HTTP updater test with locally signed fixture releases.
No GHCR or production keys. Local registry trust is deliberately injected ONLY here;
production updater always verifies GitHub OIDC Cosign identity.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m
u=load('update',ROOT/'bin/update.py')
http=load('release_http',ROOT/'tests/release-http.py')

def command(*args, cwd=None):
    return subprocess.check_output(args,cwd=cwd,stderr=subprocess.STDOUT).decode().strip()

def main():
    assert os.geteuid()==0, 'Run on disposable Docker host as root'
    os.umask(0o077)
    tag='book-update-'+secrets.token_hex(6); registry=tag+'-registry'; base_image=tag+':base'; project=tag
    with tempfile.TemporaryDirectory(prefix='book-update-e2e-') as tmp:
        root=Path(tmp); cfg=None; manager=None; current=None; tags=[]
        try:
            command('docker','run','-d','--rm','--name',registry,'-p','127.0.0.1::5000','registry:2')
            port=command('docker','port',registry,'5000/tcp').rsplit(':',1)[1]; registry_ref='127.0.0.1:'+port+'/book'
            for _ in range(60):
                try:
                    with urllib.request.urlopen('http://127.0.0.1:'+port+'/v2/',timeout=2) as r:
                        if r.status==200: break
                except OSError: time.sleep(.5)
            else: raise RuntimeError('Local registry not ready')
            # Exact local registry digests, never mutable tags, used in the integration fixture.
            u.release.image_ref=lambda repo,role,digest:registry_ref+'@'+digest
            command('docker','build','--target','production','-t',base_image,str(ROOT)); tags.append(base_image)
            key=root/'private.pem'; pub=root/'public.pem'; command('openssl','genpkey','-algorithm','ED25519','-out',str(key)); key.chmod(0o600); command('openssl','pkey','-in',str(key),'-pubout','-out',str(pub))
            with socket.socket() as sock: sock.bind(('127.0.0.1',0)); httpport=sock.getsockname()[1]
            src=root/'source'; src.mkdir(); (src/'src').mkdir(); (src/'bin').mkdir()
            for f in ('compose.yaml','compose.postgres.yaml','src/DatabaseMigrations.php','bin/postgres-init.sh'): shutil.copyfile(ROOT/f,src/f)
            p=src/'compose.yaml'; p.write_text(p.read_text().replace('127.0.0.1:8080:80',f'127.0.0.1:{httpport}:80'))
            command('git','init','-q',cwd=src); repo='Test/Book'; ev=root/'evidence'; ev.mkdir()
            def bundle(v,image_version):
                # Use actual production runtime with fixture version identifiers; no claim of historical binaries.
                b=root/('image-'+v); b.mkdir(); (b/'Dockerfile').write_text(f'FROM {base_image}\nRUN printf "{image_version}\\n" > VERSION\n')
                ref=registry_ref+':v'+v; command('docker','build','-t',ref,str(b)); tags.append(ref)
                command('docker','push',ref); refs=json.loads(command('docker','image','inspect',ref,'--format','{{json .RepoDigests}}')); digest=next(x.rsplit('@',1)[1] for x in refs if x.startswith(registry_ref+'@'))
                (src/'VERSION').write_text(v+'\n'); command('git','add','.',cwd=src); command('git','-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm',v,cwd=src); commit=command('git','rev-parse','HEAD',cwd=src)
                images={r:u.release.image_ref(repo,r,digest) for r in u.release.ROLES}
                u.release.dump(ev/'gate-evidence.json',{'version':v,'commit':commit,'repository':repo,'run_id':'1','run_attempt':'1','gates':{g:'success' for g in u.release.GATES},'images':images,'image_checks':{r:{'smoke':'success','scan':'success'} for r in u.release.ROLES}})
                for r in u.release.ROLES:
                    u.release.dump(ev/f'{r}-sbom.spdx.json',{'spdxVersion':'SPDX-2.3','packages':[{'name':'local fixture'}]}); u.release.dump(ev/f'{r}-provenance.json',{'buildType':'fixture','materials':[{'uri':'fixture'}]}); u.release.dump(ev/f'{r}-build-metadata.json',{'containerimage.digest':digest}); u.release.dump(ev/f'{r}-scan.json',{'Results':[{'Target':'fixture'}]}); (ev/f'{r}-smoke.log').write_text('TEST ONLY\n')
                out=root/('bundle-'+v); u.release.assemble(argparse.Namespace(root=src,output=out,version=v,commit=commit,repository=repo,key=key,public_key=pub,book_digest=digest,pitr_digest=digest,evidence=ev)); return out
            a=bundle('0.8.0','0.8.0'); b=bundle('0.8.1','0.8.1'); bad=bundle('0.8.2','0.0.0')
            env=root/'runtime.env'; password=secrets.token_hex(24); admin=secrets.token_hex(24); namespace=secrets.token_hex(16)
            ph=command('php','-r',f'echo password_hash("{http.PASSWORD}", PASSWORD_DEFAULT);')
            env.write_text(f"BOOK_ADMIN_HASH='{ph}'\nBOOK_SECURE_COOKIE=0\nBOOK_PG_PASSWORD={password}\nBOOK_PG_ADMIN_PASSWORD={admin}\nBOOK_PG_NAMESPACE={namespace}\n"); env.chmod(0o600)
            def local_trust(m):
                for ref in set(m['images'].values()): command('docker','pull',ref)
            manager=u.Manager(root/'state',verify_oci=local_trust); manager.pub.write_bytes(pub.read_bytes()); manager.pub.chmod(0o400)
            cfg={'project':project,'repository':repo,'overlays':['postgres'],'env':str(env),'env_sha':u.release.sha(env)}
            initial=manager.stage(cfg,'0.8.0',a); current,m=manager.load(cfg,initial)
            current.call('up','-d','--wait','--wait-timeout','120','postgres',timeout=240)
            current.one('php','bin/postgres-content.php','migrate','--offline')
            current.call('up','-d','--no-build','--wait','--wait-timeout','180','book',timeout=300)
            base='http://127.0.0.1:'+str(httpport); http.run(base)
            s=manager.enroll(argparse.Namespace(project=project,repository=repo,overlays='postgres',env_file=env,public_key=pub,version='0.8.0',bundle=a,commit=None))
            target=manager.stage(cfg,'0.8.1',b); s=manager.begin(s,target); http.run(base,persisted=True)
            assert s['current']==target and s['pending'] is None
            print('PASS actual PostgreSQL/HTTP upgrade and backup restore drill',flush=True)
            s=manager.begin(s,initial,rollback=True); http.run(base,persisted=True)
            assert s['current']==initial and s['highest']==target
            print('PASS actual rollback retains published SQL content and volume',flush=True)
            broken=manager.stage(cfg,'0.8.2',bad)
            try: manager.begin(s,broken)
            except (ValueError,RuntimeError): pass
            else: raise AssertionError('Wrong image VERSION accepted')
            s=manager.read(); assert s['current']==initial and s['pending'] is None and s['highest']==broken; http.run(base,persisted=True)
            print('PASS wrong image VERSION automatically restores old deployment',flush=True)
            assert len(list((manager.root/'backups').iterdir()))==3
        finally:
            if current is not None:
                try: current.cleanup_task(); current.call('down','-v','--remove-orphans',timeout=300)
                except Exception as e: print('Disposable project cleanup failed:',e); raise
            subprocess.run(['docker','rm','-f',registry],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            for ref in reversed(tags): subprocess.run(['docker','image','rm',ref],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

if __name__=='__main__': main()
