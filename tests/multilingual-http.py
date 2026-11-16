#!/usr/bin/env python3
"""Real localized Reader/Studio routes; --storage-only omits dependency-backed rendering."""
import importlib.util, json, os, re, socket, subprocess, sys, tempfile, time, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('identity_http', ROOT/'tests/identity-http.py'); m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
Client,check,PASSWORD=m.Client,m.check,m.PASSWORD
STORAGE='--storage-only' in sys.argv; PG='--postgres' in sys.argv

def exercise(base,env):
    owner,guest=Client(base),Client(base);owner.login()
    def post(route,action,status=303,**fields):return owner.request(route,status,{'csrf':owner.csrf('/account'),'action':action}|fields)
    key='guide/1/start';ru='guide/1/ru/start'
    def save(path,title,body,rev='0',action='publish'):
        return post('/studio',action,book='guide',version='1',path=path,title=title,body=body,section='Guide',order='10',revision=rev)
    def seed(action):
        code=r'''require 'tests/oidc-bootstrap.php';$s=new CajeerBook\BookStore(getenv('BOOK_DATA_DIR'));$s->change(function(&$d)use($argv){$key='guide/1/start';$ru='guide/1/ru/start';if($argv[1]==='original'){$p=['title'=>'Quasar','section'=>'Guide','order'=>10,'body'=>'Quasar original','updated'=>gmdate('c'),'media'=>[]];$d['pages'][$key]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>$p,'published_key'=>$key];}elseif($argv[1]==='translation'){$p=['title'=>'Квазар','section'=>'Guide','order'=>10,'body'=>'Quasar документация','updated'=>gmdate('c'),'media'=>[]]+CajeerBook\Locale::attach($d,$ru,'ru',$d['pages'][$key]['id'],CajeerBook\Locale::digest($d['pages'][$key]['published']));$d['pages'][$ru]=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>null];}elseif($argv[1]==='publish'){$e=&$d['pages'][$ru];CajeerBook\BookStore::publishSnapshot($d,$ru,$e);$e['revision']++;}},'save');'''
        subprocess.run(['php','-r',code,action],cwd=ROOT,env=env,check=True)
    if STORAGE:seed('original')
    else:save('start','Quasar','Quasar original')
    guest.request('/studio/translations',401)
    _,html=owner.request('/studio/translations?source='+key+'&ui=ru',200)
    assert 'Редактор переводов'.encode() in html and b'<html lang="ru"' in html
    assert 'Создать перевод'.encode() in html
    fields={k:re.search(rb'name="'+k.encode()+rb'" value="([^"]+)"',html).group(1).decode() for k in ['source_id','source_digest']}
    owner.request('/studio/translations',403,{'action':'create'})
    if STORAGE:seed('translation')
    else:post('/studio/translations','create',source=key,path='ru/start',locale='ru',title='Квазар',section='Guide',body='Quasar документация',**fields)
    guest.request('/l/ru/docs/'+key,404)
    assert guest.request('/l/ru/docs/'+key+'?fallback=1',302)[0]['Location']=='/docs/'+key
    check('localized workbench, CSRF, unpublished translation hidden, explicit fallback')
    if STORAGE:seed('publish')
    else:save('ru/start','Квазар','Quasar документация',rev='1')
    assert guest.request('/l/ru/docs/'+key,302)[0]['Location']=='/docs/'+ru
    assert guest.request('/l/en/docs/'+ru,302)[0]['Location']=='/docs/'+key
    pages=json.loads(guest.request('/api/v1/pages',200)[1]);assert pages[key]['locale']=='en' and pages[ru]['locale']=='ru' and 'translation_group' not in pages[ru]
    _,html=owner.request('/studio/translations?source='+key,200);assert 'Актуален'.encode() in html
    if not STORAGE:
        # Numeric PHP array keys must not turn version labels into integers.
        _,original=guest.request('/docs/'+key,200)
        assert re.search(rb'<a[^>]*class="active"[^>]*>1</a>',original), 'numeric version must render as active'
        h,html=guest.request('/docs/'+ru,200);assert h['Content-Language']=='ru' and b'<html lang="ru"' in html and b'hreflang="en"' in html and b'href="/docs/guide/1/start"' in html
        h,body=guest.request('/api/v1/search?q=quasar&locale=ru',200);assert h['X-Total-Count']=='1' and [p['key'] for p in json.loads(body)]==[ru]
        guest.request('/api/v1/search?q=quasar&locale=invalid',422)
    check('published language redirects, API language and persisted UI preference')
    post('/studio/translations','set-language',409,key=key,source=key,revision='1',locale='ru')
    post('/studio/books','visibility',slug='guide',visibility='private',revision='1')
    guest.request('/l/ru/docs/'+key,404);guest.request('/api/v1/pages/'+ru,404)
    assert owner.request('/l/ru/docs/'+key,302)[0]['Location']=='/docs/'+ru
    check('private translation aliases and API cannot leak to anonymous Reader')

def main():
    with tempfile.TemporaryDirectory(prefix='book-language-http-') as tmp:
        tmp=Path(tmp);env=dict(os.environ,BOOK_DATA_DIR=str(tmp/'data'),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',PASSWORD],text=True))
        if PG:
            assert not STORAGE and env.get('BOOK_PG_DSN');env['BOOK_PG_NAMESPACE']=uuid.uuid4().hex
            subprocess.run(['php','bin/postgres-content.php','migrate','--offline'],cwd=ROOT,env=env,check=True)
        else:
            for key in ('BOOK_PG_DSN','BOOK_PG_NAMESPACE','BOOK_REDIS_URL'):env.pop(key,None)
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php' if STORAGE else 'public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,env)
            except Exception:log.seek(0);print(log.read());raise
            finally:
                server.terminate();server.wait(timeout=5)
                if PG:subprocess.run(['php','-r','require "bootstrap.php";$p=CajeerBook\\PostgresContentStore::connect();$q=$p->prepare("DELETE FROM cajeer_book.content_installation WHERE installation=?");$q->execute([getenv("BOOK_PG_NAMESPACE")]);'],cwd=ROOT,env=env,check=True)
if __name__=='__main__':main()
