<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Template\{CajeerTemplateEngine,TemplateSandbox};
use CajeerEngine\Runtime\ServiceContainer;
final class App {
    private BookStore $store; private MediaStore $media; private MediaController $mediaController; private CajeerTemplateEngine $view;
    private string $dataDir; private readonly string $installationDir; private ?array $workspace=null; private ?array $scopedIdentity=null; private string $studioRole='none'; private string $readerVisibility='public'; private AuthController $auth; private IdentityStore $identityStore;
    public function __construct(private readonly string $root){
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
        WorkspaceUrls::set('');
        try{
            if(str_starts_with($path,'/w/')){
                if(!preg_match('~\A/w/([a-f0-9]{32})/([a-f0-9]{32})(/.*)?\z~D',$path,$m)){http_response_code(404);return;}
                $inner=$m[3]??'/';
                if(!preg_match('~\A/(?:$|(?:studio|docs|media|api/v1|search)(?:/|$))~D',$inner)){http_response_code(404);return;}
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
                $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($path,$method){$this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';$this->dispatch($path,$method);});return;
            }
            // Account, control-plane and health routes must remain reachable even when legacy content is private.
            if(in_array($path,['/login','/logout','/account','/account/users','/account/reset','/workspaces','/organizations','/health/live','/health/ready','/diagram-frame'],true)||str_starts_with($path,'/organizations/')){$this->dispatch($path,$method);return;}
            $this->identityStore->withLegacy($this->auth->token(),function($ctx,$role,$w,$visible)use($path,$method){
                $this->scopedIdentity=$ctx;$this->studioRole=$role;$this->readerVisibility=$w?Visibility::workspace($w):'public';
                header('Cache-Control: private, no-store');header('Vary: Cookie');if($ctx||!$visible||$this->readerVisibility==='private')header('X-Robots-Tag: noindex, nofollow');
                if(!$visible){$this->unavailable($path,$ctx);return;}$this->dispatch($path,$method);
            });
        }catch(\Throwable $e){error_log((string)$e);http_response_code(503);echo 'Service unavailable';}
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
            if($path==='/workspaces'){(new WorkspaceController($this->identityStore,$this->auth))->dispatch($method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/organizations'||str_starts_with($path,'/organizations/')){(new OrganizationController($this->identityStore,$this->auth))->dispatch($path,$method,fn($title,$body)=>$this->page($title,$body));return;}
            if($path==='/studio'||str_starts_with($path,'/studio/')){$this->studio($path,$method);return;}
            if($method==='POST'){http_response_code(405);header('Allow: GET, HEAD');return;}
            if(str_starts_with($path,'/media/')){$this->mediaController->serve(substr($path,7),false,$method,fn($db,$id)=>Visibility::media($db,$id,$this->readerVisibility,$this->studioRole));return;}
            if($path==='/health/live'){$this->json(['status'=>'ok']);return;}
            if($path==='/health/ready'){
                try { $this->store->checkReady(); $this->identityStore->checkReady(); $this->identityStore->checkWorkspaceStores($this->installationDir); ReleaseHealth::check($this->root); $this->json(['status'=>'ok']); }
                catch(\Throwable $e){error_log('Readiness: '.$e->getMessage());http_response_code(503);$this->json(['status'=>'unavailable']);}
                return;
            }
            $db=$this->store->read();$pages=Visibility::pages($db,$this->readerVisibility,$this->studioRole);
            if($path==='/api/v1/pages'){$this->json(array_map(fn($p)=>array_diff_key($p,['body'=>true,'link_base'=>true]),$pages));return;}
            if(str_starts_with($path,'/api/v1/pages/')){$key=substr($path,14);if(!isset($pages[$key])){if(($target=PageTree::redirect($db,$key))&&isset($pages[$target])){$this->redirect('/api/v1/pages/'.$target);return;}http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->json(['error'=>'not_found']);return;}$this->json(array_diff_key($pages[$key],['link_base'=>true]));return;}
            if($path==='/search'||$path==='/api/v1/search'){$q=is_string($_GET['q']??null)?trim(substr($_GET['q'],0,200)):'';$hits=[];foreach($pages as $key=>$p){if($q!==''&&preg_match('/'.preg_quote($q,'/').'/iu',$p['title'].' '.$p['body']))$hits[]=['key'=>$key,'title'=>$p['title'],'url'=>self::url($key)];if(count($hits)>=50)break;}if($path==='/api/v1/search'){$this->json($hits);return;}$html='<div class="eyebrow">SEARCH</div><h1>Search documentation</h1><p>'.count($hits).' results</p>';foreach($hits as $hit)$html.='<div class="result">'.self::link($hit['url'],$hit['title']).'</div>';$this->page('Search',$html,$pages,'','',$q);return;}
            if($path==='/'){$books=BookStore::books($db);$html='<div class="eyebrow">CAJEERENGINE BOOK / DOCUMENTATION</div><h1>Documentation, on your terms.</h1><p class="lead">A self-hosted knowledge base for your products, APIs and teams.</p><div class="cards">';$seen=[];foreach($pages as $key=>$p){[$b,$v]=explode('/',$key);if(isset($seen["$b/$v"]))continue;$seen["$b/$v"]=true;$html.='<div class="tile"><div class="eyebrow">'.self::e($v).'</div><h2>'.self::e($books[$b]['title']??$b).'</h2>'.self::link(self::url($key),'Read documentation →').'</div>';}$this->page('Documentation',$html.'</div>',$pages);return;}
            if(str_starts_with($path,'/docs/')){$key=substr($path,6);if(isset($pages[$key])){$p=$pages[$key];$r=Markdown::render($p['body'],$key,false,false,$p['link_base']??null,Visibility::links($db,$pages));$toc='<div class="side-label">ON THIS PAGE</div>';foreach($r['toc'] as $h)$toc.=self::link('#'.$h['id'],$h['title'],'toc-level-'.$h['level']);[$b,$v]=explode('/',$key);$prefix="$b/$v/";$siblings=array_filter($pages,fn($k)=>str_starts_with($k,$prefix),ARRAY_FILTER_USE_KEY);$keys=array_keys($siblings);$i=array_search($key,$keys,true);$pager='<div class="pager">';if($i>0)$pager.=self::link(self::url($keys[$i-1]),'← '.$siblings[$keys[$i-1]]['title']);if(isset($keys[$i+1]))$pager.=self::link(self::url($keys[$i+1]),$siblings[$keys[$i+1]]['title'].' →');$versions=[];foreach($pages as $k=>$vp){[$vb,$vv]=explode('/',$k);if($vb===$b&&!isset($versions[$vv]))$versions[$vv]=$k;} $switch='<div class="versions">Versions: ';foreach($versions as $vv=>$k)$switch.=self::link(self::url(isset($pages["$b/$vv/".substr($key,strlen($prefix))])?"$b/$vv/".substr($key,strlen($prefix)):$k),$vv,$vv===$v?'active':'');$switch.='</div>'; $html=$switch.'<div class="eyebrow">'.self::e("$b / $v / ".$p['section']).'</div><h1>'.self::e($p['title']).'</h1><article>'.$r['html'].'</article>'.$pager.'</div>';$this->page($p['title'],$html,$siblings,$key,$toc);return;}if(($target=PageTree::redirect($db,$key))&&isset($pages[$target])){$this->redirect('/docs/'.$target);return;}}
            http_response_code(404);header('X-Robots-Tag: noindex, nofollow');$this->page('Not found','<h1>Page not found</h1><p>This page is missing or not published.</p>',$pages);
        }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:400);$this->page('Invalid request','<h1>Invalid request</h1><p>'.self::e($e->getMessage()).'</p>');}catch(\Throwable $e){error_log((string)$e);http_response_code(503);echo 'Service unavailable';}
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
            if($path==='/studio/media'){$this->mediaController->post($csrf);return;}
            if($path==='/studio/books'){try{$action=$_POST['action']??'';$slug=is_string($_POST['slug']??null)?$_POST['slug']:'';
                if($action==='create')$this->store->createBook($slug,is_string($_POST['title']??null)?$_POST['title']:'');
                elseif($action==='visibility'){$rev=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($rev===false)throw new \InvalidArgumentException('Invalid revision');$this->store->setBookVisibility($slug,is_string($_POST['visibility']??null)?$_POST['visibility']:'',$rev);}
                else throw new \InvalidArgumentException('Unknown book action');header('Location: '.WorkspaceUrls::url('/studio/books'),true,303);}catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$this->page('Books',$this->booksMarkup($csrf,$e->getMessage()));}return;}
            $action=$_POST['action']??'';$str=fn($k)=>is_string($_POST[$k]??null)?$_POST[$k]:'';
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
        if($path==='/studio/history'){ $this->page('Revision history',HistoryView::render($this->store->read(),$csrf,$_GET));return; }
        if($path==='/studio/books'){$this->page('Books',$this->booksMarkup($csrf));return;}
        if($path==='/studio/media/picker'){$this->mediaController->picker();return;}
        if($path==='/studio/media'){$this->page('Media library',$this->mediaController->markup($csrf,$admin,$this->readerVisibility));return;}
        if(str_starts_with($path,'/studio/media/file/')){$this->mediaController->serve(substr($path,19),true,$method);return;}
        if($path==='/studio/media/backup'){(new MediaBackup($this->dataDir,$this->store,$this->media))->download($method);return;}
        $db=$this->store->read();if($path==='/studio/tree'){$scope=is_string($_GET['scope']??null)?$_GET['scope']:'';$notice=match($_GET['notice']??''){'published'=>'Tree published. Draft content unchanged.','saved'=>'Draft tree saved.',default=>''};$this->page('Page tree',PageTreeView::studio($db,$csrf,$scope,$notice,$admin),BookStore::published($db));return;}if($path==='/studio/export'){header('Content-Disposition: attachment; filename="cajeerbook-backup.json"');$this->json($db);return;}if($path!=='/studio'){http_response_code(404);return;}
        $edit=is_string($_GET['edit']??null)?$_GET['edit']:'';$entry=$db['pages'][$edit]??null;
        if($edit!==''&&!$entry){if($target=PageTree::draftRedirect($db,$edit)){header('Location: '.WorkspaceUrls::url('/studio?edit='.rawurlencode($target)),true,303);return;}http_response_code(404);$this->page('Page not found','<h1>Page not found</h1><a href="'.WorkspaceUrls::url('/studio/tree').'">Open page tree</a>');return;}$p=$entry['draft']??['title'=>'','section'=>'Getting started','order'=>10,'body'=>''];$parts=$entry?explode('/',$edit,3):[$this->workspace?(string)(array_key_first(BookStore::books($db))??''):'handbook','1.0',''];
        if(!$entry&&is_string($_GET['book']??null)&&isset(BookStore::books($db)[$_GET['book']]))$parts[0]=$_GET['book'];
        if(!$entry&&is_string($_GET['new_under']??null))$parts=PageTreeView::newUnder($db,$_GET['new_under'])??$parts;
        $field=fn($name,$label,$value,$readonly=false)=>'<label>'.self::e($label).'<input name="'.$name.'" value="'.self::e((string)$value).'" '.($readonly?'readonly':'').' ></label>';
        $html='<div class="eyebrow">AUTHORING / STUDIO</div><h1>Documentation Studio</h1><p>Drafts and published snapshots are separate.</p><div class="actions">'.self::link('/studio','New page').self::link('/studio/books','Books').($admin?self::link('/studio/export','Download JSON backup'):'').self::link('/studio/media','Media library').($admin?self::link('/studio/media/backup','Content backup (.tar)'):'').self::link('/studio/tree','Page tree').self::link('/studio/history','Revision history').'</div>'.PageTreeView::list($db,$edit).'<p>Move or rename pages in the page tree. Publish tree to apply navigation order and URLs.</p>'.(isset($p['link_base'])?'<p>Relative links in this moved or duplicated page resolve from <code>'.self::e($p['link_base']).'</code>. Use absolute documentation URLs for new links when needed.</p>':'').'<h2>'.($entry?'Edit page':'Create page').'</h2><form method="post" action="'.WorkspaceUrls::url('/studio').'" class="editor" data-editor-form data-can-publish="'.($admin?'1':'0').'">'.$hidden.'<input type="hidden" name="revision" value="'.($entry['revision']??0).'">'.$field('book','Book slug',$parts[0],(bool)$entry).$field('version','Version',$parts[1],(bool)$entry).$field('path','Page path',$parts[2],(bool)$entry).$field('title','Title',$p['title']).$field('section','Section',$p['section']).$field('order','Draft navigation order',$p['order']).StudioEditor::markup($p['body']).'<label>Revision note (optional, manual save/publish)<input name="note" maxlength="500" placeholder="What changed?"></label><div class="actions"><button name="action" value="save">Save draft</button>'.($admin?'<button class="primary" name="action" value="publish">Publish</button>':'').'</div></form>';
        if($entry){$html.='<p data-page-history>'.self::link('/studio/history?page='.PageTree::id($edit,$entry),'Page history').'</p><div data-saved-preview><h2>Saved draft preview</h2><article>'.Markdown::render($p['body'],$edit,false,true,$p['link_base']??null,PageTree::linkMap($db))['html'].'</article></div>'.($admin?'<form method="post" action="'.WorkspaceUrls::url('/studio').'" class="actions" data-page-actions>'.$hidden.'<input type="hidden" name="key" value="'.self::e($edit).'"><input type="hidden" name="revision" value="'.$entry['revision'].'"><button name="action" value="unpublish">Unpublish</button><button name="action" value="delete" data-confirm="Delete this page? Retained history remains recoverable.">Delete</button></form>':'');}
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
