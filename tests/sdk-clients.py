#!/usr/bin/env python3
"""Build real published SDK ZIPs, unpack and execute each against a trusted local TLS API."""
import hashlib, http.server, json, os, pathlib, shutil, ssl, subprocess, tempfile, threading, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
requests=[]
class API(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def reply(self,status,body,headers=None):
        self.send_response(status)
        for k,v in (headers or {}).items(): self.send_header(k,v)
        self.send_header('Content-Length',str(len(body)));self.end_headers()
        try:self.wfile.write(body)
        except (BrokenPipeError,ConnectionResetError,ssl.SSLError):pass
    def do_POST(self):
        raw=self.rfile.read(int(self.headers['Content-Length']));requests.append((self.path,self.headers.get('Authorization')))
        self.reply(201,json.dumps({'path':self.path,'auth':self.headers.get('Authorization'),'trace':self.headers.get('X-Trace'),'cookie':self.headers.get('Cookie'),'data':json.loads(raw)}).encode(),{'Content-Type':'application/json'})
    def do_GET(self):
        requests.append((self.path,self.headers.get('Authorization')))
        if self.path=='/v1/redirect':self.reply(302,b'',{'Location':'/v1/secret'})
        elif self.path=='/v1/binary':self.reply(200,b'\x00\xff\x01')
        elif self.path=='/v1/large':self.reply(200,b'x'*1048577)
        else:self.reply(404,b'not found')

def run(cmd,cwd,env):
    r=subprocess.run(cmd,cwd=cwd,env=env,check=True,capture_output=True,text=True);return r.stdout

def main():
    with tempfile.TemporaryDirectory(prefix='book-sdk-clients-') as t:
        tmp=pathlib.Path(t);cert=tmp/'cert.pem';key=tmp/'key.pem';subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),API);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
        try:
            env=dict(os.environ,NODE_EXTRA_CA_CERTS=str(cert),SSL_CERT_FILE=str(cert));env.pop('NODE_TLS_REJECT_UNAUTHORIZED',None)
            print(run(['php','tests/sdk.php',f'https://localhost:{server.server_port}',str(tmp)],ROOT,env),end='')
            model=json.loads((tmp/'model.json').read_text());post=model['selected'];names={v['path']:k for k,v in model['definitions'].items()};redirect=names['/redirect'];binary=names['/binary'];large=names['/large']
            for lang in ('typescript','python','php'):
                d=tmp/lang;d.mkdir();withzip=zipfile.ZipFile(tmp/(lang+'.zip'));assert withzip.testzip() is None;withzip.extractall(d);withzip.close()
                assert json.loads((d/'publication.json').read_text())['digest']==model['version']
                if lang=='typescript':
                    (d/'test.mjs').write_text(f'''import {{ Client }} from './client.mjs';
import assert from 'node:assert/strict';
const c=new Client(), input={{server:0,security:0,values:['a b','2','trace','dark'],auth:['secret','query key'],body:'{{"name":"Ada"}}',content_type:'application/json'}};
const r=await c.{post}(input); assert.equal(r.status,201); console.log(JSON.stringify(r.json()));
assert.equal((await c.{redirect}({{security:2,values:[],auth:[]}})).status,302);
assert.deepEqual(Array.from((await c.{binary}({{security:2,values:[],auth:[]}})).body),[0,255,1]);
await assert.rejects(()=>c.{large}({{security:2,values:[],auth:[]}}),/1 MiB/);
await assert.rejects(()=>c.{post}({{...input,values:['../escape','2',null,null]}}),/Unsafe/);
''');out=run(['node','test.mjs'],d,env)
                    (d/'typecheck.mts').write_text(f'''import {{Client}} from './client.mjs';
const c = new Client();
await c.{post}({{values:['id','2',null,null],auth:['token','key'],body:'{{}}',content_type:'application/json'}});
// @ts-expect-error required input missing
await c.{post}({{values:[],auth:[]}});
''')
                    if shutil.which('tsc'):run(['tsc','--noEmit','--strict','--module','nodenext','--target','es2022','typecheck.mts'],d,env);print('PASS TypeScript declarations compile and reject invalid input')
                    else:raise RuntimeError('TypeScript compiler required for SDK test')
                    pkg=json.loads((d/'package.json').read_text());packed=json.loads(run(['npm','pack','--ignore-scripts','--json'],d,env))[0]['filename'];install=tmp/'node-install';install.mkdir();(install/'package.json').write_text('{"private":true,"type":"module"}')
                    run(['npm','install','--offline','--ignore-scripts','--no-audit','--no-fund',str(d/packed)],install,env)
                    run(['node','--input-type=module','-e',f'import {{Client}} from {json.dumps(pkg["name"])}; if(typeof new Client().{post}!=="function") process.exit(1)'],install,env);print('PASS npm pack and offline local install')
                elif lang=='python':
                    (d/'test.py').write_text(f'''from cajeer_sdk import Client
import json
c=Client(); opts=dict(values=['a b','2','trace','dark'],auth=['secret','query key'],body='{{"name":"Ada"}}',content_type='application/json')
r=c.{post}(**opts);assert r.status==201;print(json.dumps(r.json()))
assert c.{redirect}(security=2).status==302
assert c.{binary}(security=2).body==bytes([0,255,1])
try: c.{large}(security=2)
except ValueError as e: assert '1 MiB' in str(e)
else: raise AssertionError('response limit missing')
try: c.{post}(**(opts|dict(values=['../escape','2',None,None])))
except ValueError: pass
else: raise AssertionError('unsafe path accepted')
''');out=run(['python3','test.py'],d,env)
                    wheels=tmp/'wheels';wheels.mkdir();run(['python3','-m','pip','wheel','--no-deps','--no-build-isolation','--no-index','-w',str(wheels),'.'],d,env);wheel=next(wheels.glob('*.whl'));install=tmp/'python-install';run(['python3','-m','pip','install','--no-deps','--no-index','--target',str(install),str(wheel)],tmp,env);run(['python3','-c',f'from cajeer_sdk import Client; assert callable(Client().{post})'],tmp,dict(env,PYTHONPATH=str(install)));print('PASS Python wheel build and offline local install')
                else:
                    (d/'test.php').write_text(f'''<?php
require __DIR__.'/Client.php'; $c=new \\CajeerSdk\\Client();
function check($v){{if(!$v)throw new Exception('assertion failed');}}
$input=['values'=>['a b','2','trace','dark'],'auth'=>['secret','query key'],'body'=>'{{"name":"Ada"}}','content_type'=>'application/json'];
$r=$c->{post}($input);check($r->status===201);echo json_encode($r->json()),PHP_EOL;
check($c->{redirect}(['security'=>2])->status===302);
check($c->{binary}(['security'=>2])->body==="\\x00\\xff\\x01");
try{{$c->{large}(['security'=>2]);throw new LogicException('limit missing');}}catch(RuntimeException $e){{check(str_contains($e->getMessage(),'1 MiB'));}}
try{{$c->{post}(array_replace($input,['values'=>['../escape','2',null,null]]));throw new LogicException('unsafe accepted');}}catch(InvalidArgumentException $e){{}}
''');out=run(['php','-d','openssl.cafile='+str(cert),'test.php'],d,env);run(['php','-l','example.php'],d,env)
                result=json.loads(out);assert result=={'path':'/v1/pets/a%20b?limit=2&key=query%20key','auth':'Bearer secret','trace':'trace','cookie':'theme=dark','data':{'name':'Ada'}},result
                print('PASS '+lang+' generated SDK: actual TLS POST, 302 without redirect, binary, response limit, path validation')
            assert not any(p=='/v1/secret' for p,a in requests)
            print('PASS no redirect forwarded credentials')
        finally:server.shutdown();server.server_close()
if __name__=='__main__':main()
