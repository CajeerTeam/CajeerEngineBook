<?php
declare(strict_types=1);
namespace CajeerBook;

/** Owner diagnostics from allowlisted, already-redacted control-plane state only. */
final class UpdateOperations
{
    private const ACTIONS = ['fetch','preflight','apply','reconcile'];
    private const STATES = ['queued','running','succeeded','failed'];

    public static function events(array $response):array
    {
        if(($response['state']??null)!=='ready'||!is_array($response['events']??null))
            throw new \RuntimeException('Invalid host operation history');
        $out=[];
        foreach(array_slice($response['events'],0,24) as $event){
            if(!is_array($event)||!in_array($event['action']??null,self::ACTIONS,true)||
                !in_array($event['state']??null,self::STATES,true)||
                !is_string($event['id']??null)||preg_match('/\A[a-f0-9]{32}\z/D',$event['id'])!==1||
                !is_int($event['time']??null)||!is_string($event['code']??null)||
                preg_match('/\A[A-Z_]{2,40}\z/D',$event['code'])!==1)throw new \RuntimeException('Invalid host operation event');
            $version=$event['version']??null;
            if($version!==null&&(!is_string($version)||strlen($version)>40||!preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D',$version)))
                throw new \RuntimeException('Invalid operation version');
            $out[]=['id'=>$event['id'],'time'=>$event['time'],'action'=>$event['action'],
                'state'=>$event['state'],'version'=>$version,'code'=>$event['code'],
                'elapsed_seconds'=>isset($event['elapsed_seconds'])&&is_int($event['elapsed_seconds'])
                    ?min(86400,max(0,$event['elapsed_seconds'])):null];
        }
        return $out;
    }

    public static function report(UpdateCenter $center,UpdateScheduler $scheduler,UpdateAgentClient $agent):array
    {
        $out=['format'=>1,'product'=>'CajeerEngineBook','generated_at'=>gmdate('c'),
            'installed_version'=>$center->installed(),'source'=>'owner_only_sanitized',
            'host'=>['status'=>'unavailable'],'scheduler'=>['status'=>'unavailable'],
            'discovery'=>['status'=>'unavailable'],'operations'=>[]];
        try{
            $cached=$center->cached();
            if(is_array($cached))$out['discovery']=['status'=>(string)($cached['status']??'unknown'),
                'channel'=>(string)($cached['channel']??'unknown'),
                'candidate_version'=>(string)($cached['candidate']['version']??''),
                'checked_at'=>(string)($cached['checked_at']??'')];
        }catch(\Throwable){$out['discovery']['status']='invalid_state';}
        try{
            $s=$scheduler->snapshot();
            $out['scheduler']=['status'=>(string)($s['last_status']??'not_started'),
                'enabled'=>($s['settings']['enabled']??false)===true,
                'mode'=>in_array($s['settings']['mode']??null,['notify_only','download_only','manual_approval'],true)?$s['settings']['mode']:'unknown',
                'channel'=>in_array($s['settings']['channel']??null,['stable','beta'],true)?$s['settings']['channel']:'unknown',
                'last_checked_at'=>is_int($s['last_checked_at']??null)?$s['last_checked_at']:null,
                'next_check_at'=>is_int($s['next_check_at']??null)?$s['next_check_at']:null,
                'history'=>[]];
            foreach(array_slice($s['history']??[],0,30) as $e){
                if(!is_array($e))continue;
                $out['scheduler']['history'][]=['time'=>is_int($e['time']??null)?$e['time']:0,
                    'event'=>substr((string)($e['event']??'unknown'),0,32),
                    'status'=>substr((string)($e['status']??'unknown'),0,32)];
            }
        }catch(\Throwable){$out['scheduler']['status']='invalid_state';}
        if($agent->configured()){
            try{
                $s=$agent->call('status');
                $out['host']=['status'=>(string)($s['state']??'unknown'),
                    'stage'=>(string)($s['stage']??'none'),
                    'action'=>(string)($s['action']??'none'),
                    'job_id'=>(string)($s['job_id']??''),
                    'version'=>(string)($s['candidate']['version']??'')];
                $out['operations']=self::events($agent->call('history'));
            }catch(\Throwable){$out['host']['history_status']='unavailable';}
            try{
                $r=$agent->call('recovery-status');
                $out['recovery']=['state'=>(string)($r['state']??'unknown'),
                    'manual_recovery_required'=>($r['manual_recovery_required']??false)===true,
                    'env_changed'=>($r['env_changed']??false)===true];
            }catch(\Throwable){$out['recovery']=['state'=>'unavailable'];}
        }
        return $out;
    }
}
