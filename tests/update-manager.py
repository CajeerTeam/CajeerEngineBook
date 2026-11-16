#!/usr/bin/env python3
"""Real signed release bytes and durable updater state; Docker adapter is simulated here.
The separate update-compose-gate.py exercises actual Docker/SQL/HTTP.
"""
import argparse
import importlib.util
import io
import http.server
import ssl
import threading
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock
import zipfile

ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m
u=module('update',ROOT/'bin/update.py')
signing=module('signing',ROOT/'tests/release-signing.py')

class FakeDocker:
    """A command adapter for state-machine/fault injection tests, NOT a Docker E2E."""
    def __init__(self):
        self.live={}; self.commands=[]; self.schema=3; self.fail=None; self.sql=True; self.bad_volume=False
    def config(self,tree):
        text=(tree/'compose.yaml').read_text(); ref=re.search(r'image: (\S+)',text).group(1)
        service={'image':ref,'volumes':[{'type':'volume','source':'book-data','target':'/var/lib/cajeerbook'}]}
        c={'services':{'book':service,'worker':dict(service)},'volumes':{'book-data':{'name':'test_book-data'}}}
        if self.sql: c['services']['postgres']={'image':'postgres:16-bookworm','volumes':[{'type':'volume','source':'book-postgres','target':'/var/lib/postgresql/data'}]}; c['volumes']['book-postgres']={'name':'test_book-postgres'}
        return c
    def initial(self,tree):
        c=self.config(tree); self.live={n:(s,c) for n,s in c['services'].items()}
    def __call__(self,args,**kw):
        self.commands.append(list(args)); cmd=args[3:]; out=lambda v:json.dumps(v).encode()
        if cmd[0]=='compose':
            tree=Path(cmd[cmd.index('--project-directory')+1]); c=self.config(tree); v=(tree/'VERSION').read_text().strip()
            # The final -f is followed by the Compose command.
            i=cmd.index('-f')
            while cmd[i]=='-f': i+=2
            a=cmd[i:]; op=a[0]
            if op=='config': return out(c)
            if op=='stop':
                for n in a[3:]: self.live.pop(n,None)
                return b''
            if op=='rm': return b''
            if op=='up':
                n=a[-1]; self.live[n]=(c['services'][n],c)
                if self.fail=='activation' and v=='0.8.1': raise RuntimeError('injected activation error')
                return b''
            if op=='run':
                if 'cat' in a: return (('bad' if self.fail=='version' and v=='0.8.1' else v)+'\n').encode()
                if 'bin/migrations.php' in a:
                    action=a[a.index('bin/migrations.php')+1]; target=int(re.search(r'public const VERSION = (\d+);',(tree/'src/DatabaseMigrations.php').read_text()).group(1))
                    if action in ('status','verify','preflight'):
                        if action=='preflight' and self.fail=='preflight': raise RuntimeError('injected preflight failure')
                        if action=='verify' and self.schema!=target: raise RuntimeError('wrong schema')
                        if action=='status' and self.schema>target: raise RuntimeError('future schema')
                        return out({'current':self.schema,'required':target,'compatible':self.schema==target,'pending':[{'reversible':True} for _ in range(self.schema,target)],'preflight':'passed','dry_run_rolled_back':True})
                    if action=='apply':
                        old=self.schema; self.schema=target; return out({'from':old,'to':target})
                    if action=='rollback':
                        old=self.schema; self.schema=int(a[-2]); return out({'from':old,'to':self.schema})
                if 'backup' in a and 'bin/installation.php' in a:
                    if self.fail=='backup': raise RuntimeError('injected backup failure')
                    d=Path(a[a.index('-v')+1].split(':')[0]); (d/'portable').mkdir(); (d/'portable'/'manifest.json').write_text('{}')
                if 'bin/installation.php' in a and 'check' in a and self.fail=='candidate-check' and v=='0.8.2': raise RuntimeError('injected postmigration failure')
                return b''
            if op=='exec':
                if kw.get('stdout'): kw['stdout'].write(b'test simulated dump')
                return b''
        elif cmd[:2]==['image','inspect']:
            return out([{'Id':'id:'+cmd[-1],'Config':{'Entrypoint':None,'Cmd':None}}])
        elif cmd[0]=='pull': return b''
        elif cmd[0]=='ps':
            return b'' if any(x.startswith('name=') for x in cmd) else ('\n'.join(self.live)+'\n').encode()
        elif cmd[0]=='inspect':
            n=cmd[1]; s,c=self.live[n]; mounts=[]
            for v in s.get('volumes',[]): mounts.append({'Destination':v['target'],'Type':'volume','Name':'wrong' if self.bad_volume and n=='book' else c['volumes'][v['source']]['name'],'RW':True})
            return out([{'Config':{'Labels':{'com.docker.compose.service':n,'com.docker.compose.project':'test'},'Image':s['image'],'Env':[],'Entrypoint':None,'Cmd':None},'State':{'Running':True},'Image':'id:'+s['image'],'Mounts':mounts}])
        raise AssertionError('Unexpected command '+str(args))

