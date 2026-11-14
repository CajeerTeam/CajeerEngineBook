#!/usr/bin/env python3
"""HTTP -> operator backup -> restore -> HTTP. Full mode uses real CommonMark."""
import contextlib, importlib.util, json, os, re, socket, subprocess, sys, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,upload,check,PASSWORD=w.Client,w.post,w.upload,w.check,w.PASSWORD
FULL='--storage-only' not in sys.argv

@contextlib.contextmanager
def server(data,port,env,log):
    env=dict(env,BOOK_DATA_DIR=str(data))
    proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
    try:
        base=f'http://127.0.0.1:{port}'
        for _ in range(100):
            try:Client(base).request('/health/live',200);break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('PHP server did not start')
        yield base
    finally:proc.terminate();proc.wait(timeout=5)

def main():
    with tempfile.TemporaryDirectory(prefix='book-installation-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';data.mkdir();restored=tmp/'restored';backup=tmp/'backup'
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        # Explicitly initialize a content store; no demo documents in this fixture.
        subprocess.run(['php','-r','require "bootstrap.php"; CajeerBook\\BookStore::writeDatabase($argv[1]."/books.json",["schema"=>1,"pages"=>[]]);',str(data)],cwd=ROOT,check=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        with (tmp/'http.log').open('w+') as log:
            try:
                with server(data,port,env,log) as base:
                    owner,writer,outside,guest=[Client(base) for _ in range(4)];owner.login()
                    for name,c in [('writer',writer),('outside',outside)]:post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
                    org=post(owner,'/organizations','create',name='Team A',slug='team-a')[0]['Location'];oid=org.split('/')[-1]
                    other=post(outside,'/organizations','create',name='Team B',slug='team-b')[0]['Location'];bid=other.split('/')[-1]
                    def state():return json.loads((data/'identity/identity.json').read_text())
                    def orev():return str(state()['organizations'][oid]['revision'])
                    uid=next(u['id'] for u in state()['users'].values() if u['username']=='writer')
                    admin=next(u['id'] for u in state()['users'].values() if u['username']=='admin')
                    _,html=post(owner,org,'invite',200,revision=orev(),username='writer');token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(writer,'/organizations/join','accept',token=token)
                    post(owner,org,'role',revision=orev(),user=uid,role='editor')
                    post(owner,'/workspaces','create',organization=oid,name='Private A',slug='same');post(outside,'/workspaces','create',organization=bid,name='Private B',slug='same');post(owner,'/workspaces','adopt',organization=oid,name='Legacy',slug='legacy')
                    ws={x['name']:x for x in state()['workspaces'].values()};a,b,l=ws['Private A'],ws['Private B'],ws['Legacy'];pa='/w/'+oid+'/'+a['id'];pb='/w/'+bid+'/'+b['id'];studio=pa+'/studio';reviews=studio+'/reviews'
                    post(owner,'/workspaces','visibility',id=l['id'],revision='1',visibility='private');guest.request('/api/v1/pages',401)
                    for c,p in [(owner,pa),(outside,pb)]:post(c,p+'/studio/books','create',slug='guide',title='Guide')
                    mid=upload(writer,pa+'/studio/media',b'Private A attachment');othermid=upload(outside,pb+'/studio/media',b'Private B attachment')
                    owner.request(pb+'/studio/export',404);outside.request(pa+'/studio/export',404);writer.request(pa+'/studio/export',403);outside.request(pa+'/studio/media/file/'+mid,404);owner.request(pa+'/studio/media/file/'+othermid,404);guest.request(pa+'/media/'+mid,401)
                    check('independent organizations, scoped export and media authorization before backup')
                    if FULL:
                        fields=dict(book='guide',version='1.0',path='start',title='Private A',section='Guide',order='1',body='# Initial\n\nPrivate A document',revision='0')
                        post(owner,studio,'publish',**fields);post(owner,reviews,'policy',revision='1',required='1')
                        post(writer,studio,'save',**(fields|{'body':'# Revised\n\nPrivate A approved text\n\n[Attachment](/media/'+mid+')','revision':'1'}))
                        post(owner,studio,'publish',409,**(fields|{'revision':'2'}))
                        h,_=post(writer,reviews,'submit',kind='page',target='guide/1.0/start',expected='2',reviewer=admin,note='Review exact draft');rid=h['Location'].split('=')[-1];post(owner,reviews,'approve',id=rid,revision='1');writer.request(pa+'/media/'+mid,404)
                        check('actual Markdown saved, mandatory review enforced, approved draft retained')
                    post(outside,'/workspaces','archive',id=b['id'],revision='1');outside.request(pb+'/api/v1/pages',404)
                    # Operator-only backup; no HTTP route exposes the full installation.
                    owner.request('/installation/backup',404);owner.request('/identity/identity.json',404)
                    subprocess.run(['php','bin/installation.php','backup',str(backup)],cwd=ROOT,env=env,check=True)
                subprocess.run(['php','bin/installation.php','restore',str(backup)],cwd=ROOT,env=dict(env,BOOK_DATA_DIR=str(restored)),check=True)
                with server(restored,port,env,log):
                    owner.request('/account',401);writer.request('/account',401);outside.request('/account',401)
                    owner.login();writer.login('writer','password-writer-123');outside.login('outside','password-outside-123')
                    guest.request('/api/v1/pages',401);guest.request(pa+'/api/v1/pages',401);outside.request(pa+'/api/v1/pages',404);writer.request(pa+'/studio/export',403);owner.request(pb+'/studio/export',404);outside.request(pb+'/api/v1/pages',404)
                    _,body=writer.request(pa+'/studio/media/file/'+mid,200);assert body==b'Private A attachment';owner.request(pa+'/studio/media/file/'+othermid,404)
                    check('fresh server restored identity, archived space, private legacy, roles and exact media bytes')
                    if FULL:
                        _,body=writer.request(pa+'/api/v1/pages/guide/1.0/start',200);assert b'Initial' in body and b'approved text' not in body
                        _,body=owner.request(reviews+'?id='+rid,200);assert b'Approved' in body or b'approved' in body
                        post(owner,reviews,'publish',id=rid,revision='2');_,body=writer.request(pa+'/api/v1/pages/guide/1.0/start',200);assert b'Private A approved text' in body
                        writer.request(pa+'/docs/guide/1.0/start',200);_,body=writer.request(pa+'/media/'+mid,200);assert body==b'Private A attachment';outside.request(pa+'/media/'+mid,404)
                        check('approved snapshot publishes after restore; Reader and media honor original privacy')
                    subprocess.run(['php','bin/installation.php','check'],cwd=ROOT,env=dict(env,BOOK_DATA_DIR=str(restored)),check=True)
                    check('full installation CLI audit after HTTP restart')
            except Exception:log.seek(0);print(log.read());raise

if __name__=='__main__':main()
