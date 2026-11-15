#!/usr/bin/env python3
"""Real HTTPS sockets and browser request implementation against a controlled TLS API.
Production DNS policy rejects this local address; only the private wire primitive is
invoked via reflection for wire tests, never through a production route.
"""
import http.server, json, os, ssl, subprocess, tempfile, threading
from pathlib import Path
from urllib.parse import urlsplit, parse_qs
ROOT=Path(__file__).resolve().parents[1]
REQUESTS=[]
class API(http.server.BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args):pass
    def response(self,status,body=b'',headers=None):
        self.send_response(status);self.send_header('Content-Length',str(len(body)));self.send_header('Content-Type','application/json');self.send_header('Access-Control-Allow-Origin','*');self.send_header('Access-Control-Expose-Headers','X-Trace');self.send_header('X-Trace','wire-test')
        for k,v in (headers or {}).items():self.send_header(k,v)
        self.end_headers()
        if self.command!='HEAD':self.wfile.write(body)
    def do_OPTIONS(self):
        self.response(204,headers={'Access-Control-Allow-Methods':'GET, POST, HEAD, OPTIONS','Access-Control-Allow-Headers':'Authorization, Content-Type, X-Trace'})
    def do_POST(self):
        body=self.rfile.read(int(self.headers.get('Content-Length','0')));REQUESTS.append((self.command,self.path,self.headers.get('Authorization'),body,self.headers.get('Host'),self.headers.get('Cookie')))
        if self.path.startswith('/v1/pets/'):
            self.response(201,json.dumps({'path':urlsplit(self.path).path,'query':parse_qs(urlsplit(self.path).query),'body':json.loads(body),'host':self.headers.get('Host')}).encode())
        else:self.response(404)
    def do_HEAD(self):self.response(200,b'not sent')
    def do_GET(self):
        REQUESTS.append((self.command,self.path,self.headers.get('Authorization'),b'',self.headers.get('Host'),self.headers.get('Cookie')))
        if self.path=='/redirect':self.response(302,headers={'Location':'/leak'});return
        if self.path=='/chunked':
            self.send_response(200);self.send_header('Transfer-Encoding','chunked');self.end_headers();self.wfile.write(b'5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n');self.wfile.flush();return
        if self.path=='/big':self.response(200,b'x'*(1048576+33));return
        if self.path=='/binary':self.response(200,b'\x00\xff\x01');return
        if self.path=='/malformed':
            self.send_response(200);self.send_header('Content-Length','10');self.send_header('Transfer-Encoding','chunked');self.end_headers();self.wfile.write(b'0\r\n\r\n');self.wfile.flush();return
        self.response(200,b'{"ok":true}')