class ComposeRendering(unittest.TestCase):
    def test_dollar_rendering_preserves_exact_environment_and_command(self):
        # Simulate both CLI rendering formats, including a literal double-dollar
        # secret. The actual compose integration gate also exercises bcrypt.
        image='example.invalid/book@sha256:'+'a'*64
        secret='$2y$12$test$$literal'
        command=['sh','-ec','echo "$EXAMPLE"; echo "$$"']
        model={'services':{'book':{'image':image,'environment':{'BOOK_ADMIN_HASH':secret},'command':command}},'volumes':{}}
        for escaped in (False,True):
            with self.subTest(escaped=escaped), tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp); (root/'compose.yaml').touch(); (root/'env').touch()
                def adapter(args,**kw):
                    def render(value):
                        raw=json.dumps(value).encode()
                        return raw.replace(b'$',b'$$') if escaped else raw
                    if 'book-config-probe' in args:
                        source=Path(args[args.index('-f')+1]); probe=json.loads(source.read_text())
                        self.assertEqual(probe['services']['probe']['environment']['BOOK_CONFIG_PROBE'],'$$book_config_probe')
                        probe['services']['probe']['environment']['BOOK_CONFIG_PROBE']='$book_config_probe'
                        return render(probe)
                    if args[3:5]==['image','inspect']:
                        return json.dumps([{'Id':'image-id','Config':{'Entrypoint':None,'Cmd':None}}]).encode()
                    return render(model)
                c=u.Compose(root,{'project':'test','env':str(root/'env'),'overlays':[]},adapter)
                config=c.config({'images':{'book':image}})
                self.assertEqual(config,model)
                obj={'Mounts':[],'Config':{'Env':['BOOK_ADMIN_HASH='+secret],'Entrypoint':None,'Cmd':command}}
                self.assertEqual(c.validate_container(obj,config,'book'),'image-id')
                obj['Config']['Env']=['BOOK_ADMIN_HASH='+secret.replace('$','$$')]
                with self.assertRaisesRegex(ValueError,'Container environment differs'):
                    c.validate_container(obj,config,'book')

    def test_compose_null_inherits_image_process_defaults(self):
        image='example.invalid/book@sha256:'+'a'*64
        defaults={'Entrypoint':['/entrypoint.sh'],'Cmd':['/usr/sbin/httpd','-DFOREGROUND']}
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); (root/'compose.yaml').touch(); (root/'env').touch()
            def adapter(args,**kw):
                self.assertEqual(args[3:5],['image','inspect'])
                return json.dumps([{'Id':'image-id','Config':defaults}]).encode()
            c=u.Compose(root,{'project':'test','env':str(root/'env'),'overlays':[]},adapter)
            # Missing and explicit null fields both inherit; an explicit nonempty
            # entrypoint without a command suppresses the image CMD.
            cases=[({},defaults['Entrypoint'],defaults['Cmd']),
                   ({'entrypoint':None,'command':None},defaults['Entrypoint'],defaults['Cmd']),
                   ({'entrypoint':None,'command':['php','bin/worker.php','--loop']},defaults['Entrypoint'],['php','bin/worker.php','--loop']),
                   ({'entrypoint':['/custom'],'command':None},['/custom'],None),
                   ({'entrypoint':['/custom'],'command':[]},['/custom'],[]),
                   ({'entrypoint':[],'command':None},[],defaults['Cmd'])]
            for override,entrypoint,cmd in cases:
                with self.subTest(override=override):
                    config={'services':{'book':{'image':image,**override}},'volumes':{}}
                    obj={'Mounts':[],'Config':{'Env':[],'Entrypoint':entrypoint,'Cmd':cmd}}
                    self.assertEqual(c.validate_container(obj,config,'book'),'image-id')
                    obj['Config']['Cmd']=['/unexpected-command']
                    with self.assertRaisesRegex(ValueError,'Container command differs'):
                        c.validate_container(obj,config,'book')
                    obj['Config']['Cmd']=cmd; obj['Config']['Entrypoint']=['/unexpected-entrypoint']
                    with self.assertRaisesRegex(ValueError,'Container entrypoint differs'):
                        c.validate_container(obj,config,'book')

    def test_signed_healthcheck_changes_only_for_application(self):
        root=Path('/release')
        before={'services':{'book':{'image':'old'},'worker':{'image':'old','healthcheck':{'disable':True}},'postgres':{'image':'pg','healthcheck':{'test':['CMD','pg_isready']}}}}
        after=json.loads(json.dumps(before));after['services']['worker']['healthcheck']={'test':['CMD','php','bin/worker.php','--check']}
        self.assertEqual(u.normalized(before,root),u.normalized(after,root))
        after['services']['postgres']['healthcheck']={'disable':True}
        self.assertNotEqual(u.normalized(before,root),u.normalized(after,root))
        after=json.loads(json.dumps(before));after['services']['worker']['command']=['unexpected']
        self.assertNotEqual(u.normalized(before,root),u.normalized(after,root))

    def test_disabled_worker_waits_for_stable_replicas(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'compose.yaml').touch();(root/'env').touch();calls=[]
            def adapter(args,**kw):
                calls.append(args)
                if 'inspect' in args:return json.dumps([{'State':{'Running':True,'Restarting':False,'StartedAt':'started'},'RestartCount':0}]).encode()
                if 'ps' in args:return b'one\ntwo\n'
                return b''
            c=u.Compose(root,{'project':'test','env':str(root/'env'),'overlays':[]},adapter)
            conf={'services':{'worker':{'healthcheck':{'disable':True}},'book':{'healthcheck':{'disable':True}}}}
            with mock.patch.object(u.time,'sleep'):c.start_service(conf,'worker',2)
            self.assertNotIn('--wait',calls[0]);self.assertEqual(sum('inspect' in a for a in calls),4)
            with self.assertRaisesRegex(ValueError,'readiness check disabled'):c.start_service(conf,'book',1)
            conf['services']['worker']['healthcheck']={'test':['CMD','php','bin/worker.php','--check']}
            c.start_service(conf,'worker',2);self.assertIn('--wait',calls[-1])

