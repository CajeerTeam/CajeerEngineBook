#!/usr/bin/env python3
"""HTTP -> operator backup -> restore -> HTTP. Full mode uses real CommonMark."""
import contextlib, importlib.util, json, os, re, shutil, socket, subprocess, sys, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,upload,check,PASSWORD=w.Client,w.post,w.upload,w.check,w.PASSWORD
FULL='--storage-only' not in sys.argv
BROWSER='--browser' in sys.argv
if BROWSER and not FULL:raise SystemExit('--browser requires the full dependency-backed scenario')

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

def browser_save(base,prefix,body):
    from playwright.sync_api import sync_playwright,expect
    from auth_helpers import login
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True);ctx=browser.new_context();login(ctx.request,base,'password-writer-123','writer');page=ctx.new_page()
        try:
            response=page.goto(base+prefix+'/studio?edit=guide%2F1.0%2Fstart');assert response.status==200
            expect(page.locator('[data-visual-panel]')).to_be_visible();page.locator('[data-mode=markdown]').click();source=page.locator('[data-editor-form] textarea[name=body]');source.fill(body)
            page.locator('[data-mode=visual]').click();expect(page.locator('[data-visual-panel]')).to_be_visible();page.locator('[data-mode=markdown]').click();expect(source).to_have_value(body)
            assert page.locator('[data-editor-form] button[value=publish]').count()==0
            # Autosave can finish while visual mode is loading. Wait for the exact revision, never save twice.
            expect(page.locator('[data-editor-form] [name=revision]')).to_have_value('2',timeout=15000)
            check('Chromium scoped Advanced Editor round trip and real autosave under Editor role')
        finally:browser.close()

