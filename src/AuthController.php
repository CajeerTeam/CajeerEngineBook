<?php
declare(strict_types=1);
namespace CajeerBook;

/** Cookie-based authentication. No bearer credentials in URLs, HTML or browser storage. */
final class AuthController
{
    public function __construct(private readonly IdentityStore $store){}
    private static function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function secure():bool{return getenv('BOOK_SECURE_COOKIE')!=='0'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');}
    private static function cookieName(string $name):string{return (self::secure()?'__Host-':'').'book_'.$name;}
    private static function cookie(string $name,string $value,int $expires=0):void{
        if(!setcookie(self::cookieName($name),$value,['expires'=>$expires,'path'=>'/','secure'=>self::secure(),'httponly'=>true,'samesite'=>'Strict']))throw new \RuntimeException('Cannot set authentication cookie');
    }
    private static function readCookie(string $name):string{$s=$_COOKIE[self::cookieName($name)]??'';return is_string($s)&&preg_match('/\A[a-f0-9]{64}\z/D',$s)?$s:'';}
    public function preauthToken():string{
        $csrf=self::readCookie('preauth');if($csrf===''&&($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){$csrf=bin2hex(random_bytes(32));self::cookie('preauth',$csrf,time()+1800);}return $csrf;
    }
    public function token():string{return self::readCookie('session');}
    public function current():?array{return $this->store->current($this->token());}
    private static function field(string $name,string $label,string $type='text',string $value='',string $autocomplete='off'):string{return '<label>'.self::e($label).'<input required name="'.$name.'" type="'.$type.'" value="'.self::e($value).'" autocomplete="'.$autocomplete.'" maxlength="'.($type==='password'?1024:254).'"></label>';}
    private static function hidden(string $csrf):string{return '<input type="hidden" name="csrf" value="'.self::e($csrf).'">';}
    private static function form(string $path,string $csrf,string $body,string $button,string $action=''):string{return '<form class="editor" method="post" action="'.$path.'">'.self::hidden($csrf).($action!==''?'<input type="hidden" name="action" value="'.self::e($action).'">':'').$body.'<button class="primary" type="submit">'.self::e($button).'</button></form>';}
    private static function redirect(string $path):void{header('Location: '.$path,true,303);}
    private static function ip():string{$s=$_SERVER['REMOTE_ADDR']??'';return is_string($s)&&filter_var($s,FILTER_VALIDATE_IP)?$s:'unknown';}
    private static function post(string $name):string{return is_string($_POST[$name]??null)?$_POST[$name]:'';}
    private static function csrf(string $expected):bool{return $expected!==''&&hash_equals($expected,self::post('csrf'));}
    private static function issue(array $result):void{self::cookie('session',$result['token'],$result['session']['expires']);self::cookie('preauth','',time()-3600);}
    private static function clear():void{self::cookie('session','',time()-3600);}
    private static function notice(string $s):string{return $s!==''?'<p role="alert" class="auth-notice">'.self::e($s).'</p>':'';}
    public function dispatch(string $path,string $method,callable $page):void{
        header('X-Robots-Tag: noindex, nofollow');header('Cache-Control: no-store');
        if(!in_array($path,['/login','/logout','/account','/account/users','/account/reset'],true)){http_response_code(404);return;}
        $ctx=$this->current();$token=$this->token();$error='';
        if($path==='/login'||$path==='/account/reset'){
            if($path==='/login'&&$ctx){self::redirect('/account');return;}
            $csrf=self::readCookie('preauth');
            if($method==='POST'){
                if(!self::csrf($csrf)){http_response_code(403);$page('Invalid request','<h1>Invalid CSRF token</h1><p>Reload the form and try again.</p>');return;}
                try{
                    if($path==='/login'){
                        $result=$this->store->login(self::post('username'),self::post('password'),self::ip(),getenv('BOOK_ADMIN_HASH')?:'');$status=$result['status'];
                        if($status==='ok'){self::issue($result);self::redirect($result['user']['owner']?'/studio':'/account');return;}
                    }else{
                        if(self::post('password')!==self::post('confirm'))throw new \InvalidArgumentException('Passwords do not match.');
                        $status=$this->store->reset(self::post('token'),self::post('password'),self::ip());
                        if($status==='ok'){self::clear();self::cookie('preauth','',time()-3600);self::redirect('/login?reset=1');return;}
                    }
                    http_response_code($status==='limited'?429:401);if($status==='limited')header('Retry-After: 900');$error=$status==='limited'?'Too many attempts. Try again later.':($path==='/login'?'Invalid username or password.':'Invalid or expired recovery token.');
                }catch(\InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
            }
            if($csrf===''){$csrf=bin2hex(random_bytes(32));self::cookie('preauth',$csrf,time()+1800);}
            if($path==='/login'){
                $setup=!$this->store->initialized()&&!getenv('BOOK_ADMIN_HASH')?'<p>Installation not initialized. Run the operator CLI <code>bin/identity.php init</code>.</p>':'';
                $page('Sign in','<div class="auth-panel"><h1>Sign in</h1>'.self::notice($error).(isset($_GET['reset'])?'<p>Password reset complete. Sign in with your new password.</p>':'').(isset($_GET['joined'])?'<p>Invitation accepted. Sign in with your new account.</p>':'').$setup.self::form('/login',$csrf,self::field('username','Username','text','','username').self::field('password','Password','password','','current-password'),'Sign in').'<p><a href="/account/reset">Recover access</a></p></div>');
            }else $page('Recover access','<div class="auth-panel"><h1>Recover access</h1><p>Ask the installation operator for a one-time recovery token. It expires after 30 minutes.</p>'.self::notice($error).self::form('/account/reset',$csrf,self::field('token','Recovery token','password','','off').self::field('password','New password','password','','new-password').self::field('confirm','Confirm password','password','','new-password'),'Reset password').'<p><a href="/login">Sign in</a></p></div>');
            return;
        }
        if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1><p><a href="/login">Sign in</a></p>');return;}
        $user=$ctx['user'];$csrf=$ctx['session']['csrf'];
        if($path==='/account/users'&&!$user['owner']){http_response_code(403);$page('Forbidden','<h1>Owner permission required</h1>');return;}
        if($path==='/logout'&&$method!=='POST'){http_response_code(405);header('Allow: POST');return;}
        if($method==='POST'){
            if(!self::csrf($csrf)){http_response_code(403);$page('Invalid request','<h1>Invalid CSRF token</h1>');return;}
            try{
                if($path==='/logout'){$this->store->logout($token);self::clear();self::redirect('/login');return;}
                $action=self::post('action');
                if($path==='/account/users'){
                    if($action==='create')$this->store->createUser($token,self::post('username'),self::post('name'),self::post('email'),self::post('password'));
                    elseif(in_array($action,['enable','disable'],true))$this->store->setEnabled($token,self::post('id'),$action==='enable');
                    else throw new \InvalidArgumentException('Unknown user action.');
                }elseif($action==='profile')$this->store->profile($token,self::post('name'),self::post('email'));
                elseif($action==='password'){
                    if(self::post('password')!==self::post('confirm'))throw new \InvalidArgumentException('Passwords do not match.');
                    $r=$this->store->changePassword($token,self::post('old'),self::post('password'),self::ip());
                    if($r['status']==='ok'){self::issue($r);self::redirect('/account?updated=1');return;}
                    if($r['status']==='limited'){http_response_code(429);header('Retry-After: 900');$error='Too many attempts. Try again later.';}else{http_response_code(422);$error='Current password is incorrect.';}
                }elseif($action==='revoke'){
                    $id=self::post('id');$this->store->revokeSession($token,$id);
                    if($id==='all'||$id===$ctx['session']['id']){self::clear();self::redirect('/login');return;}
                }else throw new \InvalidArgumentException('Unknown account action.');
                if($error===''){self::redirect($path.'?updated=1');return;}
            }catch(\InvalidArgumentException|\DomainException $e){http_response_code($e instanceof \DomainException?409:422);$error=$e->getMessage();}
        }
        $html='<div class="auth-panel"><h1>'.($path==='/account/users'?'User management':'Your account').'</h1>'.self::notice($error).(isset($_GET['updated'])?'<p>Changes saved.</p>':'').'<p><a href="/account">Account</a> · <a href="/organizations">Organizations</a>'.($user['owner']?' · <a href="/studio">Studio</a> · <a href="/account/users">Users</a>':'').'</p>';
        if($path==='/account/users'){
            $html.='<h2>Create user</h2>'.self::form($path,$csrf,self::field('username','Username','text','','username').self::field('name','Display name').self::field('email','Email','email').self::field('password','Initial password (12–72 bytes)','password','','new-password'),'Create account','create').'<h2>Accounts</h2>';
            foreach($this->store->users($token) as $u){$html.='<div class="account-row"><strong>'.self::e($u['name']).'</strong><p>'.self::e($u['username'].' · '.$u['email']).' · '.($u['owner']?'Installation owner':($u['enabled']?'Active':'Disabled')).'</p>';if(!$u['owner'])$html.=self::form($path,$csrf,'<input type="hidden" name="id" value="'.$u['id'].'">',$u['enabled']?'Disable account':'Enable account',$u['enabled']?'disable':'enable');$html.='</div>';}
        }else{
            $html.='<p>Signed in as <strong>'.self::e($user['username']).'</strong>'.($user['owner']?' · Installation owner':' · Personal account').'</p><h2>Profile</h2>'.self::form('/account',$csrf,self::field('name','Display name','text',$user['name']).self::field('email','Email','email',$user['email'],'email'),'Save profile','profile').'<h2>Change password</h2>'.self::form('/account',$csrf,self::field('old','Current password','password','','current-password').self::field('password','New password (12–72 bytes)','password','','new-password').self::field('confirm','Confirm password','password','','new-password'),'Change password','password').'<h2>Active sessions</h2><p>Changing your password or recovering access signs out all previous sessions.</p>';
            foreach($this->store->sessions($token) as $s)$html.='<div class="account-row"><p>Created '.self::e(gmdate('Y-m-d H:i',$s['created']).' UTC · Last active '.gmdate('Y-m-d H:i',$s['seen']).' UTC').($s['id']===$ctx['session']['id']?' · Current session':'').'</p>'.self::form('/account',$csrf,'<input type="hidden" name="id" value="'.$s['id'].'">','Revoke session','revoke').'</div>';
            $html.=self::form('/account',$csrf,'<input type="hidden" name="id" value="all">','Sign out all sessions','revoke');
        }
        $html.=self::form('/logout',$csrf,'','Sign out').'</div>';$page('Account',$html);
    }
}
