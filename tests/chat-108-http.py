#!/usr/bin/env python3
"""HTTP integration: real Reader chat route, quota, same-origin and OpenAI SSE."""
import json, os, socket, subprocess, tempfile, time, urllib.error, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); return s.getsockname()[1]

def request(url, expected, payload=None, origin=None):
    headers={'Content-Type':'application/json', 'Accept':'text/event-stream'}
    if origin is not None: headers['Origin']=origin
    data=None if payload is None else json.dumps(payload).encode()
    req=urllib.request.Request(url, data=data, headers=headers)
    try: r=urllib.request.urlopen(req,timeout=20)
    except urllib.error.HTTPError as e: r=e
    with r:
        body=r.read().decode('utf-8');status=r.status;headers=r.headers
    assert status==expected,(url,expected,status,body[:1500])
    return headers,body

def check(condition,label):
    if not condition:raise AssertionError(label)
    print('PASS '+label,flush=True)

def run():
    with tempfile.TemporaryDirectory(prefix='book-chat108-http-') as tmp:
        data=Path(tmp)/'data'; data.mkdir()
        seed=subprocess.check_output(['php','-r','echo json_encode(require $argv[1],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);',str(ROOT/'data/seed.php')])
        (data/'books.json').write_bytes(seed)
        fixture=port(); app=port()
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_CHAT_ENABLED='1',BOOK_CHAT_API_KEY='book-test-key-1234567890',BOOK_CHAT_TEST_LOOPBACK='1',BOOK_CHAT_API_URL=f'http://127.0.0.1:{fixture}/v1/responses',BOOK_CHAT_USER_DAILY_REQUESTS='2',BOOK_SECURE_COOKIE='0')
        env.pop('BOOK_PG_DSN',None)
        env.pop('BOOK_EMBEDDING_URL',None)
        with (Path(tmp)/'server.log').open('w+') as log:
            api=subprocess.Popen(['php','-S',f'127.0.0.1:{fixture}','-t','tests/fixtures','tests/fixtures/chat-api.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            srv=subprocess.Popen(['php','-S',f'127.0.0.1:{app}','-t','public','tests/fixtures/chat-reader-http.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            base=f'http://127.0.0.1:{app}'
            try:
                for i in range(75):
                    try: request(base+'/health/live',200);break
                    except (OSError,AssertionError):time.sleep(.1)
                else:raise AssertionError('Local Book HTTP fixture unavailable.')
                _,page=request(base+'/docs/handbook/1.0/welcome',200)
                check('data-doc-chat' in page and 'documentation-chat.js' in page,'Reader page renders chat interface and streaming JavaScript')
                endpoint=base+'/api/v1/chat';body={'question':'How can I start using documentation?','page':'handbook/1.0/welcome','history':[]}
                headers,answer=request(endpoint,200,body,base)
                check(headers['Content-Type'].startswith('text/event-stream'),'chat uses real SSE HTTP response')
                check('event: sources' in answer and '"key":"handbook/1.0/welcome"' in answer and 'event: done' in answer and answer.count('event: delta')==2 and 'Documentation ' in answer and 'answer [1].' in answer,'live HTTP stream yields authorized sources, deltas and done')
                _,bad=request(endpoint,403,body,'https://evil.example.org')
                check('Same-origin' in bad or 'Cross-origin' in bad,'cross-origin JSON submission rejected')
                _,invalid=request(endpoint,422,dict(body,question=''),base)
                check('Invalid chat request' in invalid,'malformed question rejected')
                _,_ = request(endpoint,200,body,base)
                headers,denied=request(endpoint,429,body,base)
                check('quota' in denied and 'Retry-After' in headers,'third request hits persistent per-visitor daily quota')
            except Exception:
                log.flush();print((Path(tmp)/'server.log').read_text()[-16000:]);raise
            finally:
                for proc in (srv,api):proc.terminate()
                for proc in (srv,api):proc.wait(timeout=5)

if __name__=='__main__':run()
