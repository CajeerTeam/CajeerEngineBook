<?php
declare(strict_types=1);
namespace CajeerBook;

/** Non-privileged scheduled discovery and owner notifications. Never activates releases. */
final class UpdateScheduler
{
    private readonly ?\Closure $mailer;
    private readonly ?\Closure $clock;
    private const INTERVALS = [15,60,360,720,1440];
    private const MODES = ['notify_only','download_only','manual_approval'];
    public function __construct(private readonly UpdateCenter $center,private readonly string $dataDir,
        private readonly ?UpdateAgentClient $agent=null,?\Closure $mailer=null,?\Closure $clock=null)
    {
        $this->mailer=$mailer;$this->clock=$clock;
    }
    private static function demand(bool $ok,string $why):void {if(!$ok)throw new \DomainException($why);}
    private function now():int{return $this->clock ? (int)($this->clock)() : time();}
    private function dir():string
    {
        $dir=$this->dataDir.'/update-center';
        if(!file_exists($dir))self::demand(@mkdir($dir,0700,true),'Unable to create scheduler state directory');
        self::demand(is_dir($dir)&&!is_link($dir)&&((fileperms($dir)&0077)===0),'Unsafe scheduler state directory');
        return $dir;
    }
    private function lock(callable $cb):mixed
    {
        $dir=$this->dir();$file=$dir.'/schedule.lock';
        self::demand(!is_link($file),'Unsafe scheduler lock');
        $mask=umask(0077);try{$fd=@fopen($file,'c+b');}finally{umask($mask);}self::demand(is_resource($fd),'Scheduler lock unavailable');
        try{
            self::demand((fstat($fd)['mode']&0077)===0,'Scheduler lock permissions invalid');
            self::demand(flock($fd,LOCK_EX),'Scheduler lock failed');
            return $cb($dir);
        }finally{flock($fd,LOCK_UN);fclose($fd);}
    }
    private static function read(string $file):array
    {
        self::demand(!is_link($file),'Unsafe scheduler state file');
        if(!is_file($file))return [];
        $size=filesize($file);self::demand($size!==false&&$size<=65536&&(fileperms($file)&0077)===0,'Invalid scheduler state');
        $data=json_decode((string)file_get_contents($file),true,24,JSON_THROW_ON_ERROR);
        self::demand(is_array($data)&&array_is_list($data)===false,'Invalid scheduler JSON');
        return $data;
    }
    private static function write(string $file,array $data):void
    {
        self::demand(!is_link($file),'Unsafe scheduler output file');
        $payload=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        self::demand(strlen($payload)<=65536,'Scheduler state too large');
        $temp=tempnam(dirname($file),'.schedule-');self::demand(is_string($temp),'Cannot allocate scheduler state');
        try{
            chmod($temp,0600);self::demand(file_put_contents($temp,$payload,LOCK_EX)===strlen($payload),'Scheduler state write failed');
            self::demand(rename($temp,$file),'Scheduler state commit failed');
        }finally{if(is_file($temp))unlink($temp);}
    }
    private static function defaults():array
    {
        return ['enabled'=>false,'channel'=>'stable','mode'=>'notify_only','interval_minutes'=>360,'notification_email'=>''];
    }
    private function settingsRaw(string $dir):array
    {
        $stored=self::read($dir.'/schedule-settings.json');
        $cfg=array_replace(self::defaults(),$stored);
        return self::validate($cfg);
    }
    private static function validate(array $s):array
    {
        self::demand(array_keys($s)===array_keys(self::defaults()),'Unexpected schedule settings');
        self::demand(is_bool($s['enabled']),'Invalid scheduler enabled flag');
        self::demand(in_array($s['channel'],['stable','beta'],true),'Invalid release channel');
        self::demand(in_array($s['mode'],self::MODES,true),'Invalid schedule mode');
        self::demand(in_array($s['interval_minutes'],self::INTERVALS,true),'Invalid interval');
        self::demand(is_string($s['notification_email'])&&strlen($s['notification_email'])<=254&&
            ($s['notification_email']===''||filter_var($s['notification_email'],FILTER_VALIDATE_EMAIL)),'Invalid notification email');
        self::demand($s['channel']==='stable'||$s['mode']==='notify_only',
            'Prerelease channel is discovery-only');
        return $s;
    }
    public function configure(array $raw):array
    {
        $settings=['enabled'=>($raw['enabled']??false)===true,'channel'=>$raw['channel']??'stable',
            'mode'=>$raw['mode']??'notify_only','interval_minutes'=>$raw['interval_minutes']??360,
            'notification_email'=>$raw['notification_email']??''];
        self::validate($settings);
        return $this->lock(function($dir)use($settings){
            $old=$this->settingsRaw($dir);
            self::write($dir.'/schedule-settings.json',$settings);
            // A changed channel or mode requires fresh signed discovery before host actions.
            if($old['channel']!==$settings['channel']||$old['mode']!==$settings['mode']||(!$old['enabled']&&$settings['enabled'])){
                $state=self::read($dir.'/schedule-state.json');$state['next_check_at']=0;
                self::write($dir.'/schedule-state.json',$state);
            }
            return $settings;
        });
    }
    public function snapshot():array
    {
        return $this->lock(function($dir){
            $s=self::read($dir.'/schedule-state.json');
            $s['settings']=$this->settingsRaw($dir);
            $s['notifications']=array_slice($s['notifications']??[],0,20);
            return $s;
        });
    }
    private function email(string $recipient,string $subject,string $message):bool
    {
        if($this->mailer)return ($this->mailer)($recipient,$subject,$message)===true;
        // Requires a trusted MTA/sendmail transport configured on the application host.
        return mail($recipient,$subject,$message,"From: CajeerEngine Book <book-updates@localhost>\r\nContent-Type: text/plain; charset=UTF-8");
    }
    /** Called by a dedicated non-root timer/cron worker; never by a public HTTP request. */
    public function tick():array
    {
        return $this->lock(function($dir){
            $settings=$this->settingsRaw($dir);$state=self::read($dir.'/schedule-state.json');
            if(!$settings['enabled'])return ['status'=>'disabled'];
            $now=$this->now();$due=($state['next_check_at']??0)<=$now;
            if($due){
                // Record the next permitted time before the network attempt: errors cannot create a tight retry loop.
                $state['last_checked_at']=$now;
                $state['next_check_at']=$now+$settings['interval_minutes']*60;
                try{
                    $result=$this->center->check($settings['channel']);
                    $this->center->save($result,false);
                    $state['last_status']=$result['status'];unset($state['last_error']);
                    $candidate=$result['candidate']??null;
                    if($result['status']==='available'&&is_array($candidate)){
                        $identity=$candidate['version'].':'.$candidate['manifest_sha256'];
                        if(($state['last_notice_key']??'')!==$identity){
                            $state['last_notice_key']=$identity;
                            $state['notifications'] ??= [];
                            $event=['id'=>bin2hex(random_bytes(8)),'time'=>$now,'kind'=>'release',
                                'version'=>$candidate['version'],'digest'=>$candidate['manifest_sha256'],'read'=>false,
                                'emailed'=>false];
                            array_unshift($state['notifications'],$event);
                        }
                        if($settings['channel']==='stable'&&$settings['mode']!=='notify_only'){
                            $this->center->save($result,true);
                            $state['staged_identity']=$identity;
                        }
                    }
                    if(in_array($result['status'],['rejected','incomplete','unsupported_prerelease'],true)){
                        $key='status:'.$result['status'];
                        if(($state['last_notice_key']??'')!==$key){
                            $state['last_notice_key']=$key;
                            $state['notifications'] ??= [];
                            array_unshift($state['notifications'],['id'=>bin2hex(random_bytes(8)),'time'=>$now,
                                'kind'=>'warning','status'=>$result['status'],'read'=>false,'emailed'=>false]);
                        }
                    }
                }catch(\Throwable $e){
                    $state['last_status']='error';$state['last_error']='GitHub check failed; inspect application logs';
                    $key='status:error';
                    if(($state['last_notice_key']??'')!==$key){
                        $state['last_notice_key']=$key;
                        $state['notifications'] ??= [];
                        array_unshift($state['notifications'],['id'=>bin2hex(random_bytes(8)),'time'=>$now,
                            'kind'=>'warning','status'=>'error','read'=>false,'emailed'=>false]);
                    }
                    Observability::log('update.schedule.check_failure',['error_type'=>get_class($e)]);
                }
            }
            if(!isset($state['notifications']))$state['notifications']=[];
            $state['notifications']=array_slice($state['notifications'],0,30);
            // Staging is idempotent. A timer never sends apply or bypasses maintenance approval.
            if(($state['last_status']??null)==='available'&&$settings['channel']==='stable'&&
                $settings['mode']!=='notify_only'&&isset($state['staged_identity'])){
                try{
                    $pinned=$this->center->cached(true);$c=$pinned['candidate']??null;
                    if(($pinned['status']??'')==='available'&&$pinned['installed']===$this->center->installed()&&is_array($c)&&
                        $state['staged_identity']===$c['version'].':'.$c['manifest_sha256']){
                        $agent=$this->agent??new UpdateAgentClient();
                        if($agent->configured()){
                            $host=$agent->call('status');
                            if(($state['next_stage_attempt_at']??0)<=$now&&!in_array($host['state'],['queued','running','interrupted'],true)&&
                                !(($host['action']??null)==='apply'&&$host['state']==='failed')){
                                $target=['version'=>$c['version'],'commit'=>$c['commit'],'manifest_sha256'=>$c['manifest_sha256']];
                                $matches=($host['candidate']??null)===$target;
                                if(!$matches||!in_array($host['stage']??'none',['fetched','preflight','applied'],true)){
                                    $agent->call('fetch',$target);$state['last_stage_action']='fetch';$state['next_stage_attempt_at']=$now+900;unset($state['stage_status']);
                                }elseif($settings['mode']==='manual_approval'&&$matches&&($host['stage']??'')==='fetched'&&$host['state']==='succeeded'){
                                    $agent->call('preflight',$target);$state['last_stage_action']='preflight';$state['next_stage_attempt_at']=$now+900;unset($state['stage_status']);
                                }
                            }
                        }else{$state['stage_status']='Host agent not configured';}
                    }
                }catch(\Throwable $e){
                    $state['stage_status']='Host staging unavailable; inspect operator logs';$state['next_stage_attempt_at']=$now+$settings['interval_minutes']*60;
                    Observability::log('update.schedule.stage_failure',['error_type'=>get_class($e)]);
                }
            }
            if($settings['notification_email']!==''){
                // A failed MTA is retried on a later tick, but at most once per configured interval.
                foreach($state['notifications'] as &$event){
                    if($event['emailed']||($event['next_email_attempt']??0)>$now)continue;
                    $event['next_email_attempt']=$now+$settings['interval_minutes']*60;
                    $subject=$event['kind']==='release'?'CajeerEngine Book update '.$event['version']:'CajeerEngine Book update check warning';
                    $body=$event['kind']==='release'
                        ?'A signed GitHub release '.$event['version'].' is available. Review it at /admin/updates before installation.'
                        :'Update discovery needs attention. Review /admin/updates and the application logs.';
                    try{$event['emailed']=$this->email($settings['notification_email'],$subject,$body);}
                    catch(\Throwable){$event['emailed']=false;}
                }
                unset($event);
            }
            self::write($dir.'/schedule-state.json',$state);
            return ['status'=>$due?($state['last_status']??'unknown'):'not_due','next_check_at'=>$state['next_check_at']??0,
                'notifications'=>count($state['notifications']),'stage_action'=>$state['last_stage_action']??null];
        });
    }
}
