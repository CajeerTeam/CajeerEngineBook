#!/usr/bin/env python3
"""Exercise production PHP HTTPS transport against an isolated TLS GitLab API fixture."""
import base64, hashlib, http.server, json, os, ssl, subprocess, tempfile, threading
from pathlib import Path
from urllib.parse import urlsplit, parse_qs, unquote
ROOT=Path(__file__).resolve().parents[1]
TOKEN='glpat_transport_test_only'; REPO='group/sub/docs'; SHA='a'*40; BODY=b'# Transport\n'; BLOB=hashlib.sha1(b'blob '+str(len(BODY)).encode()+b'\0'+BODY).hexdigest()
PROJECT='/api/v4/projects/group%2Fsub%2Fdocs'; BASE='/api/v4/projects/42'; requests=[]
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def response(self,status,data=b'',headers=None):
        if not isinstance(data,bytes):data=json.dumps(data).encode()
        self.send_response(status);self.send_header('Content-Length',str(len(data)));self.send_header('Content-Type','application/json');
        for k,v in (headers or {}).items():self.send_header(k,v)
        self.end_headers();self.wfile.write(data)
    def do_GET(self):
        requests.append((self.command,self.path,self.headers.get('PRIVATE-TOKEN')))
        if self.headers.get('PRIVATE-TOKEN')!=TOKEN:return self.response(401)
        p=urlsplit(self.path);path=p.path;query=parse_qs(p.query)
        if path==PROJECT:return self.response(200,{'id':42,'path_with_namespace':REPO})
        if path==BASE+'/repository/branches/main':return self.response(200,{'commit':{'id':SHA}})
        if path==BASE+'/repository/branches/redirect':return self.response(302,headers={'Location':'/leak'})
        if path==BASE+'/repository/commits/'+SHA:return self.response(200,{'id':SHA,'author_name':'TLS fixture'})
        if path==BASE+'/repository/tree' and query.get('ref')==[SHA]:return self.response(200,[{'path':'docs/README.md','type':'blob','mode':'100644','id':BLOB}])
        if path==BASE+'/repository/blobs/'+BLOB+'/raw':return self.response(200,BODY)
        if path==BASE+'/repository/files/docs%2FREADME.md' and query.get('ref')==[SHA]:return self.response(200,{'file_path':'docs/README.md','last_commit_id':SHA})
        if path==BASE+'/repository/files/docs%2Fnew.md' and query.get('ref')==[SHA]:return self.response(404)
        return self.response(404)
    def do_POST(self):
        requests.append((self.command,self.path,self.headers.get('PRIVATE-TOKEN')))
        if self.headers.get('PRIVATE-TOKEN')!=TOKEN:return self.response(401)
        d=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        if self.path==BASE+'/repository/commits':
            assert d['start_sha']==SHA and d['branch'].startswith('cajeerbook/docs-') and 'force' not in d
            assert [x['action'] for x in d['actions']]==['update','create'] and base64.b64decode(d['actions'][0]['content'])==b'# Changed'
            self.server.proposal=d['branch'];return self.response(201,{'id':'b'*40,'parent_ids':[SHA]})
        if self.path==BASE+'/merge_requests':
            assert d['source_branch']==self.server.proposal and d['target_branch']=='main';return self.response(201,{'iid':4})
        return self.response(404)
# Manual requires deliberately avoid depending on Composer, but use production classes unchanged.
CODE=r'''foreach(['core/Update/OperationFiles.php','src/BookStore.php','src/MediaStore.php','src/Markdown.php'] as $f)require_once $f;
$c=new CajeerBook\GitLabClient($argv[1],$argv[2]);$repo='group/sub/docs';if($c->identify($repo)!==42)exit(7);$sha=$c->head($repo,'main');
$r=$c->fetch($repo,$sha,'docs');if(file_get_contents($r['files']->files()['README.md'])!=="# Transport\n")exit(3);
$url=$c->propose($repo,'main',$sha,['docs/README.md'=>'# Changed','docs/new.md'=>'# New']);if($url!==$argv[1].'/group/sub/docs/-/merge_requests/4')exit(4);
try{$c->head($repo,'redirect');exit(5);}catch(CajeerBook\GitHubRemoteException $e){if(!str_contains($e->getMessage(),'HTTP 302'))exit(6);}echo "PASS production TLS fetch, commit, MR and redirect rejection\n";'''
with tempfile.TemporaryDirectory(prefix='book-gitlab-tls-') as td:
    td=Path(td);cert=td/'cert.pem';key=td/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();origin=f'https://localhost:{server.server_port}';env=dict(os.environ,BOOK_GITLAB_ORIGINS=origin)
    try:
        # Untrusted certificate must not be accepted; no API request reaches the server.
        fail=subprocess.run(['php','-r',CODE,origin,TOKEN],cwd=ROOT,env=env,capture_output=True);assert fail.returncode!=0 and b'GitLab connection failed' in fail.stderr and not requests
        subprocess.run(['php','-d',f'openssl.cafile={cert}','-r',CODE,origin,TOKEN],cwd=ROOT,env=env,check=True)
        assert all(t==TOKEN for _,_,t in requests) and not any(p=='/leak' for _,p,_ in requests)
        print('PASS untrusted TLS certificate rejected; no redirect token disclosure')
    finally:server.shutdown();server.server_close();thread.join()
