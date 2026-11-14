#!/usr/bin/env python3
"""Real multi-user review workflow E2E. --storage-only seeds parser-dependent drafts only."""
import importlib.util, io, json, os, re, socket, subprocess, sys, tarfile, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,upload,PASSWORD=w.Client,w.post,w.check,w.upload,w.PASSWORD
FULL='--storage-only' not in sys.argv

def exercise(base,data):
    owner,admin,editor,viewer,outsider,guest=[Client(base) for _ in range(6)];owner.login()
    for name,c in [('manager',admin),('writer',editor),('reader',viewer),('outside',outsider)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Review Team',slug='review-team')[0]['Location'];oid=org.split('/')[-1]
    def identity():return json.loads((data/'identity/identity.json').read_text())
    def orev():return str(identity()['organizations'][oid]['revision'])
    def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
    for name,c in [('manager',admin),('writer',editor),('reader',viewer)]:
        _,html=post(owner,org,'invite',200,revision=orev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
    def role(name,r):post(owner,org,'role',revision=orev(),user=uid(name),role=r)
    role('manager','admin');role('writer','editor')
    for name in ['Review','Other']:post(owner,'/workspaces','create',organization=oid,name=name,slug=name.lower())
    spaces={ws['name']:ws for ws in identity()['workspaces'].values()};wid=spaces['Review']['id'];prefix='/w/'+oid+'/'+wid;other='/w/'+oid+'/'+spaces['Other']['id'];folder=data/'workspaces'/oid/wid
    reviews=prefix+'/studio/reviews';studio=prefix+'/studio';api=prefix+'/api/v1/pages/';key='guide/1.0/start';moved='guide/1.0/moved'
    def db():return json.loads((folder/'books.json').read_text())
    def current(k=key):return str(db()['pages'][k]['revision'])
    def revision(rid):return str(db()['reviews'][rid]['revision'])
    def submit(c=editor,reviewer='manager',kind='page',target=key,expected=None,status=303):
        if expected is None:expected=current(target) if kind=='page' else tree_token()
        h,_=post(c,reviews,'submit',status,kind=kind,target=target,expected=expected,reviewer=uid(reviewer),note='Please review this revision')
        return h['Location'].split('=')[-1] if status==303 else None
    def decide(c,rid,action,status=303,note='',rev=None):return post(c,reviews,action,status,id=rid,revision=rev or revision(rid),note=note)
    def tree_token():return re.search(rb'name="token" value="([a-f0-9]+)"',editor.request(prefix+'/studio/tree?scope=guide%2F1.0',200)[1]).group(1).decode()
    def seed(title,body,mid=None,initial=False):
        if FULL:
            fields=dict(book='guide',version='1.0',path='start',title=title,section='Guide',order='10',body=body,revision='0' if initial else current())
            post(owner if initial else editor,studio,'publish' if initial else 'save',**fields)
        else:
            code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->setHistoryActor(["id"=>$argv[5],"username"=>$argv[6]]);$s->change(function(&$d)use($argv){$k="guide/1.0/start";$p=["title"=>$argv[2],"section"=>"Guide","order"=>10,"body"=>$argv[3],"updated"=>gmdate("c"),"media"=>$argv[4]!==""?[$argv[4]]:[]];if(!isset($d["pages"][$k]))$d["pages"][$k]=["id"=>bin2hex(random_bytes(16)),"revision"=>1,"draft"=>$p,"published"=>$p];else{$d["pages"][$k]["draft"]=$p;$d["pages"][$k]["revision"]++;}},$argv[6]==="admin"?"publish":"save");'
            subprocess.run(['php','-r',code,str(folder),title,body,mid or '',uid('admin' if initial else 'writer'),'admin' if initial else 'writer'],cwd=ROOT,check=True)
    post(owner,prefix+'/studio/books','create',slug='guide',title='Guide');mid=upload(editor,prefix+'/studio/media',b'review attachment')
    seed('Original','Original published body',initial=True);body='New **draft** <script>alert(1)</script>\n\n[Download](/media/'+mid+')';seed('New draft',body,mid)
    for c,status in [(viewer,403),(outsider,404),(guest,401)]:c.request(reviews,status)
    post(admin,reviews,'policy',403,revision='1',required='1');post(owner,reviews,'policy',revision='1',required='1');assert db()['schema']==2 and db()['review_policy']['required']
    owner.request(reviews,403,{'action':'policy','revision':'2','required':'0'})
    _,html=owner.request(reviews,200);assert b'Review required' in html and b'Save review policy' in html
    _,html=editor.request(reviews,200);assert b'Save review policy' not in html
    assert b'Publish tree</button>' not in admin.request(prefix+'/studio/tree?scope=guide%2F1.0',200)[1]
    assert b'value="publish"' not in admin.request(studio,200)[1]
    post(admin,studio,'publish',409,book='guide',version='1.0',path='start',title='Bypass',section='Guide',order='10',body='Bypass',revision=current())
    post(admin,prefix+'/studio/tree','publish',409,scope='guide/1.0',token=tree_token());post(editor,studio,'publish',403)
    check('policy UI, owner-only mutation, CSRF, and server blocks direct page/tree publication')
    _,html=editor.request(reviews+'?kind=page&target=guide%2F1.0%2Fstart',200);assert b'Submit for review' in html and b'diff-add' in html and uid('manager').encode() in html and b'&lt;script&gt;' in html
    submit(reviewer='writer',status=403);submit(reviewer='reader',status=403);submit(expected='1',status=409)
    rid=submit();assert db()['reviews'][rid]['snapshot']['draft']['body']==body
    submit(reviewer='admin',status=409);viewer.request(reviews+'?id='+rid,403);outsider.request(reviews+'?id='+rid,404);owner.request(other+'/studio/reviews?id='+rid,400)
    assert rid.encode() not in viewer.request(prefix+'/api/v1/pages',200)[1]
    check('immutable submission, duplicate/stale/self-review and cross-workspace guards')
    decide(owner,rid,'approve',403);decide(editor,rid,'approve',403);decide(owner,rid,'publish',409)
    decide(editor,rid,'comment',note='<script>comment</script>');_,html=admin.request(reviews+'?id='+rid,200);assert b'&lt;script&gt;comment&lt;/script&gt;' in html and b'Request changes' in html
    decide(admin,rid,'changes',422);decide(admin,rid,'approve',409,rev='1');decide(admin,rid,'changes',note='Please update the command');assert db()['reviews'][rid]['status']=='changes_requested'
    assert json.loads(viewer.request(api+key,200)[1])['body']=='Original published body';viewer.request(prefix+'/media/'+mid,404)
    check('reviewer-only decisions, escaped comments, stale request conflict, old publication unchanged')
    rid2=submit();decide(admin,rid2,'approve');role('manager','viewer');decide(owner,rid2,'publish',403);admin.request(reviews,403);role('manager','admin')
    seed('Edited after approval',body+'\nChanged',mid);assert db()['reviews'][rid2]['status']=='stale';decide(owner,rid2,'publish',409)
    check('revoked reviewer cannot authorize publication; later draft edit invalidates approval')
    rid3=submit();decide(admin,rid3,'approve',note='Ready');snapshot=db()['reviews'][rid3]['snapshot']['draft'];decide(owner,rid3,'publish');assert db()['pages'][key]['published']==snapshot and db()['reviews'][rid3]['status']=='published'
    assert json.loads(viewer.request(api+key,200)[1])['body']==snapshot['body'];viewer.request(prefix+'/media/'+mid,200);decide(owner,rid3,'publish',409)
    if FULL:
        _,html=editor.request(reviews+'?id='+rid3+'&preview=1',200);assert (prefix+'/studio/media/file/'+mid).encode() in html
        viewer.request(prefix+'/docs/'+key,200)
    check('approved revision published through real PHP, media and API visible, single-use publication')
    seed('Unpublished next version','This body must stay private');post(editor,prefix+'/studio/tree','move',scope='guide/1.0',token=tree_token(),source=key,target='',position='root',slug='moved')
    viewer.request(api+key,200);viewer.request(api+moved,404)
    tree=submit(kind='tree',target='guide/1.0');decide(admin,tree,'approve');decide(owner,tree,'publish');h,_=viewer.request(api+key,308);assert h['Location']==api+moved;assert json.loads(viewer.request(api+moved,200)[1])['body']==snapshot['body']
    assert db()['pages'][moved]['draft']['body']=='This body must stay private'
    check('tree review applies redirects and order without publishing draft content')
    post(owner,studio,'unpublish',key=moved,revision=current(moved));viewer.request(prefix+'/media/'+mid,404);post(owner,prefix+'/studio/media','delete',409,id=mid,revision='1')
    _,raw=owner.request(prefix+'/studio/media/backup',200)
    with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
        saved=json.load(tar.extractfile('books.json'));assert saved['reviews']==db()['reviews'] and saved['review_policy']['required'] and 'media/'+mid+'.blob' in tar.getnames()
    check('review snapshots retain private media and are included in scoped TAR backup')
    # Policy change explicitly invalidates pending reviews and re-enables direct publication.
    rid4=submit(target=moved,reviewer='admin');post(owner,reviews,'policy',revision='2',required='0');assert db()['reviews'][rid4]['status']=='stale';decide(owner,rid4,'publish',409)
    _,html=owner.request(studio,200);assert b'value="publish"' in html
    check('policy generation invalidates open reviews; direct mode is an explicit Owner choice')
    # Legacy adoption shares the same gate; archived legacy workspace cannot edit via root Studio.
    post(owner,'/workspaces','adopt',organization=oid,name='Legacy',slug='legacy');legacy=next(x for x in identity()['workspaces'].values() if x['legacy']);post(owner,'/studio/reviews','policy',revision='1',required='1')
    post(owner,'/studio','publish',409,book='handbook',version='1.0',path='welcome',title='Bypass',section='Guide',order='10',body='Bypass',revision='1')
    post(owner,org,'archive',revision=orev());owner.request('/studio/reviews',403)
    check('legacy root review gate and archived organization denies legacy Studio access')

def main():
    with tempfile.TemporaryDirectory(prefix='book-reviews-http-') as tmp:
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
