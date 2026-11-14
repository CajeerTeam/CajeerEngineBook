#!/usr/bin/env python3
"""Multi-tenant release gate over ordinary HTTP against a disposable Compose deployment.
No filesystem access to the server, no seeded content. --storage-only is a local diagnostic.
"""
import argparse, importlib.util, json, os, re, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,upload,check=w.Client,w.post,w.upload,w.check
PASSWORD=os.environ.get('BOOK_TEST_PASSWORD','release-test-only-password')
ACCOUNT_PASSWORD='team-release-only-password-123'

def exercise(base,state,storage):
    owner,writer,manager,viewer,outsider,guest=[Client(base) for _ in range(6)];owner.login(password=PASSWORD)
    suffix=uuid.uuid4().hex[:10];names={role:role+'-'+suffix for role in ('writer','manager','viewer','outsider')}
    for role,c in [('writer',writer),('manager',manager),('viewer',viewer),('outsider',outsider)]:
        name=names[role];post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password=ACCOUNT_PASSWORD);c.login(name,ACCOUNT_PASSWORD)
    org=post(owner,'/organizations','create',name='Release team '+suffix,slug='release-'+suffix)[0]['Location'];oid=org.split('/')[-1]
    other=post(outsider,'/organizations','create',name='Other team '+suffix,slug='other-'+suffix)[0]['Location'];bid=other.split('/')[-1]
    def org_form():return owner.request(org,200)[1].decode()
    def rev():return re.search(r'name="revision" value="([0-9]+)"',org_form()).group(1)
    ids={}
    for role,c in [('writer',writer),('manager',manager),('viewer',viewer)]:
        name=names[role];_,html=post(owner,org,'invite',200,revision=rev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
        ids[role]=re.search(re.escape(name)+r' · viewer[^<]*</p>.*?name="user" value="([a-f0-9]{32})"',org_form(),re.S).group(1)
        if role!='viewer':post(owner,org,'role',revision=rev(),user=ids[role],role='editor' if role=='writer' else 'admin')
    post(owner,'/workspaces','create',organization=oid,name='Release private',slug='private');post(outsider,'/workspaces','create',organization=bid,name='Other private',slug='private')
    def prefix(c,orgid):return re.search(r'href="(/w/'+orgid+r'/[a-f0-9]{32})/studio"',c.request('/workspaces',200)[1].decode()).group(1)
    a,b=prefix(owner,oid),prefix(outsider,bid);studio=a+'/studio';reviews=studio+'/reviews';page='guide/1.0/start'
    for c,p in [(owner,a),(outsider,b)]:post(c,p+'/studio/books','create',slug='guide',title='Guide')
    payload=b'Release private attachment';mid=upload(writer,studio+'/media',payload);othermid=upload(outsider,b+'/studio/media',b'Other attachment')
    viewer.request(studio,403);writer.request(studio+'/export',403);outsider.request(studio,404);owner.request(b+'/studio',404);owner.request(studio+'/media/file/'+othermid,404);guest.request(a+'/media/'+mid,401)
    check('Apache gate: four roles, separate organizations, media and export authorization')
    body='# Team release '+suffix+'\n\n[Attachment](/media/'+mid+'?download=1)\n\n| A | B |\n| --- | --- |\n| one | two |\n'
    if not storage:
        fields=dict(book='guide',version='1.0',path='start',title='Team release '+suffix,section='Guide',order='10',body=body,revision='0')
        post(owner,reviews,'policy',revision='1',required='1');post(writer,studio,'save',**fields);post(owner,studio,'publish',409,**(fields|{'revision':'1'}));viewer.request(a+'/api/v1/pages/'+page,404)
        h,_=post(writer,reviews,'submit',kind='page',target=page,expected='1',reviewer=ids['manager']);rid=h['Location'].split('=')[-1];assert b'Review assigned' in manager.request(studio+'/team',200)[1]
        post(manager,reviews,'approve',id=rid,revision='1');post(owner,reviews,'publish',id=rid,revision='2');assert json.loads(viewer.request(a+'/api/v1/pages/'+page,200)[1])['body']==body
        assert b'<table' in viewer.request(a+'/docs/'+page,200)[1];assert viewer.request(a+'/media/'+mid,200)[1]==payload;guest.request(a+'/api/v1/pages',401);outsider.request(a+'/api/v1/pages',404)
        check('Apache gate: real Markdown, mandatory independent approval, exact private publication and media')
    state.write_text(json.dumps(dict(names=names,a=a,b=b,mid=mid,body=body,page=page,storage=storage)));os.chmod(state,0o600)

def persisted(base,state):
    s=json.loads(state.read_text());owner,writer,viewer,outsider,guest=[Client(base) for _ in range(5)];owner.login(password=PASSWORD)
    for role,c in [('writer',writer),('viewer',viewer),('outsider',outsider)]:c.login(s['names'][role],ACCOUNT_PASSWORD)
    a,b=s['a'],s['b'];writer.request(a+'/studio/team',200);viewer.request(a+'/studio',403);outsider.request(a+'/api/v1/pages',404);owner.request(b+'/studio',404);guest.request(a+'/api/v1/pages',401)
    if not s['storage']:
        assert json.loads(viewer.request(a+'/api/v1/pages/'+s['page'],200)[1])['body']==s['body'];assert viewer.request(a+'/media/'+s['mid'],200)[1]==b'Release private attachment';assert b'Approved revision published' in writer.request(a+'/studio/team',200)[1]
    else:assert writer.request(a+'/studio/media/file/'+s['mid'],200)[1]==b'Release private attachment'
    check('Apache gate: restart preserves identity, scoped access, content, media and review notifications')

def main():
    p=argparse.ArgumentParser();p.add_argument('--state',type=Path,required=True);p.add_argument('--check-persisted',action='store_true');p.add_argument('--storage-only',action='store_true');args=p.parse_args()
    base=os.environ.get('BOOK_TEST_URL');assert base,'BOOK_TEST_URL must point to a disposable test installation'
    if args.check_persisted:persisted(base,args.state)
    else:exercise(base,args.state,args.storage_only)
if __name__=='__main__':main()
