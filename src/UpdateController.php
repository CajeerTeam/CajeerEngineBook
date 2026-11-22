<?php
declare(strict_types=1);
namespace CajeerBook;

/** Installation-owner-only controls; no Docker credentials or root commands in PHP. */
final class UpdateController
{
    public function __construct(private readonly IdentityStore $identity,private readonly AuthController $auth,
        private readonly string $root,private readonly string $installationDir,private readonly ?UpdateCenter $center=null,
        private readonly ?UpdateAgentClient $agent=null) {}
    private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function center():UpdateCenter{return $this->center??new UpdateCenter($this->root,$this->installationDir);}
    private function agent():UpdateAgentClient{return $this->agent??new UpdateAgentClient();}
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
            if(!in_array($action,['check','prepare','fetch','preflight','apply'],true)){http_response_code(422);$page('Invalid action','<h1>Unsupported update action</h1>');return;}
            try{
                // Owner, session and durable CSRF/rate-limit checks precede host RPC.
                $this->identity->updateCenterAuthorize($this->auth->token(),$csrf);
                if(in_array($action,['check','prepare'],true)){
                    $channel=$_POST['channel']??'stable';
                    if(!is_string($channel)||!in_array($channel,['stable','beta'],true))throw new \DomainException('Unsupported release channel');
                    $result=$center->check($channel);
                    if($action==='prepare'){
                        $digest=$_POST['manifest_sha256']??null;
                        if(!is_string($digest)||!preg_match('/\A[a-f0-9]{64}\z/D',$digest)||($result['status']??'')!=='available'||
                            !hash_equals($result['candidate']['manifest_sha256'],$digest))throw new \DomainException('Release changed; check GitHub again.');
                    }
                    $this->identity->updateCenterAction($this->auth->token(),$csrf,$action,$result['candidate']['version']??'none');
                    $center->save($result,$action==='prepare');
                    $notice=$action==='prepare'?'Signed candidate pinned; host fetch/preflight is now available.':'GitHub release check completed.';
                }else{
                    $pinned=$center->cached(true);
                    $candidate=$pinned['candidate']??null;
                    $digest=$_POST['manifest_sha256']??null;
                    if(!is_array($pinned)||($pinned['status']??'')!=='available'||($pinned['channel']??'')!=='stable'||
                        ($pinned['installed']??'')!==$center->installed()||!is_array($candidate)||
                        !is_string($digest)||!hash_equals((string)($candidate['manifest_sha256']??''),$digest))
                        throw new \DomainException('Pinned candidate changed. Check and prepare the release again.');
                    if($action==='apply'&&($_POST['confirm_maintenance']??'')!=='yes')
                        throw new \DomainException('Confirm maintenance fencing and external writer shutdown before activation.');
                    $agent=$this->agent();
                    if(!$agent->configured())throw new \DomainException('Host agent is not configured.');
                    // Log the owner request before it enters the privileged host control plane.
                    $this->identity->updateCenterAction($this->auth->token(),$csrf,$action,$candidate['version']);
                    $operation=$agent->call($action,['version'=>$candidate['version'],
                        'commit'=>$candidate['commit'],'manifest_sha256'=>$candidate['manifest_sha256']]);
                    $notice=ucfirst($action).' accepted by the host agent ('.(string)$operation['state'].'). Follow the status below.';
                }
            }catch(\Throwable $e){
                $message=$e instanceof \DomainException?$e->getMessage():'Unable to complete update operation; consult server or host operator logs.';
                $error=$message;http_response_code($e instanceof \DomainException?409:503);
                Observability::log('update.center.failure',['error_type'=>get_class($e)]);
            }
        }
        $h='<div class="auth-panel org-panel"><div class="eyebrow">ADMINISTRATION / UPDATES</div><h1>System Updates</h1>'
            .'<p><a href="/studio">Studio</a> · Installation owner only</p>'
            .'<p>Installed Book version: <strong>'.self::e($center->installed()).'</strong> · Repository: '
            .'<a href="https://github.com/CajeerTeam/CajeerEngineBook/releases" rel="noopener noreferrer">CajeerTeam/CajeerEngineBook Releases</a></p>'
            .'<p>Signed metadata discovery is separate from privileged installation. When configured, '
            .'a host-owned update agent stages and activates only the pinned signed candidate; no Docker credentials are present in PHP.</p>';
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
        // Owner-only state is read through allowlisted local RPC, not a web-writable journal.
        $agent=$this->agent();
        if($agent->configured()){
            try{
                $operation=$agent->call('status');
                $h.='<h2>Host execution status</h2><p>State: <strong>'.self::e((string)$operation['state']).'</strong>'
                    .' · Verified stage: '.self::e((string)($operation['stage']??'none'))
                    .' · Action: '.self::e((string)($operation['action']??'none'))
                    .' · Result: '.self::e((string)($operation['result']??'pending')).'</p>';
                $preparedCandidate=$prepared['candidate']??null;
                if($method!=='HEAD'&&is_array($preparedCandidate)&&($prepared['status']??'')==='available'&&
                    ($prepared['channel']??'')==='stable'&&($prepared['installed']??'')===$center->installed()&&
                    !in_array($operation['state'],['running','queued','interrupted'],true)&&
                    !(($operation['action']??'')==='apply'&&($operation['state']??'')==='failed')){
                    $matches=($operation['candidate']??null)===['version'=>$preparedCandidate['version'],
                        'commit'=>$preparedCandidate['commit'],'manifest_sha256'=>$preparedCandidate['manifest_sha256']];
                    $next=(!$matches||($operation['stage']??'none')==='none')?'fetch':
                        (($operation['stage']??'none')==='fetched'?'preflight':
                        (($operation['stage']??'none')==='preflight'?'apply':null));
                    if($next!==null){
                        $h.='<form method="post" action="/admin/updates" class="editor">'
                            .'<input type="hidden" name="csrf" value="'.self::e($ctx['session']['csrf']).'">'
                            .'<input type="hidden" name="action" value="'.self::e($next).'">'
                            .'<input type="hidden" name="manifest_sha256" value="'.self::e($preparedCandidate['manifest_sha256']).'">';
                        if($next==='apply'){
                            $h.='<p role="alert">Before applying, the operator must isolate ingress, stop all external writers, '
                                .'and create a short-lived, root-owned matching maintenance permit on the Docker host. '
                                .'The agent consumes it before executing offline apply.</p>'
                                .'<label><input type="checkbox" name="confirm_maintenance" value="yes" required> '
                                .'I confirm that maintenance fencing and host approval are in place.</label>';
                        }
                        $h.='<button type="submit" class="primary">'.self::e(ucfirst($next).' signed update').'</button></form>';
                    }
                }
                try {
                    $recovery = $agent->call('recovery-status');
                    $h .= '<h2>Backup / recovery</h2><p>Host updater: <strong>'.self::e((string)$recovery['state']).'</strong>'
                        .' · Installed: '.self::e((string)($recovery['current_version']??'unknown'))
                        .' · Previous: '.self::e((string)($recovery['previous_version']??'none')).'</p>';
                    if (($recovery['backup_id']??null)!==null) {
                        $h .= '<p>Retained backup: <code>'.self::e((string)$recovery['backup_id']).'</code>'
                            .' · Root-owned integrity receipt: '.(($recovery['backup_attested']??false)===true?'recorded':'not available')
                            .'. Verify the backup on the host before recovery.</p>';
                    }
                    if (($recovery['manual_recovery_required']??false)===true || ($recovery['env_changed']??false)===true)
                        $h .= '<p role="alert">The host updater requires operator attention. Keep traffic fenced and inspect the host journal before any recovery or rollback.</p>';
                    $h .= '<p>Recovery and rollback require a trusted host operator; they cannot be started by PHP. '
                        .'See the 1.1.5 backup and recovery runbook.</p>';
                } catch (\Throwable) {
                    $h .= '<p>Root-owned backup and recovery details are unavailable. Consult the host operator.</p>';
                }
            }catch(\Throwable){$h.='<p role="alert">Host update agent unavailable; direct execution disabled. Check the local socket service.</p>';}
        }else{$h.='<p>Host execution is not configured. Set <code>BOOK_UPDATE_AGENT_SOCKET</code> only after deploying the trusted host agent.</p>';}
        $h.='<form method="post" action="/admin/updates" class="editor">'
            .'<input type="hidden" name="csrf" value="'.self::e($ctx['session']['csrf']).'">'
            .'<input type="hidden" name="action" value="check">'
            .'<label>Release channel<select name="channel"><option value="stable">Stable</option><option value="beta">Beta (discovery only)</option></select></label>'
            .'<button type="submit" class="primary">Check GitHub releases</button></form>'
            .'<p>Configure <code>BOOK_UPDATE_PUBLIC_KEY_FILE</code> to an independently verified Ed25519 public PEM key, '
            .'on the application host. Neither this page nor a GitHub download may change the trusted key.</p>'
            .'<p>Host installation requires a trusted updater, signed release, successful preflight and operator-maintained external traffic fence. Never grant Docker socket or root privileges to PHP.</p></div>';
        $page('System Updates',$h);
    }
}