def browser_reader(base,prefix):
    from playwright.sync_api import sync_playwright,expect
    from auth_helpers import login
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True);ctx=browser.new_context();login(ctx.request,base,'password-viewer-123','viewer');page=ctx.new_page()
        try:
            response=page.goto(base+prefix+'/docs/guide/1.0/start');assert response.status==200
            expect(page.locator('article table')).to_be_visible();expect(page.get_by_text('Stable tip',exact=True)).to_be_visible();d=page.locator('.book-diagram');expect(d.locator('.book-diagram-status')).to_have_text('Diagram ready.',timeout=25000);expect(d.frame_locator('iframe').locator('svg')).to_have_count(1)
            assert page.evaluate("document.querySelector('iframe').contentDocument === null")
            guest=browser.new_page();assert guest.goto(base+prefix+'/docs/guide/1.0/start').status==401
            check('Chromium authenticated private Reader renders approved GFM, callout and real Mermaid; guest denied')
        finally:browser.close()

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
                    owner,writer,manager,viewer,outside,guest=[Client(base) for _ in range(6)];owner.login()
                    for name,c in [('writer',writer),('manager',manager),('viewer',viewer),('outside',outside)]:post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
                    org=post(owner,'/organizations','create',name='Team A',slug='team-a')[0]['Location'];oid=org.split('/')[-1]
                    other=post(outside,'/organizations','create',name='Team B',slug='team-b')[0]['Location'];bid=other.split('/')[-1]
                    def state():return json.loads((data/'identity/identity.json').read_text())
                    def orev():return str(state()['organizations'][oid]['revision'])
                    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
                    for name,c,role in [('writer',writer,'editor'),('manager',manager,'admin'),('viewer',viewer,'viewer')]:
                        _,html=post(owner,org,'invite',200,revision=orev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
                        if role!='viewer':post(owner,org,'role',revision=orev(),user=uid(name),role=role)
                    post(owner,'/workspaces','create',organization=oid,name='Private A',slug='same');post(outside,'/workspaces','create',organization=bid,name='Private B',slug='same');post(owner,'/workspaces','adopt',organization=oid,name='Legacy',slug='legacy')
                    ws={x['name']:x for x in state()['workspaces'].values()};a,b,l=ws['Private A'],ws['Private B'],ws['Legacy'];pa='/w/'+oid+'/'+a['id'];pb='/w/'+bid+'/'+b['id'];studio=pa+'/studio';reviews=studio+'/reviews'
                    post(owner,'/workspaces','visibility',id=l['id'],revision='1',visibility='private');guest.request('/api/v1/pages',401)
                    for c,p in [(owner,pa),(outside,pb)]:post(c,p+'/studio/books','create',slug='guide',title='Guide')
                    mid=upload(writer,pa+'/studio/media',b'Private A attachment');othermid=upload(outside,pb+'/studio/media',b'Private B attachment')
                    owner.request(pb+'/studio/export',404);outside.request(pa+'/studio/export',404);writer.request(pa+'/studio/export',403);outside.request(pa+'/studio/media/file/'+mid,404);owner.request(pa+'/studio/media/file/'+othermid,404);guest.request(pa+'/media/'+mid,401)
                    viewer.request(studio,403);viewer.request(pa+'/studio/team',403);manager.request(pa+'/studio/team',200);writer.request(pa+'/studio/team',200)
                    check('independent organizations, all four roles, scoped export and media authorization before backup')
                    if FULL:
                        fields=dict(book='guide',version='1.0',path='start',title='Private A',section='Guide',order='1',body='# Initial\n\nPrivate A document',revision='0')
                        post(owner,studio,'publish',**fields);post(owner,reviews,'policy',revision='1',required='1')
                        body='# Revised\n\nPrivate A approved text\n\n[Attachment](/media/'+mid+'?download=1)\n\n| A | B |\n| --- | --- |\n| one | two |\n\n~~~book-callout\n'+json.dumps({'v':1,'type':'tip','title':'Stable tip','body':'**Ready**'})+'\n~~~\n\n```mermaid\nflowchart LR\nA[Start] --> B[Finish]\n```\n\n<script>alert(1)</script>\n'
                        if BROWSER: browser_save(base,pa,body)
                        else:post(writer,studio,'save',**(fields|{'body':body,'revision':'1'}))
                        post(owner,studio,'publish',409,**(fields|{'revision':'2'}))
                        h,_=post(writer,reviews,'submit',kind='page',target='guide/1.0/start',expected='2',reviewer=uid('manager'),note='Review exact draft');rid=h['Location'].split('=')[-1]
                        assert b'Review assigned' in manager.request(pa+'/studio/team',200)[1];post(manager,reviews,'changes',id=rid,revision='1',note='Add the release note');assert b'Changes requested' in writer.request(pa+'/studio/team',200)[1]
                        approved_body=body+'\nRelease note added.\n';post(writer,studio,'save',**(fields|{'body':approved_body,'revision':'2'}));h,_=post(writer,reviews,'submit',kind='page',target='guide/1.0/start',expected='3',reviewer=uid('manager'));rid=h['Location'].split('=')[-1];post(manager,reviews,'approve',id=rid,revision='1');writer.request(pa+'/media/'+mid,404);viewer.request(pa+'/studio/history',403)
                        check('actual Markdown, independent Admin review, changes requested, notifications and approved draft retained')
                    post(outside,'/workspaces','archive',id=b['id'],revision='1');outside.request(pb+'/api/v1/pages',404)
                    # Operator-only backup; no HTTP route exposes the full installation.
                    owner.request('/installation/backup',404);owner.request('/identity/identity.json',404)
                    subprocess.run(['php','bin/installation.php','backup',str(backup)],cwd=ROOT,env=env,check=True)
                    # Simulate catastrophic identity loss while the real server is running.
                    saved=data/'identity.saved';(data/'identity').rename(saved)
                    try:
                        for route in ['/api/v1/pages',pa+'/api/v1/pages',pa+'/media/'+mid,'/studio','/login']:
                            h,raw=guest.request(route,503);assert b'Private A' not in raw and 'no-store' in h['Cache-Control']
                        guest.request('/health/live',200);guest.request('/health/ready',503)
                        check('identity directory loss fails closed across legacy, scoped API, media, Studio and login')
                    finally:
                        if (data/'identity').exists():shutil.rmtree(data/'identity')
                        saved.rename(data/'identity')
                    owner.request('/account',200);guest.request('/api/v1/pages',401)
                subprocess.run(['php','bin/installation.php','restore',str(backup)],cwd=ROOT,env=dict(env,BOOK_DATA_DIR=str(restored)),check=True)
                with server(restored,port,env,log):
                    for c in [owner,writer,manager,viewer,outside]:c.request('/account',401)
                    owner.login()
                    for name,c in [('writer',writer),('manager',manager),('viewer',viewer),('outside',outside)]:c.login(name,'password-'+name+'-123')
                    guest.request('/api/v1/pages',401);guest.request(pa+'/api/v1/pages',401);outside.request(pa+'/api/v1/pages',404);writer.request(pa+'/studio/export',403);owner.request(pb+'/studio/export',404);outside.request(pb+'/api/v1/pages',404)
                    _,body=writer.request(pa+'/studio/media/file/'+mid,200);assert body==b'Private A attachment';owner.request(pa+'/studio/media/file/'+othermid,404)
                    check('fresh server restored identity, archived space, private legacy, roles and exact media bytes')
                    if FULL:
                        _,body=writer.request(pa+'/api/v1/pages/guide/1.0/start',200);assert b'Initial' in body and b'approved text' not in body
                        _,body=owner.request(reviews+'?id='+rid,200);assert b'Approved' in body or b'approved' in body
                        post(owner,reviews,'publish',id=rid,revision='2');_,raw=viewer.request(pa+'/api/v1/pages/guide/1.0/start',200);assert json.loads(raw)['body']==approved_body
                        h,html=viewer.request(pa+'/docs/guide/1.0/start',200);assert b'data-book-mermaid' in html and b'book-callout' in html and b'<script>alert' not in html and b'<table' in html and 'no-store' in h['Cache-Control']
                        _,raw=viewer.request(pa+'/media/'+mid,200);assert raw==b'Private A attachment';outside.request(pa+'/media/'+mid,404);viewer.request(pa+'/studio/history',403)
                        assert b'Approved revision published' in writer.request(pa+'/studio/team',200)[1]
                        if BROWSER:browser_reader(base,pa)
                        # Restore an actual historical revision into a draft; publication stays exact.
                        folder=restored/'workspaces'/oid/a['id'];db=json.loads((folder/'books.json').read_text());entry=db['pages']['guide/1.0/start'];hist=[r for r in db['history'].values() if r['page']==entry['id']];initial=next(r for r in hist if r['draft']['body']==fields['body']);head=hist[-1]['id']
                        post(writer,pa+'/studio/history','restore',page=entry['id'],id=initial['id'],state='draft',head=head,revision=str(entry['revision']),note='Restore old draft');_,raw=viewer.request(pa+'/api/v1/pages/guide/1.0/start',200);assert json.loads(raw)['body']==approved_body
                        assert json.loads((folder/'books.json').read_text())['pages']['guide/1.0/start']['draft']['body']==fields['body']
                        check('approved snapshot publishes after restore; Reader, rich blocks, media, history and notifications retain privacy')
                        post(owner,'/workspaces','role',id=a['id'],revision='1',user=uid('writer'),role='none');writer.request(pa+'/studio/team',404);writer.request(pa+'/api/v1/pages',404);viewer.request(pa+'/api/v1/pages',200)
                        check('revocation after recovery closes Reader, Studio and activity without affecting Viewer')
                    subprocess.run(['php','bin/installation.php','check'],cwd=ROOT,env=dict(env,BOOK_DATA_DIR=str(restored)),check=True)
                    check('full installation CLI audit after HTTP restart')
            except Exception:log.seek(0);print(log.read());raise

if __name__=='__main__':main()
