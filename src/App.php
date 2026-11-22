<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Template\{CajeerTemplateEngine,TemplateSandbox};
use CajeerEngine\Runtime\ServiceContainer;
final class App {
    private BookStore $store; private MediaStore $media; private MediaController $mediaController; private CajeerTemplateEngine $view;
    private string $dataDir; private readonly string $installationDir; private ?array $workspace=null; private ?array $analyticsWorkspace=null; private ?array $githubPrepared=null; private ?array $openApiPrepared=null; private ?string $openApiError=null; private ?string $githubError=null; private ?array $githubFailure=null; private ?array $scopedIdentity=null; private string $studioRole='none'; private string $readerVisibility='public'; private AuthController $auth; private IdentityStore $identityStore; private string $documentHead=''; private ?string $domainHost=null; private ?string $domainCanonical=null;
    public function __construct(private readonly string $root,private readonly ?\Closure $githubClientFactory=null){
        $this->installationDir=$this->dataDir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
        $c=new ServiceContainer();$c->set('store',fn()=>new BookStore($this->dataDir));$this->store=$c->get('store');
        $this->identityStore=new IdentityStore($this->dataDir);$this->auth=new AuthController($this->identityStore);
        $this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
        $t=$root.'/templates';$this->view=new CajeerTemplateEngine(new TemplateSandbox($t),$t);
    }
    private static function e(string $s):string{return Markdown::e($s);}
    private static function t(string $s):string{return self::e(Locale::t($s));}
    private static function url(string $key):string{return WorkspaceUrls::url('/docs/'.$key);}
    private static function link(string $url,string $label,string $class=''):string{return '<a class="'.self::e($class).'" href="'.self::e(WorkspaceUrls::url($url)).'">'.self::e($label).'</a>';}
    private function page(string $title,string $body,array $pages=[],string $current='',string $toc='',string $query=''):void{
        $nav=PageTreeView::reader($pages,$current);$document=$current!==''?($pages[$current]??null):null;
        $lang=$document?Locale::language($document):Locale::ui();$searchLocale=$document?Locale::language($document):(Locale::valid($_GET['locale']??null)?$_GET['locale']:'');
        if($this->workspace&&$this->domainHost===null)$body='<p class="eyebrow"><a href="/workspaces">'.self::t('Workspaces').'</a> / '.self::e($this->workspace['name']).' · '.self::e($this->workspace['role']==='none'?'Public':ucfirst($this->workspace['role'])).'</p>'.$body;
        $analyticsPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
        $prefix=WorkspaceUrls::prefix();
        if($prefix!==''&&str_starts_with($analyticsPath,$prefix.'/'))$analyticsPath=substr($analyticsPath,strlen($prefix));
        $analyticsPage=$this->readerVisibility==='public'&&!$this->scopedIdentity&&http_response_code()===200&&preg_match('~\A/(?:$|search$|docs/)~D',$analyticsPath)===1;
        header('Content-Security-Policy: '.Analytics::contentSecurityPolicy($this->workspace??$this->analyticsWorkspace,$analyticsPage));
        echo $this->view->renderName('book.layout',['title'=>$title,'description'=>'Self-hosted technical documentation','body'=>$body,'nav'=>$nav,'toc'=>$toc,'query'=>$query,'workspacePrefix'=>WorkspaceUrls::prefix(),'homeUrl'=>WorkspaceUrls::prefix().'/', 'searchUrl'=>WorkspaceUrls::url('/search'),'studioLink'=>$this->domainHost===null&&(!$this->workspace||Permissions::allows($this->studioRole,'edit'))?self::link('/studio',Locale::t('Studio')):'','controlLinks'=>$this->domainHost===null?self::link('/team',Locale::t('Team')).(getenv('BOOK_PG_DSN')?'<a href="/audit">Audit</a>':'').self::link('/workspaces',Locale::t('Workspaces')).self::link('/organizations',Locale::t('Organizations')).self::link('/login',Locale::t('Account')):'','uiLocale'=>Locale::ui(),'contentLocale'=>$lang,'searchLocale'=>$searchLocale,'uiLinks'=>Locale::uiLinks(),'searchLabel'=>Locale::t('Search'),'searchPlaceholder'=>Locale::t('Search documentation'),'teamLabel'=>Locale::t('Team'),'workspacesLabel'=>Locale::t('Workspaces'),'organizationsLabel'=>Locale::t('Organizations'),'accountLabel'=>Locale::t('Account'),'themeLabel'=>Locale::t('Toggle theme'),'documentationLabel'=>Locale::t('Documentation'),'footerLabel'=>Locale::t('Self-hosted documentation'),'documentHead'=>$this->documentHead,'analyticsSettings'=>json_encode(Analytics::browserConfig($this->workspace??$this->analyticsWorkspace,$analyticsPage),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'chatMarkup'=>$current!==''&&DocumentationChat::enabled()&&($this->readerVisibility==='public'||DocumentationChat::privateEnabled())?'<section class="doc-chat" data-doc-chat data-endpoint="'.self::e(WorkspaceUrls::url('/api/v1/chat')).'" data-page="'.self::e($current).'"><h2>Ask about this documentation</h2><p>Questions and relevant excerpts are sent to OpenAI. Do not enter secrets.</p><div class="doc-chat-messages" role="log" aria-live="polite"></div><form class="doc-chat-form"><label for="doc-chat-question">Question</label><textarea id="doc-chat-question" maxlength="1500" rows="3" required></textarea><div class="actions"><button class="primary" type="submit">Ask</button><button type="button" data-chat-reset>Clear</button></div></form><div class="doc-chat-sources" aria-label="Documentation sources"></div></section>':'']);
    }
    private function json(mixed $data):void{header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public function run():void{
        $id=AuditLog::beginRequest();Observability::startRequest($id);
        try{$this->runRequest($id);}finally{Observability::finishRequest();}
    }
    private function runRequest(string $id):void{
        header('X-Request-ID: '.$id);header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'self'; script-src 'self' https://www.googletagmanager.com https://mc.yandex.ru https://mc.yandex.com https://yastatic.net; connect-src 'self' https://*.google-analytics.com https://*.yandex.ru https://*.yandex.com; style-src 'self'; img-src 'self' data: https:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        try{$path=RequestSecurity::path($_SERVER['REQUEST_URI']??'/');}catch(\InvalidArgumentException){http_response_code(400);return;}$method=$_SERVER['REQUEST_METHOD']??'GET';
        // Same-origin referrers preserve the Origin header on browser form POSTs; never disclose paths cross-origin.
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
        // Canonical unified route; preserve existing /studio/github bookmarks and forms.
        if(preg_match('~\A(?:/w/[a-f0-9]{32}/[a-f0-9]{32})?/studio/git\z~D',$path))$path=substr($path,0,-3).'github';
        WorkspaceUrls::set('');$this->documentHead='';$this->analyticsWorkspace=null;
        $ui=$_COOKIE['book_ui']??'en';$ui=Locale::valid($ui)?$ui:'en';
        if(in_array($method,['GET','HEAD'],true)&&Locale::valid($_GET['ui']??null)){$ui=$_GET['ui'];setcookie('book_ui',$ui,['expires'=>time()+31536000,'path'=>'/','secure'=>getenv('BOOK_SECURE_COOKIE')!=='0','httponly'=>true,'samesite'=>'Lax']);}
        Locale::setUi($ui);
        try{
            // A deployment with invalid security settings must not serve ordinary traffic.
            // Liveness remains independent of readiness/configuration.
            if($path!=='/health/live'){
                try{ReleaseHealth::configuration();}
                catch(\Throwable $e){
                    if($path!=='/health/ready')throw $e;
                    Observability::log('health.failure',['component'=>'configuration','error_type'=>get_class($e)]);http_response_code(503);$this->json(['status'=>'unavailable']);return;
                }
            }
            if($this->domainGate($path,$method))return;
            if(!RequestSecurity::browserPost($path,$method,$_SERVER,CustomDomains::primary())){http_response_code(403);header('X-Robots-Tag: noindex, nofollow');$this->json(['error'=>'Cross-origin form request denied']);return;}
            if(!$this->runtimeLimit($path,$method))return;
            if(preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/api/v1/chat\z~D',$path,$chat)){if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}$this->chatRoute($chat);return;}
            if(preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/api/v1/ci\z~D',$path,$ci)){if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}$this->ciApi($ci);return;}
            if(preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/webhooks/(github|gitlab)\z~D',$path,$hook)){if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}$this->receiveWebhook($hook);return;}
            if($method==='POST'&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/api/v1/playground\z~D',$path,$playground)){$this->playgroundProxy($playground);return;}
            // Run slow external AI calls after releasing the identity/workspace registry lock.
            if($method==='POST'&&in_array($_POST['action']??null,['gaps','migration','suggest'],true)&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/studio/assistant\z~D',$path,$assistant)){$this->editorAiExternal($assistant);return;}
            if($method==='POST'&&($_POST['action']??null)==='git-preview'&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/studio/openapi\z~D',$path,$git)){$this->githubPull($git,true);return;}
            if($method==='POST'&&in_array($_POST['action']??null,['preview','apply'],true)&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/studio/github\z~D',$path,$git)){$this->githubPull($git);return;}
            if(str_starts_with($path,'/w/')){
                if(!preg_match('~\A/w/([a-f0-9]{32})/([a-f0-9]{32})(/.*)?\z~D',$path,$m)){http_response_code(404);return;}
                $inner=$m[3]??'/';
                if(!preg_match('~\A/(?:$|(?:studio|docs|media|api/v1|search|playground|sdk|l|analytics/collect)(?:/|$))~D',$inner)){http_response_code(404);return;}
                $publicRead=(in_array($method,['GET','HEAD'],true)&&$inner!=='/studio'&&!str_starts_with($inner,'/studio/'))||($method==='POST'&&$inner==='/analytics/collect');
                $this->identityStore->withWorkspace($this->auth->token(),$m[1],$m[2],function($ctx,$w)use($inner,$method){
                    header('Cache-Control: private, no-store');header('Vary: Cookie');if(!$w||$ctx||Visibility::workspace($w)==='private')header('X-Robots-Tag: noindex, nofollow');
                    if(!$w){$this->unavailable($inner,$ctx,true);return;}
                    $this->workspace=$w;$this->analyticsWorkspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$w['role'];$this->readerVisibility=Visibility::workspace($w);
                    WorkspaceUrls::set(Workspaces::prefix($w));
                    $this->dataDir=Workspaces::directory($this->installationDir,$w);
                    $this->store=new BookStore($this->dataDir,false);$this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
                    try{$this->dispatch($inner,$method);}finally{WorkspaceUrls::set('');}
                },$publicRead);return;
            }
            if($path==='/studio'||str_starts_with($path,'/studio/')){
                $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($path,$method){$this->analyticsWorkspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';if($w)$this->useLegacyStore();$this->dispatch($path,$method);});return;
            }
            // Account, control-plane and health routes must remain reachable even when legacy content is private.
            if(in_array($path,['/login','/logout','/account','/account/users','/account/reset','/auth/oidc/start','/auth/oidc/callback','/auth/oidc/complete','/auth/oidc/backchannel-logout','/analytics','/workspaces','/domains','/organizations','/team','/audit','/health/live','/health/ready','/diagram-frame'],true)||str_starts_with($path,'/organizations/')){$this->dispatch($path,$method);return;}
            $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($path,$method){
                $this->analyticsWorkspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';if($w)$this->useLegacyStore();
                header('Cache-Control: private, no-store');header('Vary: Cookie');if($ctx||!$visible||$this->readerVisibility==='private')header('X-Robots-Tag: noindex, nofollow');
                if(!$visible){$this->unavailable($path,$ctx);return;}$this->dispatch($path,$method);
            },in_array($method,['GET','HEAD'],true));
        }catch(\Throwable $e){Observability::log('http.failure',['error_type'=>get_class($e)]);if(!headers_sent()){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');echo 'Service unavailable';}}
        finally{
            // A denial has no business transaction to join. Record a minimal, separate event after locks release.
            $status=http_response_code();if(getenv('BOOK_PG_DSN')&&$this->domainHost===null&&in_array($status,[401,403,429],true)&&preg_match('~\A(?:/w/[a-f0-9]{32}/[a-f0-9]{32})?/(?:studio|api/v1|webhooks|account|organizations|workspaces|domains|audit)(?:/|$)~D',$path)){
                try{$this->identityStore->auditFailure($status===429?'access.limited':'access.denied',$status===429?'limited':'denied');}catch(\Throwable){Observability::log('audit.failure');}
            }
        }
    }
    /** Strict host selection precedes all auth, CI, webhook and workspace dispatch. */
    private function domainGate(string $path,string $method):bool{
        $primary=CustomDomains::primary();if($primary===null){if($path==='/domains'||in_array($path,['/.well-known/book-domain-ask','/.well-known/book-domain-host'],true)){http_response_code(404);return true;}return false;}
        $raw=$_SERVER['HTTP_HOST']??'';$host=CustomDomains::requestHost($raw);$ip=$_SERVER['REMOTE_ADDR']??'';
        $uri=$_SERVER['REQUEST_URI']??'/';if(!is_string($uri)||!str_starts_with($uri,'/')||str_starts_with($uri,'//')||preg_match('/[\x00-\x20\x7f\\\\]/',$uri)){http_response_code(400);return true;}
        // Container health check uses loopback; TLS permission is accessible only directly from the gateway.
        if(in_array($raw,['127.0.0.1','127.0.0.1:80','localhost'],true)&&in_array($ip,['127.0.0.1','::1'],true)&&in_array($path,['/health/live','/health/ready'],true)&&in_array($method,['GET','HEAD'],true))return false;
        if(in_array($path,['/.well-known/book-domain-ask','/.well-known/book-domain-host'],true)){
            header('Cache-Control: no-store');header('Content-Type: text/plain; charset=utf-8');
            if($ip!==(getenv('BOOK_DOMAIN_ASK_IP')?:'172.30.44.10')||$method!=='GET'){http_response_code(404);return true;}
            if($path==='/.well-known/book-domain-ask'){if(!in_array($raw,['book','book:80'],true)){http_response_code(404);return true;}$ask=is_string($_GET['domain']??null)?CustomDomains::requestHost($_GET['domain']):null;}else $ask=$host;
            if($ask===null){http_response_code(403);return true;}
            $allowed=$ask===$primary||$this->identityStore->withDomain($ask,fn($w)=>$w!==null);http_response_code($allowed?204:($path==='/.well-known/book-domain-host'?421:403));return true;
        }
        if($host===null){http_response_code(421);return true;}
        if($host===$primary){return $this->requireDomainHttps($host,$method);}
        // The custom domain never consumes main-host credentials, including an injected cookie.
        // Capture the chat target while holding the domain-registry lock, then stream
        // outside it: network latency must never block identity or Workspace updates.
        $chatCapture=null;
        $this->identityStore->withDomain($host,function($w)use($host,$path,$method,&$chatCapture){
            if(!$w){http_response_code(421);header('X-Robots-Tag: noindex, nofollow');return;}
            if(!in_array($method,['GET','HEAD'],true)&&!($method==='POST'&&in_array($path,['/analytics/collect','/api/v1/chat'],true))){http_response_code(405);header('Allow: GET, HEAD');return;}
            if(!CustomDomains::readerPath($path)){http_response_code(404);header('X-Robots-Tag: noindex, nofollow');return;}
            if($this->requireDomainHttps($host,$method))return;
            $this->domainHost=$host;$this->domainCanonical=CustomDomains::canonical($w,$host);
            $this->workspace=$w+['role'=>'none','owner'=>false];$this->analyticsWorkspace=$w;$this->studioRole='none';$this->scopedIdentity=null;$this->readerVisibility='public';
            WorkspaceUrls::set('');$this->dataDir=Workspaces::directory($this->installationDir,$w);$this->store=new BookStore($this->dataDir,false);$this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
            if(!$this->runtimeLimit($path,$method))return;
            if($path==='/api/v1/chat'){
                $chatCapture=['dir'=>$this->dataDir,'scope'=>$w['organization'].'/'.$w['id']];
                return;
            }
            $this->dispatch($path,$method);
        });
        if($chatCapture!==null)$this->chatSend($chatCapture['dir'],$chatCapture['scope'],null,'none','public');
        return true;
    }
    private function requireDomainHttps(string $host,string $method):bool{
        $secret=getenv('BOOK_DOMAIN_EDGE_SECRET');$provided=$_SERVER['HTTP_X_BOOK_EDGE_SECRET']??'';
        // A direct TLS server or the authenticated private ingress may assert HTTPS.
        $direct=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
        $edge=is_string($secret)&&preg_match('/\A[a-f0-9]{64}\z/D',$secret)&&is_string($provided)&&hash_equals($secret,$provided)&&($_SERVER['HTTP_X_BOOK_EDGE_PROTO']??'')==='https';
        if($direct||$edge){header('Strict-Transport-Security: max-age=31536000');return false;}
        if(!in_array($method,['GET','HEAD'],true)){http_response_code(403);return true;}
        header('Location: https://'.$host.($_SERVER['REQUEST_URI']??'/'),true,308);return true;
    }
    private function canonicalUrl(string $path):string{if($this->domainCanonical===null)return $path;$prefix=WorkspaceUrls::prefix();if($prefix!==''&&str_starts_with($path,$prefix.'/'))$path=substr($path,strlen($prefix));return 'https://'.$this->domainCanonical.$path;}
    /** Shared ingress limits supplement (never replace) durable identity throttling. */
    private function runtimeLimit(string $path,string $method):bool{
        if(!RedisRuntime::enabled())return true;
        $inner=preg_replace('~\\A/w/[a-f0-9]{32}/[a-f0-9]{32}(?=/)~D','',$path);
        $policy=match(true){
            $method==='POST'&&$path==='/auth/oidc/start'=>['oidc-start',30,900,true],
            $path==='/auth/oidc/backchannel-logout'=>['oidc-logout',120,60,false],
            $path==='/auth/oidc/callback'=>['oidc-callback',60,60,false],
            $method==='POST'&&$path==='/login'=>['login',30,900,true],
            $method==='POST'&&$inner==='/analytics/collect'=>['analytics',180,60,false],
            $method==='POST'&&$inner==='/api/v1/chat'=>['chat',30,60,false],
            $method==='POST'&&$path==='/account/reset'=>['reset',30,900,true],
            $method==='POST'&&$path==='/account'&&($_POST['action']??null)==='password'=>['password',30,900,true],
            $inner==='/api/v1/ci'=>['ci',120,60,false],
            str_starts_with($inner,'/webhooks/')=>['webhook',300,60,false],
            str_starts_with($inner,'/api/v1/')=>['api',300,60,false],
            $inner==='/search'=>['search',120,60,false],
            default=>null};
        if(!$policy)return true;
        [$bucket,$max,$window,$durable]=$policy;
        try{$r=RedisRuntime::forRoot($this->installationDir)->limit($bucket,RedisRuntime::clientIp(),$max,$window);}
        catch(\Throwable $e){if($durable)return true;http_response_code(503);header('Retry-After: 2');$this->json(['error'=>'Rate limiter unavailable']);return false;}
        if($r['allowed'])return true;
        http_response_code(429);header('Retry-After: '.$r['retry']);
        if($durable||$bucket==='search')$this->page('Too many requests','<h1>Too many requests</h1><p>Try again after '.(int)$r['retry'].' seconds.</p>');
        else $this->json(['error'=>'Too many requests']);return false;
    }
    private function renderPublished(array $p,string $key,array $db,array $pages):array{
        $links=Visibility::links($db,$pages);$base=$p['link_base']??null;
        $render=fn()=>Markdown::render($p['body'],$key,false,false,$base,$links);
        if($this->readerVisibility!=='public'||Permissions::allows($this->studioRole,'read'))return $render();
        $redis=RedisRuntime::forRoot($this->installationDir);
        // Exact published inputs, including visible redirects and URL scope, invalidate naturally.
        return $redis?$redis->remember('markdown',[WorkspaceUrls::prefix(),$key,$p['body'],$base,$links],$render):$render();
    }
    /** Complete authorization under the identity lock, release it BEFORE calling the model. */
    private function chatRoute(array $route):void {
        $token=$this->auth->token();
        if(!empty($route[1])){
            $capture=$this->identityStore->withWorkspace($token,$route[1],$route[2],function($ctx,$w){
                if(!$w)return null;
                return ['dir'=>Workspaces::directory($this->installationDir,$w),'scope'=>$w['organization'].'/'.$w['id'],'ctx'=>$ctx,'role'=>$w['role'],'visibility'=>Visibility::workspace($w)];
            },true);
        }else{
            $capture=$this->identityStore->withLegacy($token,function($ctx,$role,$w,$visible){
                if(!$visible)return null;
                return ['dir'=>$w?Workspaces::directory($this->installationDir,$w):$this->installationDir,'scope'=>'legacy','ctx'=>$ctx,'role'=>$role,'visibility'=>$w?Visibility::workspace($w):'public'];
            },true);
        }
        if(!$capture){http_response_code(404);$this->json(['error'=>'Chat is unavailable for this documentation.']);return;}
        $this->chatSend($capture['dir'],$capture['scope'],$capture['ctx'],$capture['role'],$capture['visibility']);
    }
    private static function sse(string $event,array $data):void {
        echo 'event: '.$event."\n".'data: '.json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
        if(ob_get_level())@ob_flush();flush();
    }
    /** Stream validated OpenAI text deltas; no upstream API key, headers or unchecked links reach clients. */
    private function chatSend(string $dir,string $scope,?array $ctx,string $role,string $visibility):void {
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Vary: Cookie');header('Referrer-Policy: no-referrer');
        $fail=function(int $status,string $message):void{http_response_code($status);$this->json(['error'=>$message]);};
        if(!DocumentationChat::enabled()){$fail(503,'Documentation chat is not configured.');return;}
        if(!isset($_SERVER['HTTP_ORIGIN'])||!RequestSecurity::browserPost('/api/v1/chat','POST',$_SERVER,$this->domainHost??CustomDomains::primary())){$fail(403,'Same-origin browser request required.');return;}
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json'){$fail(415,'JSON request required.');return;}
        $raw=file_get_contents('php://input',false,null,0,16385);
        if(!is_string($raw)||strlen($raw)>16384){$fail(413,'Chat request too large.');return;}
        try{
            $input=DocumentationChat::input($raw);
            if($visibility==='private'&&!DocumentationChat::privateEnabled()){$fail(403,'Private documentation chat is disabled.');return;}
            $ip=is_string($_SERVER['REMOTE_ADDR']??null)?$_SERVER['REMOTE_ADDR']:'unknown';
            $actor=ChatQuota::identity($ctx?'user:'.$ctx['user']['id']:'guest:'.$ip);
            // Reserve the worst-case retrieval budget BEFORE hybrid search can invoke embeddings.
            $reserve=ChatQuota::reservation($input['question'],$input['history'],[['text'=>str_repeat('x',18000)]]);
            if(!ChatQuota::reserve($this->installationDir,$scope?:'legacy',$actor,$reserve)){header('Retry-After: 3600');$fail(429,'Daily chat quota exceeded.');return;}
            $store=new BookStore($dir,false);
            $prefix=$scope==='legacy'?'':'/w/'.$scope;
            if($this->domainHost!==null)$prefix='';
            $sources=DocumentationChat::sources($store,$visibility,$role,$input['question'],$input['page'],$prefix);
            if(!$sources){$fail(404,'No accessible documentation sources were found.');return;}
            $messages=DocumentationChat::modelInput($input,$sources);
            // Do not start the browser SSE response until the API accepted the request.
            $upstream=OpenAiResponses::open($messages);
        }catch(\JsonException|\InvalidArgumentException $e){$fail(422,'Invalid chat request.');return;}
        catch(\DomainException $e){$fail(404,'Page is not available for chat.');return;}
        catch(\Throwable $e){Observability::log('chat.failed',['error_type'=>get_class($e)]);$fail(502,'Chat provider or documentation search is unavailable.');return;}
        header('Content-Type: text/event-stream; charset=utf-8');header('X-Accel-Buffering: no');header('Content-Encoding: identity');
        if(function_exists('set_time_limit'))@set_time_limit(120);
        self::sse('sources',['items'=>array_map(static fn($s)=>array_diff_key($s,['text'=>true]),$sources)]);
        try{
            $bytes=0;
            $usage=OpenAiResponses::consume($upstream,static function(string $delta)use(&$bytes):void{
                $bytes+=strlen($delta);
                if($bytes>32768)throw new \RuntimeException('Chat output safety limit reached.');
                self::sse('delta',['text'=>$delta]);
                if(connection_aborted())throw new \RuntimeException('Client disconnected.');
            });
            self::sse('done',['usage'=>['input_tokens'=>(int)($usage['input_tokens']??0),'output_tokens'=>(int)($usage['output_tokens']??0)]]);
        }catch(\Throwable $e){Observability::log('chat.stream_failed',['error_type'=>get_class($e)]);self::sse('error',['message'=>'Answer stream interrupted. Please try again.']);}
    }
    private function ciApi(array $route):void{
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');header('Vary: Authorization');
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json'){http_response_code(415);$this->json(['error'=>'JSON required']);return;}
        $raw=file_get_contents('php://input',false,null,0,12*1024*1024+1);if(!is_string($raw)||strlen($raw)>12*1024*1024){http_response_code(413);$this->json(['error'=>'CI request too large']);return;}
        $authorization=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/\ABearer (cbci_[a-f0-9]{32}\.[a-f0-9]{64})\z/D',$authorization,$m)){http_response_code(401);header('WWW-Authenticate: Bearer');$this->json(['error'=>'CI bearer token required']);return;}
        try{$input=json_decode($raw,true,48,JSON_THROW_ON_ERROR);if(!is_array($input))throw new \InvalidArgumentException('Invalid CI request.');
            if(($input['action']??null)==='export'){$this->ciExport($route,$m[1],$input);return;}
            $result=$this->identityStore->automationWorkspace($route[1]??'',$route[2]??'',function($w,$admins)use($input,$m){$dir=$w?Workspaces::directory($this->installationDir,$w):$this->installationDir;$store=new BookStore($dir,$w===null);return CiController::execute($store,new MediaStore($dir,$store),$admins,$m[1],$input);});
            if($result===null){http_response_code(404);$this->json(['error'=>'Workspace unavailable']);return;}$this->json($result);
        }catch(OrganizationAccessException $e){http_response_code(403);$this->json(['error'=>'CI authorization denied']);}
        catch(\JsonException|\InvalidArgumentException $e){http_response_code(422);$this->json(['error'=>$e->getMessage()]);}
        catch(\DomainException $e){http_response_code(409);$this->json(['error'=>$e->getMessage()]);}
    }
    /** Build under identity/store locks; stream the completed immutable artifact after releasing them. */
    private function ciExport(array $route,string $credential,array $input):void {
        $id=$input['id']??null;$digest=$input['digest']??null;
        if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id)||!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest))throw new \InvalidArgumentException('Invalid release ID or digest.');
        $temp=sys_get_temp_dir().'/.book-ci-export-'.bin2hex(random_bytes(16));
        if(!mkdir($temp,0700))throw new \RuntimeException('Cannot allocate release export');
        $target=$temp.'/site.zip';$abort=ignore_user_abort(true);
        try{
            $result=$this->identityStore->automationWorkspace($route[1]??'',$route[2]??'',function($w,$admins)use($credential,$id,$digest,$target){
                $dir=$w?Workspaces::directory($this->installationDir,$w):$this->installationDir;
                $store=new BookStore($dir,$w===null);$media=new MediaStore($dir,$store);
                $visibility=$w?Visibility::workspace($w):'public';
                WorkspaceUrls::set($w?Workspaces::prefix($w):'');
                try{$result=ReleaseArtifact::create($this->root,$store,$media,$admins,$credential,$visibility,$id,$digest,$target,CustomDomains::publicOrigin($w));$token=CiPublishing::authenticate($store->read(),$credential,$admins);$store->auditEvent('export.ci',$admins[$token['actor']['id']],$id);return $result;}
                finally{WorkspaceUrls::set('');}
            });
            if($result===null){http_response_code(404);$this->json(['error'=>'Workspace unavailable']);return;}
            $size=filesize($target);$hash=hash_file('sha256',$target);
            if($size===false||$hash===false)throw new \RuntimeException('Cannot read release artifact');
            header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="cajeer-release-'.$id.'.zip"');
            header('Content-Length: '.$size);header('X-Book-Artifact-SHA256: '.$hash);
            readfile($target);
        }finally{if(is_file($target))unlink($target);rmdir($temp);ignore_user_abort((bool)$abort);}
    }
    private function receiveWebhook(array $route):void{
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: no-store');
        $body=file_get_contents('php://input',false,null,0,1048577);
        if(!is_string($body)||strlen($body)>1048576){http_response_code(413);$this->json(['error'=>'Webhook body too large']);return;}
        if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json'){http_response_code(415);$this->json(['error'=>'JSON required']);return;}
        $provider=$route[3];$headers=$provider==='github'?
            ['signature'=>$_SERVER['HTTP_X_HUB_SIGNATURE_256']??'','event'=>$_SERVER['HTTP_X_GITHUB_EVENT']??'','delivery'=>$_SERVER['HTTP_X_GITHUB_DELIVERY']??'']:
            ['signature'=>$_SERVER['HTTP_X_GITLAB_TOKEN']??'','event'=>$_SERVER['HTTP_X_GITLAB_EVENT']??'','delivery'=>$_SERVER['HTTP_X_GITLAB_EVENT_UUID']??$_SERVER['HTTP_IDEMPOTENCY_KEY']??$_SERVER['HTTP_X_GITLAB_WEBHOOK_UUID']??''];
        try{
            $result=$this->identityStore->automationWorkspace($route[1]??'',$route[2]??'',function($w,$admins)use($provider,$body,$headers){
                $dir=$w?Workspaces::directory($this->installationDir,$w):$this->installationDir;$store=new BookStore($dir,$w===null);$result=null;
                $store->change(function(&$db)use($provider,$body,$headers,$admins,&$result){$result=AutomationQueue::receive($db,$provider,$body,$headers,$admins);});return $result;
            });
            if($result===null){http_response_code(404);$this->json(['error'=>'Webhook unavailable']);return;}
            http_response_code($result['status']==='queued'?202:200);$this->json($result);
        }catch(OrganizationAccessException $e){http_response_code(403);$this->json(['error'=>'Webhook authentication failed']);}
        catch(\JsonException|\InvalidArgumentException $e){http_response_code(422);$this->json(['error'=>'Invalid webhook payload']);}
        catch(\DomainException $e){http_response_code(409);$this->json(['error'=>'Webhook unavailable or queue conflict; retry later']);}
    }
    /** Capture a visible published definition under the identity lock, execute externally, then reauthorize the response. */
    private function playgroundProxy(array $route):void {
        header('Cache-Control: private, no-store');header('Vary: Cookie');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        $fail=function(int $status,string $message){http_response_code($status);$this->json(['error'=>$message]);return null;};
        $key=is_string($_POST['key']??null)?$_POST['key']:'';$binding=is_string($_POST['binding']??null)?$_POST['binding']:'';$csrf=is_string($_POST['csrf']??null)?$_POST['csrf']:'';$raw=is_string($_POST['payload']??null)?$_POST['payload']:'';
        if(strlen($key)>380||!preg_match('/\A[a-f0-9]{64}\z/D',$binding)||strlen($raw)>524288){$fail(400,'Invalid Playground request.');return;}
        try{$input=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}catch(\JsonException){$fail(400,'Invalid Playground payload.');return;}
        if(!is_array($input)){$fail(400,'Invalid Playground payload.');return;}
        $with=function(callable $fn)use($route){
            if(!empty($route[1]))return $this->identityStore->withWorkspace($this->auth->token(),$route[1],$route[2],function($ctx,$w)use($fn){return $fn($ctx,$w['role']??'none',$w?Visibility::workspace($w):'private',$w?Workspaces::directory($this->installationDir,$w):null);});
            return $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($fn){return $fn($ctx,$role,$w?Visibility::workspace($w):'public',$visible?$this->installationDir:null);});
        };
        $authorize=function($ctx,$role,$visibility,$dir)use($key,$binding,$csrf,$fail){
            if(!$ctx)return $fail(401,'Sign in to use managed proxy.');
            if(!$dir||!Permissions::allows($role,'read'))return $fail(404,'Operation not available.');
            if(!hash_equals($ctx['session']['csrf'],$csrf))return $fail(403,'Invalid CSRF token.');
            $store=new BookStore($dir,false);$db=$store->read();$pages=Visibility::pages($db,$visibility,$role);$d=ApiPlayground::published($db,$pages,$key);
            if(!$d)return $fail(404,'Published Playground operation not available.');
            if(!hash_equals(ApiPlaygroundView::binding($key,$pages[$key],$d),$binding))return $fail(409,'Published operation changed. Reload Playground.');
            return ['definition'=>$d,'user'=>$ctx['user']['id']];
        };
        try{
            $capture=$with($authorize);if(!$capture)return;
            if(!in_array($capture['definition']['method'],['GET','HEAD','OPTIONS'],true)&&($input['confirm']??null)!==true){$fail(422,'Confirm that this request may modify API data.');return;}
            $request=ApiPlayground::request($capture['definition'],$input,ApiPlayground::origins('BOOK_PLAYGROUND_PROXY_ORIGINS'),true);
            if(!ApiPlaygroundTransport::limit($this->installationDir,$capture['user'])){$fail(429,'Playground rate limit reached.');return;}
            $result=ApiPlaygroundTransport::send($request);
            // A revoked user cannot retrieve a response after an in-flight external operation.
            $current=$with($authorize);if(!$current)return;if($current['user']!==$capture['user']){$fail(403,'Session changed.');return;}
            $this->json($result);
        }catch(\InvalidArgumentException $e){$fail(422,$e->getMessage());}
        catch(\Throwable){$fail(502,'API request failed or timed out. Check the approved server and TLS configuration.');}
    }
    /** Two-phase pull: capture authorization, fetch immutable Git data outside the identity lock,
     * then reauthorize and apply under the same lock as all Studio mutations. */
    private function githubPull(array $route,bool $spec=false):void{
        $studioPath=$spec?'/studio/openapi':'/studio/github';
        $action=$_POST['action'];$str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
        $scoped=isset($route[1])&&$route[1]!=='';
        $with=function(callable $fn)use($route,$scoped,$studioPath){
            if($scoped)return $this->identityStore->withWorkspace($this->auth->token(),$route[1],$route[2],function($ctx,$w)use($fn,$studioPath){
                if(!$w){$this->unavailable($studioPath,$ctx,true);return null;}
                $this->workspace=$w;$this->analyticsWorkspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$w['role'];$this->readerVisibility=Visibility::workspace($w);WorkspaceUrls::set(Workspaces::prefix($w));
                $this->dataDir=Workspaces::directory($this->installationDir,$w);$this->store=new BookStore($this->dataDir,false);$this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);return $fn();
            });
            return $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($fn){$this->analyticsWorkspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';if($w)$this->useLegacyStore();return $fn();});
        };
        try{
            $capture=$with(function()use($action,$str,$studioPath){
                if(!$this->scopedIdentity||!Permissions::studio($this->studioRole,$studioPath,'POST',$action)||!hash_equals($this->scopedIdentity['session']['csrf'],$str('csrf'))){$this->studio($studioPath,'POST');return null;}
                $csrf=$this->scopedIdentity['session']['csrf'];return ['user'=>$this->scopedIdentity['user']['id'],'db'=>$this->store->read(),'secret'=>MarkdownImport::previewSecret($this->installationDir,$csrf),'dir'=>$this->dataDir,'prefix'=>WorkspaceUrls::prefix()];
            });
            if($capture===null)return;
            try{$sync=new GitHubSync($this->store,new MarkdownImport($this->store,$this->media),$this->githubClientFactory,$capture['dir'].'/github-cache');if($spec)$this->openApiPrepared=$sync->downloadSpecification($capture['db'],$str('folder'),$str('root'));else $this->githubPrepared=$sync->download($capture['db'],$capture['secret'],$action==='apply'?$str('preview_token'):null,$str('commit'),$str('resolution'));}
            catch(\InvalidArgumentException|\DomainException $e){if($spec)$this->openApiError=$e->getMessage();else $this->githubError=$e->getMessage();if(!$spec&&$e instanceof GitHubRemoteException&&isset($capture['db']['github']))$this->githubFailure=$capture['db']['github'];}
            $with(function()use($capture,$studioPath){
                if($this->scopedIdentity&&$this->scopedIdentity['user']['id']!==$capture['user']){$this->unavailable($studioPath,null,true);return;}
                $this->studio($studioPath,'POST');
            });
        }finally{WorkspaceUrls::set('');$this->githubPrepared=null;$this->githubError=null;$this->githubFailure=null;$this->openApiPrepared=null;$this->openApiError=null;}
    }
    private function useLegacyStore():void{
        $this->store=new BookStore($this->installationDir,false);$this->media=new MediaStore($this->installationDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
    }
    private function unavailable(string $path,?array $ctx,bool $scoped=false):void{
        http_response_code($ctx?404:401);header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: private, no-store');
        if(str_starts_with($path,'/api/')||($_SERVER['HTTP_X_BOOK_STUDIO']??'')==='editor'||($_SERVER['HTTP_X_BOOK_MEDIA']??'')==='1'){$this->json(['error'=>'Documentation unavailable. Sign in or request access.']);return;}
        if(str_starts_with($path,'/media/')){echo 'Media not found';return;}
        $label=$scoped?'Workspace unavailable':'Documentation unavailable';$this->page($label,'<h1>'.$label.'</h1><p><a href="/login">Sign in</a> or <a href="/workspaces">choose a workspace</a>.</p>');
    }
    private function search(bool $api):void
    {
        try{$o=Search::options($_GET);}catch(\InvalidArgumentException $e){http_response_code(422);if($api)$this->json(['error'=>$e->getMessage()]);else $this->page('Search','<h1>Search documentation</h1><p>'.self::e($e->getMessage()).'</p>');return;}
        $r=$this->store->search($this->readerVisibility,$this->studioRole,$o,!$api);
        $hits=$r['hits'];foreach($hits as &$hit)$hit['url']=self::url($hit['key']);unset($hit);
        if($api){header('X-Total-Count: '.$r['total']);header('X-Search-Engine: '.$r['engine']);header('X-Search-Match: '.$r['match']);$this->json($hits);return;}
        $html='<div class="eyebrow">'.self::t('Search').'</div><h1>'.self::t('Search documentation').'</h1><form method="get" action="'.self::e(WorkspaceUrls::url('/search')).'" class="editor"><label>'.self::t('Search').' <input name="q" maxlength="200" value="'.self::e($o['q']).'"></label><label>'.self::t('Book').' <input name="book" maxlength="64" value="'.self::e($o['book']).'"></label><label>'.self::t('Version').' <input name="version" maxlength="64" value="'.self::e($o['version']).'"></label><label>'.self::t('Language').'<select name="locale">'.Locale::options($o['locale'],true).'</select></label><button class="primary">'.self::t('Search').'</button></form><p>'.(Locale::ui()==='ru'?self::t('Results').': '.$r['total']:$r['total'].' results').'</p>'.($r['match']==='similar'?'<p>'.self::t('Similar titles or paths (no full-text matches).').'</p>':'');
        foreach($hits as $hit)$html.='<div class="result" lang="'.self::e($hit['locale']).'"><h2>'.self::link($hit['url'],$hit['title']).'</h2><small>'.self::e($hit['key'].' · '.Locale::LANGUAGES[$hit['locale']]).'</small><p>'.self::e($hit['snippet']).'</p></div>';
        $params=['q'=>$o['q'],'book'=>$o['book'],'version'=>$o['version'],'locale'=>$o['locale'],'limit'=>$o['limit']];
        $url=static fn(int $offset)=>'/search?'.http_build_query($params+['offset'=>$offset],'','&',PHP_QUERY_RFC3986);
        $html.='<div class="pager">';if($o['offset']>0)$html.=self::link($url(max(0,$o['offset']-$o['limit'])),'← '.Locale::t('Previous'));if($o['offset']+$o['limit']<$r['total'])$html.=self::link($url($o['offset']+$o['limit']),Locale::t('Next').' →');$html.='</div>';
        $this->page(Locale::t('Search'),$html,$r['pages'],'','',$o['q']);
    }
    private function searchInspector():void
    {
        $html='<div class="eyebrow">STUDIO / SEARCH</div><h1>Search Inspector</h1><p>'.self::link('/studio','Back to Studio').' · '.self::link('/search','Open Reader search').'</p>';
        try{$info=$this->store->searchInfo();}catch(\RuntimeException $e){Observability::log('health.failure',['component'=>'search','error_type'=>get_class($e)]);http_response_code(503);$this->page('Search Inspector',$html.'<p role="alert">Search index unavailable. Stop writers and run <code>php bin/search.php reindex --offline</code>, then <code>php bin/search.php check</code>.</p>');return;}
        $html.='<p>Engine: '.self::e($info['engine']).' · Published documents: '.$info['documents'].($info['revision']!==null?' · Indexed revision: '.$info['revision']:' · File mode: no persistent index').'</p>';
        try{$o=Search::options($_GET);$audience=$_GET['audience']??'member';if(!in_array($audience,['member','public'],true))throw new \InvalidArgumentException('Invalid search audience.');}catch(\InvalidArgumentException $e){http_response_code(422);$this->page('Search Inspector',$html.'<p>'.self::e($e->getMessage()).'</p>');return;}
        $html.='<form method="get" action="'.self::e(WorkspaceUrls::url('/studio/search')).'" class="editor"><label>Query<input name="q" maxlength="200" value="'.self::e($o['q']).'"></label><label>Audience<select name="audience"><option value="member"'.($audience==='member'?' selected':'').'>Authorized member</option><option value="public"'.($audience==='public'?' selected':'').'>Anonymous public Reader</option></select></label><label>Book<input name="book" value="'.self::e($o['book']).'"></label><label>Version<input name="version" value="'.self::e($o['version']).'"></label><label>'.self::t('Language').'<select name="locale">'.Locale::options($o['locale'],true).'</select></label><button class="primary">Inspect query</button></form>';
        if($o['groups']){$r=$this->store->search($this->readerVisibility,$audience==='public'?'none':$this->studioRole,$o);$html.='<p>'.$r['total'].' results · '.self::e($r['match']).' · showing up to '.$o['limit'].'</p>';foreach($r['hits'] as $h)$html.='<div class="result">'.self::link(self::url($h['key']),$h['title']).' <small>Score: '.self::e((string)$h['score']).'</small><p>'.self::e($h['snippet']).'</p></div>';}
        $this->page('Search Inspector',$html);
    }
    private function audit(string $method):void{
        header('Cache-Control: private, no-store');header('Vary: Cookie');header('X-Robots-Tag: noindex, nofollow');
        if($method!=='GET'&&$method!=='POST'){http_response_code(405);header('Allow: GET, POST');return;}
        try{
            $filters=AuditLog::filters($method==='POST'?$_POST:$_GET);
            $format=$method==='POST'?($_POST['format']??''):'';
            if($method==='POST'&&!in_array($format,['csv','jsonl'],true))throw new \InvalidArgumentException('Unknown export format.');
            $r=$this->identityStore->enterpriseAudit($this->auth->token(),$filters,$method==='POST'?5000:50,$method==='POST',is_string($_POST['csrf']??null)?$_POST['csrf']:'');
            if($method==='POST'){
                $data=AuditView::encode($r['events'],$format);header('Content-Type: '.($format==='csv'?'text/csv; charset=utf-8':'application/x-ndjson; charset=utf-8'));
                header('Content-Disposition: attachment; filename="book-audit.'.$format.'"');header('X-Audit-Count: '.count($r['events']));if($r['next']!==null)header('X-Audit-Next-Cursor: '.$r['next']);echo $data;return;
            }
            $this->page('Enterprise Audit Log',AuditView::render($r,$filters));
        }catch(OrganizationAccessException $e){http_response_code(403);$this->page('Forbidden','<h1>Audit permission required</h1>');}
        catch(\InvalidArgumentException|\DomainException $e){http_response_code(422);$this->page('Audit unavailable','<h1>Audit unavailable</h1><p>'.self::e($e->getMessage()).'</p>');}
    }
    private function dispatch(string $path,string $method):void{
        try{
            if($path==='/diagram-frame'){DiagramFrame::serve($method);return;}
            if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
            if(in_array($path,['/login','/logout','/account','/account/users','/account/reset','/auth/oidc/start','/auth/oidc/callback','/auth/oidc/complete','/auth/oidc/backchannel-logout'],true)){$this->auth->dispatch($path,$method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/audit'){$this->audit($method);return;}
            if($path==='/analytics'){(new AnalyticsController($this->identityStore,$this->auth,$this->installationDir))->dispatch($method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/analytics/collect'){$this->collectAnalytics($method);return;}
            if($path==='/team'){
                header('Cache-Control: private, no-store');header('Vary: Cookie');header('X-Robots-Tag: noindex, nofollow');
                try{$mark=$method==='POST'?(is_string($_POST['id']??null)?$_POST['id']:''):null;if($method==='POST'&&($_POST['action']??'')!=='read')throw new \InvalidArgumentException('Unknown team action');
                    $ctx=$this->identityStore->team($this->auth->token(),$mark,is_string($_POST['csrf']??null)?$_POST['csrf']:null,is_string($_GET['workspace_page']??null)?(int)$_GET['workspace_page']:1);
                    if(!$ctx){$this->unavailable($path,null);return;}if($method==='POST'){header('Location: /team',true,303);return;}$this->page('Team Studio',TeamView::global($ctx,$_GET));
                }catch(OrganizationAccessException|\InvalidArgumentException $e){http_response_code($e instanceof OrganizationAccessException?403:422);$this->page('Team request failed','<h1>Team request failed</h1><p>'.self::e($e->getMessage()).'</p><a href="/team">Return to Team Studio</a>');}return;
            }
            if($path==='/domains'){(new DomainController($this->identityStore,$this->auth))->dispatch($method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/workspaces'){(new WorkspaceController($this->identityStore,$this->auth))->dispatch($method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/organizations'||str_starts_with($path,'/organizations/')){(new OrganizationController($this->identityStore,$this->auth))->dispatch($path,$method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/studio'||str_starts_with($path,'/studio/')){$this->studio($path,$method);return;}
            if($method==='POST'){http_response_code(405);header('Allow: GET, HEAD');return;}
            if(str_starts_with($path,'/media/')){$this->mediaController->serve(substr($path,7),false,$method,fn($db,$id)=>Visibility::media($db,$id,$this->readerVisibility,$this->studioRole),[$this->readerVisibility,$this->studioRole]);return;}
            if($path==='/health/live'){$this->json(['status'=>'ok']);return;}
            if($path==='/health/ready'){
                try { $this->identityStore->checkReady(); $this->identityStore->checkWorkspaceStores($this->installationDir); ReleaseHealth::check($this->root); RedisRuntime::forRoot($this->installationDir)?->check(); $this->json(['status'=>'ok']); }
                catch(\Throwable $e){Observability::log('health.failure',['component'=>'readiness','error_type'=>get_class($e)]);http_response_code(503);$this->json(['status'=>'unavailable']);}
                return;
            }
            if($path==='/search'||$path==='/api/v1/search'){$this->search($path==='/api/v1/search');return;}
            if(str_starts_with($path,'/api/v1/pages/')){
                $key=substr($path,14);$result=$this->store->publishedPage($key,$this->readerVisibility,$this->studioRole);
                if($result['page']===null){if($result['redirect']!==null){$this->redirect('/api/v1/pages/'.$result['redirect']);return;}http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->json(['error'=>'not_found']);return;}
                $p=$result['page'];$this->json(array_diff_key($p,['link_base'=>true,'playground'=>true,'source_digest'=>true,'translation_group'=>true])+['locale'=>Locale::language($p)]);return;
            }
            $db=in_array($path,['/sdk','/playground'],true)?$this->store->read():$this->store->readerSnapshot($this->readerVisibility,$this->studioRole,str_starts_with($path,'/docs/')?substr($path,6):null,$path==='/api/v1/pages');$pages=Visibility::pages($db,$this->readerVisibility,$this->studioRole);
            if($this->domainHost!==null&&$path==='/robots.txt'){header('Content-Type: text/plain; charset=utf-8');echo 'User-agent: *'."\n".'Allow: /'."\n".'Sitemap: https://'.$this->domainCanonical.'/sitemap.xml'."\n";return;}
            if($this->domainHost!==null&&$path==='/sitemap.xml'){if($this->domainCanonical!==$this->domainHost){header('Location: https://'.$this->domainCanonical.'/sitemap.xml',true,302);return;}header('Content-Type: application/xml; charset=utf-8');echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($pages as $key=>$p)echo '<url><loc>'.htmlspecialchars($this->canonicalUrl('/docs/'.$key),ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';echo '</urlset>';return;}
            if(preg_match('~\A/l/(en|ru)/docs/(.+)\z~D',$path,$localized)){
                $key=$localized[2];if(!isset($pages[$key]))$key=PageTree::redirect($db,$key)??$key;
                $variants=Locale::variants($pages,$key);$target=$variants[$localized[1]]??null;
                // Language selection is mutable, so never cache a permanent redirect.
                if($target===null&&($_GET['fallback']??null)==='1'&&isset($pages[$key]))$target=$key;
                if($target!==null){header('Location: '.self::url($target),true,302);return;}
                http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->page(Locale::t('Page not found'),'<h1>'.self::t('Page not found').'</h1><p>'.self::t('This page is missing or not published.').'</p>',$pages);return;
            }

            if($path==='/sdk'){
                $key=is_string($_GET['key']??null)?$_GET['key']:'';$language=is_string($_GET['lang']??null)?$_GET['lang']:'typescript';
                header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
                $model=SdkBuilder::collect($db,$pages,$key);
                if(!$model){http_response_code(404);$this->page('SDK unavailable','<h1>SDK unavailable</h1><p>Choose a supported published API operation.</p>',$pages);return;}
                if(!in_array($language,['curl',...SdkBuilder::LANGUAGES],true))throw new \InvalidArgumentException('Unsupported SDK language.');
                if(($_GET['download']??null)==='1'){
                    if(!in_array($language,SdkBuilder::LANGUAGES,true))throw new \InvalidArgumentException('Unsupported SDK language.');
                    $digest=is_string($_GET['digest']??null)?$_GET['digest']:'';
                    if(!hash_equals($model['version'],$digest))throw new \DomainException('Published SDK changed. Reload the SDK page.');
                    $zip=SdkBuilder::archive($model,$language);header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="cajeer-sdk-'.$language.'-'.substr($model['version'],0,16).'.zip"');header('Content-Length: '.strlen($zip));if($method!=='HEAD')echo $zip;return;
                }
                $index=static function(string $name):int{$v=$_GET[$name]??'0';if(!is_string($v)||!preg_match('/\A(?:0|[1-9][0-9]?)\z/D',$v))throw new \InvalidArgumentException('Invalid SDK example selection.');return (int)$v;};
                $this->page('SDK & Code Examples',SdkView::markup($model,$key,$language,$index('server'),$index('security')),$pages,$key);return;
            }
            if($path==='/playground'){
                $key=is_string($_GET['key']??null)?$_GET['key']:'';$d=ApiPlayground::published($db,$pages,$key);
                if(!$d){http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->page('Playground unavailable','<h1>Playground unavailable</h1><p>Choose a supported published API operation.</p>',$pages);return;}
                header('Content-Security-Policy: '.ApiPlaygroundView::csp($d));header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
                $this->page('API Playground',ApiPlaygroundView::markup($key,$pages[$key],$d,$this->scopedIdentity,$this->studioRole),$pages,$key);return;
            }
            if($path==='/api/v1/pages'){$this->json(array_map(fn($p)=>array_diff_key($p,['body'=>true,'link_base'=>true,'playground'=>true,'source_digest'=>true,'translation_group'=>true])+['locale'=>Locale::language($p)],$pages));return;}


            if($path==='/'){
                $locale=$_GET['locale']??'';if($locale!=='')Locale::require($locale);$visible=Locale::pages($pages,$locale);$books=BookStore::books($db);
                $html='<div class="eyebrow">CAJEERENGINE BOOK / '.self::t('Documentation').'</div><h1>'.self::t('Documentation, on your terms.').'</h1><p class="lead">'.self::t('A self-hosted knowledge base for your products, APIs and teams.').'</p><form method="get" class="actions"><label>'.self::t('Language').' <select name="locale">'.Locale::options($locale,true).'</select></label><button>'.self::t('Documentation').'</button></form><div class="cards">';$seen=[];
                foreach($visible as $key=>$p){[$b,$v]=explode('/',$key);$lang=Locale::language($p);$group="$b/$v/$lang";if(isset($seen[$group]))continue;$seen[$group]=true;$html.='<div class="tile"><div class="eyebrow">'.self::e($v.' · '.Locale::LANGUAGES[$lang]).'</div><h2>'.self::e($books[$b]['title']??$b).'</h2>'.self::link(self::url($key),Locale::t('Read documentation →')).'</div>';}
                $this->page(Locale::t('Documentation'),$html.'</div>',$visible);return;
            }
            if(str_starts_with($path,'/docs/')){
                $key=substr($path,6);
                if(isset($pages[$key])){
                    $p=$pages[$key];
                    if($this->domainHost===null&&$this->workspace&&CustomDomains::primary()!==null&&$this->readerVisibility==='public'&&Visibility::publicBook(BookStore::books($db)[explode('/',$key,2)[0]]??[],'public')){$preferred=CustomDomains::canonical($this->workspace,'');if($preferred!=='')$this->domainCanonical=$preferred;}
                    $lang=Locale::language($p);header('Content-Language: '.$lang);
                    $r=$this->renderPublished($p,$key,$db,$pages);$toc='<div class="side-label">'.self::t('On this page').'</div>';foreach($r['toc'] as $h)$toc.=self::link('#'.$h['id'],$h['title'],'toc-level-'.$h['level']);
                    [$b,$v]=explode('/',$key);$prefix="$b/$v/";$localizedPages=Locale::pages($pages,$lang);$siblings=array_filter($localizedPages,fn($k)=>str_starts_with($k,$prefix),ARRAY_FILTER_USE_KEY);
                    $keys=array_keys($siblings);$i=array_search($key,$keys,true);$pager='<div class="pager">';if($i>0)$pager.=self::link(self::url($keys[$i-1]),'← '.$siblings[$keys[$i-1]]['title']);if(isset($keys[$i+1]))$pager.=self::link(self::url($keys[$i+1]),$siblings[$keys[$i+1]]['title'].' →');
                    $versions=[];foreach($localizedPages as $k=>$vp){[$vb,$vv]=explode('/',$k);if($vb===$b&&!isset($versions[$vv]))$versions[$vv]=$k;}
                    $switch='<div class="versions">'.self::t('Versions').': ';foreach($versions as $vv=>$k){$vv=(string)$vv;$switch.=self::link(self::url(isset($localizedPages["$b/$vv/".substr($key,strlen($prefix))])?"$b/$vv/".substr($key,strlen($prefix)):$k),$vv,$vv===$v?'active':'');}$switch.='</div>';
                    $variants=Locale::variants($pages,$key);$switch.='<nav class="versions" aria-label="'.self::t('Language').'">'.self::t('Language').': ';
                    $this->documentHead='<link rel="canonical" href="'.self::e($this->canonicalUrl(self::url($key))).'">';
                    foreach($variants as $code=>$dest){$href=self::e(self::url($dest));$switch.='<a href="'.$href.'" lang="'.$code.'" hreflang="'.$code.'"'.($code===$lang?' class="active" aria-current="page"':'').'>'.self::e(Locale::LANGUAGES[$code]).'</a> ';
                        $this->documentHead.='<link rel="alternate" hreflang="'.$code.'" href="'.self::e($this->canonicalUrl(self::url($dest))).'">';}
                    $switch.='</nav>';
                    $html=$switch.'<div class="eyebrow">'.self::e("$b / $v / ".$p['section']).'</div><h1>'.self::e($p['title']).'</h1>'.($this->domainHost===null&&ApiPlayground::published($db,$pages,$key)?'<p class="actions">'.self::link('/playground?key='.rawurlencode($key),'Try API in Playground').' '.self::link('/sdk?key='.rawurlencode($key),'SDK & Code Examples').'</p>':'').'<article lang="'.$lang.'">'.$r['html'].'</article>'.$pager.'</div>';
                    $this->page($p['title'],$html,$siblings,$key,$toc);return;
                }
                if(($target=PageTree::redirect($db,$key))&&isset($pages[$target])){$this->redirect('/docs/'.$target);return;}
            }
            http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->page(Locale::t('Page not found'),'<h1>'.self::t('Page not found').'</h1><p>'.self::t('This page is missing or not published.').'</p>',$pages);
        }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:400);$this->page('Invalid request','<h1>Invalid request</h1><p>'.self::e($e->getMessage()).'</p>');}catch(\Throwable $e){Observability::log('http.failure',['error_type'=>get_class($e)]);if(!headers_sent()){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');echo 'Service unavailable';}}
    }
    /** An anonymous same-origin opt-in beacon. No identifying data is accepted or stored. */
    private function collectAnalytics(string $method):void
    {
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');
        if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}
        $w=$this->workspace??$this->analyticsWorkspace;
        if(!$w||$this->readerVisibility!=='public'||!Analytics::settings($w)['enabled']){http_response_code(404);return;}
        // Unlike ordinary form POSTs, anonymous collection has no CSRF token.
        // A strict matching Origin is therefore mandatory even for legacy clients.
        if(!isset($_SERVER['HTTP_ORIGIN'])||!RequestSecurity::browserPost('/analytics/collect','POST',$_SERVER,$this->domainHost??CustomDomains::primary())){http_response_code(403);return;}
        if(!str_starts_with(strtolower(trim((string)($_SERVER['CONTENT_TYPE']??''))),'application/json')){http_response_code(415);return;}
        try{
            $raw=file_get_contents('php://input',false,null,0,1025);
            if(!is_string($raw))throw new \InvalidArgumentException('Invalid analytics request.');
            [$event,$path,$dimension]=array_pad(Analytics::accept($raw),3,'');
            Analytics::collect($this->installationDir,$w,$event,$path,$dimension);
            http_response_code(204);
        }catch(\InvalidArgumentException|\JsonException $e){http_response_code(400);}
         catch(\DomainException $e){http_response_code(404);}
         catch(\Throwable $e){Observability::log('analytics.collect_failure',['error_type'=>get_class($e)]);http_response_code(503);}
    }
    private function redirect(string $path): void {
        $q=$_SERVER['QUERY_STRING']??'';
        if(is_string($q)&&$q!==''&&!preg_match('/[\x00-\x20\x7f]/',$q))$path.='?'.$q;
        header('Cache-Control: private, no-store');header('Location: '.WorkspaceUrls::url($path),true,308);
    }
    private function editorAiPost(string $csrf,array $identity):void {
            $str=static fn(string $k):string=>is_string($_POST[$k]??null)?$_POST[$k]:'';
            $actor=$identity['user'];$users=$identity['review_users']??[];
            $view=null;$notice='';$query=['page'=>$str('page')];
            try{
                $action=$str('action');
                if(in_array($action,['gaps','migration','suggest'],true)&&EditorAssistant::enabled()){
                    $prefix=WorkspaceUrls::prefix();$scope='legacy';
                    if(preg_match('~\A/w/([a-f0-9]{32})/([a-f0-9]{32})\z~D',$prefix,$match))$scope=$match[1].'/'.$match[2];
                    $estimated=10000+strlen($str('error'))+strlen($str('instruction'));
                    if($action==='suggest'){
                        $page=$str('page');$current=$this->store->read()['pages'][$page]['draft']['body']??'';
                        if(is_string($current))$estimated+=strlen($current);
                    }
                    if(!ChatQuota::reserve($this->installationDir,$scope,ChatQuota::identity('editor:'.$actor['id']),min(50000,$estimated)))throw new \DomainException('Daily editor AI quota exhausted; try again tomorrow.');
                }
                if($action==='gaps'){$data=EditorAssistant::analyzeGaps($this->store->read(),$str('scope'));$view=['type'=>'gaps']+$data;}
                elseif($action==='migration'){$view=['type'=>'migration','answer'=>EditorAssistant::explainMigration($str('error'))];}
                elseif($action==='suggest'){
                    $p=EditorAssistant::suggest($this->store->read(),$str('page'),$str('instruction'));
                    $secret=MarkdownImport::previewSecret($this->installationDir,$csrf);
                    $view=['type'=>'suggest','proposal'=>$p,'sealed'=>EditorAssistant::seal($p,$secret,$actor['id'])];
                }elseif($action==='submit'){
                    if(!EditorAssistant::enabled())throw new \DomainException('Editor AI is disabled.');
                    $secret=MarkdownImport::previewSecret($this->installationDir,$csrf);
                    $p=EditorAssistant::unseal($str('payload'),$str('signature'),$secret,$actor['id']);
                    $id=$this->store->submitAiProposal($p,$actor,$users,$str('reviewer'));
                    header('Location: '.WorkspaceUrls::url('/studio/reviews?id='.$id),true,303);return;
                }else throw new \InvalidArgumentException('Unknown editor AI action.');
            }catch(OrganizationAccessException|\InvalidArgumentException|\DomainException|\JsonException $e){
                http_response_code($e instanceof OrganizationAccessException?403:($e instanceof \DomainException?409:422));$notice=$e->getMessage();
            }catch(\RuntimeException $e){
                Observability::log('editor_ai.failure',['error_type'=>get_class($e)]);http_response_code(502);$notice='Editor AI request failed. Check OpenAI configuration and retry.';
            }
            $this->page('Editor AI Assistant',EditorAssistantView::render($this->store->read(),$csrf,$actor,$users,$query,$view,$notice));return;
    }
    private function editorAiExternal(array $route):void {
        header('Cache-Control: private, no-store');header('Vary: Cookie');header('X-Robots-Tag: noindex, nofollow');
        if(isset($route[1],$route[2])&&$route[1]!==''){
            [$ctx,$w]=$this->identityStore->withWorkspace($this->auth->token(),$route[1],$route[2],static fn($ctx,$w)=>[$ctx,$w]);
            if(!$w){http_response_code(404);return;}
            $this->workspace=$w;$this->analyticsWorkspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$w['role'];$this->readerVisibility=Visibility::workspace($w);
            WorkspaceUrls::set(Workspaces::prefix($w));$this->dataDir=Workspaces::directory($this->installationDir,$w);
            $this->store=new BookStore($this->dataDir,false);$this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
        }else{
            [$ctx,$role,$w]=$this->identityStore->withLegacy($this->auth->token(),static fn($ctx,$role,$w,$visible)=>[$ctx,$role,$w]);
            $this->scopedIdentity=$ctx;$this->studioRole=$role;$this->analyticsWorkspace=$w;$this->readerVisibility=$w?Visibility::workspace($w):'public';
            if($w)$this->useLegacyStore();
        }
        try{
            if(!$this->scopedIdentity){http_response_code(401);$this->page('Sign in required','<h1>Sign in required</h1>');return;}
            if(!Permissions::studio($this->studioRole,'/studio/assistant','POST',is_string($_POST['action']??null)?$_POST['action']:'')){http_response_code(403);$this->page('Forbidden','<h1>Editor permission required</h1>');return;}
            $csrf=$this->scopedIdentity['session']['csrf'];
            if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])){http_response_code(403);$this->page('Forbidden','<h1>Invalid CSRF token</h1>');return;}
            if(empty($_POST)&&(int)($_SERVER['CONTENT_LENGTH']??0)>0){http_response_code(413);return;}
            $this->editorAiPost($csrf,$this->scopedIdentity);
        }finally{WorkspaceUrls::set('');}
    }
    private function studio(string $path,string $method):void{
        header('Cache-Control: no-store');header('X-Robots-Tag: noindex');
        $identity=$this->scopedIdentity;
        if(!$identity){http_response_code(401);if(($_SERVER['HTTP_X_BOOK_STUDIO']??'')==='editor'||($_SERVER['HTTP_X_BOOK_MEDIA']??'')==='1'){$this->json(['error'=>'Session expired. Sign in again; retain your local edits.']);return;}$this->page('Sign in required','<h1>Sign in required</h1><p><a href="/login">Sign in to Studio</a></p>');return;}
        if(!Permissions::studio($this->studioRole,$path,$method,is_string($_POST['action']??null)?$_POST['action']:'')){$denied='Permission denied for this Studio operation.';http_response_code(403);if(($_SERVER['HTTP_X_BOOK_STUDIO']??'')==='editor'||($_SERVER['HTTP_X_BOOK_MEDIA']??'')==='1'){$this->json(['error'=>$denied]);return;}$this->page('Forbidden','<h1>'.self::e(rtrim($denied,'.')).'</h1><p><a href="/account">Your account</a></p>');return;}
        $this->store->setHistoryActor($identity['user']);
        $admin=Permissions::allows($this->studioRole,'publish');$csrf=$identity['session']['csrf'];$hidden='<input type="hidden" name="csrf" value="'.$csrf.'">';
        if($method==='POST'){
            if(empty($_POST) && ini_parse_quantity((string)ini_get('post_max_size'))>0 && (int)($_SERVER['CONTENT_LENGTH']??0)>ini_parse_quantity((string)ini_get('post_max_size'))){http_response_code(413);echo 'Request exceeds server upload limit';return;}
            if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf'])){http_response_code(403);echo 'Invalid CSRF token';return;}
            if($path==='/studio/assistant'){$this->editorAiPost($csrf,$identity);return;}
            if($path==='/studio/translations'){
                $str=static fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
                try{
                    if($str('action')==='create'){
                        $source=$str('source');if(!PageTree::validKey($source))throw new \InvalidArgumentException('Invalid source page.');[$book,$version]=explode('/',$source,3);
                        $key=$this->store->save(['book'=>$book,'version'=>$version,'path'=>$str('path'),'title'=>$str('title'),'section'=>$str('section'),'order'=>10,'body'=>$str('body'),'revision'=>0,'locale'=>$str('locale'),'translation_source'=>$str('source_id'),'source_digest'=>$str('source_digest'),'note'=>'Translation created'],false);
                        header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($key)),true,303);return;
                    }
                    if($str('action')==='set-language'){
                        if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision.');
                        $this->store->setPageLocale($str('key'),(int)$str('revision'),$str('locale'));
                        header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($str('key'))),true,303);return;
                    }
                    if($str('action')==='review-source'){
                        if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision.');
                        $this->store->reviewTranslationSource($str('key'),(int)$str('revision'),$str('source_digest'));
                        header('Location: '.WorkspaceUrls::url('/studio/translations?source='.rawurlencode($str('source'))),true,303);return;
                    }
                    throw new \InvalidArgumentException('Unknown translation action.');
                }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$this->page(Locale::t('Translation Studio'),'<p role="alert">'.self::e($e->getMessage()).'</p>'.TranslationView::render($this->store->read(),$csrf,$str('source')));return;}
            }
            if($path==='/studio/static-export'){
                $scope=is_string($_POST['scope']??null)?$_POST['scope']:'';
                $private=($_POST['visibility']??'')==='all';
                try{
                    if(!in_array($_POST['visibility']??null,['public','all'],true))throw new \InvalidArgumentException('Choose an export visibility.');
                    if($private&&($_POST['confirm_private']??'')!=='1')throw new \InvalidArgumentException('Confirm that the ZIP may contain private published documentation.');
                    (new StaticExport($this->root,$this->store,$this->media,CustomDomains::publicOrigin($this->workspace)))->download($this->readerVisibility,$scope,$private,fn()=> $this->store->auditEvent($private?'export.static.private':'export.static.public',$identity['user']));
                }catch(\InvalidArgumentException|\DomainException $e){http_response_code(422);$this->page('Static Export',StaticExport::form($this->store->read(),$this->readerVisibility,$csrf,$e->getMessage()));}
                return;
            }
            if($path==='/studio/ci'){
                $str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';$actor=$identity['user'];$users=$identity['review_users']??[];$action=$str('action');$id=$str('id');$notice='';$secret='';
                try{$this->store->change(function(&$db)use($str,$actor,$users,$action,&$id,&$secret){switch($action){
                    case 'issue':$secret=CiPublishing::issue($db,$actor,$str('scope'),$str('label'),(int)$str('days'),$str('publish')==='1');break;
                    case 'rotate':if(!ctype_digit($str('days'))||!ctype_digit($str('overlap')))throw new \InvalidArgumentException('Invalid rotation duration.');$secret=CiPublishing::rotate($db,$actor,$id,(int)$str('days'),(int)$str('overlap'),$users);$id='';break;
                    case 'revoke':CiPublishing::revoke($db,$actor,$id);$id='';break;
                    case 'build':$b=CiPublishing::build($db,$actor,$str('scope'),bin2hex(random_bytes(16)),'',$this->media,$str('expected'));$id=$b['id'];break;
                    case 'approve':CiPublishing::approve($db,$actor,$users,$id,$str('digest'),$str('note'));break;
                    case 'publish':BookStore::publishBundle($db,$actor,$users,$id,$str('digest'),$this->media);break;
                    case 'cancel':CiPublishing::cancel($db,$actor,$id);break;
                    case 'prune':CiPublishing::prune($db,$actor,$id);$id='';break;
                    case 'rollback':CiPublishing::rollbackDrafts($db,$actor,$id,$str('expected'));break;
                    default:throw new \InvalidArgumentException('Unknown CI action.');}},$action==='publish'?'publish':($action==='rollback'?'restore':null),$action==='publish'?'CI publication '.$id:($action==='rollback'?'CI restore '.$id:''));$notice='CI operation completed.';
                }catch(OrganizationAccessException|\InvalidArgumentException|\DomainException $e){$secret='';http_response_code($e instanceof OrganizationAccessException?403:($e instanceof \DomainException?409:422));$notice=$e->getMessage();}
                $this->page('CI/CD Publishing',CiView::render($this->store->read(),$csrf,$notice,$secret,$id));return;
            }
            if($path==='/studio/automation'){
                $str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';$notice='';
                try{$this->store->change(function(&$db)use($str,$identity){if($str('action')==='configure'){AutomationQueue::configure($db,$identity['user']['id'],$str('expected'),$str('incoming'),$str('destination'),$str('outgoing'),is_array($_POST['events']??null)?array_filter($_POST['events'],'is_string'):[]);ActivityJournal::record($db,$identity['user'],'automation.configure','Webhook configuration',$db['automation']['config']['id']??'');}elseif($str('action')==='rotate'){if(!ctype_digit($str('overlap')))throw new \InvalidArgumentException('Invalid rotation overlap.');AutomationQueue::assertRotation($db,$str('stamp'));AutomationQueue::rotate($db,$str('expected'),$str('kind'),$str('secret'),(int)$str('overlap'));ActivityJournal::record($db,$identity['user'],'automation.configure','Webhook credential rotated',$db['automation']['config']['id']);}elseif($str('action')==='retire'){AutomationQueue::assertRotation($db,$str('stamp'));AutomationQueue::retirePrevious($db,$str('expected'));ActivityJournal::record($db,$identity['user'],'automation.configure','Previous credential retired',$db['automation']['config']['id']);}elseif($str('action')==='retry'){AutomationQueue::retry($db,$str('id'));ActivityJournal::record($db,$identity['user'],'automation.retry','Automation job',$str('id'));}else throw new \InvalidArgumentException('Unknown automation action.');});$notice='Automation configuration or job updated.';}catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$notice=$e->getMessage();}
                $this->page('Webhooks & Automation',AutomationView::render($this->store->read(),$csrf,$notice));return;
            }
            if($path==='/studio/team'){
                try{$id=is_string($_POST['id']??null)?$_POST['id']:'';$uid=$identity['user']['id'];$this->store->change(function(&$db)use($id,$uid){ActivityJournal::mark($db,$uid,$id,$db['activity']??[]);});header('Location: '.WorkspaceUrls::url('/studio/team'),true,303);}catch(\InvalidArgumentException $e){http_response_code(422);$this->page('Notification not found','<h1>Notification not found</h1><a href="'.WorkspaceUrls::url('/studio/team').'">Return to Team dashboard</a>');}return;
            }
            if($path==='/studio/openapi'){
                $str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';$preview=null;$notice='';$query=[];$api=new OpenApiStore($this->store);
                try{
                    $action=$str('action');$secret=MarkdownImport::previewSecret($this->installationDir,$csrf);$book=$str('book');$version=$str('version');$slug=$str('slug');$commit='';
                    if($action==='apply'){
                        $encoded=$str('bundle');if(strlen($encoded)>3*OpenApiDocument::MAX_BYTES||($bundle=base64_decode($encoded,true))===false)throw new \InvalidArgumentException('Invalid specification bundle.');
                        $api->apply($bundle,$book,$version,$slug,$secret,$str('token'),$identity['user'],$str('commit'));header('Location: '.WorkspaceUrls::url('/studio/openapi?spec='.rawurlencode(BookStore::key($book,$version,'reference/'.$slug))),true,303);return;
                    }
                    if($action==='restore-preview'){
                        $spec=$this->store->read()['openapi'][$str('spec')]??null;if(!$spec)throw new \InvalidArgumentException('Specification not found.');$rev=null;foreach($spec['revisions'] as $r)if((string)$r['revision']===$str('source_revision'))$rev=$r;if(!$rev)throw new \InvalidArgumentException('Source revision not found.');$doc=OpenApiDocument::fromBundle($rev['bundle']);$book=$spec['book'];$version=$spec['version'];$slug=$spec['slug'];$commit=$rev['commit'];
                    }elseif($action==='git-preview'){
                        if($this->openApiError!==null)throw new \DomainException($this->openApiError);$p=$this->openApiPrepared;$c=$this->store->read()['github']??null;
                        if(!$p||!$c||$c['id']!==$p['id']||$c['revision']!==$p['revision'])throw new \DomainException('Git connection changed. Preview again.');$doc=$p['document'];$commit=$p['commit'];
                    }elseif($action==='preview'){
                        $files=[];$root=$str('root');$upload=$_FILES['files']??[];$dir=$_FILES['directory']??[];$hasFiles=is_array($upload['error']??null)&&($upload['error'][0]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;$hasDir=is_array($dir['error']??null)&&($dir['error'][0]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;$source=$str('source');
                        if((int)$hasFiles+(int)$hasDir+(int)($source!=='')!==1)throw new \InvalidArgumentException('Choose one source: paste, files, ZIP or directory.');
                        if($source!=='')$files[$root]=$source;else{
                            $u=$hasDir?$dir:$upload;$mode=$hasDir?'directory':(count($u['name'])===1&&preg_match('/\.zip$/i',(string)$u['name'][0])?'zip':'files');$set=ImportFiles::uploads($u,$mode);$total=0;$limit=count($set->files())===1&&str_ends_with(strtolower($root),'.json')?2*OpenApiDocument::MAX_BYTES:OpenApiDocument::MAX_BYTES;
                            foreach($set->files() as $name=>$file){$size=filesize($file);if($size===false||($total+=$size)>$limit||count($files)>=OpenApiDocument::MAX_FILES)throw new \InvalidArgumentException('Specification bundle exceeds 32 files or 1 MiB.');$text=file_get_contents($file);if(!is_string($text))throw new \RuntimeException('Cannot read staged specification.');$files[$name]=$text;}
                        }$doc=OpenApiDocument::load($root,$files);
                    }else throw new \InvalidArgumentException('Unknown OpenAPI action.');
                    $preview=$api->preview($doc,$book,$version,$slug,$secret,$commit);$query['spec']=$preview['scope'];
                }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$notice=$e->getMessage();}
                $this->page('OpenAPI Reference',OpenApiView::render($this->store->read(),$csrf,$query,$preview,$notice));return;
            }
            if($path==='/studio/github'){
                $str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';$sync=new GitHubSync($this->store,new MarkdownImport($this->store,$this->media),$this->githubClientFactory,$this->dataDir.'/github-cache');$preview=null;$notice='';
                try{$action=$str('action');$actor=$identity['user']['username'];if($this->githubError!==null){if($this->githubFailure!==null)$sync->failure($this->githubFailure['id'],$this->githubFailure['revision'],$actor,$action,$this->githubError);throw new \DomainException($this->githubError);}$revision=(int)$str('revision');
                    if($action==='configure'){$fields=[];foreach(['repo','branch','root','book','version','token','push','provider','origin'] as $k)$fields[$k]=$str($k);$sync->configure($fields);$notice='Repository connected.';}
                    elseif($action==='rotate'){$sync->rotate($str('token'),$revision,$str('push')==='1');$notice='Credentials replaced.';}
                    elseif($action==='disconnect'){if($str('confirm')!=='1')throw new \InvalidArgumentException('Confirm disconnect.');$sync->disconnect($revision);$notice='Repository disconnected; documents retained.';}
                    elseif($action==='preview'){$preview=$sync->preview(MarkdownImport::previewSecret($this->installationDir,$csrf),$this->githubPrepared);}
                    elseif($action==='apply'){$rows=$sync->apply(MarkdownImport::previewSecret($this->installationDir,$csrf),$str('preview_token'),$str('commit'),$str('resolution'),$actor,$this->githubPrepared,is_array($_POST['decision']??null)?$_POST['decision']:[]);$notice='Sync applied: '.count($rows).' source records processed as drafts.';}
                    elseif($action==='propose'){if($str('confirm')!=='1')throw new \InvalidArgumentException('Confirm sending drafts to the remote repository.');$notice=GitHubSync::proposalLabel($this->store->read()['github']??[]).' created: '.$sync->propose($revision,$actor,$str('include_new')==='1',$str('state'));}
                    else throw new \InvalidArgumentException('Unknown Git action.');
                }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$notice=$e->getMessage();}
                $this->page('Git Sync',GitHubView::render($this->store->read(),$csrf,$admin,$preview,$notice));return;
            }
            if($path==='/studio/import'){
                try{$str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';$upload=$_FILES['files']??[];
                    if(!preg_match('/\A[1-9][0-9]{0,2}\z/D',$str('count'))||!is_array($upload['name']??null)||count($upload['name'])!==(int)$str('count'))throw new \InvalidArgumentException('Upload count mismatch; check server upload limits');
                    $paths=$_POST['paths']??null;if($paths!==null&&!is_array($paths))throw new \InvalidArgumentException('Invalid directory upload paths');
                    $files=ImportFiles::uploads($upload,$str('mode'),$paths);$importer=new MarkdownImport($this->store,$this->media);$secret=MarkdownImport::previewSecret($this->installationDir,$csrf);
                    $result=$str('action')==='preview'?$importer->preview($files->files(),$str('book'),$str('version'),$str('strategy'),$secret):$importer->apply($files->files(),$str('book'),$str('version'),$str('strategy'),$secret,$str('token'));
                    $this->json($result);
                }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$this->json(['error'=>$e->getMessage()]);}return;
            }
            if($path==='/studio/media'){$this->mediaController->post($csrf);return;}
            if($path==='/studio/books'){try{$action=$_POST['action']??'';$slug=is_string($_POST['slug']??null)?$_POST['slug']:'';
                if($action==='create')$this->store->createBook($slug,is_string($_POST['title']??null)?$_POST['title']:'');
                elseif($action==='visibility'){$rev=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($rev===false)throw new \InvalidArgumentException('Invalid revision');$this->store->setBookVisibility($slug,is_string($_POST['visibility']??null)?$_POST['visibility']:'',$rev);}
                else throw new \InvalidArgumentException('Unknown book action');header('Location: '.WorkspaceUrls::url('/studio/books'),true,303);}catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$this->page('Books',$this->booksMarkup($csrf,$e->getMessage()));}return;}
            $action=$_POST['action']??'';$str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
            if($path==='/studio/reviews'){
                try{$fields=[];foreach(['id','revision','kind','target','expected','reviewer','note','required'] as $k)$fields[$k]=$str($k);
                    $id=$this->store->review($identity['user'],$identity['review_users']??[],$str('action'),$fields);
                    header('Location: '.WorkspaceUrls::url('/studio/reviews'.($id!==''?'?id='.$id:'')),true,303);
                }catch(OrganizationAccessException|\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof OrganizationAccessException?403:($e instanceof \DomainException?409:422));$this->page('Review request failed','<h1>Review request failed</h1><p>'.self::e($e->getMessage()).'</p><p>'.self::link('/studio/reviews','Return to reviews').'</p>');}return;
            }
            if($path==='/studio/history'){
                if(!preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision');
                $key=$this->store->restoreRevision($str('page'),$str('id'),$str('state'),(int)$str('revision'),$str('head'),$str('note'));
                header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($key)),true,303);return;
            }
            if($path==='/studio/tree'){
                try{
                    $scope=$str('scope');$token=$str('token');
                    $this->store->change(function(array &$db)use($action,$str,$scope,$token){
                        if($action==='publish'){PageTree::publish($db,$scope,$token);return;}
                        if(!in_array($action,['move','duplicate'],true)||PageTree::scope($str('source'))!==$scope)throw new \InvalidArgumentException('Invalid tree action');
                        PageTree::move($db,$str('source'),$str('target'),$str('position'),$str('slug'),$token,$action==='duplicate');
                    },$action==='publish'?'publish-tree':$action);
                    header('Location: '.WorkspaceUrls::url('/studio/tree?scope='.rawurlencode($scope)).'&notice='.($action==='publish'?'published':'saved'),true,303);return;
                }catch(\DomainException|\InvalidArgumentException $e){http_response_code($e instanceof \DomainException?409:422);$this->page('Page tree',PageTreeView::studio($this->store->read(),$csrf,$str('scope'),$e->getMessage(),$admin));return;}
            }
            if($path==='/studio/editor/render'){
                try {
                    $body=$str('body'); Markdown::validate($body);
                    if(!in_array($str('mode'),['visual','preview'],true))throw new \InvalidArgumentException('Unknown editor mode');
                    $key=null;try{$key=BookStore::key($str('book'),$str('version'),$str('path'));}catch(\InvalidArgumentException){}
                    $db=$this->store->read();$base=$key===null?null:($db['pages'][$key]['draft']['link_base']??null);
                    $this->json(Markdown::render($body,$key,$str('mode')==='visual',true,$base,PageTree::linkMap($db)));return;
                }catch(\InvalidArgumentException $e){http_response_code(422);$this->json(['error'=>$e->getMessage()]);return;}
            }
            if($path!=='/studio'){http_response_code(404);return;}
            if($action==='save'||$action==='publish'){
                $ajax=($_SERVER['HTTP_X_BOOK_STUDIO']??'')==='editor';
                try {
                    if(!preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision');
                    $result=$this->store->saveResult(['book'=>$str('book'),'version'=>$str('version'),'path'=>$str('path'),'title'=>$str('title'),'section'=>$str('section'),'order'=>$str('order'),'body'=>$str('body'),'revision'=>$str('revision'),'note'=>$str('note'),'locale'=>$str('locale')],$action==='publish');
                    if($ajax){if($result['navigation_changed'])$result['navigation']=PageTreeView::list($this->store->read(),$result['key']);$this->json($result);return;}
                    header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($result['key'])),true,303);return;
                }catch(\DomainException $e){if(!$ajax)throw $e;http_response_code(409);$this->json(['error'=>$e->getMessage()]);return;}
                catch(\InvalidArgumentException $e){if(!$ajax)throw $e;http_response_code(422);$this->json(['error'=>$e->getMessage()]);return;}
            }
            if($action==='delete'||$action==='unpublish'){if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision');$this->store->remove($str('key'),(int)$str('revision'),$action==='unpublish');}elseif($action==='copy'){$this->store->copyVersion($str('book'),$str('from'),$str('to'));}else throw new \InvalidArgumentException('Unknown action');header('Location: '.WorkspaceUrls::url('/studio'),true,303);return;
        }
        if($path==='/studio/assistant'){$this->page('Editor AI Assistant',EditorAssistantView::render($this->store->read(),$csrf,$identity['user'],$identity['review_users']??[],$_GET));return;}
        if($path==='/studio/translations'){$this->page(Locale::t('Translation Studio'),TranslationView::render($this->store->read(),$csrf,is_string($_GET['source']??null)?$_GET['source']:''));return;}
        if($path==='/studio/openapi'){
            $db=$this->store->read();if(isset($_GET['download'])){$scope=is_string($_GET['spec']??null)?$_GET['spec']:'';$revision=is_string($_GET['download'])?$_GET['download']:'';foreach($db['openapi'][$scope]['revisions']??[] as $r)if((string)$r['revision']===$revision){header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="openapi-source-'.$revision.'.json"');if($method!=='HEAD')echo $r['bundle'];return;}http_response_code(404);return;}
            $this->page('OpenAPI Reference',OpenApiView::render($db,$csrf,$_GET));return;
        }
        if($path==='/studio/search'){$this->searchInspector();return;}
        if($path==='/studio/static-export'){$this->page('Static Export',StaticExport::form($this->store->read(),$this->readerVisibility,$csrf));return;}
        if($path==='/studio/ci'){$this->page('CI/CD Publishing',CiView::render($this->store->read(),$csrf,'','',is_string($_GET['id']??null)?$_GET['id']:'',is_string($_GET['preview']??null)?$_GET['preview']:''));return;}
        if($path==='/studio/automation'){$this->page('Webhooks & Automation',AutomationView::render($this->store->read(),$csrf,'',$_GET));return;}
        if($path==='/studio/github'){$this->page('Git Sync',GitHubView::render($this->store->read(),$csrf,$admin));return;}
        if($path==='/studio/import'){$this->page('Import documentation',ImportView::render($this->store->read(),$csrf));return;}
        if($path==='/studio/team'){$this->page('Team dashboard',TeamView::workspace($this->store->read(),$identity,$this->studioRole,$_GET));return;}
        if($path==='/studio/reviews'){$this->page('Reviews',ReviewView::render($this->store->read(),$csrf,$identity['user'],$identity['review_users']??[],$_GET));return;}
        if($path==='/studio/history'){ $this->page('Revision history',HistoryView::render($this->store->read(),$csrf,$_GET));return; }
        if($path==='/studio/books'){$this->page('Books',$this->booksMarkup($csrf));return;}
        if($path==='/studio/media/picker'){$this->mediaController->picker();return;}
        if($path==='/studio/media'){$this->page('Media library',$this->mediaController->markup($csrf,$admin,$this->readerVisibility));return;}
        if(str_starts_with($path,'/studio/media/file/')){$this->mediaController->serve(substr($path,19),true,$method);return;}
        if($path==='/studio/media/backup'){if($method!=='HEAD')$this->store->auditEvent('export.media.request',$identity['user']);(new MediaBackup($this->dataDir,$this->store,$this->media))->download($method);return;}
        $db=$this->store->read();$directPublish=$admin&&!ReviewWorkflow::required($db);if($path==='/studio/tree'){$scope=is_string($_GET['scope']??null)?$_GET['scope']:'';$notice=match($_GET['notice']??''){'published'=>'Tree published. Draft content unchanged.','saved'=>'Draft tree saved.',default=>''};$this->page('Page tree',PageTreeView::studio($db,$csrf,$scope,$notice,$admin),BookStore::published($db));return;}if($path==='/studio/export'){if($method!=='HEAD')$this->store->auditEvent('export.content',$identity['user']);header('Content-Disposition: attachment; filename="cajeerbook-backup.json"');$this->json($db);return;}if($path!=='/studio'){http_response_code(404);return;}
        $edit=is_string($_GET['edit']??null)?$_GET['edit']:'';$entry=$db['pages'][$edit]??null;
        if($edit!==''&&!$entry){if($target=PageTree::draftRedirect($db,$edit)){header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($target)),true,303);return;}http_response_code(404);$this->page('Page not found','<h1>Page not found</h1><a href="'.WorkspaceUrls::url('/studio/tree').'">Open page tree</a>');return;}$p=$entry['draft']??['title'=>'','section'=>'Getting started','order'=>10,'body'=>''];$parts=$entry?explode('/',$edit,3):[$this->workspace?(string)(array_key_first(BookStore::books($db))??''):'handbook','1.0',''];
        if(!$entry&&is_string($_GET['book']??null)&&isset(BookStore::books($db)[$_GET['book']]))$parts[0]=$_GET['book'];
        if(!$entry&&is_string($_GET['new_under']??null))$parts=PageTreeView::newUnder($db,$_GET['new_under'])??$parts;
        $field=fn($name,$label,$value,$readonly=false)=>'<label>'.self::t($label).'<input name="'.$name.'" value="'.self::e((string)$value).'" '.($readonly?'readonly':'').' ></label>';
        $html='<div class="eyebrow">'.self::t('Studio').'</div><h1>'.self::t('Documentation Studio').'</h1><p>'.self::t('Drafts and published snapshots are separate.').'</p><div class="actions">'.self::link('/studio',Locale::t('New page')).self::link('/studio/translations',Locale::t('Translations')).self::link('/studio/assistant','AI Assistant').self::link('/studio/import',Locale::t('Import Markdown / MDX')).self::link('/studio/openapi','OpenAPI Reference').self::link('/studio/git','Git Sync').($admin?self::link('/studio/automation','Webhooks & Automation').self::link('/studio/ci','CI/CD Publishing').self::link('/studio/search',Locale::t('Search Inspector')):'').self::link('/studio/team',Locale::t('Team dashboard')).self::link('/studio/books',Locale::t('Books')).($admin?self::link('/studio/static-export',Locale::t('Static Export')).self::link('/studio/export','Download JSON backup'):'').self::link('/studio/media',Locale::t('Media library')).($admin?self::link('/studio/media/backup','Content backup (.tar)'):'').self::link('/studio/tree',Locale::t('Page tree')).self::link('/studio/history',Locale::t('Revision history')).self::link('/studio/reviews',Locale::t('Reviews')).'</div>'.PageTreeView::list($db,$edit).'<p>'.self::t('Move or rename pages in the page tree. Publish tree to apply navigation order and URLs.').'</p>'.(isset($p['link_base'])?'<p>Relative links in this moved or duplicated page resolve from <code>'.self::e($p['link_base']).'</code>. Use absolute documentation URLs for new links when needed.</p>':'').'<h2>'.self::t($entry?'Edit page':'Create page').'</h2><form method="post" action="'.WorkspaceUrls::url('/studio').'" class="editor" data-editor-form data-can-publish="'.($admin?'1':'0').'">'.$hidden.'<input type="hidden" name="revision" value="'.($entry['revision']??0).'">'.$field('book','Book slug',$parts[0],(bool)$entry).$field('version','Version',$parts[1],(bool)$entry).$field('path','Page path',$parts[2],(bool)$entry).'<label>'.self::t('Language').'<select name="locale"'.($entry?' disabled':'').'>'.Locale::options(Locale::language($p)).'</select></label>'.$field('title','Title',$p['title']).$field('section','Section',$p['section']).$field('order','Draft navigation order',$p['order']).StudioEditor::markup($p['body']).'<label>'.self::t('Revision note (optional, manual save/publish)').'<input name="note" maxlength="500" placeholder="'.self::t('What changed?').'"></label><div class="actions"><button name="action" value="save">'.self::t('Save draft').'</button>'.($directPublish?'<button class="primary" name="action" value="publish">'.self::t('Publish').'</button>':'').'</div></form>';
        if(ReviewWorkflow::required($db))$html.='<p class="tree-notice">Review required. Save the draft, then submit its saved revision for approval. '.self::link('/studio/reviews','Open reviews').'</p>';
        if($entry){$html.='<p>'.self::link('/studio/reviews?kind=page&target='.rawurlencode($edit),Locale::t('Review saved draft')).'</p><p data-page-history>'.self::link('/studio/history?page='.PageTree::id($edit,$entry),Locale::t('Page history')).'</p><div data-saved-preview><h2>'.self::t('Saved draft preview').'</h2><article>'.Markdown::render($p['body'],$edit,false,true,$p['link_base']??null,PageTree::linkMap($db))['html'].'</article></div>'.($admin?'<form method="post" action="'.WorkspaceUrls::url('/studio').'" class="actions" data-page-actions>'.$hidden.'<input type="hidden" name="key" value="'.self::e($edit).'"><input type="hidden" name="revision" value="'.$entry['revision'].'"><button name="action" value="unpublish">'.self::t('Unpublish').'</button><button name="action" value="delete" data-confirm="Delete this page? Retained history remains recoverable.">'.self::t('Delete').'</button></form>':'');}
        $html.='<h2>'.self::t('Copy version to drafts').'</h2><form method="post" action="'.WorkspaceUrls::url('/studio').'" class="editor">'.$hidden.$field('book','Book','handbook').$field('from','Source version','1.0').$field('to','New version','2.0').'<button name="action" value="copy">'.self::t('Copy published pages').'</button></form>';$this->page(Locale::t('Studio'),$html,BookStore::published($db));
    }
    private function booksMarkup(string $csrf,string $error=''):string{
        $db=$this->store->read();$h='<h1>Books</h1><p><a href="'.WorkspaceUrls::url('/studio').'">Studio</a> · <a href="/workspaces">Workspaces</a></p>'.($error!==''?'<p role="alert">'.self::e($error).'</p>':'');
        foreach(BookStore::books($db) as $b){
            $count=0;foreach($db['pages'] as $key=>$entry)if(str_starts_with($key,$b['slug'].'/'))$count++;
            $h.='<section class="org-card"><h2>'.self::e($b['title']).'</h2><p>'.self::e($b['slug']).' · '.$count.' pages · '.(Visibility::publicBook($b,$this->readerVisibility)?'Public':'Private').'</p><a href="'.WorkspaceUrls::url('/studio?book=').rawurlencode($b['slug']).'">Create page</a>';
            if(Permissions::allows($this->studioRole,'manage')){$options='';foreach(Visibility::BOOK as $v)$options.='<option value="'.$v.'"'.(Visibility::book($b)===$v?' selected':'').'>'.ucfirst($v).'</option>';
                $h.='<form class="editor" method="post" action="'.WorkspaceUrls::url('/studio/books').'"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="action" value="visibility"><input type="hidden" name="slug" value="'.self::e($b['slug']).'"><input type="hidden" name="revision" value="'.($b['visibility_revision']??1).'"><label>Book visibility<select name="visibility">'.$options.'</select></label><p>Private workspace always takes precedence. Public books expose published pages and referenced media to anyone.</p><button data-confirm="Change book visibility?">Save visibility</button></form>';}
            $h.='</section>';
        }
        return $h.'<h2>Create book</h2><form class="editor" method="post" action="'.WorkspaceUrls::url('/studio/books').'"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><input type="hidden" name="action" value="create"><label>Book slug<input required name="slug" maxlength="64"></label><label>Title<input required name="title" maxlength="200"></label><button class="primary">Create book</button></form>';
    }

}
