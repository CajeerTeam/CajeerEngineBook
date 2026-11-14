#!/usr/bin/env python3
"""Real team forms and per-session inbox; bridge mode is for restricted browser navigation."""
import importlib.util,json,os,re,socket,subprocess,tempfile,time
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,PASSWORD=w.Client,w.post,w.PASSWORD

def main():
    bridge=os.getenv('BOOK_TEST_BROWSER_TRANSPORT')=='1'
    with tempfile.TemporaryDirectory(prefix='book-team-browser-') as tmp:
        data=Path(tmp);env=dict(os.environ,BOOK_DATA_DIR=tmp,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.1)
            owner,writer=Client(base),Client(base);owner.login();post(owner,'/account/users','create',username='writer',name='Writer',email='writer@example.org',password='writer-browser-password-123');writer.login('writer','writer-browser-password-123')
            org=post(owner,'/organizations','create',name='Team Browser',slug='team-browser')[0]['Location'];oid=org.split('/')[-1]
            def state():return json.loads((data/'identity/identity.json').read_text())
            def rev():return str(state()['organizations'][oid]['revision'])
            uid=next(u['id'] for u in state()['users'].values() if u['username']=='writer');aid=next(u['id'] for u in state()['users'].values() if u['username']=='admin')
            _,html=post(owner,org,'invite',200,revision=rev(),username='writer');token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode()
            with sync_playwright() as p:
                opts={'headless':True};exe=os.getenv('BOOK_CHROMIUM');
                if exe:opts['executable_path']=exe
                browser=p.chromium.launch(**opts);a,b=[browser.new_page() for _ in range(2)];errors=[]
                for page,client in [(a,owner),(b,writer)]:
                    page.set_default_timeout(5000);page.on('pageerror',lambda e:errors.append(str(e)));page.context.add_cookies([{'name':c.name,'value':c.value,'url':base,'httpOnly':True,'secure':False,'sameSite':'Strict'} for c in client.jar])
                def load(page,path,status=200):
                    if not bridge:
                        r=page.goto(base+path);assert r.status==status,(r.status,path);return
                    r=page.context.request.get(base+path,max_redirects=0);assert r.status==status,(r.status,path);page.set_content(re.sub(r'<script[^>]*>.*?</script>|<link[^>]*>','',r.text(),flags=re.S));page.add_style_tag(path=str(ROOT/'public/assets/book.css'));page.evaluate('''() => {window.formData=null;document.addEventListener('submit',e=>{e.preventDefault();window.formData={path:e.target.getAttribute('action'),method:e.target.method,data:Object.fromEntries(new FormData(e.target,e.submitter))};});}''')
                def click(page,form,button):
                    if not bridge:
                        with page.expect_navigation(wait_until='domcontentloaded') as nav:form.get_by_role('button',name=button,exact=True).click()
                        assert nav.value.status==200;return
                    form.get_by_role('button',name=button,exact=True).click();payload=page.evaluate('window.formData');assert payload
                    if payload['method']=='get':
                        from urllib.parse import urlencode
                        load(page,payload['path']+'?'+urlencode(payload['data']));return
                    r=page.context.request.post(base+payload['path'],form=payload['data'],max_redirects=0);assert r.status==303,(r.status,r.text());load(page,r.headers['location'])
                load(b,'/team');expect(b.get_by_text('Organization invitation',exact=True)).to_be_visible();expect(b.get_by_text('1 unread',exact=True)).to_be_visible();click(b,b.locator('form').filter(has=b.get_by_role('button',name='Mark read',exact=True)),'Mark read');expect(b.get_by_text('0 unread',exact=True)).to_be_visible();print('PASS browser personal invitation read receipt',flush=True)
                post(writer,'/organizations/join','accept',token=token);post(owner,org,'role',revision=rev(),user=uid,role='editor');post(owner,'/workspaces','create',organization=oid,name='Team Space',slug='team-space');wid=next(iter(state()['workspaces']));prefix='/w/'+oid+'/'+wid;folder=data/'workspaces'/oid/wid;team=prefix+'/studio/team';review=prefix+'/studio/reviews'
                post(writer,prefix+'/studio/books','create',slug='guide',title='Guide')
                if not bridge:post(writer,prefix+'/studio','save',book='guide',version='1.0',path='start',title='Team draft',section='Guide',order='10',body='Draft body',revision='0')
                else:
                    code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->setHistoryActor(["id"=>$argv[2],"username"=>"writer"]);$s->change(function(&$d){$p=["title"=>"Team draft","section"=>"Guide","order"=>10,"body"=>"Draft body","updated"=>gmdate("c"),"media"=>[]];$d["pages"]["guide/1.0/start"]=["id"=>bin2hex(random_bytes(16)),"draft"=>$p,"published"=>null,"revision"=>1];},"save");';subprocess.run(['php','-r',code,str(folder),uid],cwd=ROOT,check=True)
                load(b,'/team');expect(b.get_by_role('link',name='Team dashboard',exact=True)).to_have_attribute('href',team);load(b,team);expect(b.get_by_role('link',name='Team draft',exact=True)).to_be_visible();form=b.locator('form').filter(has=b.locator('select[name=status]'));form.locator('input[name=q]').fill('notfound');click(b,form,'Filter');expect(b.get_by_text('No matching documents.',exact=True)).to_be_visible();print('PASS browser workspace and document filtering',flush=True)
                h,_=post(writer,review,'submit',kind='page',target='guide/1.0/start',expected='1',reviewer=aid);rid=h['Location'].split('=')[-1];load(a,team);expect(a.get_by_text('1 unread',exact=True)).to_be_visible();click(a,a.locator('form').filter(has=a.get_by_role('button',name='Mark all read',exact=True)),'Mark all read');expect(a.get_by_text('0 unread',exact=True)).to_be_visible();expect(a.get_by_role('link',name='guide/1.0/start',exact=True)).to_be_visible();post(owner,review,'changes',id=rid,revision='1',note='Please revise');load(b,team);expect(b.get_by_text('1 unread',exact=True)).to_be_visible();expect(b.get_by_role('link',name='Open review',exact=True).first).to_be_visible();print('PASS browser independent reviewer and author inboxes',flush=True)
                post(owner,org,'role',revision=rev(),user=uid,role='viewer');load(b,team,403);load(b,'/team');assert b.get_by_role('link',name='Team dashboard',exact=True).count()==0;print('PASS browser role downgrade removes dashboard access',flush=True)
                assert not errors,errors;browser.close()
        finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
