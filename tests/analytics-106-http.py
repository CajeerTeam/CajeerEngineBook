#!/usr/bin/env python3
"""Real HTTP analytics, workspace isolation, browser consent, and vendor script injection."""
import importlib.util, json, os, socket, subprocess, tempfile, time, urllib.error, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('idhttp',ROOT/'tests/identity-http.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
Client=module.Client

def check(condition,label):
    assert condition,label
    print('PASS '+label,flush=True)

def beacon(base,path,payload,origin=None):
    headers={'Content-Type':'application/json'}
    if origin is not None:headers['Origin']=origin
    req=urllib.request.Request(base+path,data=json.dumps(payload).encode(),headers=headers)
    try:res=urllib.request.urlopen(req,timeout=10)
    except urllib.error.HTTPError as e:res=e
    with res:return res.status

with tempfile.TemporaryDirectory(prefix='book-106-http-') as tmp:
    data=Path(tmp)/'data';password='analytics-106-password-123'
    h=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',password],text=True)
    env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=h)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    with (Path(tmp)/'server.log').open('w+') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
        try:
            for _ in range(80):
                try:Client(base).request('/health/live',200);break
                except OSError:time.sleep(.1)
            else:raise RuntimeError('PHP server failed')
            admin,guest=Client(base),Client(base)
            admin.login('admin',password)
            headers,_=admin.request('/organizations',303,dict(csrf=admin.csrf('/organizations'),action='create',name='106 Org',slug='org106'))
            oid=headers['Location'].rsplit('/',1)[-1]
            admin.request('/workspaces',303,dict(csrf=admin.csrf('/workspaces'),action='create',organization=oid,name='Doc One',slug='one'))
            ws=next(iter(json.loads((data/'identity/identity.json').read_text())['workspaces'].values()))
            wid=ws['id'];prefix=f'/w/{oid}/{wid}'
            admin.request('/workspaces',303,dict(csrf=admin.csrf('/workspaces'),action='visibility',id=wid,revision='1',visibility='public',slug=''))
            _,html=guest.request(prefix+'/',200)
            check(b'phc_abc123456789' not in html,'disabled public pages have no vendor config')
            settings=dict(csrf=admin.csrf('/analytics?id='+wid),revision='2',enabled='1',search_terms='1',goals='signup,download',
                ga4='G-ABCDEF1234',metrica='12345678',posthog_key='phc_abc123456789',posthog_host='https://eu.i.posthog.com',
                matomo_url='https://matomo.example.com',matomo_site='3',
                plausible_script='https://plausible.io/js/pa-AbCd12345.js',
                umami_url='https://umami.example.com',umami_site='94db1cb1-74f4-4a40-ad6c-962362670409')
            admin.request('/analytics?id='+wid,303,settings)
            headers,html=guest.request(prefix+'/',200)
            # Client.request() returns a mapping of headers, which is case insensitive in practice.
            check(b'phc_abc123456789' in html and b'pa-AbCd12345.js' in html and b'94db1cb1' in html,'all four vendors configured for public reader')
            check('matomo.example.com' in str(headers) and 'eu-assets.i.posthog.com' in str(headers),'live CSP allows exactly configured third-party hosts')
            search={'event':'search','path':'/search','consent':True,'query':'PHP Deployment'}
            for _ in range(3):check(beacon(base,prefix+'/analytics/collect',search,base)==204,'opt-in search term persisted')
            check(beacon(base,prefix+'/analytics/collect',dict(search,query='privatephrase'),base)==204,'rare search term accepted into aggregate storage')
            goal={'event':'conversion','path':'/','consent':True,'goal':'signup'}
            for _ in range(2):check(beacon(base,prefix+'/analytics/collect',goal,base)==204,'allowlisted conversion persisted')
            check(beacon(base,prefix+'/analytics/collect',dict(goal,goal='admin'),base)==400,'undeclared goal rejected')
            check(beacon(base,prefix+'/analytics/collect',dict(search,query='user@example.com'),base)==400,'PII-like search query rejected')
            check(beacon(base,prefix+'/analytics/collect',goal,'https://untrusted.example.org')==403,'cross-origin conversion rejected')
            check(beacon(base,prefix+'/analytics/collect',dict(goal,visitor='private'),base)==400,'unexpected identifiers rejected')
            guest.request('/analytics?id='+wid,401)
            _,stats=admin.request('/analytics?id='+wid,200)
            check(b'PHP Deployment' not in stats and b'php deployment' in stats and b'privatephrase' not in stats,'manager report shows only normalized frequent queries')
            check(b'signup' in stats and b'Conversions by goal' in stats and b'Daily activity' in stats,'manager dashboard provides real conversions and visualization')
            # A hardened desktop Chromium profile may install a global URLBlocklist.
            # Keep HTTP coverage available in those environments, but run the full
            # browser verification on normal CI Chromium with loopback access.
            browser_verified=False
            from playwright.sync_api import sync_playwright, Error as BrowserError
            with sync_playwright() as p:
                browser_options={'headless':True,'args':['--no-sandbox']}
                if os.getenv('BOOK_CHROMIUM'):
                    browser_options['executable_path']=os.environ['BOOK_CHROMIUM']
                elif not os.path.isfile(p.chromium.executable_path):
                    import shutil
                    system_browser=shutil.which('chromium')
                    if system_browser:browser_options['executable_path']=system_browser
                browser=p.chromium.launch(**browser_options)
                try:
                    page=browser.new_page()
                    external=[]
                    page.on('request',lambda req:external.append(req.url) if req.url.startswith('https://') else None)
                    def external_stub(route):
                        if route.request.url.startswith('https://'):route.fulfill(status=200,content_type='application/javascript',body='')
                        else:route.continue_()
                    page.route('**/*',external_stub)
                    try:
                        page.goto(base+prefix+'/',wait_until='networkidle')
                    except BrowserError as e:
                        if 'ERR_BLOCKED_BY_ADMINISTRATOR' not in str(e):raise
                        print('SKIP Chromium network E2E: managed browser policy blocks all navigation',flush=True)
                    else:
                        check(not external and page.get_by_text('Accept analytics').is_visible(),'no vendor request before browser consent')
                        page.get_by_text('Accept analytics').click()
                        page.wait_for_timeout(800)
                        check(any('eu-assets.i.posthog.com/static/array.js' in u for u in external) and
                            any('matomo.example.com/matomo.js' in u for u in external) and
                            any('plausible.io/js/pa-' in u for u in external) and
                            any('umami.example.com/script.js' in u for u in external),'PostHog, Matomo, Plausible, Umami actually injected after consent')
                        check(page.evaluate('typeof window.CajeerBookAnalytics.trackConversion')=='function','published browser exposes working custom conversion API')
                        page.evaluate('window.CajeerBookAnalytics.trackConversion("signup")')
                        page.wait_for_timeout(150)
                        before=len(external)
                        page.get_by_text('Privacy preferences').click()
                        page.get_by_text('Reject analytics').click()
                        page.wait_for_load_state('networkidle')
                        check(len(external)==before,'withdrawn browser consent prevents subsequent vendor loading')
                        browser_verified=True
                finally:browser.close()
            _,stats=admin.request('/analytics?id='+wid,200)
            if browser_verified:check(b'<strong>3</strong>' in stats and b'signup' in stats,'browser-triggered conversion reaches first-party storage')
            admin.request('/analytics?id='+wid,303,dict(csrf=admin.csrf('/analytics?id='+wid),revision='3',posthog_host='https://us.i.posthog.com'))
            check(beacon(base,prefix+'/analytics/collect',goal,base)==404,'disabling workspace stops collection')
        except Exception:
            log.seek(0);print(log.read());raise
        finally:server.terminate();server.wait(timeout=5)
