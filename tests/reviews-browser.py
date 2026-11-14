#!/usr/bin/env python3
"""Browser review forms with independent sessions. Bridge mode supports restricted local browsers."""
import importlib.util,json,os,re,socket,subprocess,tempfile,time
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,PASSWORD=w.Client,w.post,w.PASSWORD

def main():
    bridged=os.getenv('BOOK_TEST_BROWSER_TRANSPORT')=='1'
    with tempfile.TemporaryDirectory(prefix='book-reviews-browser-') as tmp:
        data=Path(tmp);env=dict(os.environ,BOOK_DATA_DIR=tmp,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.1)
            owner,writer=Client(base),Client(base);owner.login()
            post(owner,'/account/users','create',username='writer',name='Writer',email='writer@example.org',password='writer-browser-password-123');writer.login('writer','writer-browser-password-123')
            org=post(owner,'/organizations','create',name='Review Team',slug='review-team')[0]['Location'];oid=org.split('/')[-1]
            def identity():return json.loads((data/'identity/identity.json').read_text())
            def orev():return str(identity()['organizations'][oid]['revision'])
            def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
            _,html=post(owner,org,'invite',200,revision=orev(),username='writer');token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(writer,'/organizations/join','accept',token=token)
            post(owner,org,'role',revision=orev(),user=uid('writer'),role='editor');post(owner,'/workspaces','create',organization=oid,name='Review Space',slug='review-space')
            wid=next(iter(identity()['workspaces']));prefix='/w/'+oid+'/'+wid;folder=data/'workspaces'/oid/wid;reviews=prefix+'/studio/reviews';target='guide/1.0/start';submit_url=reviews+'?kind=page&target=guide%2F1.0%2Fstart'
            post(owner,prefix+'/studio/books','create',slug='guide',title='Guide')
            def seed(body):
                if not bridged:
                    entry=json.loads((folder/'books.json').read_text())['pages'];rev=str(entry.get(target,{}).get('revision',0)) if isinstance(entry,dict) else '0';post(writer,prefix+'/studio','save',book='guide',version='1.0',path='start',title='Review document',section='Guide',order='10',body=body,revision=rev)
                else:
                    code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->setHistoryActor(["id"=>$argv[2],"username"=>"writer"]);$s->change(function(&$d)use($argv){$k="guide/1.0/start";$p=["title"=>"Review document","section"=>"Guide","order"=>10,"body"=>$argv[3],"updated"=>gmdate("c"),"media"=>[]];$e=$d["pages"][$k]??["id"=>bin2hex(random_bytes(16)),"revision"=>0,"published"=>null];$e["draft"]=$p;$e["revision"]++;$d["pages"][$k]=$e;},"save");';subprocess.run(['php','-r',code,str(folder),uid('writer'),body],cwd=ROOT,check=True)
            seed('Initial draft')
            with sync_playwright() as p:
                opts={'headless':True};exe=os.getenv('BOOK_CHROMIUM');
                if exe:opts['executable_path']=exe
                browser=p.chromium.launch(**opts);a,b=[browser.new_page() for _ in range(2)];errors=[]
                for page,client in [(a,owner),(b,writer)]:
                    page.set_default_timeout(5000);page.on('pageerror',lambda e:errors.append(str(e)));page.context.add_cookies([{'name':c.name,'value':c.value,'url':base,'httpOnly':True,'secure':False,'sameSite':'Strict'} for c in client.jar])
                def load(page,path,status=200):
                    if not bridged:
                        r=page.goto(base+path);assert r.status==status,(r.status,path);return
                    r=page.context.request.get(base+path,max_redirects=0);assert r.status==status,(r.status,path);page.set_content(re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S));page.add_style_tag(path=str(ROOT/'public/assets/book.css'));page.evaluate('''() => {window.formData=null; document.addEventListener('submit',e=>{e.preventDefault();window.formData={path:e.target.getAttribute('action'),data:Object.fromEntries(new FormData(e.target,e.submitter))};});}''')
                def action(page,name):return page.locator('form').filter(has=page.locator(f'input[name="action"][value="{name}"]'))
                def click(page,form,button,fields=None,confirm=False):
                    for name,value in (fields or {}).items():
                        field=form.locator(f'[name="{name}"]');field.select_option(value) if field.evaluate('(el)=>el.tagName')=='SELECT' else field.fill(value)
                    if not bridged:
                        if confirm:page.once('dialog',lambda d:d.accept())
                        with page.expect_navigation(wait_until='domcontentloaded') as nav:form.get_by_role('button',name=button,exact=True).click()
                        assert nav.value.status==200;return page.url.removeprefix(base)
                    form.get_by_role('button',name=button,exact=True).click();payload=page.evaluate('window.formData');assert payload
                    r=page.context.request.post(base+payload['path'],form=payload['data'],max_redirects=0);assert r.status==303,(r.status,r.text());path=r.headers['location'];load(page,path);return path
                load(a,reviews);click(a,action(a,'policy'),'Save review policy',{'required':'1'},True);expect(a.get_by_text('Review required',exact=True)).to_be_visible();print('PASS browser owner enables required review',flush=True)
                load(b,submit_url);rid=click(b,action(b,'submit'),'Submit for review',{'reviewer':uid('admin'),'note':'Please review'});expect(b.get_by_text('In review',exact=True)).to_be_visible();assert b.get_by_role('button',name='Approve',exact=True).count()==0
                load(a,rid);decision=a.locator('form').filter(has=a.get_by_role('button',name='Request changes',exact=True));click(a,decision,'Request changes',{'note':'Update command'});expect(a.get_by_text('Changes requested',exact=True)).to_be_visible();load(b,rid);expect(b.get_by_text('Update command',exact=True)).to_be_visible();print('PASS browser submit and request changes across two sessions',flush=True)
                seed('Revised draft');load(b,submit_url);rid=click(b,action(b,'submit'),'Submit for review',{'reviewer':uid('admin')});load(a,rid);decision=a.locator('form').filter(has=a.get_by_role('button',name='Approve',exact=True));click(a,decision,'Approve');expect(a.get_by_text('Approved',exact=True)).to_be_visible();click(a,action(a,'publish'),'Publish approved revision',confirm=True);expect(a.get_by_text('Published',exact=True)).to_be_visible();assert json.loads(owner.request(prefix+'/api/v1/pages/'+target,200)[1])['body']=='Revised draft';print('PASS browser approval and exact publication through PHP',flush=True)
                if not bridged:
                    load(a,prefix+'/docs/'+target);expect(a.get_by_text('Revised draft',exact=True)).to_be_visible()
                assert not errors,errors;browser.close()
        finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
