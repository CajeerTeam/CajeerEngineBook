#!/usr/bin/env python3
"""Multi-user history E2E. --storage-only skips parser-dependent save/restore/preview."""
import importlib.util, json, os, re, socket, subprocess, sys, tempfile, time, tarfile, io
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,upload,PASSWORD=w.Client,w.post,w.check,w.upload,w.PASSWORD
FULL='--storage-only' not in sys.argv

def exercise(base,data):
    owner,editor,viewer,outsider,guest=[Client(base) for _ in range(5)];owner.login()
    for name,c in [('writer',editor),('reader',viewer),('outsider',outsider)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='History Team',slug='history-team')[0]['Location'].split('/')[-1]
    def identity():return json.loads((data/'identity/identity.json').read_text())
    def orev():return str(identity()['organizations'][org]['revision'])
    def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
    for name,c in [('writer',editor),('reader',viewer)]:
        _,html=post(owner,'/organizations/'+org,'invite',200,revision=orev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
    post(owner,'/organizations/'+org,'role',revision=orev(),user=uid('writer'),role='editor')
    post(owner,'/workspaces','create',organization=org,name='History',slug='history')
    ws=next(w for w in identity()['workspaces'].values() if not w['legacy']);wid=ws['id'];prefix='/w/'+org+'/'+wid;folder=data/'workspaces'/org/wid
    def db():return json.loads((folder/'books.json').read_text())
    history=prefix+'/studio/history';studio=prefix+'/studio';key='guide/1.0/start';moved='guide/1.0/moved'
    post(owner,prefix+'/studio/books','create',slug='guide',title='Guide')
    mid=upload(owner,prefix+'/studio/media',b'historical file');body='Original secret\n\n[Download](/media/'+mid+')'
    fields=dict(book='guide',version='1.0',path='start',title='Original',section='Guide',order='10',body=body,revision='0')
    if FULL:
        post(owner,studio,'publish',**fields);post(editor,studio,'save',**(fields|{'title':'Changed','body':'New draft <script>alert(1)</script>','revision':'1'}))
    else:
        # Seed only parser-dependent content. All subsequent HTTP operations use production code.
        code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->change(function(&$d)use($argv){$p=["title"=>"Original","section"=>"Guide","order"=>10,"body"=>$argv[2],"updated"=>gmdate("c"),"media"=>[$argv[3]]];$d["pages"]["guide/1.0/start"]=["id"=>bin2hex(random_bytes(16)),"revision"=>1,"draft"=>$p,"published"=>$p];});'
        subprocess.run(['php','-r',code,str(folder),body,mid],cwd=ROOT,check=True)
    page=db()['pages'][key]['id'];owner.request(history,200);editor.request(history+'?page='+page,200);viewer.request(history,403);outsider.request(history,404);guest.request(history,401)
    assert 'history' not in json.loads(owner.request(prefix+'/api/v1/pages',200)[1]);check('history restricted to authoring roles; not exposed by Reader API')
    token=re.search(rb'name="token" value="([a-f0-9]+)"',editor.request(prefix+'/studio/tree?scope=guide%2F1.0',200)[1]).group(1).decode()
    post(editor,prefix+'/studio/tree','move',scope='guide/1.0',source=key,target='',position='root',slug='moved',token=token)
    d=db();events=[r for r in d['history'].values() if r['page']==page];assert events[-1]['action']=='move' and events[-1]['actor']['id']==uid('writer') and events[-1]['key']==moved;assert d['pages'][moved]['id']==page
    original=events[0]['id'];head=events[-1]['id'];_,html=editor.request(history+'?page='+page+'&id='+original,200);assert b'Original secret' in html and b'Restore as draft' in html and b'writer' in html and b'Historical revision' in html
    assert b'name="head" value="'+head.encode()+b'"' in html
    if FULL:assert b'&lt;script&gt;' in html and b'<script>alert(1)</script>' not in html
    check('real history UI, actor, stable ID and source comparison after move')
    restore=dict(page=page,id=original,state='draft',head=head,revision=str(d['pages'][moved]['revision']))
    post(viewer,history,'restore',403,**restore);post(outsider,history,'restore',404,**restore)
    editor.request(history,403,{'action':'restore'}|restore);post(editor,history,'restore',409,**(restore|{'revision':'1'}));post(editor,history,'restore',409,**(restore|{'head':original}));check('restore rejects Viewer, outsider, invalid CSRF and stale revision/head')
    if FULL:
        _,html=editor.request(history+'?page='+page+'&id='+original+'&preview=1',200);assert (prefix+'/studio/media/file/'+mid).encode() in html
        pub=d['pages'][moved]['published'];post(editor,history,'restore',**restore);d=db();assert d['pages'][moved]['draft']['body']==body and d['pages'][moved]['published']==pub
        assert list(d['history'].values())[-1]['action']=='restore';check('historical preview and real restore preserve public snapshot')
    d=db();post(owner,studio,'unpublish',key=moved,revision=str(d['pages'][moved]['revision']));owner.request(prefix+'/media/'+mid,404);post(owner,prefix+'/studio/media','delete',409,id=mid,revision='1');check('historical media retained, not publicly accessible')
    d=db();post(owner,studio,'delete',key=moved,revision=str(d['pages'][moved]['revision']));d=db();assert moved not in d['pages'];events=[r for r in d['history'].values() if r['page']==page];assert events[-1]['deleted'];head=events[-1]['id'];_,html=editor.request(history+'?page='+page,200);assert b'Deleted page' in html
    if FULL:
        post(editor,history,'restore',page=page,id=original,state='draft',head=head,revision='0');d=db();assert d['pages'][moved]['id']==page and d['pages'][moved]['published'] is None and d['pages'][moved]['draft']['body']==body
    check('deleted page history remains accessible'+('; restored as new draft' if FULL else ''))
    _,raw=owner.request(prefix+'/studio/media/backup',200)
    with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
        saved=json.load(tar.extractfile('books.json'));assert saved['history']==db()['history'] and 'media/'+mid+'.blob' in tar.getnames()
    check('scoped TAR includes history and retained attachment')
    post(owner,'/organizations/'+org,'role',revision=orev(),user=uid('writer'),role='viewer');editor.request(history+'?page='+page,403);check('downgraded editor immediately loses history access')

def main():
    with tempfile.TemporaryDirectory(prefix='book-history-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data)
            except Exception:log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
