#!/usr/bin/env python3
"""Multi-user Team Studio HTTP regression. --storage-only seeds drafts without CommonMark."""
import importlib.util, io, json, os, re, socket, subprocess, sys, tarfile, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
FULL='--storage-only' not in sys.argv

def exercise(base,data):
    owner,manager,writer,viewer,outside,guest=[Client(base) for _ in range(6)];owner.login()
    guest.request('/team',401)
    for name,c in [('manager',manager),('writer',writer),('viewer',viewer),('outside',outside)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Team & Docs',slug='team-docs')[0]['Location'];oid=org.split('/')[-1]
    def identity():return json.loads((data/'identity/identity.json').read_text())
    def orev():return str(identity()['organizations'][oid]['revision'])
    def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
    for name,c in [('manager',manager),('writer',writer),('viewer',viewer)]:
        _,html=post(owner,org,'invite',200,revision=orev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode()
        if name=='writer':
            h,html=c.request('/team',200);assert b'Organization invitation' in html and b'Team &amp; Docs' in html and token.encode() not in html and h['Cache-Control']=='private, no-store'
            assert b'Team &amp; Docs' not in outside.request('/team',200)[1];event=next(e for e in identity()['activity'].values() if e['type']=='organization.invite' and uid(name) in e['recipients'])
            post(outside,'/team','read',422,id=event['id']);c.request('/team',403,{'action':'read','id':event['id'],'csrf':'wrong'});post(c,'/team','read',id=event['id']);assert uid(name) in identity()['activity'][event['id']]['read']
        post(c,'/organizations/join','accept',token=token)
    check('personal invitation inbox, CSRF, foreign event denial and no credentials')
    post(owner,org,'role',revision=orev(),user=uid('manager'),role='admin');post(owner,org,'role',revision=orev(),user=uid('writer'),role='editor')
    post(owner,'/workspaces','create',organization=oid,name='Engineering',slug='engineering');post(owner,'/workspaces','create',organization=oid,name='Other',slug='other')
    spaces={x['name']:x for x in identity()['workspaces'].values()};wid=spaces['Engineering']['id'];prefix='/w/'+oid+'/'+wid;other='/w/'+oid+'/'+spaces['Other']['id'];folder=data/'workspaces'/oid/wid
    team=prefix+'/studio/team';reviews=prefix+'/studio/reviews';studio=prefix+'/studio';key='guide/1.0/start'
    def db():return json.loads((folder/'books.json').read_text())
    h,html=writer.request('/team',200);assert b'Engineering' in html and b'Organization role changed' in html and b'Administrative audit' not in html and (prefix+'/studio/team').encode() in html
    assert b'Administrative audit' in manager.request('/team',200)[1];assert b'Engineering' not in outside.request('/team',200)[1]
    viewer.request(team,403);outside.request(team,404);guest.request(team,401)
    check('team workspace selection and administrative audit scoped by role')
    post(writer,prefix+'/studio/books','create',slug='guide',title='Guide')
    if FULL:post(writer,studio,'save',book='guide',version='1.0',path='start',title='Draft secret & guide',section='Guide',order='10',body='Private body',revision='0')
    else:
        code='require "bootstrap.php";$s=new CajeerBook\\BookStore($argv[1],false);$s->setHistoryActor(["id"=>$argv[2],"username"=>"writer"]);$s->change(function(&$d){$p=["title"=>"Draft secret & guide","section"=>"Guide","order"=>10,"body"=>"Private body","updated"=>gmdate("c"),"media"=>[]];$d["pages"]["guide/1.0/start"]=["id"=>bin2hex(random_bytes(16)),"draft"=>$p,"published"=>null,"revision"=>1];},"save");';subprocess.run(['php','-r',code,str(folder),uid('writer')],cwd=ROOT,check=True)
    _,html=writer.request(team+'?status=mine&author=writer&book=guide&q=secret',200);assert b'Draft secret &amp; guide' in html and b'Private body' not in html and b'Workspace members' in html and b'Viewer' in html
    _,html=writer.request(team+'?q=notfound',200);assert b'No matching documents' in html and b'Draft secret &amp; guide' not in html
    assert b'guide/1.0/start' not in writer.request(other+'/studio/team',200)[1];assert b'No matching documents' in writer.request(team+'?updated_after=2099-01-01',200)[1]
    check('document filters, attribution and workspace isolation')
    h,_=post(writer,reviews,'submit',kind='page',target=key,expected='1',reviewer=uid('manager'),note='Private discussion');rid=h['Location'].split('=')[-1]
    notification=next(e for e in db()['activity'].values() if e['type']=='review.in_review');nid=notification['id'];_,html=manager.request(team,200);assert b'1 unread' in html and b'Review assigned' in html and rid.encode() in html and b'Private discussion' not in html
    assert b'1 unread' not in writer.request(team,200)[1];post(writer,team,'read',422,id=nid);manager.request(team,403,{'csrf':'bad','action':'read','id':nid});post(manager,team,'read',id=nid);assert uid('manager') in db()['activity'][nid]['read'];assert b'0 unread' in manager.request(team,200)[1]
    post(manager,reviews,'changes',id=rid,revision='1',note='Please fix');assert b'Changes requested' in writer.request(team,200)[1]
    post(writer,team,'read',id='all');assert b'0 unread' in writer.request(team,200)[1]
    check('review assignment, changes request and per-user durable read receipts')
    h,_=post(writer,reviews,'submit',kind='page',target=key,expected='1',reviewer=uid('manager'));rid=h['Location'].split('=')[-1];post(manager,reviews,'approve',id=rid,revision='1');assert b'Review approved' in writer.request(team,200)[1];post(manager,reviews,'publish',id=rid,revision='2');assert b'Approved revision published' in writer.request(team,200)[1]
    assert b'Publication and access audit' in manager.request(team,200)[1] and b'Publication and access audit' not in writer.request(team,200)[1]
    _,raw=manager.request(prefix+'/studio/media/backup',200)
    with tarfile.open(fileobj=io.BytesIO(raw)) as t:assert json.load(t.extractfile('books.json'))['activity']==db()['activity']
    check('approval and publication activity, administrative audit and TAR persistence')
    post(owner,'/workspaces','role',id=wid,revision='1',user=uid('writer'),role='viewer');writer.request(team,403);assert (prefix+'/studio/team').encode() not in writer.request('/team',200)[1]
    post(owner,org,'remove',revision=orev(),user=uid('writer'));writer.request(team,404);assert b'Engineering' not in writer.request('/team',200)[1]
    assert b'Draft secret' not in guest.request('/api/v1/pages',200)[1]
    check('role downgrade and membership removal revoke activity and notification access')
    # No credential or body has entered the event journal, including identity backups.
    raw=json.dumps(identity()['activity'])+json.dumps(db()['activity']);assert 'Private body' not in raw and 'Private discussion' not in raw and 'password-' not in raw
    check('journals contain only safe event metadata')

def main():
    with tempfile.TemporaryDirectory(prefix='book-team-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:
                        with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                    except OSError:time.sleep(.1)
                exercise(base,data)
            except Exception:
                log.seek(0);print(log.read()[-6000:],file=sys.stderr);raise
            finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
