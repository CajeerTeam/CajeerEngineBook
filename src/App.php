<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Template\{CajeerTemplateEngine,TemplateSandbox};
use CajeerEngine\Runtime\ServiceContainer;
final class App {
    private BookStore $store; private MediaStore $media; private MediaController $mediaController; private CajeerTemplateEngine $view;
    private string $dataDir; private readonly string $installationDir; private ?array $workspace=null; private ?array $githubPrepared=null; private ?array $openApiPrepared=null; private ?string $openApiError=null; private ?string $githubError=null; private ?array $githubFailure=null; private ?array $scopedIdentity=null; private string $studioRole='none'; private string $readerVisibility='public'; private AuthController $auth; private IdentityStore $identityStore;
    public function __construct(private readonly string $root,private readonly ?\Closure $githubClientFactory=null){
        $this->installationDir=$this->dataDir=DataDirectory::resolve(getenv('BOOK_DATA_DIR')?:$root.'/var',$root.'/public');
        $c=new ServiceContainer();$c->set('store',fn()=>new BookStore($this->dataDir));$this->store=$c->get('store');
        $this->identityStore=new IdentityStore($this->dataDir);$this->auth=new AuthController($this->identityStore);
        $this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
        $t=$root.'/templates';$this->view=new CajeerTemplateEngine(new TemplateSandbox($t),$t);
    }
    private static function e(string $s):string{return Markdown::e($s);}
    private static function url(string $key):string{return WorkspaceUrls::url('/docs/'.$key);}
    private static function link(string $url,string $label,string $class=''):string{return '<a class="'.self::e($class).'" href="'.self::e(WorkspaceUrls::url($url)).'">'.self::e($label).'</a>';}
    private function page(string $title,string $body,array $pages=[],string $current='',string $toc='',string $query=''):void{
        $nav=PageTreeView::reader($pages,$current);
        if($this->workspace)$body='<p class="eyebrow"><a href="/workspaces">Workspaces</a> / '.self::e($this->workspace['name']).' · '.self::e($this->workspace['role']==='none'?'Public':ucfirst($this->workspace['role'])).'</p>'.$body;
        echo $this->view->renderName('book.layout',['title'=>$title,'description'=>'Self-hosted technical documentation','body'=>$body,'nav'=>$nav,'toc'=>$toc,'query'=>$query,'workspacePrefix'=>WorkspaceUrls::prefix(),'homeUrl'=>WorkspaceUrls::prefix().'/', 'searchUrl'=>WorkspaceUrls::url('/search'),'studioLink'=>(!$this->workspace||Permissions::allows($this->studioRole,'edit'))?self::link('/studio','Studio'):'']);
    }
    private function json(mixed $data):void{header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public function run():void{
        header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https: http:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';
        // Canonical unified route; preserve existing /studio/github bookmarks and forms.
        if(preg_match('~\A(?:/w/[a-f0-9]{32}/[a-f0-9]{32})?/studio/git\z~D',$path))$path=substr($path,0,-3).'github';
        WorkspaceUrls::set('');
        try{
            if(preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/webhooks/(github|gitlab)\z~D',$path,$hook)){if($method!=='POST'){http_response_code(405);header('Allow: POST');return;}$this->receiveWebhook($hook);return;}
            if($method==='POST'&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/api/v1/playground\z~D',$path,$playground)){$this->playgroundProxy($playground);return;}
            if($method==='POST'&&($_POST['action']??null)==='git-preview'&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/studio/openapi\z~D',$path,$git)){$this->githubPull($git,true);return;}
            if($method==='POST'&&in_array($_POST['action']??null,['preview','apply'],true)&&preg_match('~\A(?:/w/([a-f0-9]{32})/([a-f0-9]{32}))?/studio/github\z~D',$path,$git)){$this->githubPull($git);return;}
            if(str_starts_with($path,'/w/')){
                if(!preg_match('~\A/w/([a-f0-9]{32})/([a-f0-9]{32})(/.*)?\z~D',$path,$m)){http_response_code(404);return;}
                $inner=$m[3]??'/';
                if(!preg_match('~\A/(?:$|(?:studio|docs|media|api/v1|search|playground|sdk)(?:/|$))~D',$inner)){http_response_code(404);return;}
                $publicRead=in_array($method,['GET','HEAD'],true)&&$inner!=='/studio'&&!str_starts_with($inner,'/studio/');
                $this->identityStore->withWorkspace($this->auth->token(),$m[1],$m[2],function($ctx,$w)use($inner,$method){
                    header('Cache-Control: private, no-store');header('Vary: Cookie');if(!$w||$ctx||Visibility::workspace($w)==='private')header('X-Robots-Tag: noindex, nofollow');
                    if(!$w){$this->unavailable($inner,$ctx,true);return;}
                    $this->workspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$w['role'];$this->readerVisibility=Visibility::workspace($w);
                    WorkspaceUrls::set(Workspaces::prefix($w));
                    $this->dataDir=Workspaces::directory($this->installationDir,$w);
                    $this->store=new BookStore($this->dataDir,false);$this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);
                    try{$this->dispatch($inner,$method);}finally{WorkspaceUrls::set('');}
                },$publicRead);return;
            }
            if($path==='/studio'||str_starts_with($path,'/studio/')){
                $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($path,$method){$this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';if($w)$this->useLegacyStore();$this->dispatch($path,$method);});return;
            }
            // Account, control-plane and health routes must remain reachable even when legacy content is private.
            if(in_array($path,['/login','/logout','/account','/account/users','/account/reset','/workspaces','/organizations','/team','/health/live','/health/ready','/diagram-frame'],true)||str_starts_with($path,'/organizations/')){$this->dispatch($path,$method);return;}
            $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($path,$method){
                $this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';if($w)$this->useLegacyStore();
                header('Cache-Control: private, no-store');header('Vary: Cookie');if($ctx||!$visible||$this->readerVisibility==='private')header('X-Robots-Tag: noindex, nofollow');
                if(!$visible){$this->unavailable($path,$ctx);return;}$this->dispatch($path,$method);
            });
        }catch(\Throwable $e){error_log('Book request failed: '.get_class($e).': '.$e->getMessage());if(!headers_sent()){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');echo 'Service unavailable';}}
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
                $this->workspace=$w;$this->scopedIdentity=$ctx;$this->studioRole=$w['role'];$this->readerVisibility=Visibility::workspace($w);WorkspaceUrls::set(Workspaces::prefix($w));
                $this->dataDir=Workspaces::directory($this->installationDir,$w);$this->store=new BookStore($this->dataDir,false);$this->media=new MediaStore($this->dataDir,$this->store);$this->mediaController=new MediaController($this->media,$this->store);return $fn();
            });
            return $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($fn){$this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';if($w)$this->useLegacyStore();return $fn();});
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
    private function dispatch(string $path,string $method):void{
        try{
            if($path==='/diagram-frame'){DiagramFrame::serve($method);return;}
            if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
            if(in_array($path,['/login','/logout','/account','/account/users','/account/reset'],true)){$this->auth->dispatch($path,$method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/team'){
                header('Cache-Control: private, no-store');header('Vary: Cookie');header('X-Robots-Tag: noindex, nofollow');
                try{$mark=$method==='POST'?(is_string($_POST['id']??null)?$_POST['id']:''):null;if($method==='POST'&&($_POST['action']??'')!=='read')throw new \InvalidArgumentException('Unknown team action');
                    $ctx=$this->identityStore->team($this->auth->token(),$mark,is_string($_POST['csrf']??null)?$_POST['csrf']:null,is_string($_GET['workspace_page']??null)?(int)$_GET['workspace_page']:1);
                    if(!$ctx){$this->unavailable($path,null);return;}if($method==='POST'){header('Location: /team',true,303);return;}$this->page('Team Studio',TeamView::global($ctx,$_GET));
                }catch(OrganizationAccessException|\InvalidArgumentException $e){http_response_code($e instanceof OrganizationAccessException?403:422);$this->page('Team request failed','<h1>Team request failed</h1><p>'.self::e($e->getMessage()).'</p><a href="/team">Return to Team Studio</a>');}return;
            }
            if($path==='/workspaces'){(new WorkspaceController($this->identityStore,$this->auth))->dispatch($method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/organizations'||str_starts_with($path,'/organizations/')){(new OrganizationController($this->identityStore,$this->auth))->dispatch($path,$method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/studio'||str_starts_with($path,'/studio/')){$this->studio($path,$method);return;}
            if($method==='POST'){http_response_code(405);header('Allow: GET, HEAD');return;}
            if(str_starts_with($path,'/media/')){$this->mediaController->serve(substr($path,7),false,$method,fn($db,$id)=>Visibility::media($db,$id,$this->readerVisibility,$this->studioRole));return;}
            if($path==='/health/live'){$this->json(['status'=>'ok']);return;}
            if($path==='/health/ready'){
                try { $this->identityStore->checkReady(); $this->identityStore->checkWorkspaceStores($this->installationDir); ReleaseHealth::check($this->root); $this->json(['status'=>'ok']); }
                catch(\Throwable $e){error_log('Readiness: '.$e->getMessage());http_response_code(503);$this->json(['status'=>'unavailable']);}
                return;
            }
            $db=$this->store->read();$pages=Visibility::pages($db,$this->readerVisibility,$this->studioRole);
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
            if($path==='/api/v1/pages'){$this->json(array_map(fn($p)=>array_diff_key($p,['body'=>true,'link_base'=>true,'playground'=>true]),$pages));return;}
            if(str_starts_with($path,'/api/v1/pages/')){$key=substr($path,14);if(!isset($pages[$key])){if(($target=PageTree::redirect($db,$key))&&isset($pages[$target])){$this->redirect('/api/v1/pages/'.$target);return;}http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->json(['error'=>'not_found']);return;}$this->json(array_diff_key($pages[$key],['link_base'=>true,'playground'=>true]));return;}
            if($path==='/search'||$path==='/api/v1/search'){$q=is_string($_GET['q']??null)?trim(substr($_GET['q'],0,200)):'';$hits=[];foreach($pages as $key=>$p){if($q!==''&&preg_match('/'.preg_quote($q,'/').'/iu',$p['title'].' '.$p['body']))$hits[]=['key'=>$key,'title'=>$p['title'],'url'=>self::url($key)];if(count($hits)>=50)break;}if($path==='/api/v1/search'){$this->json($hits);return;}$html='<div class="eyebrow">SEARCH</div><h1>Search documentation</h1><p>'.count($hits).' results</p>';foreach($hits as $hit)$html.='<div class="result">'.self::link($hit['url'],$hit['title']).'</div>';$this->page('Search',$html,$pages,'','',$q);return;}
            if($path==='/'){$books=BookStore::books($db);$html='<div class="eyebrow">CAJEERENGINE BOOK / DOCUMENTATION</div><h1>Documentation, on your terms.</h1><p class="lead">A self-hosted knowledge base for your products, APIs and teams.</p><div class="cards">';$seen=[];foreach($pages as $key=>$p){[$b,$v]=explode('/',$key);if(isset($seen["$b/$v"]))continue;$seen["$b/$v"]=true;$html.='<div class="tile"><div class="eyebrow">'.self::e($v).'</div><h2>'.self::e($books[$b]['title']??$b).'</h2>'.self::link(self::url($key),'Read documentation →').'</div>';}$this->page('Documentation',$html.'</div>',$pages);return;}
            if(str_starts_with($path,'/docs/')){$key=substr($path,6);if(isset($pages[$key])){$p=$pages[$key];$r=Markdown::render($p['body'],$key,false,false,$p['link_base']??null,Visibility::links($db,$pages));$toc='<div class="side-label">ON THIS PAGE</div>';foreach($r['toc'] as $h)$toc.=self::link('#'.$h['id'],$h['title'],'toc-level-'.$h['level']);[$b,$v]=explode('/',$key);$prefix="$b/$v/";$siblings=array_filter($pages,fn($k)=>str_starts_with($k,$prefix),ARRAY_FILTER_USE_KEY);$keys=array_keys($siblings);$i=array_search($key,$keys,true);$pager='<div class="pager">';if($i>0)$pager.=self::link(self::url($keys[$i-1]),'← '.$siblings[$keys[$i-1]]['title']);if(isset($keys[$i+1]))$pager.=self::link(self::url($keys[$i+1]),$siblings[$keys[$i+1]]['title'].' →');$versions=[];foreach($pages as $k=>$vp){[$vb,$vv]=explode('/',$k);if($vb===$b&&!isset($versions[$vv]))$versions[$vv]=$k;} $switch='<div class="versions">Versions: ';foreach($versions as $vv=>$k)$switch.=self::link(self::url(isset($pages["$b/$vv/".substr($key,strlen($prefix))])?"$b/$vv/".substr($key,strlen($prefix)):$k),$vv,$vv===$v?'active':'');$switch.='</div>'; $html=$switch.'<div class="eyebrow">'.self::e("$b / $v / ".$p['section']).'</div><h1>'.self::e($p['title']).'</h1>'.(ApiPlayground::published($db,$pages,$key)?'<p class="actions">'.self::link('/playground?key='.rawurlencode($key),'Try API in Playground').' '.self::link('/sdk?key='.rawurlencode($key),'SDK & Code Examples').'</p>':'').'<article>'.$r['html'].'</article>'.$pager.'</div>';$this->page($p['title'],$html,$siblings,$key,$toc);return;}if(($target=PageTree::redirect($db,$key))&&isset($pages[$target])){$this->redirect('/docs/'.$target);return;}}
            http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->page('Not found','<h1>Page not found</h1><p>This page is missing or not published.</p>',$pages);
        }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:400);$this->page('Invalid request','<h1>Invalid request</h1><p>'.self::e($e->getMessage()).'</p>');}catch(\Throwable $e){error_log('Book request failed: '.get_class($e).': '.$e->getMessage());if(!headers_sent()){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');echo 'Service unavailable';}}
    }
    private function redirect(string $path): void {
        $q=$_SERVER['QUERY_STRING']??'';
        if(is_string($q)&&$q!==''&&!preg_match('/[\x00-\x20\x7f]/',$q))$path.='?'.$q;
        header('Cache-Control: private, no-store');header('Location: '.WorkspaceUrls::url($path),true,308);
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
            if($path==='/studio/automation'){
                $str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';$notice='';
                try{$this->store->change(function(&$db)use($str,$identity){if($str('action')==='configure'){AutomationQueue::configure($db,$identity['user']['id'],$str('expected'),$str('incoming'),$str('destination'),$str('outgoing'),is_array($_POST['events']??null)?array_filter($_POST['events'],'is_string'):[]);ActivityJournal::record($db,$identity['user'],'automation.configure','Webhook configuration',$db['automation']['config']['id']??'');}else{AutomationQueue::retry($db,$str('id'));ActivityJournal::record($db,$identity['user'],'automation.retry','Automation job',$str('id'));}});$notice='Automation configuration or job updated.';}catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$notice=$e->getMessage();}
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
                    $files=ImportFiles::uploads($upload,$str('mode'));$importer=new MarkdownImport($this->store,$this->media);$secret=MarkdownImport::previewSecret($this->installationDir,$csrf);
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
                    $result=$this->store->saveResult(['book'=>$str('book'),'version'=>$str('version'),'path'=>$str('path'),'title'=>$str('title'),'section'=>$str('section'),'order'=>$str('order'),'body'=>$str('body'),'revision'=>$str('revision'),'note'=>$str('note')],$action==='publish');
                    if($ajax){if($result['navigation_changed'])$result['navigation']=PageTreeView::list($this->store->read(),$result['key']);$this->json($result);return;}
                    header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($result['key'])),true,303);return;
                }catch(\DomainException $e){if(!$ajax)throw $e;http_response_code(409);$this->json(['error'=>$e->getMessage()]);return;}
                catch(\InvalidArgumentException $e){if(!$ajax)throw $e;http_response_code(422);$this->json(['error'=>$e->getMessage()]);return;}
            }
            if($action==='delete'||$action==='unpublish'){if(!preg_match('/\A[1-9][0-9]{0,17}\z/D',$str('revision')))throw new \InvalidArgumentException('Invalid revision');$this->store->remove($str('key'),(int)$str('revision'),$action==='unpublish');}elseif($action==='copy'){$this->store->copyVersion($str('book'),$str('from'),$str('to'));}else throw new \InvalidArgumentException('Unknown action');header('Location: '.WorkspaceUrls::url('/studio'),true,303);return;
        }
        if($path==='/studio/openapi'){
            $db=$this->store->read();if(isset($_GET['download'])){$scope=is_string($_GET['spec']??null)?$_GET['spec']:'';$revision=is_string($_GET['download'])?$_GET['download']:'';foreach($db['openapi'][$scope]['revisions']??[] as $r)if((string)$r['revision']===$revision){header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="openapi-source-'.$revision.'.json"');if($method!=='HEAD')echo $r['bundle'];return;}http_response_code(404);return;}
            $this->page('OpenAPI Reference',OpenApiView::render($db,$csrf,$_GET));return;
        }
        if($path==='/studio/automation'){$this->page('Webhooks & Automation',AutomationView::render($this->store->read(),$csrf,'',$_GET));return;}
        if($path==='/studio/github'){$this->page('Git Sync',GitHubView::render($this->store->read(),$csrf,$admin));return;}
        if($path==='/studio/import'){$this->page('Import Markdown',ImportView::render($this->store->read(),$csrf));return;}
        if($path==='/studio/team'){$this->page('Team dashboard',TeamView::workspace($this->store->read(),$identity,$this->studioRole,$_GET));return;}
        if($path==='/studio/reviews'){$this->page('Reviews',ReviewView::render($this->store->read(),$csrf,$identity['user'],$identity['review_users']??[],$_GET));return;}
        if($path==='/studio/history'){ $this->page('Revision history',HistoryView::render($this->store->read(),$csrf,$_GET));return; }
        if($path==='/studio/books'){$this->page('Books',$this->booksMarkup($csrf));return;}
        if($path==='/studio/media/picker'){$this->mediaController->picker();return;}
        if($path==='/studio/media'){$this->page('Media library',$this->mediaController->markup($csrf,$admin,$this->readerVisibility));return;}
        if(str_starts_with($path,'/studio/media/file/')){$this->mediaController->serve(substr($path,19),true,$method);return;}
        if($path==='/studio/media/backup'){(new MediaBackup($this->dataDir,$this->store,$this->media))->download($method);return;}
        $db=$this->store->read();$directPublish=$admin&&!ReviewWorkflow::required($db);if($path==='/studio/tree'){$scope=is_string($_GET['scope']??null)?$_GET['scope']:'';$notice=match($_GET['notice']??''){'published'=>'Tree published. Draft content unchanged.','saved'=>'Draft tree saved.',default=>''};$this->page('Page tree',PageTreeView::studio($db,$csrf,$scope,$notice,$admin),BookStore::published($db));return;}if($path==='/studio/export'){header('Content-Disposition: attachment; filename="cajeerbook-backup.json"');$this->json($db);return;}if($path!=='/studio'){http_response_code(404);return;}
        $edit=is_string($_GET['edit']??null)?$_GET['edit']:'';$entry=$db['pages'][$edit]??null;
        if($edit!==''&&!$entry){if($target=PageTree::draftRedirect($db,$edit)){header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($target)),true,303);return;}http_response_code(404);$this->page('Page not found','<h1>Page not found</h1><a href="'.WorkspaceUrls::url('/studio/tree').'">Open page tree</a>');return;}$p=$entry['draft']??['title'=>'','section'=>'Getting started','order'=>10,'body'=>''];$parts=$entry?explode('/',$edit,3):[$this->workspace?(string)(array_key_first(BookStore::books($db))??''):'handbook','1.0',''];
        if(!$entry&&is_string($_GET['book']??null)&&isset(BookStore::books($db)[$_GET['book']]))$parts[0]=$_GET['book'];
        if(!$entry&&is_string($_GET['new_under']??null))$parts=PageTreeView::newUnder($db,$_GET['new_under'])??$parts;
        $field=fn($name,$label,$value,$readonly=false)=>'<label>'.self::e($label).'<input name="'.$name.'" value="'.self::e((string)$value).'" '.($readonly?'readonly':'').' ></label>';
        $html='<div class="eyebrow">AUTHORING / STUDIO</div><h1>Documentation Studio</h1><p>Drafts and published snapshots are separate.</p><div class="actions">'.self::link('/studio','New page').self::link('/studio/import','Import Markdown').self::link('/studio/openapi','OpenAPI Reference').self::link('/studio/git','Git Sync').($admin?self::link('/studio/automation','Webhooks & Automation'):'').self::link('/studio/team','Team dashboard').self::link('/studio/books','Books').($admin?self::link('/studio/export','Download JSON backup'):'').self::link('/studio/media','Media library').($admin?self::link('/studio/media/backup','Content backup (.tar)'):'').self::link('/studio/tree','Page tree').self::link('/studio/history','Revision history').self::link('/studio/reviews','Reviews').'</div>'.PageTreeView::list($db,$edit).'<p>Move or rename pages in the page tree. Publish tree to apply navigation order and URLs.</p>'.(isset($p['link_base'])?'<p>Relative links in this moved or duplicated page resolve from <code>'.self::e($p['link_base']).'</code>. Use absolute documentation URLs for new links when needed.</p>':'').'<h2>'.($entry?'Edit page':'Create page').'</h2><form method="post" action="'.WorkspaceUrls::url('/studio').'" class="editor" data-editor-form data-can-publish="'.($admin?'1':'0').'">'.$hidden.'<input type="hidden" name="revision" value="'.($entry['revision']??0).'">'.$field('book','Book slug',$parts[0],(bool)$entry).$field('version','Version',$parts[1],(bool)$entry).$field('path','Page path',$parts[2],(bool)$entry).$field('title','Title',$p['title']).$field('section','Section',$p['section']).$field('order','Draft navigation order',$p['order']).StudioEditor::markup($p['body']).'<label>Revision note (optional, manual save/publish)<input name="note" maxlength="500" placeholder="What changed?"></label><div class="actions"><button name="action" value="save">Save draft</button>'.($directPublish?'<button class="primary" name="action" value="publish">Publish</button>':'').'</div></form>';
        if(ReviewWorkflow::required($db))$html.='<p class="tree-notice">Review required. Save the draft, then submit its saved revision for approval. '.self::link('/studio/reviews','Open reviews').'</p>';
        if($entry){$html.='<p>'.self::link('/studio/reviews?kind=page&target='.rawurlencode($edit),'Review saved draft').'</p><p data-page-history>'.self::link('/studio/history?page='.PageTree::id($edit,$entry),'Page history').'</p><div data-saved-preview><h2>Saved draft preview</h2><article>'.Markdown::render($p['body'],$edit,false,true,$p['link_base']??null,PageTree::linkMap($db))['html'].'</article></div>'.($admin?'<form method="post" action="'.WorkspaceUrls::url('/studio').'" class="actions" data-page-actions>'.$hidden.'<input type="hidden" name="key" value="'.self::e($edit).'"><input type="hidden" name="revision" value="'.$entry['revision'].'"><button name="action" value="unpublish">Unpublish</button><button name="action" value="delete" data-confirm="Delete this page? Retained history remains recoverable.">Delete</button></form>':'');}
        $html.='<h2>Copy version to drafts</h2><form method="post" action="'.WorkspaceUrls::url('/studio').'" class="editor">'.$hidden.$field('book','Book','handbook').$field('from','Source version','1.0').$field('to','New version','2.0').'<button name="action" value="copy">Copy published pages</button></form>';$this->page('Studio',$html,BookStore::published($db));
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
