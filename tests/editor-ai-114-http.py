#!/usr/bin/env python3
"""HTTP integration: authenticated editor assistant, Origin/CSRF, bounded mock Responses API, no implicit writes."""
import importlib.util, os, re, socket, subprocess, tempfile, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('identity_http',ROOT/'tests/identity-http.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
Client,PASSWORD=m.Client,m.PASSWORD

def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def main():
    with tempfile.TemporaryDirectory(prefix='book-editor-ai-http-') as temp:
        p=Path(temp);api_port=port();server_port=port()
        env=dict(os.environ,BOOK_DATA_DIR=str(p/'data'),BOOK_SECURE_COOKIE='0',
            BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True),
            BOOK_EDITOR_AI_ENABLED='1',BOOK_CHAT_API_KEY='book-test-key-1234567890',BOOK_CHAT_TEST_LOOPBACK='1',
            BOOK_CHAT_API_URL=f'http://127.0.0.1:{api_port}/v1/responses')
        with (p/'api.log').open('w+') as api_log,(p/'book.log').open('w+') as log:
            api=subprocess.Popen(['php','-S',f'127.0.0.1:{api_port}','-t','tests/fixtures','tests/fixtures/editor-ai-api.php'],cwd=ROOT,env=env,stdout=api_log,stderr=api_log)
            app=subprocess.Popen(['php','-S',f'127.0.0.1:{server_port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                client=Client(f'http://127.0.0.1:{server_port}');guest=Client(client.base)
                for _ in range(60):
                    try:guest.request('/health/live',200);break
                    except OSError:time.sleep(.1)
                else:raise RuntimeError('App server did not start')
                guest.request('/studio/assistant',401);client.login()
                _,html=client.request('/studio/assistant',200)
                assert b'Editor AI Assistant' in html and b'name="action" value="suggest"' in html
                print('PASS authenticated Studio UI, unauthenticated denied',flush=True)
                csrf=client.csrf('/account');form={'csrf':csrf,'action':'migration','error':'ERROR: migration version 6 failed'}
                client.request('/studio/assistant',403,{**form,'csrf':'f'*64})
                client.request('/studio/assistant',403,form,{'Origin':'https://untrusted.example'})
                _,body=client.request('/studio/assistant',200,form)
                assert b'Check migration schema version' in body and b'No migration commands were executed' in body
                print('PASS CSRF/Origin, OpenAI SSE diagnostic and escaped result',flush=True)
                _,body=client.request('/studio/assistant',200,{'csrf':csrf,'action':'gaps','scope':'handbook/1.0'})
                assert b'Coverage findings' in body and b'Prioritize short' in body
                print('PASS real HTTP gap analysis over scoped Book draft snapshot',flush=True)
                client.request('/studio/assistant',422,{'csrf':csrf,'action':'gaps','scope':'../../private'})
                client.request('/studio/assistant',422,{'csrf':csrf,'action':'migration','error':'X'*4001})
                assert not (p/'data'/'books.json').exists(), 'Read-only AI operations must not write the Book content store'
                print('PASS bad inputs denied, no Book content writes',flush=True)
            except Exception:
                log.seek(0);print(log.read());api_log.seek(0);print(api_log.read());raise
            finally:
                app.terminate();api.terminate();app.wait(timeout=5);api.wait(timeout=5)
if __name__=='__main__':main()