class Update(unittest.TestCase):
    def setUp(self):
        self.f=signing.Signing('test_real_package_and_reproducibility'); self.f.setUp(); self.addCleanup(self.f.doCleanups)
        self.base=self.f.base; self.env=self.base/'runtime.env'; self.env.write_text('BOOK_TEST=1\n'); self.env.chmod(0o600)
        self.fake=FakeDocker(); self.verifications=[]
        self.manager=u.Manager(self.base/'state',command=self.fake,verify_oci=lambda m:self.verifications.append(m['version']))
        self.manager.pub.write_bytes(self.f.pub.read_bytes()); self.manager.pub.chmod(0o400)
        self.cfg={'project':'test','repository':self.f.repo,'overlays':['postgres'],'env':str(self.env),'env_sha':u.release.sha(self.env)}
        self.old_bundle=self.build('0.8.0','1'); self.new_bundle=self.build('0.8.1','3')
        self.old=self.manager.stage(self.cfg,'0.8.0',self.old_bundle); self.new=self.manager.stage(self.cfg,'0.8.1',self.new_bundle)
        old,_=self.manager.load(self.cfg,self.old); self.fake.initial(old.tree)
        self.state={'format':1,'config':self.cfg,'key':u.hashlib.sha256(u.release.key_der(self.manager.pub,True)).hexdigest(),'current':self.old,'previous':None,'highest':self.old,'pending':None}
        self.manager.save(self.state)
        self.chown=mock.patch.object(u.os,'chown'); self.chown.start(); self.addCleanup(self.chown.stop)
    def build(self,v,d,schema=3):
        f=self.f; (f.root/'VERSION').write_text(v+'\n'); p=f.root/'src/DatabaseMigrations.php'; p.write_text(re.sub(r'public const VERSION = \d+;',f'public const VERSION = {schema};',p.read_text()))
        f.git('add','.'); f.git('-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm',v)
        f.commit=f.git('rev-parse','HEAD').strip(); f.version=v; f.digests[0]='sha256:'+d*64; f.images['book']=u.release.image_ref(f.repo,'book',f.digests[0])
        f.e.update(version=v,commit=f.commit,images=f.images,gates={g:"success" for g in u.release.gates_for(v)});
        if u.release.stable(v): f.e["production_qualification"]=signing.fixture.write_fixture(u.release,f.evidence,f.root,v,f.commit,f.images)
        u.release.dump(f.evidence/'gate-evidence.json',f.e); u.release.dump(f.evidence/'book-build-metadata.json',{'containerimage.digest':f.digests[0]})
        out=self.base/('bundle-'+v); p=f.command(output=out); self.assertEqual(p.returncode,0,p.stderr); return out
    def test_upgrade_and_rollback_highwater(self):
        s=self.manager.begin(self.state,self.new); self.assertEqual(s['current'],self.new); self.assertIsNone(s['pending']); self.assertEqual(self.fake.schema,3)
        s=self.manager.begin(s,self.old,rollback=True); self.assertEqual(s['current'],self.old); self.assertEqual(s['highest'],self.new)
        self.assertEqual(len(list((self.manager.root/'backups').iterdir())),2)
        self.assertEqual(self.manager.read()['current'],self.old); self.assertTrue(any('preflight' in a for a in self.fake.commands)); self.assertTrue(any('pg_dump' in a for a in self.fake.commands))
        self.assertEqual(self.manager.begin(s,self.new)['current'],self.new)
    def test_stable_bundle_staging(self):
        bundle=self.build('1.0.0','5'); target=self.manager.stage(self.cfg,'1.0.0',bundle)
        self.assertEqual(target['version'],'1.0.0'); _,manifest=self.manager.load(self.cfg,target)
        self.assertIn('qualification-soak.json',manifest['artifacts'])
    def test_candidate_version_failure_rolls_back(self):
        self.fake.fail='version'
        with self.assertRaises(ValueError): self.manager.begin(self.state,self.new)
        s=self.manager.read(); self.assertIsNone(s['pending']); self.assertEqual(s['current'],self.old); self.assertEqual(s['highest'],self.new); self.assertEqual(set(self.fake.live),{'book','worker','postgres'})
    def test_backup_failure_rolls_back(self):
        self.fake.fail='backup'
        with self.assertRaises(RuntimeError): self.manager.begin(self.state,self.new)
        self.assertIsNone(self.manager.read()['pending']); self.assertFalse(any('apply' in a for a in self.fake.commands))
    def test_migration_preflight_failure_rolls_back(self):
        self.fake.fail='preflight'
        with self.assertRaises(RuntimeError): self.manager.begin(self.state,self.new)
        self.assertIsNone(self.manager.read()['pending']); self.assertEqual(self.fake.schema,3)
    def test_activation_failure_requires_recovery(self):
        self.fake.fail='activation'
        with self.assertRaises(RuntimeError): self.manager.begin(self.state,self.new)
        s=self.manager.read(); self.assertEqual(s['pending']['phase'],'activating'); self.assertEqual(set(self.fake.live),{'postgres'})
        with self.assertRaises(ValueError): self.manager.recover(s,False)
        self.fake.fail=None; self.assertEqual(self.manager.recover(s,True)['current'],self.old)
    def test_crash_journal_recovery(self):
        s=self.manager.read(); s['pending']={'from':self.old,'to':self.new,'running':{'book':1,'worker':1,'postgres':1},'phase':'stopping','backup':'crash','rollback':False}; s['highest']=self.new; self.manager.save(s)
        self.fake.live.pop('book'); self.assertEqual(self.manager.recover(self.manager.read(),False)['current'],self.old); self.assertIsNone(self.manager.read()['pending'])
    def test_future_schema_rollback_before_activation(self):
        s=self.manager.begin(self.state,self.new); third=self.manager.stage(self.cfg,'0.8.2',self.build('0.8.2','4',4))
        self.fake.fail='candidate-check'
        with self.assertRaises(RuntimeError): self.manager.begin(s,third,allow_schema=True)
        self.assertEqual(self.fake.schema,3); self.assertEqual(self.manager.read()['current'],self.new); self.assertIsNone(self.manager.read()['pending']); self.assertTrue(any('rollback' in a for a in self.fake.commands))
    def test_schema_upgrade_requires_consent_and_compatible_old(self):
        r=self.manager.stage(self.cfg,'0.8.2',self.build('0.8.2','4',4))
        with self.assertRaises(ValueError): self.manager.begin(self.state,r,allow_schema=True)
        self.assertIsNone(self.manager.read()['pending'])
    def test_downgrade_and_conflicting_highwater(self):
        with self.assertRaises(ValueError): self.manager.begin(self.state,self.old)
        s=self.manager.read(); s['highest']=dict(self.new,manifest='f'*64)
        with self.assertRaises(ValueError): self.manager.begin(s,self.new)
    def test_wrong_volume(self):
        self.fake.bad_volume=True
        with self.assertRaises(ValueError): self.manager.begin(self.state,self.new)
        self.assertIsNone(self.manager.read()['pending'])
    def test_tamper_tree(self):
        p=self.manager.root/'releases'/self.new['id']/'tree'/'VERSION'; p.write_text('0.8.9\n')
        with self.assertRaises(ValueError): self.manager.load(self.cfg,self.new)
    def test_tampered_bundle_and_wrong_commit(self):
        with self.assertRaises(ValueError): self.manager.stage(self.cfg,'0.8.1',self.new_bundle,commit='a'*40)
        p=self.new_bundle/'book-sbom.spdx.json'; p.write_bytes(b'x'*p.stat().st_size)
        with self.assertRaises(ValueError): self.manager.stage(self.cfg,'0.8.1',self.new_bundle)
    def test_changed_env_and_key(self):
        self.env.write_text('BOOK_TEST=2\n')
        with self.assertRaises(ValueError): self.manager.read()
    def test_lock_exclusion(self):
        with self.manager.locked():
            with self.assertRaises(RuntimeError):
                with self.manager.locked(): pass
    def test_project_lock_exclusion(self):
        with u.project_lock('test',self.base):
            with self.assertRaises(RuntimeError):
                with u.project_lock('test',self.base): pass
    def test_reconcile_env(self):
        self.env.write_text('BOOK_TEST=2\n')
        s=self.manager.reconcile_env(self.manager.read(allow_env_change=True))
        self.assertEqual(s['config']['env_sha'],u.release.sha(self.env))
        self.assertEqual(self.manager.read()['current'],self.old)
    def test_download_manifest_first(self):
        seen=[]
        def get(url,dest,cap,expected=None):
            name=url.rsplit('/',1)[1]; seen.append(name)
            with (self.new_bundle/name).open('rb') as f: u.copy_stream(f,dest,cap,expected)
        with mock.patch.object(u,'download',get): self.manager.stage(self.cfg,'0.8.1')
        self.assertEqual(seen[:2],['release.json','release.json.sig']); self.assertEqual(set(seen),u.ALL_FILES)
        (self.new_bundle/'release.json.sig').write_bytes(b'0'*64); seen.clear()
        with mock.patch.object(u,'download',get),self.assertRaises(subprocess.CalledProcessError): self.manager.stage(self.cfg,'0.8.1')
        self.assertEqual(seen,['release.json','release.json.sig'])
    def test_redirect_and_size_bounds(self):
        for url in ('http://github.com/x','https://evil.example/x','https://github.com@evil.example/x','https://github.com:444/x','https://github.com.evil.example/x'):
            with self.assertRaises(ValueError): u.safe_url(url)
        self.assertEqual(u.safe_url('https://release-assets.githubusercontent.com/a?x=1'),'https://release-assets.githubusercontent.com/a?x=1')
        with self.assertRaises(ValueError): u.copy_stream(io.BytesIO(b'1234'),self.base/'too-big',3)
        with self.assertRaises(ValueError): u.copy_stream(io.BytesIO(b'12'),self.base/'too-small',3,3)
    def test_real_https_download_and_redirect(self):
        cert=self.base/'tls.crt'; key=self.base/'tls.key'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        body=(self.new_bundle/'release.json').read_bytes()
        class Handler(http.server.BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self):
                if self.path=='/redirect':
                    self.send_response(302); self.send_header('Location','http://localhost:'+str(self.server.server_port)+'/asset'); self.end_headers(); return
                self.send_response(200); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
        ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(cert,key); server.socket=ctx.wrap_socket(server.socket,server_side=True)
        thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
        try:
            url='https://localhost:'+str(server.server_port)
            with mock.patch.object(u,'HOSTS',{'localhost'}),mock.patch.object(u,'HTTPS_PORTS',{server.server_port}),mock.patch.dict(os.environ,{'SSL_CERT_FILE':str(cert)}):
                p=self.base/'https-asset'; u.download(url+'/asset',p,1024*1024,len(body)); self.assertEqual(p.read_bytes(),body)
                with self.assertRaises(ValueError): u.download(url+'/redirect',self.base/'bad-redirect',1024*1024)
                with self.assertRaises(ValueError): u.download(url+'/asset',self.base/'bad-length',1024*1024,len(body)-1)
        finally: server.shutdown(); server.server_close(); thread.join()
    def test_zip_path_conflict(self):
        path=self.base/'bad.zip'
        with zipfile.ZipFile(path,'w') as z:
            for n in ('a','a/b'):
                i=zipfile.ZipInfo(n); i.create_system=3; i.external_attr=0o100644<<16; z.writestr(i,b'x')
        with zipfile.ZipFile(path) as z,self.assertRaises(ValueError): u.zip_members(z)

if __name__=='__main__': unittest.main(verbosity=2)
