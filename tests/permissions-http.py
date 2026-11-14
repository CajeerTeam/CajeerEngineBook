#!/usr/bin/env python3
"""Real HTTP RBAC. Default includes CommonMark save/publish; --storage-only uses a stored fixture."""
import json, os, re, socket, subprocess, tempfile, time, sys
from pathlib import Path
from importlib.util import spec_from_file_location, module_from_spec
ROOT=Path(__file__).resolve().parents[1]
spec=spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=module_from_spec(spec);spec.loader.exec_module(w)
Client,check,post,upload,PASSWORD=w.Client,w.check,w.post,w.upload,w.PASSWORD
FULL='--storage-only' not in sys.argv

def exercise(base,data,env):
    owner,admin,editor,viewer,outsider,guest=[Client(base) for _ in range(6)]
    owner.login(); clients={'manager':admin,'writer':editor,'reader':viewer,'outsider':outsider}
    for name,c in clients.items():
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='RBAC team',slug='rbac-team')[0]['Location'];oid=org.split('/')[-1]
    def state():return json.loads((data/'identity/identity.json').read_text())
    def orev():return str(state()['organizations'][oid]['revision'])
    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
    def invite(c,name):
        _,html=post(c,org,'invite',200,revision=orev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(clients[name],'/organizations/join','accept',token=token)
    for name in ['manager','writer','reader']:invite(owner,name)
    def role(c,name,r,status=303,rev=None):return post(c,org,'role',status,revision=rev or orev(),user=uid(name),role=r)
    role(owner,'manager','admin');role(admin,'writer','editor')
    _,html=owner.request(org,200);assert b'name="role"' in html and b'Change role' in html
    _,html=admin.request(org,200);assert b'Change role' in html and b'Transfer ownership' not in html and b'<option value="admin"' not in html
    _,html=editor.request(org,200);assert b'Change role' not in html
    role(admin,'writer','admin',403);role(editor,'writer','admin',403);role(admin,'manager','viewer',403);role(owner,'writer','owner',403);role(owner,'writer','viewer',409,rev='1')
    post(admin,org,'remove',403,revision=orev(),user=uid('admin'));post(admin,org,'transfer',403,revision=orev(),user=uid('manager'));post(admin,org,'rename',403,revision=orev(),name='Attack',slug='attack')
    post(outsider,org,'role',404,revision=orev(),user=uid('writer'),role='admin')
    owner.request(org,403,{'action':'role','user':uid('writer'),'role':'viewer','revision':orev()})
    check('role UI, server escalation guards, CSRF and stale revisions')
    for name in ['Alpha','Beta']:post(admin,'/workspaces','create',organization=oid,name=name,slug=name.lower()+'-space')
    spaces={w['name']:w for w in state()['workspaces'].values()};a,b=spaces['Alpha'],spaces['Beta'];aid,bid=a['id'],b['id'];pa='/w/'+oid+'/'+aid;pb='/w/'+oid+'/'+bid
    def wrev(id):return str(state()['workspaces'][id]['revision'])
    def scoped(c,id,name,r,status=303,rev=None):return post(c,'/workspaces','role',status,id=id,revision=rev or wrev(id),user=uid(name),role=r)
    def dbpath(id):return data/'workspaces'/oid/id/'books.json'
    def db(id=aid):return json.loads(dbpath(id).read_text())
    for p in [pa,pb]:post(editor,p+'/studio/books','create',slug='guide',title='Guide')
    _,html=editor.request(pa+'/studio',200);assert b'data-can-publish="0"' in html and b'value="publish"' not in html and b'Download JSON backup' not in html
    _,html=admin.request(pa+'/studio',200);assert b'data-can-publish="1"' in html and b'value="publish"' in html
    for route in ['/studio','/studio/books','/studio/tree','/studio/media','/studio/media/picker','/studio/export','/studio/media/backup','/studio/media/file/'+'a'*32]:viewer.request(pa+route,403)
    viewer.request(pa+'/api/v1/pages',200);guest.request(pa+'/api/v1/pages',401);outsider.request(pa+'/api/v1/pages',404)
    # Every mutating handler is checked, even with valid CSRF and arbitrary inputs.
    for route,actions in {'/studio':['save','copy','publish','unpublish','delete'],'/studio/tree':['move','duplicate','publish'],'/studio/media':['upload','update','delete'],'/studio/books':['create'],'/studio/editor/render':['']}.items():
        for action in actions:post(viewer,pa+route,action,403)
    for route,action in [('/studio','publish'),('/studio','unpublish'),('/studio','delete'),('/studio/tree','publish'),('/studio/media','delete')]:post(editor,pa+route,action,403)
    editor.request(pa+'/studio/export',403);editor.request(pa+'/studio/media/backup',403);post(editor,pa+'/studio','unknown',403)
    check('all Studio read/write paths enforce Viewer and Editor boundaries')
    # A real media write and metadata update as Editor; private media remains inaccessible to Viewer.
    mid=upload(editor,pa+'/studio/media');editor.request(pa+'/studio/media/file/'+mid,200);viewer.request(pa+'/studio/media/file/'+mid,403);viewer.request(pa+'/media/'+mid,404)
    post(editor,pa+'/studio/media','update',id=mid,revision='1',name='updated.txt',folder='docs');assert db()['media'][mid]['name']=='updated.txt'
    _,html=editor.request(pa+'/studio/media',200);assert b'value="delete"' not in html and b'Download content backup' not in html
    post(editor,pa+'/studio/media','delete',403,id=mid,revision='2');assert mid in db()['media']
    check('editor upload/update, private files and destructive actions')
    fields=dict(book='guide',version='1.0',path='start',title='Secret draft',section='Guide',order='10',body='# Draft\n\nPrivate body',revision='0')
    if FULL:
        post(editor,pa+'/studio','save',**fields);assert db()['pages']['guide/1.0/start']['published'] is None
        viewer.request(pa+'/api/v1/pages/guide/1.0/start',404)
        post(admin,pa+'/studio','publish',**(fields|{'revision':'1'}));assert db()['pages']['guide/1.0/start']['published']['body']==fields['body']
        viewer.request(pa+'/docs/guide/1.0/start',200)
    else:
        code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d){$p=["title"=>"Stored fixture","section"=>"Guide","order"=>10,"body"=>"# Fixture","updated"=>gmdate("c"),"media"=>[]];$d["pages"]["guide/1.0/start"]=["id"=>bin2hex(random_bytes(16)),"revision"=>1,"draft"=>$p,"published"=>$p,"published_key"=>"guide/1.0/start"];});'
        subprocess.run(['php','-r',code,str(dbpath(aid).parent)],cwd=ROOT,check=True)
    viewer.request(pa+'/api/v1/pages/guide/1.0/start',200)
    _,tree=editor.request(pa+'/studio/tree?scope=guide%2F1.0',200);assert b'Publish tree</button>' not in tree
    token=re.search(rb'name="token" value="([a-f0-9]+)"',tree).group(1).decode();post(editor,pa+'/studio/tree','move',scope='guide/1.0',token=token,source='guide/1.0/start',target='',position='root',slug='moved')
    viewer.request(pa+'/api/v1/pages/guide/1.0/start',200);viewer.request(pa+'/api/v1/pages/guide/1.0/moved',404)
    _,tree=admin.request(pa+'/studio/tree?scope=guide%2F1.0',200);token=re.search(rb'name="token" value="([a-f0-9]+)"',tree).group(1).decode();post(admin,pa+'/studio/tree','publish',scope='guide/1.0',token=token)
    h,_=viewer.request(pa+'/api/v1/pages/guide/1.0/start',308);assert h['Location']==pa+'/api/v1/pages/guide/1.0/moved'
    admin.request(pa+'/studio/export',200);admin.request(pa+'/studio/media/backup',200);post(admin,pa+'/studio/media','delete',id=mid,revision='2');assert mid not in db()['media']
    check('editor tree move, admin publication, backup and deletion'+(' with actual Markdown draft/publish' if FULL else ' (stored page fixture)'))
    # Restriction on one workspace leaves sibling unaffected, includes Admin and legacy.
    _,html=owner.request('/workspaces',200);assert b'Workspace access' in html and b'Save access' in html
    scoped(owner,aid,'writer','viewer');editor.request(pa+'/studio',403);editor.request(pb+'/studio',200);editor.request(pa+'/api/v1/pages',200)
    scoped(admin,aid,'writer','none');editor.request(pa+'/api/v1/pages',404);editor.request(pa+'/studio',404);editor.request(pb+'/studio',200)
    _,html=editor.request('/workspaces',200);assert b'Alpha' not in html and b'Beta' in html
    scoped(owner,aid,'manager','none');admin.request(pa+'/studio',404);admin.request(pb+'/studio',200);scoped(admin,aid,'writer','inherit',403)
    scoped(admin,bid,'manager','admin',403);scoped(admin,bid,'reader','editor',403);scoped(owner,aid,'writer','inherit',409,rev='1')
    role(owner,'writer','viewer');editor.request(pa+'/api/v1/pages',404);role(owner,'writer','editor');editor.request(pa+'/api/v1/pages',404)
    scoped(owner,aid,'writer','inherit');editor.request(pa+'/studio',200)
    check('workspace restrictions, no self-grant, immediate downgrade and retained denials')
    post(owner,'/workspaces','adopt',organization=oid,name='Legacy',slug='legacy');legacy=next(w for w in state()['workspaces'].values() if w['legacy'])
    admin.request('/studio',200);editor.request('/studio',200);viewer.request('/studio',403);guest.request('/api/v1/pages',200)
    scoped(owner,legacy['id'],'manager','none');admin.request('/studio',403);admin.request(pb+'/studio',200)
    scoped(owner,legacy['id'],'writer','viewer');editor.request('/studio',403)
    post(owner,org,'remove',revision=orev(),user=uid('writer'));editor.request(pb+'/studio',404);editor.request('/studio',403)
    check('legacy Studio RBAC and removed member immediately denied')
    # Non-installation Owner of a different organization is not the installation owner.
    other=post(outsider,'/organizations','create',name='Other',slug='other-team')[0]['Location'].split('/')[-1]
    post(outsider,'/workspaces','create',organization=other,name='Other private',slug='other-space');otherw=next(w for w in state()['workspaces'].values() if w['organization']==other)
    owner.request('/w/'+other+'/'+otherw['id']+'/studio',404);post(owner,'/workspaces','role',403,id=otherw['id'],revision='1',user=uid('outsider'),role='none')
    check('installation Owner cannot bypass another organization')

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-rbac-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data,env)
            except Exception:log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)
