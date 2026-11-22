<?php
declare(strict_types=1);
namespace CajeerBook;

/** Installation-owner-only controls; no Docker credentials or root commands in PHP. */
final class UpdateController
{
    public function __construct(private readonly IdentityStore $identity,private readonly AuthController $auth,
        private readonly string $root,private readonly string $installationDir,private readonly ?UpdateCenter $center=null) {}
    private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function center():UpdateCenter{return $this->center??new UpdateCenter($this->root,$this->installationDir);}
    public function dispatch(string $method,callable $page):void
    {
        header('Cache-Control: private, no-store');header('Vary: Cookie');header('X-Robots-Tag: noindex, nofollow');
        if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);header('Allow: GET, HEAD, POST');return;}
        $ctx=$this->auth->current();
        if(!$ctx){http_response_code(401);$page('Sign in required','<h1>Sign in required</h1><a href="/login">Sign in</a>');return;}
        if(($ctx['user']['owner']??false)!==true){http_response_code(403);$page('Forbidden','<h1>Installation owner permission required</h1>');return;}
        $notice='';$error='';$result=null;$center=$this->center();
        if($method==='POST'){
            $csrf=$_POST['csrf']??null;$action=$_POST['action']??null;
            if(!is_string($csrf)||!hash_equals($ctx['session']['csrf'],$csrf)){http_response_code(403);$page('Forbidden','<h1>Invalid CSRF token</h1>');return;}
            if(!in_array($action,['check','prepare'],true)){http_response_code(422);$page('Invalid action','<h1>Unsupported update action</h1>');return;}
            $channel=$_POST['channel']??'stable';
            if(!is_string($channel)||!in_array($channel,['stable','beta'],true)){http_response_code(422);$page('Invalid channel','<h1>Unsupported release channel</h1>');return;}
            try{
                // Network and signature work is done WITHOUT holding the identity database lock.
                $this->identity->updateCenterAuthorize($this->auth->token(),$csrf);
                $result=$center->check($channel);
                if($action==='prepare'){
                    $digest=$_POST['manifest_sha256']??null;
                    if(!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)||($result['status']??'')!=='available'||
                        !hash_equals($result['candidate']['manifest_sha256'],$digest))throw new \DomainException('Release changed or is not available; run Check updates again.');
                }
                // Reauthorize and audit the exact successful operator action after network access.
                $this->identity->updateCenterAction($this->auth->token(),$csrf,$action,
                    $result['candidate']['version']??'none');
                $center->save($result,$action==='prepare');
                $notice=$action==='prepare'?'Verified release metadata pinned. The host updater must still fetch and verify all assets.':'GitHub release check completed.';
            }catch(\Throwable $e){
                // Do not reveal filesystem paths, credentials, or raw network failures to browsers.
                $message=$e instanceof \DomainException?$e->getMessage():'Unable to verify the GitHub release. Check server configuration and operator logs.';
                $error=$message;http_response_code($e instanceof \DomainException?409:503);
                Observability::log('update.center.failure',['error_type'=>get_class($e)]);
            }
        }
        $h='<div class="auth-panel org-panel"><div class="eyebrow">ADMINISTRATION / UPDATES</div><h1>System Updates</h1>'
            .'<p><a href="/studio">Studio</a> · Installation owner only</p>'
            .'<p>Installed Book version: <strong>'.self::e($center->installed()).'</strong> · Repository: '
            .'<a href="https://github.com/CajeerTeam/CajeerEngineBook/releases" rel="noopener noreferrer">CajeerTeam/CajeerEngineBook Releases</a></p>'
            .'<p>Checking and preparing metadata never installs software, downloads deployment archives or calls Docker. '
            .'The host-side signed updater remains the only supported installation path.</p>';
        if($notice!=='')$h.='<p role="status">'.self::e($notice).'</p>';
        if($error!=='')$h.='<p role="alert">'.self::e($error).'</p>';
        try{$cached=$result??$center->cached();$prepared=$center->cached(true);}catch(\Throwable){$cached=$result;$prepared=null;$h.='<p role="alert">Stored update metadata could not be read safely.</p>';}
        if($cached){
            $h.='<h2>Last check</h2><p>Status: <strong>'.self::e((string)$cached['status']).'</strong> · Channel: '.self::e((string)$cached['channel'])
                .' · Checked: '.self::e((string)$cached['checked_at']).'</p>';
            if(!empty($cached['pagination_truncated']))$h.='<p role="alert">GitHub inventory is incomplete. No up-to-date conclusion can be made.</p>';
            if(is_array($c=$cached['candidate']??null)){
                $h.='<section class="org-card"><h3>'.self::e($c['tag']).' · '.self::e($c['name']).'</h3>'
                    .'<p>Commit: <code>'.self::e($c['commit']).'</code> · Schema: '.(int)$c['schema'].($c['requires_schema_upgrade']?' (offline migration required)':'').'</p>'
                    .'<p>Signed manifest SHA-256: <code>'.self::e($c['manifest_sha256']).'</code></p>'
                    .'<p>Published: '.self::e($c['published_at']).' · <a href="'.self::e('https://github.com/CajeerTeam/CajeerEngineBook/releases/tag/v'.$c['version']).'" rel="noopener noreferrer">Release on GitHub</a></p>'
                    .'<details><summary>Release notes</summary><pre class="update-release-notes">'.self::e($c['changelog']).'</pre></details></section>';
                if($method!=='HEAD'&&$cached['status']==='available'){
                    // Pin only the exact cryptographically verified manifest from this request.
                    $h.='<form method="post" action="/admin/updates" class="editor">'
                       .'<input type="hidden" name="csrf" value="'.self::e($ctx['session']['csrf']).'">'
                       .'<input type="hidden" name="action" value="prepare">'
                       .'<input type="hidden" name="channel" value="'.self::e($cached['channel']).'">'
                       .'<input type="hidden" name="manifest_sha256" value="'.self::e($c['manifest_sha256']).'">'
                       .'<button type="submit">Pin verified candidate for host preparation</button></form>';
                }
            }
            if(!empty($cached['unsupported']))$h.='<p>Prerelease versions found, but the current signed installer supports stable versions only.</p>';
            foreach(array_slice($cached['rejected']??[],0,8) as $bad)$h.='<p role="alert">Rejected '.self::e($bad['tag']).': '.self::e($bad['reason']).'</p>';
        }
        if(is_array($prepared['candidate']??null))$h.='<p>Previously pinned candidate: <strong>'.self::e($prepared['candidate']['tag']).'</strong>, '
            .self::e($prepared['checked_at']).'. Requires a fresh host-side <code>fetch</code> and <code>preflight</code>.</p>';
        $h.='<form method="post" action="/admin/updates" class="editor">'
            .'<input type="hidden" name="csrf" value="'.self::e($ctx['session']['csrf']).'">'
            .'<input type="hidden" name="action" value="check">'
            .'<label>Release channel<select name="channel"><option value="stable">Stable</option><option value="beta">Beta (discovery only)</option></select></label>'
            .'<button type="submit" class="primary">Check GitHub releases</button></form>'
            .'<p>Configure <code>BOOK_UPDATE_PUBLIC_KEY_FILE</code> to an independently verified Ed25519 public PEM key, '
            .'on the application host. Neither this page nor a GitHub download may change the trusted key.</p>'
            .'<p>Operator commands: <code>update.py check</code> → <code>fetch</code> → <code>preflight</code> → '
            .'<code>apply --offline</code> (after maintenance fencing). Never run the privileged updater from PHP.</p></div>';
        $page('System Updates',$h);
    }
}
