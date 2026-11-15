<?php
declare(strict_types=1);
namespace CajeerBook;
use CajeerEngine\Update\OperationFiles as Files;

/** A single installation worker serializes claims, not web requests. Network runs outside identity/store locks. */
final class AutomationWorker
{
    public function __construct(private readonly string $root,private readonly IdentityStore $identity,private readonly ?\Closure $gitFactory=null,private readonly ?\Closure $sender=null){}
    private function with(string $org,string $id,callable $fn):mixed{
        return $this->identity->automationWorkspace($org,$id,function($w,$admins)use($fn){$dir=$w?Workspaces::directory($this->root,$w):$this->root;return $fn(new BookStore($dir,$w===null),$dir,$admins);});
    }
    public function tick():int{
        Files::dir($this->root);$lock=Files::path($this->root,'automation-worker.lock');$h=fopen($lock,'c+');if(!$h)throw new \RuntimeException('Worker lock unavailable.');
        try{if(!flock($h,LOCK_EX|LOCK_NB))return 0;chmod($lock,0600);$count=0;foreach($this->identity->automationScopes() as $scope){try{if($this->process($scope['org'],$scope['id']))$count++;}catch(\Throwable $e){error_log('Automation workspace failed: '.get_class($e));}}return $count;}finally{fclose($h);}
    }
    private function process(string $org,string $id):bool{
        $capture=$this->with($org,$id,function($store,$dir,$admins){
            $db=$store->read();if(!isset($db['automation']))return null;
            $now=time();$due=false;foreach($db['automation']['jobs'] as $j)if(($j['state']==='queued'&&$j['due']<=$now)||$j['state']==='running'){$due=true;break;}if(!$due)return null;
            $job=null;$store->change(function(&$db)use(&$job,$now){$job=AutomationQueue::claim($db,$now,true);});if(!$job)return null;
            $db=$store->read();return ['job'=>$job,'db'=>$db,'dir'=>$dir,'actor'=>$admins[$job['actor']]??null];
        });if(!$capture)return false;$job=$capture['job'];$db=$capture['db'];$actor=$capture['actor'];
        try{
            $c=AutomationQueue::assertLease($db,$job);if(!$actor)throw new OrganizationAccessException('Automation administrator no longer authorized.');
            if($job['kind']==='delivery'){
                $request=AutomationQueue::delivery($c,$job);
                $response=$this->sender?($this->sender)($request):ApiPlaygroundTransport::send($request,'BOOK_WEBHOOK_ORIGINS');$status=$response['status'];
                if($status>=200&&$status<300)$this->finish($org,$id,$job,'succeeded');
                else $this->finish($org,$id,$job,'dead','HTTP '.$status,in_array($status,[408,425,429],true)||$status>=500);
            }else{
                if(($db['github']['id']??null)!==$job['payload']['git']||$c['git']!==$job['payload']['git'])throw new OrganizationAccessException('Git connection replaced.');
                $dir=$capture['dir'];$store=new BookStore($dir,false);$sync=new GitHubSync($store,new MarkdownImport($store,new MediaStore($dir,$store)),$this->gitFactory,$dir.'/github-cache');
                $secret=bin2hex(random_bytes(32));$prepared=$sync->download($db,$secret);
                $this->with($org,$id,function($store,$dir,$admins)use($job,$prepared,$secret){
                    $db=$store->read();AutomationQueue::assertLease($db,$job);$actor=$admins[$job['actor']]??null;if(!$actor)throw new OrganizationAccessException('Automation administrator no longer authorized.');
                    $store->setHistoryActor($actor);$sync=new GitHubSync($store,new MarkdownImport($store,new MediaStore($dir,$store)),$this->gitFactory,$dir.'/github-cache');
                    if(($db['github']['id']??null)!==$job['payload']['git']||$prepared['revision']!==($db['github']['revision']??null))throw new \DomainException('Git connection changed during synchronization.');
                    if($prepared['commit']===$db['github']['commit']){$store->change(fn(&$d)=>AutomationQueue::finish($d,$job,'succeeded','',false,0,$prepared['commit']));return;}
                    $preview=$sync->preview($secret,$prepared);if($preview['conflicts']||$preview['blocked'])throw new \DomainException('Git conflict requires manual Studio resolution.');
                    // Cursor and successful job commit together, eliminating duplicate local imports after a crash.
                    $sync->apply($secret,$preview['token'],$preview['commit'],'safe',$actor['username'],$prepared,[],fn(&$d)=>AutomationQueue::finish($d,$job,'succeeded','',false,0,$prepared['commit']));
                });
            }
        }catch(OrganizationAccessException $e){$this->finish($org,$id,$job,'cancelled','Authorization revoked or connection replaced.');}
        catch(GitHubRemoteException $e){$this->finish($org,$id,$job,'dead','Git provider request failed.',true);}
        catch(\DomainException|\InvalidArgumentException $e){$this->finish($org,$id,$job,'dead','Configuration, conflict or validation requires attention.');}
        catch(\Throwable $e){$this->finish($org,$id,$job,'dead','Network or worker failure.',true);}
        return true;
    }
    private function finish(string $org,string $id,array $job,string $state,string $error='',bool $retry=false):void{
        $this->with($org,$id,function($store,$dir,$admins)use($job,$state,$error,$retry){$store->change(function(&$db)use($job,$state,$error,$retry,$admins){
            $current=$db['automation']['jobs'][$job['id']]??null;if(!$current||$current['state']!=='running'||$current['lease']!==$job['lease'])return;
            if(!isset($admins[$job['actor']])){AutomationQueue::finish($db,$job,'cancelled','Authorization revoked.');return;}
            AutomationQueue::finish($db,$job,$state,$error,$retry);
        });});
    }
}