PHP=r'''require 'src/ApiPlayground.php';require 'src/ApiPlaygroundTransport.php';
use CajeerBook\ApiPlaygroundTransport as T;
$origin=$argv[1];$port=(int)parse_url($origin,PHP_URL_PORT);$m=new ReflectionMethod(T::class,'sendPinned');
$send=function($method,$path,$headers=[],$body='',$name='localhost')use($origin,$port,$m){return $m->invoke(null,['method'=>$method,'url'=>$origin.$path,'headers'=>$headers,'body'=>$body,'origin'=>$origin],$name,$port,'127.0.0.1');};
$r=$send('POST','/v1/pets/a%20b?limit=2&key=secret',['Authorization'=>'Bearer wire-secret','Content-Type'=>'application/json','X-Trace'=>'trace','Cookie'=>'theme=night'], '{"name":"Ada"}');
if($r['status']!==201||json_decode($r['body'],true)['body']['name']!=='Ada'||!in_array(['X-Trace','wire-test'],$r['headers'],true))throw new RuntimeException('POST wire response mismatch');
$r=$send('GET','/redirect',['Authorization'=>'Bearer wire-secret']);if($r['status']!==302)throw new RuntimeException('Redirect status lost');
$r=$send('GET','/chunked');if($r['body']!=='hello world'||$r['truncated'])throw new RuntimeException('Chunk decoding failed');
$r=$send('GET','/big');if(strlen($r['body'])!==1048576||!$r['truncated'])throw new RuntimeException('Response cap failed');
$r=$send('GET','/binary');if($r['encoding']!=='base64'||base64_decode($r['body'])!=="\x00\xff\x01")throw new RuntimeException('Binary response failed');
$r=$send('HEAD','/');if($r['body']!=='')throw new RuntimeException('HEAD should have no body');
try{$send('GET','/malformed');throw new LogicException('Ambiguous framing accepted');}catch(RuntimeException $e){if($e instanceof LogicException||!str_contains($e->getMessage(),'framing'))throw $e;}
try{$send('GET','/',[],'','other.example.org');throw new LogicException('Wrong TLS name accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;}
putenv('BOOK_PLAYGROUND_PROXY_ORIGINS='.$origin);try{T::send(['method'=>'GET','url'=>$origin.'/','headers'=>[],'body'=>'','origin'=>$origin]);throw new LogicException('Loopback accepted');}catch(InvalidArgumentException $e){}
echo "PASS actual pinned TLS POST, headers, chunked, redirect, binary, limits, HEAD, framing, certificate hostname and DNS policy\n";'''
NODE=r'''const assert=require('node:assert/strict');const {build,curl,browserRequest}=require('./public/assets/api-playground.js');
const base=process.argv[1];const d={method:'POST',path:'/pets/{id}',servers:[base+'/v1'],parameters:[{name:'id',in:'path',required:true,schema:{type:'string'}},{name:'limit',in:'query',required:true,schema:{type:'integer'}}],security:[[{name:'token',kind:'bearer',in:'header',field:'Authorization'},{name:'key',kind:'key',in:'query',field:'key'}]],body_types:['application/json'],body_required:true};
(async()=>{const i={server:0,security:0,values:['a b','2'],auth:['browser-secret','query-secret'],body:'{"name":"Ada"}',content_type:'application/json'};const r=build(d,i,[0]);assert.equal(r.url,base+'/v1/pets/a%20b?limit=2&key=query-secret');assert(!curl(r).includes('browser-secret')&&!curl(r).includes('query-secret'));assert.throws(()=>build(d,{...i,values:['..','2']},[0]));assert.throws(()=>build(d,i,[]));const result=await browserRequest(r,AbortSignal.timeout(5000));assert.equal(result.status,201);assert.equal(JSON.parse(result.body).body.name,'Ada');let x=await browserRequest({method:'GET',url:base+'/big',headers:{},body:''},AbortSignal.timeout(5000));assert(x.truncated&&x.body.length===1048576);x=await browserRequest({method:'GET',url:base+'/binary',headers:{},body:''},AbortSignal.timeout(5000));assert.equal(x.encoding,'base64');await assert.rejects(browserRequest({method:'GET',url:base+'/redirect',headers:{Authorization:'Bearer browser-secret'},body:''},AbortSignal.timeout(5000)));console.log('PASS actual JavaScript fetch POST, redacted cURL, response cap, binary and redirect rejection');})().catch(e=>{console.error(e);process.exit(1)});'''

def main():
    with tempfile.TemporaryDirectory(prefix='book-playground-tls-') as td:
        td=Path(td);cert=td/'cert.pem';key=td/'key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),API);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start();origin=f'https://localhost:{server.server_port}'
        try:
            subprocess.run(['php','-d',f'openssl.cafile={cert}','-r',PHP,origin],cwd=ROOT,check=True,timeout=40)
            subprocess.run(['node','-e',NODE,origin],cwd=ROOT,env=dict(os.environ,NODE_EXTRA_CA_CERTS=str(cert)),check=True,timeout=30)
            assert any(r[0]=='POST' and r[2]=='Bearer wire-secret' and r[5]=='theme=night' and r[4]==f'localhost:{server.server_port}' for r in REQUESTS)
            assert any(r[0]=='POST' and r[2]=='Bearer browser-secret' and r[5] is None for r in REQUESTS)
            assert not any(r[1]=='/leak' for r in REQUESTS)
            print('PASS wire assertions: pinned Host, credentials, no ambient cookies and no redirected requests')
        finally:server.shutdown();server.server_close()
if __name__=='__main__':main()
